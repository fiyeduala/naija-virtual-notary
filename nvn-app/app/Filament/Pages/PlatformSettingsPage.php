<?php

namespace App\Filament\Pages;

use App\Enums\Specialty;
use App\Models\NotaryProfile;
use App\Models\NotaryService;
use App\Models\PlatformSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class PlatformSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Platform settings';
    protected static ?string $navigationGroup = 'Content & settings';
    protected static ?string $title           = 'Platform settings';
    protected static ?int    $navigationSort  = 99;
    protected static string  $view            = 'filament.pages.platform-settings-page';

    public ?array $data = [];

    public function mount(): void
    {
        $systemProfile = NotaryProfile::where('is_system_native', true)->with('services')->first();

        $this->form->fill([
            'onboarding_fee_ngn'      => PlatformSetting::get('onboarding_fee_ngn', config('nvn.onboarding_fee_ngn')) / 100,
            'default_commission_rate' => PlatformSetting::get('default_commission_rate', config('nvn.default_commission_rate')),
            'fallback_minutes'        => PlatformSetting::get('fallback_minutes', config('nvn.fallback_minutes')),
            'paystack_transfers'      => \App\Support\Settings::paystackTransfersEnabled(),
            // FileUpload wants the stored path, not a URL.
            'site_logo'               => \App\Support\Branding::path('site_logo'),
            'site_icon'               => \App\Support\Branding::path('site_icon'),
            'email_rate_per_minute'   => \App\Support\Settings::int('email_rate_per_minute', 30),
            'offsite_fee_ngn'         => \App\Support\Settings::offsiteFeeMinor() / 100,
            'asset_guide_url'         => \App\Support\Settings::string('asset_guide_url', ''),
            'tawk_property_id'        => \App\Support\Settings::string('tawk_property_id', (string) config('nvn.tawk.property_id', '')),
            'tawk_widget_id'          => \App\Support\Settings::string('tawk_widget_id', (string) config('nvn.tawk.widget_id', 'default')),
            'system_services'         => $systemProfile
                ? $systemProfile->services->map(fn ($svc) => [
                    'id'                         => $svc->id,
                    'service_type'               => $svc->service_type,
                    'price_ngn'                  => $svc->price_ngn / 100,
                    'price_usd'                  => $svc->price_usd / 100,
                    'estimated_duration_minutes' => $svc->estimated_duration_minutes,
                    'active'                     => $svc->active,
                ])->toArray()
                : [],
        ]);
    }

    public function form(Form $form): Form
    {
        $specialtyOptions = collect(Specialty::cases())
            ->mapWithKeys(fn ($s) => [$s->label() => $s->label()])
            ->toArray();

        return $form
            ->schema([

                // ── Onboarding fee ────────────────────────────────────────
                Forms\Components\Section::make('Onboarding fee')
                    ->description('The one-time fee notary partners pay to activate their application.')
                    ->schema([
                        Forms\Components\TextInput::make('onboarding_fee_ngn')
                            ->label('Onboarding fee (₦ NGN)')
                            ->helperText('Enter in naira — e.g. 30000 for ₦30,000')
                            ->numeric()
                            ->prefix('₦')
                            ->minValue(0)
                            ->required(),
                    ]),

                // ── Offsite notarization ─────────────────────────────────
                Forms\Components\Section::make('Offsite notarization')
                    ->description('What a notary pays to seal a document they took on themselves — a job '
                        . 'they got in their own office or at a client\'s premises, brought here only to '
                        . 'be stamped and sealed digitally. The platform takes the fee, hands back the '
                        . 'sealed PDF, and does nothing else: no client account, no appointment, no '
                        . 'commission, and no payout, because this money is paid TO you, not through you.')
                    ->schema([
                        Forms\Components\TextInput::make('offsite_fee_ngn')
                            ->label('Fee per document (₦ NGN)')
                            ->helperText('Charged for each document on the job — three documents is three '
                                . 'times this. A notary is shown the total before paying, and the price '
                                . 'is frozen onto the job when they start it, so changing this never '
                                . 'moves the total of one already under way. Set 0 to make offsite '
                                . 'sealing free; notaries then go straight from upload to the editor.')
                            ->numeric()
                            ->prefix('₦')
                            ->minValue(0)
                            ->required(),
                    ]),

                // ── Partner asset guide ──────────────────────────────────
                Forms\Components\Section::make('Guide: getting an e-signature, stamp and seal')
                    ->description('Most partners stall at the upload step because they do not yet have the '
                        . 'files, not because the form is hard. A link here puts the instructions beside '
                        . 'the form that needs them. It opens in a new tab, so nobody loses a half-filled '
                        . 'application by going to read it. Leave it blank and no button is shown.')
                    ->schema([
                        // The guide is normally an article we published
                        // ourselves, so offer the list rather than making
                        // someone go and copy a URL out of another tab. This
                        // field only fills in the one below; it is not stored.
                        Forms\Components\Select::make('asset_guide_post')
                            ->label('Pick a published article')
                            ->placeholder('Choose one of your blog posts…')
                            ->options(fn () => \App\Models\Post::published()
                                ->orderByDesc('published_at')
                                ->pluck('title', 'slug')
                                ->toArray())
                            ->searchable()
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                                if ($state) {
                                    $set('asset_guide_url', route('blog.show', $state));
                                }
                            }),

                        Forms\Components\TextInput::make('asset_guide_url')
                            ->label('Guide link')
                            ->helperText('Or paste any address — it does not have to be one of our own '
                                . 'articles. Only http:// and https:// links are used; anything else is '
                                . 'ignored and the button stays hidden.')
                            ->url()
                            ->live(onBlur: true)
                            ->maxLength(500),

                        Forms\Components\Placeholder::make('asset_guide_status')
                            ->label('Status')
                            ->columnSpanFull()
                            ->content(function (Forms\Get $get) {
                                $url = trim((string) $get('asset_guide_url'));

                                return $url === ''
                                    ? 'Off — partners see no guide button on the upload form.'
                                    : 'Partners see a "How do I get these?" button linking to ' . $url;
                            }),
                    ])->columns(2),


                // ── Commission & fallback ────────────────────────────────
                Forms\Components\Section::make('Commission & fallback')
                    ->description('Default revenue share and request-handling timeouts.')
                    ->schema([
                        Forms\Components\TextInput::make('default_commission_rate')
                            ->label('Default commission rate (%)')
                            ->helperText('Percentage retained by the platform. Applied to new notary profiles; can be overridden per-notary.')
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                        Forms\Components\TextInput::make('fallback_minutes')
                            ->label('Response window (minutes)')
                            ->helperText('How long a notary is expected to take before the request is flagged as overdue on the admin desk. Nothing is reassigned automatically — the platform can notarize on a partner\'s behalf at any point.')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ])->columns(2),

                // ── Paying notaries ──────────────────────────────────────
                Forms\Components\Section::make('Paying notaries')
                    ->description('How money reaches your notary partners. What each of them is owed is '
                        . 'tracked either way — this only decides whether the platform moves it for you.')
                    ->schema([
                        Forms\Components\Toggle::make('paystack_transfers')
                            ->label('Send payouts automatically through Paystack')
                            ->helperText('Off: you settle each payout yourself and record how it was paid — '
                                . 'the safe setting while testing. On: the Send button debits your Paystack '
                                . 'balance and the money cannot be recalled. Requires Transfers to be enabled '
                                . 'on your Paystack account, and a funded balance — a settlement schedule that '
                                . 'sweeps everything to your bank each day will leave nothing to pay out with.'),
                    ]),

                // ── Site branding ────────────────────────────────────────
                Forms\Components\Section::make('Site logo & icon')
                    ->description('Used across the public site, sign-in, the client and notary dashboards, '
                        . 'and this admin panel. Leave either blank and the site falls back to its name in '
                        . 'text — nothing breaks.')
                    ->schema([
                        Forms\Components\FileUpload::make('site_logo')
                            ->label('Logo')
                            ->helperText('Shown in the navigation bar. A wide, transparent PNG or SVG works '
                                . 'best — it is displayed about 40px tall, so anything taller is scaled down.')
                            ->disk(\App\Support\Branding::DISK)
                            ->directory('')
                            ->visibility('public')
                            ->image()
                            ->imagePreviewHeight('60')
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'])
                            ->maxSize(2048)
                            // A predictable name so it can also be dropped in by
                            // FTP during a host move, without a database row.
                            ->getUploadedFileNameForStorageUsing(
                                fn ($file) => 'logo.' . $file->getClientOriginalExtension()),

                        Forms\Components\FileUpload::make('site_icon')
                            ->label('Icon (favicon & app icon)')
                            ->helperText('The square mark in the browser tab, and the icon shown when the '
                                . 'site is installed to a phone Home Screen or a desktop. A square PNG of '
                                . '512×512 is ideal — iOS cannot use an SVG for the Home Screen, so an SVG '
                                . 'here gets the built-in shield there instead. Leave blank and the shield '
                                . 'is used everywhere.')
                            ->disk(\App\Support\Branding::DISK)
                            ->directory('')
                            ->visibility('public')
                            ->image()
                            ->imagePreviewHeight('60')
                            ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/svg+xml', 'image/webp'])
                            ->maxSize(1024)
                            ->getUploadedFileNameForStorageUsing(
                                fn ($file) => 'icon.' . $file->getClientOriginalExtension()),
                    ])->columns(2),

                // ── Bulk email pacing ────────────────────────────────────
                Forms\Components\Section::make('Sending email')
                    ->description('How quickly announcements composed under Email are released to the mail '
                        . 'server. Nothing is lost by going slowly — the send simply takes longer.')
                    ->schema([
                        Forms\Components\TextInput::make('email_rate_per_minute')
                            ->label('Emails per minute')
                            ->helperText('Shared cPanel hosts commonly cap outgoing mail at a few hundred an '
                                . 'hour and start rejecting once you pass it. 30 a minute is a safe default. '
                                . 'Set 0 to send as fast as the queue worker can, which is right for a '
                                . 'dedicated sending service and wrong for shared hosting.')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(600)
                            ->required(),
                    ]),

                // ── Live chat ────────────────────────────────────────────
                Forms\Components\Section::make('Live chat (Tawk.to)')
                    ->description('A support chat button on every visitor-facing page — the public site, '
                        . 'sign-in, and the client and notary dashboards. It is never shown in this admin '
                        . 'panel, and it stays off the notarization session screen so it cannot sit on top '
                        . 'of a document. Leave the property ID blank to switch it off entirely: nothing is '
                        . 'loaded and no request is made to Tawk.')
                    ->schema([
                        Forms\Components\TextInput::make('tawk_property_id')
                            ->label('Property ID')
                            // The realistic action is copying the whole snippet out
                            // of Tawk's dashboard, so accept that and pull the two
                            // IDs out rather than making someone dissect a URL.
                            ->helperText('Paste your whole Tawk embed code — or just the widget URL — here and '
                                . 'both IDs will be filled in for you.')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                                if ($state && preg_match('#embed\.tawk\.to/([0-9a-f]+)/([0-9a-zA-Z]+)#i', $state, $m)) {
                                    $set('tawk_property_id', $m[1]);
                                    $set('tawk_widget_id', $m[2]);
                                }
                            })
                            ->maxLength(2000),

                        Forms\Components\TextInput::make('tawk_widget_id')
                            ->label('Widget ID')
                            ->helperText('Usually "default" unless you created more than one widget.')
                            ->maxLength(100),

                        Forms\Components\Placeholder::make('tawk_status')
                            ->label('Status')
                            ->columnSpanFull()
                            ->content(function (Forms\Get $get) {
                                $property = trim((string) $get('tawk_property_id'));
                                $widget   = trim((string) $get('tawk_widget_id')) ?: 'default';

                                return $property === ''
                                    ? 'Off — no chat widget is loaded anywhere.'
                                    : 'Loading from https://embed.tawk.to/' . $property . '/' . $widget;
                            }),
                    ])->columns(2),

                // ── System (admin) notarization pricing ──────────────────
                Forms\Components\Section::make('Admin / system notarization pricing')
                    ->description('These are the services and prices offered by the system notary — the fallback that handles requests when no partner notary responds in time. Clients see these prices in the marketplace.')
                    ->schema([
                        Forms\Components\Placeholder::make('no_profile_notice')
                            ->label('')
                            ->content('⚠ No system notary profile found. Seed the database to create one.')
                            ->visible(fn () => ! NotaryProfile::where('is_system_native', true)->exists()),

                        Forms\Components\Repeater::make('system_services')
                            ->label('Services')
                            ->visible(fn () => NotaryProfile::where('is_system_native', true)->exists())
                            ->schema([
                                Forms\Components\Hidden::make('id'),

                                Forms\Components\Select::make('service_type')
                                    ->label('Service type')
                                    ->options($specialtyOptions)
                                    ->searchable()
                                    ->required()
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('price_ngn')
                                    ->label('Price NGN (₦)')
                                    ->helperText('Enter in naira — e.g. 15000 for ₦15,000')
                                    ->numeric()
                                    ->prefix('₦')
                                    ->minValue(0)
                                    ->required(),

                                Forms\Components\TextInput::make('price_usd')
                                    ->label('Price USD ($)')
                                    ->helperText('Enter in dollars — e.g. 25 for $25.00')
                                    ->numeric()
                                    ->prefix('$')
                                    ->minValue(0)
                                    ->required(),

                                Forms\Components\TextInput::make('estimated_duration_minutes')
                                    ->label('Duration (minutes)')
                                    ->numeric()
                                    ->minValue(5)
                                    ->default(30),

                                Forms\Components\Toggle::make('active')
                                    ->label('Visible in marketplace')
                                    ->default(true),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add service')
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['service_type'] ?? null),
                    ]),

            ])
            ->statePath('data');
    }

    /** FileUpload state is an array keyed by a temporary id; we want the path. */
    private static function uploadedPath(mixed $state): string
    {
        if (is_array($state)) {
            $state = reset($state) ?: null;
        }

        return is_string($state) ? $state : '';
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // ── Platform settings ────────────────────────────────────────────
        PlatformSetting::set('onboarding_fee_ngn',      (int) round((float) $data['onboarding_fee_ngn'] * 100), 'integer');
        PlatformSetting::set('default_commission_rate', (int) $data['default_commission_rate'],                  'integer');
        PlatformSetting::set('fallback_minutes',        (int) $data['fallback_minutes'],                         'integer');
        PlatformSetting::set('paystack_transfers',      (bool) ($data['paystack_transfers'] ?? false) ? 1 : 0,   'boolean');
        PlatformSetting::set('email_rate_per_minute',   max(0, (int) ($data['email_rate_per_minute'] ?? 30)),    'integer');

        // FileUpload hands back an array (or null once cleared); only the path
        // is stored, and clearing the field stores '' so the site falls back to
        // its text name rather than to whatever was uploaded before.
        PlatformSetting::set('site_logo', static::uploadedPath($data['site_logo'] ?? null), 'string');
        PlatformSetting::set('site_icon', static::uploadedPath($data['site_icon'] ?? null), 'string');

        PlatformSetting::set('offsite_fee_ngn', max(0, (int) round((float) ($data['offsite_fee_ngn'] ?? 0) * 100)), 'integer');

        // Stored as typed; Settings::assetGuideUrl() is what decides whether it
        // is safe to put in an href, so a mistake here shows as a missing
        // button rather than as a link that does something unexpected.
        PlatformSetting::set('asset_guide_url', trim((string) ($data['asset_guide_url'] ?? '')), 'string');

        // Stored bare even if a full <script> block was pasted and the extractor
        // did not recognise it: a property ID that is not an ID simply produces
        // no widget, which is the same as off.
        PlatformSetting::set('tawk_property_id', trim((string) ($data['tawk_property_id'] ?? '')), 'string');
        PlatformSetting::set('tawk_widget_id',   trim((string) ($data['tawk_widget_id'] ?? '')) ?: 'default', 'string');

        // Settings memoises its lookups per request; without this the page would
        // re-render showing the value it had before the save.
        \App\Support\Settings::flush();

        // ── System notary service pricing ────────────────────────────────
        $systemProfile = NotaryProfile::where('is_system_native', true)->first();

        if ($systemProfile && isset($data['system_services'])) {
            $submittedIds = collect($data['system_services'])
                ->pluck('id')
                ->filter()
                ->values();

            // Delete services removed from the list
            $systemProfile->services()
                ->whereNotIn('id', $submittedIds)
                ->delete();

            foreach ($data['system_services'] as $svcData) {
                $attributes = [
                    'service_type'               => $svcData['service_type'],
                    'price_ngn'                  => (int) round((float) $svcData['price_ngn'] * 100),
                    'price_usd'                  => (int) round((float) $svcData['price_usd'] * 100),
                    'estimated_duration_minutes' => (int) ($svcData['estimated_duration_minutes'] ?? 30),
                    'active'                     => (bool) ($svcData['active'] ?? true),
                ];

                if (! empty($svcData['id'])) {
                    NotaryService::find($svcData['id'])?->update($attributes);
                } else {
                    $systemProfile->services()->create($attributes);
                }
            }
        }

        Notification::make()
            ->title('Platform settings saved')
            ->success()
            ->send();
    }
}
