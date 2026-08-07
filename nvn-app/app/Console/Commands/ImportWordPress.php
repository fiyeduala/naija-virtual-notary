<?php

namespace App\Console\Commands;

use App\Models\NotarizationRequest;
use App\Models\NotaryProfile;
use App\Models\Post;
use App\Models\User;
use App\Support\WordPressHasher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Brings the old WordPress site's people and paperwork into this application.
 *
 * Three things this deliberately does not do:
 *
 *  1. It never writes to `payments`. WooCommerce and WCFM hold years of orders
 *     and a vendor ledger, and every one of those notaries has already been
 *     paid. Importing that history as payments would make the payout ledger
 *     believe the platform owes it all over again.
 *
 *  2. It never approves a notary. Six people had vendor accounts on a
 *     WordPress site; whether they are duly appointed Notaries Public today is
 *     a judgement for a human with their certificate in front of them, so
 *     every profile lands as `pending` and waits.
 *
 *  3. It never puts a WordPress hash in `users.password`. That column is cast
 *     'hashed', so assigning a hash to it hashes the hash and locks the person
 *     out permanently. Hashes go to `legacy_password`, and LegacyUserProvider
 *     converts each one on its owner's first successful sign-in.
 *
 * Historical requests are imported as `completed`. Not because every one of
 * them was — some were abandoned at the payment step — but because the two
 * statuses that describe an open job, `paid` and `submitted`, are the statuses
 * the response-window watchdog and the notary desk look for. Importing 213
 * closed jobs as open ones would put two hundred alerts in front of the admin
 * on the first morning, about work finished months ago.
 *
 * Safe to run repeatedly: everything is keyed on where it came from, so a
 * second run updates rather than duplicates.
 */
class ImportWordPress extends Command
{
    protected $signature = 'nvn:import-wordpress
        {--dry-run : Do the whole import inside a transaction, report it, then roll back}
        {--only= : Comma-separated stages to run: users, notaries, requests, posts}
        {--stamp-as-seal : Register each notary stamp as their seal as well (see the note this prints)}
        {--author=client : Role for WordPress "author" accounts: client, notary or admin}
        {--map=* : form-address=account-address, for a form filled in under a different email. Repeatable.}';

    protected $description = 'Import users, notary profiles and request history from the old WordPress site';

    private bool $dry = false;

    /** basename => absolute path, for every file under cfdb7_uploads. */
    private array $fileIndex = [];

    /** Counters, printed as the summary. Everything interesting is a number here. */
    private array $stats = [];

    /** Which --map arguments actually matched something. */
    private array $mapsUsed = [];

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');

        if (! $this->preflight()) {
            return self::FAILURE;
        }

        $stages = $this->option('only')
            ? array_map('trim', explode(',', $this->option('only')))
            : ['users', 'notaries', 'requests', 'posts'];

        $this->indexUploads();

        DB::beginTransaction();

        try {
            if (in_array('users', $stages, true)) {
                $this->importUsers();
            }

            if (in_array('notaries', $stages, true)) {
                $this->importNotaries();
            }

            if (in_array('requests', $stages, true)) {
                $this->importRequests();
            }

            if (in_array('posts', $stages, true)) {
                $this->importPosts();
            }

            if ($this->dry) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->newLine();
            $this->error('Rolled back. Nothing was written.');
            $this->error($e->getMessage());

            throw $e;
        }

        $this->report();

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Preflight
    |--------------------------------------------------------------------------
    */

