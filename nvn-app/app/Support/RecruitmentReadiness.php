<?php

namespace App\Support;

use App\Enums\RecruitmentRole;
use App\Enums\VacancyState;
use App\Models\AssessmentTemplate;
use App\Models\RecruitmentMessage;
use App\Models\Vacancy;
use App\Services\Recruitment\RetentionService;
use Illuminate\Support\Facades\Storage;

/**
 * Is this module actually ready to be used on real people?
 *
 * One list, read by the admin screen and by `nvn:recruitment-readiness`, so
 * the panel and the terminal cannot disagree about whether something is set
 * up. Each check returns one of three answers:
 *
 *   fail — something would go wrong, or somebody would be misled. Do not open
 *          a vacancy to the public with one of these outstanding.
 *   warn — it works, but it is not finished, or it is a local-development
 *          setting that must not reach the server.
 *   ok   — verified, as far as code can verify it.
 *
 * What this cannot check is said out loud rather than guessed at. Whether cron
 * is running is inferred from whether queued email has gone stale, which is
 * evidence rather than proof; whether a privacy notice is lawful is not a
 * question software can answer at all, so the check reports only whether
 * anybody has looked at it.
 */
class RecruitmentReadiness
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, fix: ?string}>
     */
    public static function checks(): array
    {
        return array_values(array_filter([
            static::featureSwitch(),
            static::mailTransport(),
            static::replyTo(),
            static::queue(),
            static::cron(),
            static::failedMail(),
            static::privateDisk(),
            static::scanner(),
            static::privacyNotice(),
            static::retention(),
            static::assessments(),
            static::vacancies(),
            static::reviewers(),
        ]));
    }

    /** True while any check would stop this being shown to the public. */
    public static function blocked(): bool
    {
        foreach (static::checks() as $check) {
            if ($check['status'] === self::FAIL) {
                return true;
            }
        }

        return false;
    }

    /** @return array{ok: int, warn: int, fail: int} */
    public static function tally(): array
    {
        $tally = [self::OK => 0, self::WARN => 0, self::FAIL => 0];

        foreach (static::checks() as $check) {
            $tally[$check['status']]++;
        }

        return ['ok' => $tally[self::OK], 'warn' => $tally[self::WARN], 'fail' => $tally[self::FAIL]];
    }

    /** @return array{key: string, label: string, status: string, detail: string, fix: ?string} */
    private static function check(string $key, string $label, string $status, string $detail, ?string $fix = null): array
    {
        return compact('key', 'label', 'status', 'detail', 'fix');
    }

    /* ---- the checks ------------------------------------------------------ */

    private static function featureSwitch(): array
    {
        // Asked of the same place the module asks: a platform setting beats
        // the env default, and a readiness page that reads only the file can
        // report the feature off while every careers route is answering.
        $on = RecruitmentPermission::moduleEnabled();

        return static::check(
            'feature',
            'The recruitment feature switch',
            $on ? self::OK : self::WARN,
            $on
                ? 'On. The careers pages answer and the Recruitment group is in the panel.'
                : 'Off. Every careers route returns 404 and nobody outside this panel can see anything. '
                    . 'That is the correct setting until you are ready.',
            $on ? null : 'Set NVN_RECRUITMENT_ENABLED=true in .env, then php artisan config:clear.'
                . ' A recruitment_enabled platform setting, if one is stored, overrides the file.',
        );
    }

    private static function mailTransport(): array
    {
        $driver = (string) config('mail.default');

        if (in_array($driver, ['log', 'array', 'null'], true)) {
            return static::check(
                'mail',
                'Mail transport',
                self::WARN,
                'Set to "' . $driver . '", so nothing is delivered — every email is written to the log '
                    . 'instead. Right for development and wrong for a live vacancy: a candidate would '
                    . 'never receive their sign-in link.',
                'Set MAIL_MAILER=smtp and the rest of the mail block before opening a vacancy.',
            );
        }

        return static::check('mail', 'Mail transport', self::OK, 'Set to "' . $driver . '". Email leaves the server.');
    }

    private static function replyTo(): array
    {
        $address = RecruitmentTemplates::replyTo();

        return static::check(
            'reply_to',
            'Where candidate replies go',
            $address ? self::OK : self::WARN,
            $address
                ? $address
                : 'Unset, so replies go to the platform\'s own from-address. Nothing is broken, but '
                    . 'several emails invite a candidate to reply and somebody should be reading them.',
            $address ? null : 'Recruitment settings → Where replies go.',
        );
    }

    private static function queue(): array
    {
        $driver = (string) config('queue.default');

        if ($driver === 'sync') {
            return static::check(
                'queue',
                'Queue driver',
                self::FAIL,
                'Set to "sync", which sends email inside the web request. A slow mail server would then '
                    . 'hold up an application form, and a failure would lose the message with no record '
                    . 'of it. The outbox is built on a real queue.',
                'Set QUEUE_CONNECTION=database.',
            );
        }

        return static::check('queue', 'Queue driver', self::OK, 'Set to "' . $driver . '".');
    }

    /**
     * Is the cron actually running?
     *
     * There is no way to ask a shared host that directly, so this is inferred
     * from evidence: a message that has been due to go out for more than
     * fifteen minutes and has not been attempted means nothing is draining the
     * queue. An empty outbox proves nothing either way and says so.
     */
    private static function cron(): array
    {
        $stale = RecruitmentMessage::query()
            ->where('status', RecruitmentMessage::PENDING)
            ->where('available_at', '<', now()->subMinutes(15))
            ->count();

        if ($stale > 0) {
            return static::check(
                'cron',
                'The scheduler',
                self::FAIL,
                $stale . ' ' . str('email')->plural($stale) . ' ' . ($stale === 1 ? 'has' : 'have')
                    . ' been due to go out for over fifteen minutes and ' . ($stale === 1 ? 'has' : 'have')
                    . ' not been attempted. Nothing is draining the queue.',
                'Check the cron entry runs `php artisan schedule:run` every minute, and that '
                    . '`php artisan nvn:recruitment-dispatch` succeeds when run by hand.',
            );
        }

        $pending = RecruitmentMessage::query()->where('status', RecruitmentMessage::PENDING)->count();
        $sent    = RecruitmentMessage::query()->where('status', RecruitmentMessage::SENT)->count();

        if ($sent === 0 && $pending === 0) {
            return static::check(
                'cron',
                'The scheduler',
                self::WARN,
                'Nothing has ever been queued, so there is no evidence either way. This check only '
                    . 'becomes meaningful once an email has been sent.',
                'Verify by hand: php artisan nvn:recruitment-dispatch',
            );
        }

        return static::check(
            'cron',
            'The scheduler',
            self::OK,
            'Nothing is overdue in the outbox' . ($sent > 0 ? ', and ' . $sent . ' ' . str('email')->plural($sent) . ' ' . ($sent === 1 ? 'has' : 'have') . ' gone out.' : '.'),
        );
    }

    private static function failedMail(): array
    {
        $failed = RecruitmentMessage::query()->where('status', RecruitmentMessage::FAILED)->count();

        return static::check(
            'failed_mail',
            'Failed emails',
            $failed > 0 ? self::WARN : self::OK,
            $failed > 0
                ? $failed . ' ' . str('message')->plural($failed) . ' could not be delivered. Somebody was '
                    . 'not told something they were waiting for.'
                : 'None.',
            $failed > 0 ? 'Open each application and use "Try again" on the failed email, after fixing the cause.' : null,
        );
    }

    /**
     * Can we actually store a CV, and is the store private?
     *
     * Two separate questions. A disk that cannot be written to loses an
     * application; a disk that is reachable from the web publishes one.
     */
    private static function privateDisk(): array
    {
        $root = (string) config('filesystems.disks.private.root');

        try {
            $probe = 'recruitment-readiness/' . bin2hex(random_bytes(8)) . '.txt';
            Storage::disk('private')->put($probe, 'probe');
            $readable = Storage::disk('private')->get($probe) === 'probe';
            Storage::disk('private')->delete($probe);
        } catch (\Throwable $exception) {
            return static::check(
                'storage',
                'Private document storage',
                self::FAIL,
                'Could not write to the private disk: ' . $exception->getMessage(),
                'Check the permissions on ' . $root . '.',
            );
        }

        if (! $readable) {
            return static::check('storage', 'Private document storage', self::FAIL,
                'A file written to the private disk could not be read back.');
        }

        // The one arrangement that would publish every CV on the internet: a
        // private root sitting inside the document root, or symlinked from it.
        $public = realpath(public_path()) ?: public_path();
        $inside = realpath($root) && str_starts_with((string) realpath($root), $public);

        if ($inside) {
            return static::check(
                'storage',
                'Private document storage',
                self::FAIL,
                'The private disk is inside the public document root (' . $root . '), so a CV could be '
                    . 'fetched by anybody who guessed its filename. Authorization in this module assumes '
                    . 'the files are not web-reachable.',
                'Move the private disk outside public/ and clear any symlink pointing into it.',
            );
        }

        return static::check('storage', 'Private document storage', self::OK,
            'Writable and outside the document root (' . $root . ').');
    }

    private static function scanner(): array
    {
        $on = (bool) config('recruitment.scanner.enabled');

        return static::check(
            'scanner',
            'Malware scanning',
            $on ? self::OK : self::WARN,
            $on
                ? 'On (' . config('recruitment.scanner.command') . '). A scan that fails quarantines the '
                    . 'file and refuses the upload rather than accepting it unchecked.'
                : 'Off, which most shared hosts require. Stored files are recorded as "not scanned" and '
                    . 'are never labelled clean — the panel says so next to each download. Nobody should '
                    . 'open a candidate attachment on a machine they care about.',
            $on ? null : 'Optional: NVN_RECRUITMENT_SCANNER=true with a working clamscan on the host.',
        );
    }

    private static function privacyNotice(): array
    {
        $isDefault = RecruitmentPrivacyNotice::isDefault();
        $noContact = RecruitmentPrivacyNotice::contactMissing();

        if ($isDefault) {
            return static::check(
                'privacy',
                'The privacy notice',
                self::FAIL,
                'Still the shipped draft. It describes what this software does, which is the part that '
                    . 'can be verified in code — it does not and cannot assert that it satisfies the NDPA '
                    . 'or any other law. Somebody qualified has to read it before a stranger ticks it.',
                'Recruitment settings → The privacy notice. Edit it, or save it back unchanged after '
                    . 'review, and bump the version.',
            );
        }

        if ($noContact) {
            return static::check(
                'privacy',
                'The privacy notice',
                self::WARN,
                'Reviewed, but there is no address for a candidate to write to about their own data — '
                    . 'the one subject on which they are entitled to an answer.',
                'Recruitment settings → Where candidates write about their own data.',
            );
        }

        return static::check('privacy', 'The privacy notice', self::OK,
            'Reviewed and at version ' . RecruitmentPrivacyNotice::version()
            . ', with ' . RecruitmentPrivacyNotice::contact() . ' for data requests.');
    }

    private static function retention(): array
    {
        $retention = app(RetentionService::class);
        $months    = $retention->months();

        if ($months === null) {
            return static::check(
                'retention',
                'How long records are kept',
                self::WARN,
                'Not set, so nothing is ever deleted automatically. This is deliberate: a retention '
                    . 'period has legal weight and this module will not choose one for you. Records '
                    . 'accumulate until somebody removes them by hand.',
                'Recruitment settings → How long records are kept, once you have an approved policy.',
            );
        }

        return static::check(
            'retention',
            'How long records are kept',
            self::OK,
            $months . ' months' . ($retention->automaticEnabled()
                ? ', swept nightly. Backups taken before a purge keep the data until they age out on '
                    . 'your host\'s own cycle.'
                : ', but the automatic purge is off — removal is by hand.'),
        );
    }

    private static function assessments(): array
    {
        // Mapped back to the backing values on purpose. Eloquent's pluck()
        // applies the model's casts, so this column arrives as a
        // RecruitmentRole and contains() against a string matched nothing —
        // which reported all four exercises missing while all four existed.
        $covered = AssessmentTemplate::query()
            ->active()
            ->pluck('role_key')
            ->map(fn ($role) => $role instanceof RecruitmentRole ? $role->value : (string) $role)
            ->unique();

        $missing = collect(RecruitmentRole::cases())
            ->reject(fn (RecruitmentRole $role) => $covered->contains($role->value))
            ->map(fn (RecruitmentRole $role) => $role->label());

        if ($missing->isEmpty()) {
            return static::check('assessments', 'Assessment exercises', self::OK,
                'All four role categories have a live exercise.');
        }

        return static::check(
            'assessments',
            'Assessment exercises',
            self::WARN,
            'No live exercise for: ' . $missing->implode('; ') . '. A vacancy in one of those can be '
                . 'published and applied for, but nobody can be invited to an assessment.',
            'Recruitment → Assessments.',
        );
    }

    private static function vacancies(): array
    {
        $published = Vacancy::query()->where('state', VacancyState::Published->value)->count();
        $drafts    = Vacancy::query()->where('state', VacancyState::Draft->value)->get();

        $blocked = $drafts
            ->filter(fn (Vacancy $vacancy) => $vacancy->publicationBlockers() !== [])
            ->map(fn (Vacancy $vacancy) => $vacancy->title . ' (' . count($vacancy->publicationBlockers()) . ' outstanding)');

        if ($published === 0 && $drafts->isEmpty()) {
            return static::check('vacancies', 'Vacancies', self::WARN, 'None at all — not even a draft.',
                'Recruitment → Vacancies, or run the seeder: php artisan db:seed --class=RecruitmentSeeder');
        }

        if ($published === 0) {
            return static::check(
                'vacancies',
                'Vacancies',
                self::WARN,
                $drafts->count() . ' ' . str('draft')->plural($drafts->count()) . ', nothing published. '
                    . ($blocked->isEmpty()
                        ? 'All of them are ready to publish when you are.'
                        : 'Not ready: ' . $blocked->implode('; ') . '.'),
                'Open each draft; the publication panel lists exactly what is missing.',
            );
        }

        return static::check(
            'vacancies',
            'Vacancies',
            self::OK,
            $published . ' published' . ($drafts->isEmpty() ? '.' : ', ' . $drafts->count() . ' still in draft.'),
        );
    }

    private static function reviewers(): array
    {
        $unstaffed = Vacancy::query()
            ->where('state', VacancyState::Published->value)
            ->whereDoesntHave('reviewers')
            ->pluck('title');

        if ($unstaffed->isEmpty()) {
            return static::check('reviewers', 'Who reads the applications', self::OK,
                'Every published vacancy has at least one named reviewer.');
        }

        return static::check(
            'reviewers',
            'Who reads the applications',
            self::FAIL,
            'No reviewer is assigned to: ' . $unstaffed->implode('; ') . '. Applications to those '
                . 'vacancies are visible only to somebody holding "review every application", so the '
                . 'people meant to read them cannot.',
            'Open the vacancy → Who reviews this.',
        );
    }
}
