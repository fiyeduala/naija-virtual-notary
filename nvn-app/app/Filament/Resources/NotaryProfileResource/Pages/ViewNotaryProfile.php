<?php

namespace App\Filament\Resources\NotaryProfileResource\Pages;

use App\Filament\Resources\NotaryProfileResource;
use App\Models\NotaryAsset;
use App\Models\NotaryService;
use App\Services\BankAccountService;
use App\Support\AuditLogger;
use App\Support\Banks;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ViewNotaryProfile extends ViewRecord
{
    protected static string $resource = NotaryProfileResource::class;

    /** The image assets stamped onto a sealed PDF, in the order they are shown. */
    private const IMAGE_ASSETS = ['signature', 'stamp', 'seal'];

    protected function getHeaderActions(): array
    {
        return [
            $this->editDetailsAction(),
            $this->manageAssetsAction(),
            $this->payoutAccountAction(),

            Actions\Action::make('edit_commission')
                ->label('Edit commission')
                ->icon('heroicon-o-percent-badge')
                ->color('gray')
                ->fillForm(fn ($record) => ['commission_rate' => $record->commission_rate])
                ->form([
                    Forms\Components\TextInput::make('commission_rate')
                        ->label('Platform commission rate (%)')
                        ->helperText('Percentage of each fee the platform retains. The notary receives the remainder.')
                        ->numeric()
                        ->suffix('%')
                        ->minValue(0)
                        ->maxValue(100)
                        ->required(),
                ])
                ->action(function (array $data, $record) {
                    $record->update(['commission_rate' => (int) $data['commission_rate']]);
                    Notification::make()->title('Commission rate updated')->success()->send();
                }),

            Actions\Action::make('edit_service_pricing')
                ->label('Edit service pricing')
                ->icon('heroicon-o-currency-dollar')
                ->fillForm(function ($record) {
                    return [
                        'services' => $record->services->map(fn ($svc) => [
                            'id'                         => $svc->id,
                            'service_type'               => $svc->service_type,
                            'price_ngn'                  => $svc->price_ngn / 100,
                            'price_usd'                  => $svc->price_usd / 100,
                            'estimated_duration_minutes' => $svc->estimated_duration_minutes,
                            'active'                     => $svc->active,
                        ])->toArray(),
                    ];
                })
                ->form([
                    Forms\Components\Repeater::make('services')
                        ->label('Services')
                        ->schema([
                            Forms\Components\Hidden::make('id'),
                            Forms\Components\TextInput::make('service_type')
                                ->label('Service type')
                                ->disabled()
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('price_ngn')
                                ->label('Price NGN (₦ — in naira, not kobo)')
                                ->numeric()
                                ->prefix('₦')
                                ->required(),
                            Forms\Components\TextInput::make('price_usd')
                                ->label('Price USD ($ — in dollars, not cents)')
                                ->numeric()
                                ->prefix('$')
                                ->required(),
                            Forms\Components\TextInput::make('estimated_duration_minutes')
                                ->label('Duration (minutes)')
                                ->numeric(),
                            Forms\Components\Toggle::make('active')
                                ->label('Active / visible in marketplace')
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false),
                ])
                ->modalWidth('4xl')
                ->action(function (array $data) {
                    foreach ($data['services'] as $svcData) {
                        NotaryService::find($svcData['id'])?->update([
                            'price_ngn'                  => (int) round((float) $svcData['price_ngn'] * 100),
                            'price_usd'                  => (int) round((float) $svcData['price_usd'] * 100),
                            'estimated_duration_minutes' => (int) $svcData['estimated_duration_minutes'],
                            'active'                     => (bool) $svcData['active'],
                        ]);
                    }
                    Notification::make()->title('Service pricing updated')->success()->send();
                }),
        ];
    }

    /**
     * The notary's own details — the ones a client sees.
     *
     * Everything else on this page edits something the notary set up. This
     * edits who they are, and the reason it exists is the WordPress import:
     * WordPress falls back to the username when the name fields are empty, so
     * several imported notaries arrived called things like "pebiala". That
     * string appears in the marketplace listing and on the notarial
     * certificate, which makes it the one field here that has to be right
     * before anybody is approved.
     *
     * Email is editable but deliberately awkward — it is the login identity,
     * and changing it changes what the person types to get in.
     */
    private function editDetailsAction(): Actions\Action
    {
        return Actions\Action::make('edit_details')
            ->label('Edit details')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->modalHeading('Notary details')
            ->modalWidth('2xl')
            ->fillForm(fn ($record) => [
                'full_name'         => $record->user?->full_name,
                'email'             => $record->user?->email,
                'phone'             => $record->user?->phone,
                'entity_type'       => $record->entity_type,
                'organization_name' => $record->organization_name,
                'license_ref'       => $record->license_ref,
                'scn'               => $record->scn,
                'year_of_oath'      => $record->year_of_oath,
            ])
            ->form([
                Forms\Components\TextInput::make('full_name')
                    ->label('Full name')
                    ->required()
                    ->maxLength(250)
                    ->helperText('As it should appear in the marketplace and on a notarial certificate.'),

                // Grid, not ->columns(2) on the action: Filament\Actions\Action
                // has no columns() method, and the failure is a 500 the first
                // time somebody opens the modal rather than anything visible
                // when the file is written.
                Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(250)
                    ->helperText('This is what they sign in with. Changing it changes their login.')
                    ->rule(fn ($record) => Rule::unique('users', 'email')->ignore($record->user_id)),

                Forms\Components\TextInput::make('phone')
                    ->label('Phone')
                    ->tel()
                    ->maxLength(50),

                Forms\Components\Select::make('entity_type')
                    ->label('Entity type')
                    ->options(['individual' => 'Individual', 'agency' => 'Agency / firm'])
                    ->required(),

                Forms\Components\TextInput::make('organization_name')
                    ->label('Organization')
                    ->maxLength(250),

                Forms\Components\TextInput::make('license_ref')
                    ->label('License ref')
                    ->maxLength(100),

                Forms\Components\TextInput::make('scn')
                    ->label('SCN')
                    ->maxLength(100),

                Forms\Components\TextInput::make('year_of_oath')
                    ->label('Year of oath')
                    ->numeric()
                    ->minValue(1900)
                    ->maxValue((int) date('Y')),
                ]),
            ])
            ->action(function (array $data, $record) {
                $user = $record->user;

                $before = [
                    'full_name' => $user?->full_name,
                    'email'     => $user?->email,
                ];

                $user?->update([
                    'full_name' => $data['full_name'],
                    'email'     => $data['email'],
                    'phone'     => $data['phone'] ?: null,
                ]);

                $record->update([
                    'entity_type'       => $data['entity_type'],
                    'organization_name' => $data['organization_name'] ?: null,
                    'license_ref'       => $data['license_ref'] ?: null,
                    'scn'               => $data['scn'] ?: null,
                    'year_of_oath'      => $data['year_of_oath'] ?: null,
                ]);

                AuditLogger::record('notary.details_updated', 'notary_profile', $record->id, [
                    'before'     => $before,
                    'after'      => ['full_name' => $data['full_name'], 'email' => $data['email']],
                    'updated_by' => auth()->id(),
                ]);

                $this->record->refresh()->load('user');

                Notification::make()->title('Details updated')->success()->send();
            });
    }

    /**
     * Set or re-check the account a notary is paid into.
     *
     * The notary normally does this at /notary/profile. The admin equivalent
     * exists for the same two reasons the asset modal does — the platform's own
     * profile, and manual onboarding — plus a third: re-running verification
     * when it failed at the time (no network, Paystack down, transfers not yet
     * enabled on the account) without making the notary re-enter anything.
     */
    private function payoutAccountAction(): Actions\Action
    {
        return Actions\Action::make('payout_account')
            ->label('Payout account')
            ->icon('heroicon-o-banknotes')
            ->color('gray')
            ->modalHeading('Payout account')
            ->modalDescription('The account earnings are transferred to. Saving re-checks it with the bank.')
            ->modalSubmitActionLabel('Save and verify')
            ->modalWidth('2xl')
            ->fillForm(fn ($record) => [
                'bank_code'    => $record->bankDetails?->bank_code,
                'account_name' => $record->bankDetails?->account_name,
                // Deliberately not pre-filled: the stored number is encrypted and
                // showing it in full serves no purpose. Blank means "keep it".
                'account_number' => null,
            ])
            ->form([
                Forms\Components\Placeholder::make('current')
                    ->label('On file')
                    ->content(function ($record) {
                        $bank = $record->bankDetails;

                        if (! $bank) {
                            return 'No account on file.';
                        }

                        $state = match (true) {
                            ! $bank->isVerified()            => 'unverified',
                            $bank->name_matches === false    => 'verified, name mismatch (' . $bank->resolved_account_name . ')',
                            default                          => 'verified as ' . $bank->resolved_account_name,
                        };

                        return $bank->bank_name . ' · ' . $bank->maskedAccountNumber() . ' — ' . $state;
                    })
                    ->columnSpanFull(),

                Forms\Components\Select::make('bank_code')
                    ->label('Bank')
                    ->options(fn () => Banks::all())
                    ->searchable()
                    ->required()
                    ->helperText(fn () => Banks::isLive()
                        ? null
                        : 'Showing the bundled bank list — Paystack could not be reached.'),

                Forms\Components\TextInput::make('account_number')
                    ->label('Account number')
                    ->numeric()
                    ->minLength(10)
                    ->maxLength(10)
                    ->placeholder(fn ($record) => $record->bankDetails
                        ? 'Leave empty to keep ' . $record->bankDetails->maskedAccountNumber()
                        : '10 digits'),

                Forms\Components\TextInput::make('account_name')
                    ->label('Account name')
                    ->required()
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, $record) {
                $number = $data['account_number']
                    ?: (string) $record->bankDetails?->account_number;

                if (! $number) {
                    Notification::make()->warning()
                        ->title('Account number required')
                        ->body('There is nothing on file to keep.')
                        ->send();

                    return;
                }

                $detail = app(BankAccountService::class)->save($record, [
                    'bank_code'      => $data['bank_code'],
                    'account_number' => $number,
                    'account_name'   => $data['account_name'],
                ]);

                $this->record->refresh()->load('bankDetails');

                match (true) {
                    ! $detail->isVerified() => Notification::make()->warning()
                        ->title('Saved, but not verified')
                        ->body('The bank could not be reached. Try again later — no payout can be sent until this clears.')
                        ->send(),

                    $detail->name_matches === false => Notification::make()->warning()
                        ->title('Saved — name mismatch')
                        ->body('The bank holds this account as "' . $detail->resolved_account_name
                            . '", which does not match the notary\'s name.')
                        ->send(),

                    default => Notification::make()->success()
                        ->title('Verified')
                        ->body('Confirmed as "' . $detail->resolved_account_name . '" and ready for payouts.')
                        ->send(),
                };
            });
    }

    /**
     * Upload or replace the signature / stamp / seal / initials that get stamped
     * onto a sealed PDF.
     *
     * Notaries normally do this themselves at /notary/profile. This is the admin
     * equivalent, for two cases the self-service form does not cover: setting up
     * the platform's own system-native notary, and onboarding a partner manually
     * when they cannot complete registration.
     *
     * Writes match NotaryProfileController::saveAssets() — same private disk,
     * same directory, same one-row-per-type shape.
     */
    private function manageAssetsAction(): Actions\Action
    {
        return Actions\Action::make('manage_assets')
            ->label('Manage notarial assets')
            ->icon('heroicon-o-finger-print')
            ->color('warning')
            ->modalHeading('Notarial assets')
            ->modalDescription('Upload or replace this notary\'s signature, stamp and seal. Leave a file empty to keep the one already on file.')
            ->modalSubmitActionLabel('Save assets')
            ->modalWidth('2xl')
            ->fillForm(fn ($record) => [
                'initials' => $record->assets->firstWhere('type', 'initials')?->text_value,
            ])
            ->form([
                Forms\Components\Placeholder::make('current_assets')
                    ->label('Currently on file')
                    ->content(function ($record) {
                        $assets = $record->assets;

                        if ($assets->isEmpty()) {
                            return 'Nothing uploaded yet — this notary cannot seal a document.';
                        }

                        return $assets
                            ->map(fn ($a) => $a->type === 'initials'
                                ? 'initials (' . $a->text_value . ')'
                                : $a->type)
                            ->implode(' · ');
                    }),

                Forms\Components\TextInput::make('initials')
                    ->label('Initials')
                    ->maxLength(10)
                    ->helperText('Short text the notary can stamp on a page, e.g. "F.I."'),

                ...collect(self::IMAGE_ASSETS)->map(fn (string $type) => Forms\Components\FileUpload::make($type)
                    ->label(ucfirst($type))
                    ->disk('private')
                    ->directory('notary-assets')
                    ->visibility('private')
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])
                    ->maxSize(4096)
                    // The private disk serves no public URLs, so Filament cannot
                    // preview or link the file. The infolist below shows it instead,
                    // through the authorized admin.assets.view route.
                    ->previewable(false)
                    ->openable(false)
                    ->downloadable(false)
                    ->helperText('PNG or JPG, max 4 MB. A transparent PNG sits best on the page.'))->all(),
            ])
            ->action(function (array $data, $record) {
                $changed = [];

                $initials = trim((string) ($data['initials'] ?? ''));
                if ($initials !== '') {
                    NotaryAsset::updateOrCreate(
                        ['notary_profile_id' => $record->id, 'type' => 'initials'],
                        ['text_value' => $initials],
                    );
                    $changed[] = 'initials';
                }

                foreach (self::IMAGE_ASSETS as $type) {
                    // A single FileUpload dehydrates to a string, but can arrive as
                    // a one-element array depending on how the field was filled.
                    $path = is_array($data[$type] ?? null)
                        ? Arr::first($data[$type])
                        : ($data[$type] ?? null);

                    if (! $path) {
                        continue; // left empty — keep whatever is on file
                    }

                    $previous = NotaryAsset::where('notary_profile_id', $record->id)
                        ->where('type', $type)
                        ->value('file_url');

                    NotaryAsset::updateOrCreate(
                        ['notary_profile_id' => $record->id, 'type' => $type],
                        ['file_url' => $path],
                    );

                    if ($previous && $previous !== $path) {
                        Storage::disk('private')->delete($previous);
                    }

                    $changed[] = $type;
                }

                if ($changed === []) {
                    Notification::make()
                        ->title('Nothing to update')
                        ->body('No initials text and no files were supplied.')
                        ->warning()
                        ->send();

                    return;
                }

                AuditLogger::record('notary.assets_updated', 'notary_profile', $record->id, [
                    'types'      => $changed,
                    'updated_by' => auth()->id(),
                ]);

                $this->record->refresh()->load('assets');

                Notification::make()
                    ->title('Notarial assets updated')
                    ->body('Saved: ' . implode(', ', $changed) . '.')
                    ->success()
                    ->send();
            });
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([

            // ── Core profile ──────────────────────────────────────────────
            Section::make('Notary')->schema([
                TextEntry::make('user.full_name')->label('Name'),
                TextEntry::make('user.email')->label('Email'),
                TextEntry::make('user.phone')->label('Phone'),
                TextEntry::make('entity_type')->badge(),
                TextEntry::make('organization_name')->label('Organization')->placeholder('—'),
                TextEntry::make('license_ref')->label('License ref')->placeholder('—'),
                TextEntry::make('scn')->label('SCN')->placeholder('—'),
                TextEntry::make('year_of_oath')->label('Year of oath')->placeholder('—'),
                TextEntry::make('verification_status')->badge()->color(fn ($state) => match ($state) {
                    'approved'  => 'success',
                    'pending'   => 'warning',
                    'rejected'  => 'danger',
                    'suspended' => 'gray',
                    default     => 'gray',
                }),
                TextEntry::make('commission_rate')->suffix('%')->label('Commission rate'),
                TextEntry::make('onboarding_fee_paid_at')->label('Fee paid at')->dateTime()->placeholder('Not paid'),
                TextEntry::make('public_listing_enabled')->label('Listed')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
            ])->columns(2),

            // ── Application ───────────────────────────────────────────────
            Section::make('Application')->schema([
                TextEntry::make('experience')->label('Experience')->columnSpanFull(),
                TextEntry::make('motivation')->label('Motivation')->columnSpanFull(),
                TextEntry::make('specialties')->badge(),
            ]),

            // ── E-signature & identity ────────────────────────────────────
            Section::make('E-signature & identity')->schema([
                RepeatableEntry::make('assets')
                    ->label('Notarial assets')
                    ->schema([
                        TextEntry::make('type')
                            ->label('Type')
                            ->badge()
                            ->color(fn ($state) => match ($state) {
                                'signature' => 'success',
                                'stamp'     => 'info',
                                'seal'      => 'warning',
                                'initials'  => 'gray',
                                default     => 'gray',
                            }),
                        TextEntry::make('text_value')
                            ->label('Initials text')
                            ->placeholder('—'),
                        ImageEntry::make('image_url')
                            ->label('Preview')
                            ->getStateUsing(fn ($record) => $record->file_url
                                ? route('admin.assets.view', $record->id)
                                : null)
                            ->height(72)
                            ->width(180)
                            ->extraImgAttributes(['style' => 'object-fit:contain; border:1px solid #e3e6ea; border-radius:6px; background:#f9fafb; padding:4px;'])
                            ->defaultImageUrl('')
                            ->placeholder('No image'),
                    ])
                    ->columns(3)
                    ->placeholder('No assets uploaded yet'),
            ]),

            // ── Payout account ────────────────────────────────────────────
            Section::make('Payout account')
                ->description('Where this notary\'s earnings are transferred. Verify before the first payout.')
                ->schema([
                    TextEntry::make('bankDetails.bank_name')->label('Bank')->placeholder('—'),

                    // Never render the decrypted number in full — the last four
                    // digits are enough to tell two accounts apart.
                    TextEntry::make('bankDetails.account_number')
                        ->label('Account number')
                        ->formatStateUsing(fn ($record) => $record->bankDetails?->maskedAccountNumber() ?? '—')
                        ->placeholder('—'),

                    TextEntry::make('bankDetails.account_name')->label('Name given')->placeholder('—'),

                    TextEntry::make('bankDetails.resolved_account_name')
                        ->label('Name at the bank')
                        ->placeholder('Not verified')
                        ->color(fn ($record) => $record->bankDetails?->name_matches === false ? 'warning' : null)
                        ->helperText(fn ($record) => $record->bankDetails?->name_matches === false
                            ? 'Does not match the notary\'s name — check before paying.'
                            : null),

                    TextEntry::make('bankDetails.verified_at')
                        ->label('Verification')
                        ->badge()
                        ->formatStateUsing(fn ($state, $record) => match (true) {
                            $record->bankDetails === null            => 'No account',
                            ! $record->bankDetails->isVerified()     => 'Unverified',
                            $record->bankDetails->name_matches === false => 'Name mismatch',
                            default                                  => 'Verified',
                        })
                        ->color(fn ($record) => match (true) {
                            $record->bankDetails === null            => 'gray',
                            ! $record->bankDetails->isVerified()     => 'gray',
                            $record->bankDetails->name_matches === false => 'warning',
                            default                                  => 'success',
                        })
                        ->default('—'),

                    // Without a recipient code Paystack has nothing to transfer to,
                    // so this is the real "can we pay them" answer.
                    TextEntry::make('bankDetails.paystack_recipient_code')
                        ->label('Payable via Paystack')
                        ->formatStateUsing(fn ($state) => $state ? 'Yes — ' . $state : 'No')
                        ->color(fn ($state) => $state ? 'success' : 'gray')
                        ->default('No'),
                ])->columns(3),

            // ── Services & pricing ────────────────────────────────────────
            Section::make('Services & pricing')->schema([
                RepeatableEntry::make('services')
                    ->label('Marketplace services')
                    ->schema([
                        TextEntry::make('service_type')->label('Service'),
                        TextEntry::make('price_ngn')
                            ->label('Price NGN')
                            ->formatStateUsing(fn ($state) => '₦' . number_format($state / 100, 2)),
                        TextEntry::make('price_usd')
                            ->label('Price USD')
                            ->formatStateUsing(fn ($state) => '$' . number_format($state / 100, 2)),
                        TextEntry::make('estimated_duration_minutes')
                            ->label('Duration')
                            ->suffix(' min'),
                        TextEntry::make('active')
                            ->label('Active')
                            ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'gray'),
                    ])
                    ->columns(5)
                    ->placeholder('No services added yet'),
            ]),

            // ── Credentials ───────────────────────────────────────────────
            Section::make('Credentials')->schema([
                RepeatableEntry::make('credentials')
                    ->label('Uploaded documents')
                    ->schema([
                        TextEntry::make('document_type')
                            ->label('Type')
                            ->badge(),
                        TextEntry::make('original_filename')
                            ->label('Filename')
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn ($state) => match ($state) {
                                'approved' => 'success',
                                'pending'  => 'warning',
                                'rejected' => 'danger',
                                default    => 'gray',
                            }),
                        TextEntry::make('id')
                            ->label('Action')
                            ->formatStateUsing(fn () => 'Download / View')
                            ->url(fn ($record) => route('admin.credentials.download', $record->id))
                            ->openUrlInNewTab(),
                    ])
                    ->columns(4)
                    ->placeholder('No credentials uploaded'),
            ]),

        ]);
    }
}