    private function preflight(): bool
    {
        foreach ((array) $this->option('map') as $pair) {
            if (! str_contains((string) $pair, '=')) {
                $this->error("--map needs form-address=account-address, got: {$pair}");

                return false;
            }
        }

        if (! config('database.connections.wordpress.database')) {
            $this->error('WP_DB_DATABASE is not set. Add the old database credentials to .env:');
            $this->line('  WP_DB_DATABASE=  WP_DB_USERNAME=  WP_DB_PASSWORD=  WP_TABLE_PREFIX=  WP_PATH=');

            return false;
        }

        try {
            $this->wp()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot connect to the WordPress database: ' . $e->getMessage());

            return false;
        }

        if (! $this->wp()->getSchemaBuilder()->hasTable($this->t('users'))) {
            $this->error("No table `{$this->t('users')}`. Is WP_TABLE_PREFIX right?");

            return false;
        }

        $path = config('nvn.wordpress.path');

        if (! $path || ! is_dir($path)) {
            $this->warn('WP_PATH is not set or is not a directory.');
            $this->warn('Accounts will import fine; every uploaded file will be skipped.');

            if (! $this->dry && ! $this->confirm('Continue without files?', false)) {
                return false;
            }
        }

        if ($this->dry) {
            $this->info('DRY RUN — everything below happens inside a transaction that is rolled back.');
            $this->newLine();
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 1 — accounts
    |--------------------------------------------------------------------------
    */

    private function importUsers(): void
    {
        $this->line('Users…');

        $rows = $this->wp()->table($this->t('users'))->orderBy('ID')->get();
        $meta = $this->userMeta($rows->pluck('ID')->all());
        $bar  = $this->output->createProgressBar($rows->count());

        foreach ($rows as $row) {
            $bar->advance();

            $m     = $meta[$row->ID] ?? [];
            $email = trim((string) $row->user_email);

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->bump('users.skipped_bad_email');
                continue;
            }

            $role = $this->mapRole($m[$this->t('capabilities')] ?? null);

            if ($role === null) {
                $this->bump('users.skipped_unmapped_role');
                continue;
            }

            $existing = User::withTrashed()
                ->where('legacy_source', 'wordpress')->where('legacy_id', $row->ID)
                ->first()
                ?? User::withTrashed()->where('email', $email)->first();

            // An account that already exists here and did NOT come from
            // WordPress is somebody who has signed in to this application —
            // the seeded admin, most likely. Their working password must not
            // be replaced with a legacy hash they may no longer remember.
            if ($existing && $existing->legacy_source === null) {
                $this->bump('users.skipped_already_native');
                continue;
            }

            $user = $existing ?? new User();
            $name = $this->displayName($row, $m);

            $user->fill([
                'full_name' => $name,
                'email'     => $email,
                'phone'     => $m['billing_phone'] ?? $m['phone'] ?? $user->phone,
                'role'      => $role,
                'status'    => 'active',
                // These people verified their address on the old site years
                // ago. Sending 421 of them through the OTP gate again, on a
                // platform they did not ask to be moved to, would read as a
                // broken migration rather than a security measure.
                'email_verified_at' => $existing?->email_verified_at ?? $row->user_registered,
            ]);

            if (! $user->exists) {
                // Cast 'hashed' turns this into a bcrypt hash of a string
                // nobody knows, which is the point: the only way in is the
                // WordPress hash below, or a password reset.
                $user->password   = Str::random(48);
                $user->created_at = $row->user_registered;
            }

            // Only while it is still needed. Once somebody has signed in,
            // LegacyUserProvider has nulled this and upgraded the account —
            // putting the old hash back would undo that.
            if ($user->legacy_password !== null || ! $user->exists) {
                if (WordPressHasher::recognises($row->user_pass)) {
                    $user->legacy_password = $row->user_pass;
                } else {
                    $this->bump('users.unrecognised_hash');
                }
            } else {
                $this->bump('users.already_converted');
            }

            $user->legacy_source = 'wordpress';
            $user->legacy_id     = $row->ID;

            if ($user->trashed()) {
                $user->restore();
                $this->bump('users.restored');
            }

            $this->bump($user->exists ? 'users.updated' : 'users.created');
            $this->bump('users.role_' . $role);

            $user->save();
        }

        $bar->finish();
        $this->newLine(2);
    }

    /** All the usermeta we care about, in one query rather than 421. */
    private function userMeta(array $ids): array
    {
        $keys = [
            $this->t('capabilities'), 'first_name', 'last_name', 'billing_phone', 'phone',
            'store_name', 'wcfmmp_store_name', '_store_description',
            '_wcfm_street_1', '_wcfm_street_2', '_wcfm_city', '_wcfm_state',
            '_wcfm_zip', '_wcfm_country',
        ];

        $out = [];

        $this->wp()->table($this->t('usermeta'))
            ->whereIn('user_id', $ids)
            ->whereIn('meta_key', $keys)
            ->orderBy('umeta_id')
            ->chunk(2000, function ($chunk) use (&$out) {
                foreach ($chunk as $r) {
                    $out[$r->user_id][$r->meta_key] = $r->meta_value;
                }
            });

        return $out;
    }

    /**
     * WordPress role => this application's role.
     *
     * wp_capabilities is a serialized map of role => true. A user can hold
     * several; the most privileged wins, because losing an administrator to a
     * subscriber row is the failure that matters.
     */
    private function mapRole(?string $capabilities): ?string
    {
        $caps = @unserialize((string) $capabilities);

        if (! is_array($caps)) {
            return 'client';
        }

        $held = array_keys(array_filter($caps));

        if (in_array('administrator', $held, true)) {
            return 'admin';
        }

        if (array_intersect($held, ['wcfm_vendor', 'seller', 'shop_manager', 'dc_vendor'])) {
            return 'notary';
        }

        if (in_array('author', $held, true) || in_array('editor', $held, true)) {
            $choice = strtolower((string) $this->option('author'));

            return in_array($choice, ['client', 'notary', 'admin'], true) ? $choice : 'client';
        }

        // sa_client, customer, subscriber, and anything bespoke.
        return 'client';
    }

    private function displayName(object $row, array $meta): string
    {
        $parts = trim(($meta['first_name'] ?? '') . ' ' . ($meta['last_name'] ?? ''));

        foreach ([$parts, $row->display_name, $row->user_login] as $candidate) {
            if (trim((string) $candidate) !== '') {
                return Str::limit(trim($candidate), 250, '');
            }
        }

        return 'WordPress user ' . $row->ID;
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 2 — notary profiles, credentials, sealing assets, bank details
    |--------------------------------------------------------------------------
    */

    private function importNotaries(): void
    {
        $this->line('Notary profiles…');

        $applications = $this->submissionsByEmail(config('nvn.wordpress.forms.application'), 'email');
        $confirmations = $this->submissionsByEmail(config('nvn.wordpress.forms.confirmation'), 'notary-email');

        $notaries = User::where('role', 'notary')->where('legacy_source', 'wordpress')->get();

        $this->bump('notaries.applications_on_file', $applications->count());
        $this->bump('notaries.confirmations_on_file', $confirmations->count());

        $claimed = [];

        foreach ($notaries as $user) {
            $profile = NotaryProfile::firstOrNew(['user_id' => $user->id]);

            if (! $profile->exists) {
                $profile->fill([
                    // Never 'approved'. See the class docblock.
                    'verification_status'    => 'pending',
                    'public_listing_enabled' => false,
                    'commission_rate'        => config('nvn.default_commission_rate'),
                    'delegation_consent'     => false,
                    'is_system_native'       => false,
                ]);
                $this->bump('notaries.profiles_created');
            } else {
                $this->bump('notaries.profiles_updated');
            }

            $key = strtolower($user->email);

            if ($app = $applications->get($key)) {
                $this->applyApplication($profile, $app);
                $this->bump('notaries.matched_application');
            } else {
                $this->bump('notaries.no_application');
            }

            $profile->save();

            if ($app) {
                $this->importCredential($profile, $app, 'id-documentcfdb7_file', 'valid_id');
                $this->importCredential($profile, $app, 'certificatecfdb7_file', 'notary_certificate');
            }

            if ($confirmation = $confirmations->get($key)) {
                $this->applyConfirmation($profile, $confirmation);
                $this->bump('notaries.matched_confirmation');
                $claimed[] = $key;
            } else {
                $this->bump('notaries.no_confirmation');
            }

            // The question that decides whether this person can work on day one.
            $profile->load('assets');
            $this->bump($profile->canSeal() ? 'notaries.can_seal' : 'notaries.cannot_seal');
        }

        // A confirmation nobody claimed is the expensive kind of miss. It means
        // somebody completed the partner form — stamp, signature, bank details,
        // the assets a notary cannot work without — using an address that is not
        // the one on their account. The submission is not lost, but nothing will
        // find it on its own, so name it here rather than leave it in a count.
        $orphans = $confirmations->keys()->diff($claimed);

        if ($orphans->isNotEmpty()) {
            $this->newLine();
            $this->warn("{$orphans->count()} partner confirmation(s) matched no notary account:");

            foreach ($orphans as $email) {
                $this->line("  · {$email}");
            }

            $this->line('  Either that person has no WordPress notary account, or they');
            $this->line('  filled the form in with a different address. Fix the address on');
            $this->line('  one side and re-run — the import is safe to run twice.');
        }

        $this->newLine();
    }

    private function applyApplication(NotaryProfile $profile, array $f): void
    {
        // Through str(), not a direct cast: CFDB7 stores a radio, checkbox or
        // select as an array even when only one option can be chosen, so half
        // these fields arrive as ['Individual'] rather than 'Individual'.
        $type = strtolower((string) $this->str($f['applicant-type'] ?? null));

        $profile->fill(array_filter([
            'entity_type' => Str::contains($type, ['agency', 'organis', 'organiz', 'firm', 'compan'])
                ? 'agency'
                : 'individual',
            'organization_name' => $this->str($f['organization'] ?? null, 250),
            'license_ref'       => $this->str($f['license-number'] ?? null, 250),
            'experience'        => $this->str($f['experience'] ?? null),
            'motivation'        => $this->str($f['reason'] ?? null),
        ], fn ($v) => $v !== null && $v !== ''));

        if ($specialty = $f['specialty'] ?? null) {
            $list = is_array($specialty) ? $specialty : preg_split('/\s*[,;|]\s*/', (string) $specialty);
            $list = array_values(array_filter(array_map('trim', $list)));

            if ($list) {
                $profile->specialties = $list;
            }
        }

        // The old application asked when the licence expires; there is no
        // column for that here, and inventing one for a field six people
        // filled in is worse than putting it where a reviewer will read it.
        $notes = array_filter([
            $profile->review_notes,
            isset($f['license-expiry']) && $f['license-expiry'] !== ''
                ? 'Licence expiry, per the WordPress application: ' . $this->str($f['license-expiry'], 100)
                : null,
            'Imported from the WordPress application form. Not yet verified.',
        ]);

        $profile->review_notes = implode("\n", array_unique($notes));
    }

    private function applyConfirmation(NotaryProfile $profile, array $f): void
    {
        $signature = $this->storeFile($f['upload-e-signaturecfdb7_file'] ?? null, 'notary-assets');
        $stamp     = $this->storeFile($f['upload-stampcfdb7_file'] ?? null, 'notary-assets');
        $logo      = $this->storeFile($f['upload-logocfdb7_file'] ?? null, 'notary-assets');

        if ($signature) {
            $this->putAsset($profile, 'signature', $signature, $this->str($f['e-signature'] ?? null, 250));
        } elseif ($typed = $this->str($f['e-signature'] ?? null, 250)) {
            // A typed name with no image behind it. Recorded, but it will not
            // satisfy canSeal(), which wants something that can be drawn.
            $this->putAsset($profile, 'signature', null, $typed);
        }

        if ($stamp) {
            $this->putAsset($profile, 'stamp', $stamp);

            if ($this->option('stamp-as-seal')) {
                $this->putAsset($profile, 'seal', $stamp);
                $this->bump('notaries.stamp_copied_to_seal');
            }
        }

        if ($logo) {
            // Nothing in this application renders profile_photo_url yet. Kept
            // so the file is not lost when WordPress goes away.
            $profile->profile_photo_url = $logo;
            $profile->save();
        }

        $bank    = $this->str($f['bank-name'] ?? null, 250);
        $number  = $this->str($f['account-number'] ?? null, 100);
        $account = $this->str($f['account-name'] ?? null, 250);

        if ($bank && $number && $account) {
            $existing = $profile->bankDetails()->first();

            // Bank details that are already here were typed by the notary into
            // this application, and may have been verified against Paystack
            // since. A years-old form submission does not get to overwrite that.
            if (! $existing) {
                $profile->bankDetails()->create([
                    'bank_name'      => $bank,
                    'account_number' => preg_replace('/\D/', '', $number),
                    'account_name'   => $account,
                ]);
                $this->bump('notaries.bank_details_created');
            } else {
                $this->bump('notaries.bank_details_kept');
            }
        } else {
            $this->bump('notaries.bank_details_incomplete');
        }
    }

    private function putAsset(NotaryProfile $profile, string $type, ?string $path, ?string $text = null): void
    {
        $asset = $profile->assets()->firstOrNew(['type' => $type]);

        // Do not blank an asset that is already here with a file we could not
        // find. An empty file_url means "cannot seal".
        if ($path !== null) {
            $asset->file_url = $path;
        }

        if ($text !== null && $text !== '') {
            $asset->text_value = $text;
        }

        $asset->save();
        $this->bump('notaries.asset_' . $type);
    }

    private function importCredential(NotaryProfile $profile, array $f, string $field, string $type): void
    {
        $path = $this->storeFile($f[$field] ?? null, 'notary-credentials');

        if ($path === null) {
            return;
        }

        $profile->credentials()->updateOrCreate(
            ['document_type' => $type],
            [
                'file_url'          => $path,
                'original_filename' => $this->firstFilename($f[$field]),
                'status'            => 'pending',
            ]
        );

        $this->bump('notaries.credential_' . $type);
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 3 — request history
    |--------------------------------------------------------------------------
    */

    private function importRequests(): void
    {
        $this->line('Request history…');

        $rows = $this->submissions(config('nvn.wordpress.forms.request'));
        $bar  = $this->output->createProgressBar($rows->count());

        foreach ($rows as $row) {
            $bar->advance();

            $f     = $row->fields;
            $email = strtolower((string) $this->str($f['email'] ?? null));

            $client = $email ? User::where('email', $email)->first() : null;

            if (! $client) {
                // A request whose email never became an account. It cannot be
                // attached to anybody, and a request with no client is not a
                // record of anything. The CFDB7 row stays in the old database.
                $this->bump('requests.no_matching_client');
                continue;
            }

            $reference = 'NVN-WP-' . str_pad((string) $row->id, 6, '0', STR_PAD_LEFT);

            $request = NotarizationRequest::withTrashed()->firstOrNew(['reference' => $reference]);
            $isNew   = ! $request->exists;

            $request->fill([
                'client_id'    => $client->id,
                'notary_id'    => null,
                'service_id'   => null,
                // See the class docblock: closed, so no desk and no watchdog
                // treats months-old paperwork as work waiting to be done.
                'status'       => 'completed',
                'document_use' => $this->str($f['document-use'] ?? null),
                'currency'     => 'NGN',
                'hard_copy_requested' => $this->truthy($f['hard-copy'] ?? null),
                'delivery_address'    => array_filter([
                    'street'    => $this->str($f['street-address'] ?? null, 250),
                    'apartment' => $this->str($f['apartment'] ?? null, 250),
                    'city'      => $this->str($f['city'] ?? null, 120),
                    'state'     => $this->str($f['state'] ?? null, 120),
                    'zip'       => $this->str($f['zip'] ?? null, 40),
                    'country'   => $this->str($f['country'] ?? null, 120),
                ], fn ($v) => $v !== null && $v !== ''),
                'intake_data'  => $this->archivable($f),
                'submitted_at' => $row->form_date,
                'completed_at' => $row->form_date,
                'created_at'   => $row->form_date,
            ]);

            $request->save();
            $this->bump($isNew ? 'requests.created' : 'requests.updated');

            $this->importDocument($request, $client, $f, 'upload-documentcfdb7_file', 'document');
            $this->importDocument($request, $client, $f, 'upload-idcfdb7_file', 'identification');

            // Any other upload on the form, whatever it was called. CFDB7 marks
            // a file field by appending 'cfdb7_file' to its name, and that
            // suffix is the only reliable way to tell an upload from a text box
            // — 'additional-documents' has no suffix and holds a description
            // the client typed, which was being read as a list of filenames.
            foreach (array_keys($f) as $field) {
                if (str_ends_with($field, 'cfdb7_file')
                    && ! in_array($field, ['upload-documentcfdb7_file', 'upload-idcfdb7_file'], true)) {
                    $this->importDocument($request, $client, $f, $field, 'additional');
                }
            }
        }

        $bar->finish();
        $this->newLine(2);
    }

    private function importDocument(
        NotarizationRequest $request,
        User $client,
        array $fields,
        string $field,
        string $type
    ): void {
        foreach ($this->filenames($fields[$field] ?? null) as $filename) {
            $path = $this->storeFile($filename, 'request-documents');

            if ($path === null) {
                continue;
            }

            $request->documents()->updateOrCreate(
                ['file_type' => $type, 'original_filename' => $filename],
                [
                    'file_url'    => $path,
                    'uploaded_by' => $client->id,
                    // The old site had no notion of a finished notarised PDF
                    // in this form; these are all client uploads.
                    'is_final_notarized' => false,
                ]
            );

            $this->bump('requests.document_' . $type);
        }
    }

    /**
     * The submission, minus the plugin's own bookkeeping.
     *
     * cfdb7_status is CFDB7's read/unread marker and the product dropdown is a
     * WooCommerce id that means nothing here; everything else is what the
     * client actually typed, and is kept verbatim so the record stays useful.
     */
    private function archivable(array $fields): array
    {
        $drop = ['cfdb7_status'];

        $kept = array_filter(
            $fields,
            fn ($k) => ! in_array($k, $drop, true) && ! str_ends_with($k, 'cfdb7_file'),
            ARRAY_FILTER_USE_KEY
        );

        return $kept + ['_imported_from' => 'wordpress-cfdb7'];
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 4 — blog articles
    |--------------------------------------------------------------------------
    */

    /**
     * Every article becomes the admin's, whoever wrote it on the old site.
     *
     * The alternative is importing WordPress's author accounts as people who
     * can write here, and a WordPress "author" is not an administrator — it is
     * a role that existed to let one member of staff post updates. Carrying it
     * over as panel access would hand a years-old password an admin login.
     */
    private function importPosts(): void
    {
        $this->line('Blog articles…');

        $author = User::where('email', config('nvn.system_native_email'))->where('role', 'admin')->first()
            ?? User::where('role', 'admin')->orderBy('id')->first();

        if (! $author) {
            $this->warn('  No admin account exists yet — run the seeder first. Skipping articles.');

            return;
        }

        $rows = $this->wp()->table($this->t('posts'))
            ->where('post_type', 'post')
            ->whereIn('post_status', ['publish', 'draft', 'pending', 'private', 'future'])
            ->orderBy('ID')
            ->get();

        $thumbnails = $this->thumbnailPaths($rows->pluck('ID')->all());
        $bar        = $this->output->createProgressBar($rows->count());

        foreach ($rows as $row) {
            $bar->advance();

            $post = Post::withTrashed()->firstOrNew([
                'legacy_source' => 'wordpress',
                'legacy_id'     => $row->ID,
            ]);

            $isNew = ! $post->exists;

            // Only on the way in. If somebody has since edited the article here,
            // re-running the import must not throw their work away.
            if (! $isNew) {
                $this->bump('posts.already_imported');
                continue;
            }

            $title = $this->str($row->post_title, 250) ?? 'Untitled';
            $html  = (string) $row->post_content;

            $this->bump('posts.embeds_removed', preg_match_all('/<iframe\b/i', $html));

            $post->fill([
                'author_id'    => $author->id,
                'title'        => $title,
                'slug'         => Post::uniqueSlug($this->str($row->post_name) ?: $title),
                'excerpt'      => $this->str($row->post_excerpt, 500),
                'body'         => $this->rewriteInlineMedia($html),
                'cover_image'  => $thumbnails[$row->ID] ?? null,
                // Everything that was not live on WordPress arrives as a draft,
                // including 'future' — a post scheduled for a date that has
                // since passed should be looked at, not published unread.
                'status'       => $row->post_status === 'publish' ? 'published' : 'draft',
                'published_at' => $row->post_status === 'publish' ? $row->post_date : null,
                'created_at'   => $row->post_date,
            ]);

            $post->save();

            $this->bump('posts.created');
            $this->bump('posts.' . ($row->post_status === 'publish' ? 'published' : 'draft'));
        }

        $bar->finish();
        $this->newLine(2);
    }

    /**
     * Featured images, as paths on the blog disk, keyed by post id.
     *
     * Two hops in WordPress: the post's _thumbnail_id points at an attachment,
     * and the attachment's _wp_attached_file is its path under uploads/.
     */
    private function thumbnailPaths(array $postIds): array
    {
        if (! $postIds) {
            return [];
        }

        $thumbnailIds = $this->wp()->table($this->t('postmeta'))
            ->whereIn('post_id', $postIds)
            ->where('meta_key', '_thumbnail_id')
            ->pluck('meta_value', 'post_id');

        if ($thumbnailIds->isEmpty()) {
            return [];
        }

        $files = $this->wp()->table($this->t('postmeta'))
            ->whereIn('post_id', $thumbnailIds->values()->all())
            ->where('meta_key', '_wp_attached_file')
            ->pluck('meta_value', 'post_id');

        $out = [];

        foreach ($thumbnailIds as $postId => $attachmentId) {
            $relative = $files[$attachmentId] ?? null;

            if ($relative && $stored = $this->storeMedia($relative)) {
                $out[$postId] = $stored;
            }
        }

        return $out;
    }

    /**
     * Copy the pictures inside an article across and repoint them at us.
     *
     * The old site's image URLs are absolute, on a domain that is about to stop
     * serving WordPress — the cut-over is a document-root change, so those URLs
     * keep resolving and start returning 404. Only images an article actually
     * references are copied, which is a few megabytes rather than the 1.3 GB
     * uploads directory.
     */
    private function rewriteInlineMedia(string $html): string
    {
        return preg_replace_callback(
            '#(<img\b[^>]*?\bsrc\s*=\s*["\'])([^"\']*?/wp-content/uploads/)([^"\']+)(["\'])#i',
            function (array $m) {
                $stored = $this->storeMedia(rawurldecode($m[3]));

                if ($stored === null) {
                    $this->bump('posts.inline_image_missing');

                    return $m[0];
                }

                $this->bump('posts.inline_image_rewritten');

                return $m[1] . '/blog-media/' . $stored . $m[4];
            },
            $html
        ) ?? $html;
    }

    /**
     * Copy one file from wp-content/uploads onto the public blog disk.
     *
     * Returns its path on that disk, or null. The path under uploads/ is kept
     * as-is (2024/05/whatever.jpg), because WordPress filenames already collide
     * constantly and the year/month folders are what keeps them apart.
     */
    private function storeMedia(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        // No traversal out of uploads/, whatever the database says.
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $base = config('nvn.wordpress.path');

        if (! $base) {
            return null;
        }

        $source = rtrim($base, '/\\') . '/wp-content/uploads/' . $relative;

        if (! is_file($source) || ! is_readable($source)) {
            return null;
        }

        $target = 'imported/' . $relative;

        if ($this->dry) {
            $this->bump('files.would_copy_media');

            return $target;
        }

        if (! Storage::disk('blog')->exists($target)) {
            Storage::disk('blog')->put($target, file_get_contents($source));
            $this->bump('files.media_copied');
        }

        return $target;
    }

    /*
    |--------------------------------------------------------------------------
    | CFDB7
    |--------------------------------------------------------------------------
    */

    /** Every submission of one form, with form_value already unserialized. */
    private function submissions(int $formId): \Illuminate\Support\Collection
    {
        return $this->wp()->table($this->t('db7_forms'))
            ->where('form_post_id', $formId)
            ->orderBy('form_id')
            ->get()
            ->map(function ($row) {
                $fields = @unserialize((string) $row->form_value);

                return (object) [
                    'id'        => $row->form_id,
                    'form_date' => $row->form_date,
                    'fields'    => is_array($fields) ? $fields : null,
                ];
            })
            ->filter(fn ($row) => $row->fields !== null)
            ->values();
    }

    /**
     * One submission per email address — the most recent, keyed lowercase.
     *
     * Eight partner confirmations exist for six vendors, so somebody filled
     * the form in twice. The later one is the one they meant.
     */
    private function submissionsByEmail(int $formId, string $emailField): \Illuminate\Support\Collection
    {
        return $this->submissions($formId)
            ->filter(fn ($row) => filled($this->str($row->fields[$emailField] ?? null)))
            ->keyBy(fn ($row) => $this->mapEmail($this->str($row->fields[$emailField])))
            ->map(fn ($row) => $row->fields);
    }

    /**
     * Redirect a form address to the account it belongs to.
     *
     * These forms join to accounts on a typed email and nothing else, so a
     * notary who applied from a chambers or Bar address and registered from a
     * personal one leaves a complete set of sealing assets attached to nobody.
     * The correction is recorded here, as an argument, rather than by editing
     * the WordPress row or temporarily changing somebody's login address —
     * both of which work once and leave no trace of why.
     */
    private function mapEmail(?string $email): string
    {
        $email = strtolower(trim((string) $email));

        foreach ((array) $this->option('map') as $pair) {
            [$from, $to] = array_pad(explode('=', (string) $pair, 2), 2, null);

            if ($to !== null && strtolower(trim($from)) === $email) {
                $this->mapsUsed[strtolower(trim($from))] = true;

                return strtolower(trim($to));
            }
        }

        return $email;
    }

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    */

    /**
     * Build a basename => path index of the whole upload tree, once.
     *
     * CFDB7 stores only a filename in the database, and where it puts the file
     * on disk has changed between versions — sometimes flat in cfdb7_uploads,
     * sometimes in a per-submission subdirectory. Indexing the tree once means
     * the importer does not have to know which, and turns 500-odd filesystem
     * searches into one walk.
     */
    private function indexUploads(): void
    {
        $path = config('nvn.wordpress.path');

        if (! $path || ! is_dir($path)) {
            return;
        }

        $root = rtrim($path, '/\\') . '/wp-content/uploads/cfdb7_uploads';

        if (! is_dir($root)) {
            $this->warn("No cfdb7_uploads directory under {$root} — files will be skipped.");

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                // First one wins. A duplicate basename in two submission
                // folders is possible ("passport.pdf"), and picking either is
                // wrong — but the alternative, matching on nothing, loses both.
                $this->fileIndex[strtolower($file->getFilename())] ??= $file->getPathname();
            }
        }

        $this->bump('files.indexed', count($this->fileIndex));
    }

    /** A CFDB7 file field can hold one name, several, or an array of them. */
    private function filenames(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $list = is_array($value) ? $value : preg_split('/\s*[,\n]\s*/', (string) $value);

        // Scalars only. A multi-file field can nest one level deeper, and a
        // stray array here would abort the run with "Array to string
        // conversion" — over one attachment, out of hundreds.
        return array_values(array_filter(array_map(
            fn ($v) => trim(basename(str_replace('\\', '/', (string) $v))),
            array_filter($list, 'is_scalar')
        )));
    }

    private function firstFilename(mixed $value): ?string
    {
        return $this->filenames($value)[0] ?? null;
    }

    /**
     * Copy one WordPress upload onto the private disk.
     *
     * Returns the disk-relative path, or null when the file is not on disk —
     * which happens: a CFDB7 row outlives the file it names whenever somebody
     * has cleaned out uploads. The caller counts those rather than failing,
     * because one missing scan of an ID from last year should not stop 420
     * accounts from moving.
     */
    private function storeFile(mixed $value, string $folder): ?string
    {
        $filename = $this->firstFilename($value);

        if ($filename === null) {
            return null;
        }

        $source = $this->fileIndex[strtolower($filename)] ?? null;

        if ($source === null || ! is_readable($source)) {
            $this->bump('files.missing');

            return null;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
        $target    = $folder . '/' . Str::random(40) . '.' . strtolower($extension);

        if ($this->dry) {
            $this->bump('files.would_copy');

            return $target;
        }

        Storage::disk('private')->put($target, file_get_contents($source));
        $this->bump('files.copied');

        return $target;
    }

    /*
    |--------------------------------------------------------------------------
    | Small helpers
    |--------------------------------------------------------------------------
    */

    private function wp(): \Illuminate\Database\Connection
    {
        return DB::connection('wordpress');
    }

    private function t(string $table): string
    {
        return config('nvn.wordpress.prefix') . $table;
    }

    private function str(mixed $value, ?int $limit = null): ?string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_scalar'));
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $limit ? Str::limit($value, $limit, '') : $value;
    }

    private function truthy(mixed $value): bool
    {
        // A CFDB7 checkbox arrives as an array — usually ['Yes'], sometimes the
        // label the client saw, sometimes empty for "unticked".
        return in_array(strtolower((string) $this->str($value)), ['1', 'yes', 'y', 'true', 'on'], true);
    }

    private function bump(string $key, int $by = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $by;
    }

    private function report(): void
    {
        ksort($this->stats);

        $this->newLine();
        $this->table(
            ['', 'count'],
            array_map(fn ($k, $v) => [$k, number_format($v)], array_keys($this->stats), $this->stats)
        );

        // A --map that matched nothing is almost always a typo, and its symptom
        // is the thing it was meant to fix staying broken. Say so.
        foreach ((array) $this->option('map') as $pair) {
            $from = strtolower(trim(explode('=', (string) $pair, 2)[0]));

            if (! isset($this->mapsUsed[$from])) {
                $this->newLine();
                $this->warn("--map {$from} matched no submission. Check the spelling.");
            }
        }

        if (($this->stats['notaries.cannot_seal'] ?? 0) > 0 && ! $this->option('stamp-as-seal')) {
            $this->newLine();
            $this->warn(sprintf(
                '%d notary profile(s) cannot seal a document yet.',
                $this->stats['notaries.cannot_seal']
            ));
            $this->line('  This application wants three marks — signature, stamp and seal. The old');
            $this->line('  partner form collected a signature, a stamp and a logo, so no seal came');
            $this->line('  across. Either those notaries upload one, or, if their stamp IS their');
            $this->line('  seal, re-run with --stamp-as-seal. That is a notarial question, not a');
            $this->line('  technical one, which is why this does not decide it for you.');
        }

        if (($this->stats['posts.embeds_removed'] ?? 0) > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d embed(s) — YouTube, maps, and the like — were stripped from articles.',
                $this->stats['posts.embeds_removed']
            ));
            $this->line('  An <iframe> renders whatever the other site decides to serve, on a page');
            $this->line('  your visitors trust, so the sanitiser removes them. The surrounding text');
            $this->line('  is intact; those articles need the video re-added by hand, or a link.');
        }

        if (($this->stats['files.missing'] ?? 0) > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d file(s) named in the database were not on disk and were skipped.',
                $this->stats['files.missing']
            ));
        }

        if ($this->dry) {
            $this->newLine();
            $this->info('Rolled back — nothing was written. Re-run without --dry-run to keep it.');
        }
    }
}
