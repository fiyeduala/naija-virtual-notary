<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationResource\Pages;
use App\Filament\Resources\OrganizationResource\RelationManagers;
use App\Models\NotarizationRequest;
use App\Models\NotaryProfile;
use App\Models\NotaryService;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\OrganizationOnboardingService;
use App\Services\OrganizationPayoutService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * The roll of partner bodies, and the screen an application is decided on.
 *
 * The application IS this record — a submission creates the row at
 * `status = pending`, and approving it changes a status rather than copying
 * anything across. That is what makes "correct what they already sent" free:
 * an admin fixing a misspelled name or a wrong RC number is editing the same
 * record in the same form they will keep using afterwards.
 *
 * Two things are deliberately kept off the public form and live only here. The
 * money — the arrangement, the rate and the prices — is the negotiation, and no
 * applicant proposes their own. The bank details are asked for only under
 * `commission`, because there is nothing to pay a body that earns nothing.
 */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'Users & notaries';
    protected static ?string $navigationLabel = 'Organizations';
    protected static ?string $modelLabel = 'organization';

    /**
     * Applications waiting, plus bodies with commission owed and unsettled.
     *
     * A count of something that wants doing, which is how PayoutResource's
     * badge is used — not a headcount of partners, which never changes and
     * would sit there permanently as a number nobody can act on.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = Organization::pending()->count();

        $payouts = app(OrganizationPayoutService::class);

        $owed = Organization::active()
            ->where('arrangement', Organization::COMMISSION)
            ->get()
            ->filter(fn (Organization $organization) => $payouts->owed($organization) > 0)
            ->count();

        return ($pending + $owed) ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Organization::pending()->exists() ? 'warning' : 'success';
    }

    public static function money(?int $minor, string $currency = 'NGN'): string
    {
        return ($currency === 'USD' ? '$' : '₦') . number_format(((int) $minor) / 100, 2);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('The body')
                ->description('What they sent us, and what we can correct.')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()->maxLength(255)->columnSpan(2),
                    Forms\Components\TextInput::make('registration_number')
                        ->label('RC / registration number')->maxLength(255),
                    Forms\Components\TextInput::make('sector')->maxLength(255),
                    Forms\Components\TextInput::make('website')->url()->maxLength(255),
                    Forms\Components\TextInput::make('expected_volume')
                        ->label('Volume they expect')->maxLength(255),
                    Forms\Components\Textarea::make('address')->rows(2)->columnSpanFull(),
                    Forms\Components\Textarea::make('about')
                        ->label('What they do')->rows(3)->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make('Who to talk to')
                ->description('Used when a submission has a mistake in it, and where the link and '
                    . 'the portal password are emailed on approval.')
                ->schema([
                    Forms\Components\TextInput::make('contact_name')->label('Contact')->maxLength(255),
                    Forms\Components\TextInput::make('contact_role')->label('Their role')->maxLength(255),
                    Forms\Components\TextInput::make('contact_email')->label('Contact email')
                        ->email()->required()->maxLength(255),
                    Forms\Components\TextInput::make('phone')->tel()->maxLength(50),
                ])->columns(2),

            Forms\Components\Section::make('The arrangement')
                ->description('The negotiation. None of this is ever proposed on the public form.')
                ->schema([
                    Forms\Components\Select::make('arrangement')
                        ->options(Organization::ARRANGEMENTS)
                        ->required()
                        // live() so the rate and the bank section appear and
                        // disappear as the arrangement is chosen, rather than
                        // sitting there asking for an account number from a
                        // government office that will never be paid anything.
                        ->live()
                        ->helperText('A middleman takes a share. A government body is charged its own '
                            . 'rate and takes nothing — which is a different arrangement, not a 0% '
                            . 'commission: the payout run skips it entirely.'),
                    Forms\Components\TextInput::make('commission_rate')
                        ->label('Their share')
                        ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                        ->default(fn () => (int) config('nvn.organizations.default_commission_rate', 0))
                        ->visible(fn (Forms\Get $get) => $get('arrangement') === Organization::COMMISSION)
                        ->helperText('Taken out of the platform’s own fee, never a notary’s. Frozen onto '
                            . 'each request as it is created, so changing this never moves what was '
                            . 'earned on work already done.'),
                    Forms\Components\TextInput::make('default_price_ngn')
                        ->label('Their price per document (₦, in kobo)')
                        ->required()
                        ->numeric()->minValue(0)
                        ->helperText('A negotiated figure, not a discount — ₦250,000 is 25000000. This '
                            . 'is what their applicants are quoted and what they pay.'),
                    Forms\Components\TextInput::make('default_price_usd')
                        ->label('Their price per document ($, in cents)')
                        ->numeric()->minValue(0)
                        ->helperText('Optional. Commission is only ever paid on naira work, because a '
                            . 'transfer settles in naira — a dollar job earns nothing payable.'),
                    Forms\Components\Placeholder::make('public_prices')
                        ->label('For comparison — our own notary’s public list')
                        ->content(fn () => new HtmlString(static::publicPriceList()))
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('attribution_days')
                        ->label('How long a referral keeps counting (days)')
                        ->numeric()->minValue(0)
                        ->placeholder((string) config('nvn.organizations.attribution_days', 30))
                        ->helperText('Blank uses the house default. 0 means forever.'),
                ])->columns(2),

            Forms\Components\Section::make('Where the commission goes')
                // Hidden rather than disabled under price_only: an account
                // number asked for and never used is a thing somebody will
                // eventually try to pay.
                ->visible(fn (Forms\Get $get) => $get('arrangement') === Organization::COMMISSION)
                ->schema([
                    Forms\Components\TextInput::make('bank_name')->maxLength(255),
                    Forms\Components\TextInput::make('account_name')->maxLength(255),
                    Forms\Components\TextInput::make('account_number')
                        ->label('Account number')
                        ->maxLength(50)
                        // Read back off the record by hand. account_number is
                        // in the model's $hidden, and Filament fills an edit
                        // form from attributesToArray(), which honours that —
                        // so without this the field would open blank on a body
                        // that has one and save the blank over it.
                        ->afterStateHydrated(fn (Forms\Components\TextInput $component, ?Organization $record) => $component->state($record?->account_number))
                        ->helperText('Stored encrypted. Settled by hand — there is no automatic transfer '
                            . 'for organizations.'),
                ])->columns(2),

            Forms\Components\Section::make('Their link')
                // Nothing to show until approval has generated a slug, and an
                // empty section would read as something not working.
                ->visible(fn (?Organization $record) => (bool) $record?->slug)
                ->schema([
                    Forms\Components\Placeholder::make('link')
                        ->label('What they hand to their applicants')
                        ->content(fn (Organization $record) => new HtmlString(
                            '<code>' . e($record->landingUrl()) . '</code><br>'
                            . '<code>' . e($record->shortUrl()) . '</code><br>'
                            . 'Or the code <strong>' . e((string) $record->code) . '</strong>, typed in '
                            . 'on the organizations page.'
                        )),
                    Forms\Components\Placeholder::make('portal')
                        ->label('Portal sign-in')
                        ->content(fn (Organization $record) => new HtmlString(
                            e($record->email ?: 'No login issued yet.') . ' — '
                            . ($record->last_login_at
                                ? 'last signed in ' . e($record->last_login_at->diffForHumans())
                                : 'never signed in')
                        )),
                ])->columns(2),

            Forms\Components\Section::make('Status and paperwork')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->options(Organization::STATUSES)
                        ->required()
                        // Not offered on create. Approval is what mints the
                        // slug, the code and the portal password, so a record
                        // typed straight into `active` would be a live partner
                        // with no link and no way in.
                        ->visibleOn('edit')
                        ->helperText('Pausing closes the link and the portal at the same moment.'),
                    Forms\Components\Placeholder::make('documents')
                        ->label('What they sent in')
                        ->content(fn (?Organization $record) => new HtmlString(static::documentList($record))),
                    Forms\Components\Textarea::make('review_note')
                        ->label('Review note')->rows(2)
                        ->helperText('They see this if the application is declined.'),
                    Forms\Components\Textarea::make('notes')
                        ->label('Our notes')->rows(2)
                        ->helperText('Never shown to them.'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'requests as referred_count',
                'requests as completed_count' => fn (Builder $inner) => $inner->where('status', 'completed'),
                'referredUsers as people_count',
                'prices as price_overrides_count',
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()->sortable()
                    ->description(fn (Organization $record) => $record->sector ?: null),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (Organization $record) => $record->statusLabel())
                    ->color(fn ($state) => match ($state) {
                        'active'   => 'success',
                        'pending'  => 'warning',
                        'rejected' => 'danger',
                        default    => 'gray',
                    }),
                Tables\Columns\TextColumn::make('arrangement')
                    ->badge()
                    ->formatStateUsing(fn (Organization $record) => $record->arrangementLabel())
                    ->color(fn ($state) => $state === Organization::COMMISSION ? 'info' : 'gray')
                    ->description(fn (Organization $record) => $record->earnsCommission()
                        ? $record->commission_rate . '% of what they refer'
                        : null),
                Tables\Columns\TextColumn::make('default_price_ngn')
                    ->label('Their rate')
                    ->state(fn (Organization $record) => $record->displayDefaultPrice('NGN'))
                    ->description(fn (Organization $record) => $record->price_overrides_count
                        ? $record->price_overrides_count . ' category override'
                            . ($record->price_overrides_count === 1 ? '' : 's')
                        : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('referred_count')
                    ->label('Orders')->sortable()->alignRight(),
                Tables\Columns\TextColumn::make('completed_count')
                    ->label('Completed')->sortable()->alignRight(),
                Tables\Columns\TextColumn::make('people_count')
                    ->label('People')->sortable()->alignRight()
                    ->tooltip('Accounts registered through their link')
                    ->toggleable(),
                // Gross and owed are worked out a row at a time rather than
                // joined. The roll is tens of bodies, not thousands, and the
                // alternative is a second definition of what a body has earned
                // sitting next to OrganizationPayoutService's.
                Tables\Columns\TextColumn::make('gross')
                    ->label('Gross referred')
                    ->state(fn (Organization $record) => static::money(static::grossFor($record)))
                    ->alignRight(),
                Tables\Columns\TextColumn::make('owed')
                    ->label('Commission owed')
                    ->state(fn (Organization $record) => $record->earnsCommission()
                        ? static::money(app(OrganizationPayoutService::class)->owed($record))
                        : '—')
                    ->badge()
                    ->color(fn (Organization $record) => $record->earnsCommission()
                        && app(OrganizationPayoutService::class)->owed($record) > 0
                            ? 'warning'
                            : 'gray')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('applied_at')
                    ->label('Applied')->date('j M Y')
                    ->placeholder('—')->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(Organization::STATUSES),
                Tables\Filters\SelectFilter::make('arrangement')->options(Organization::ARRANGEMENTS),
                Tables\Filters\Filter::make('earns_commission')
                    ->label('Earns a share')
                    ->query(fn (Builder $query) => $query
                        ->where('arrangement', Organization::COMMISSION)
                        ->where('commission_rate', '>', 0)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Organization $record) => ! $record->isActive())
                    ->requiresConfirmation()
                    ->modalHeading('Take this partnership live')
                    // What is missing is said before the button is pressed, not
                    // after. An admin who has not set a price can go and set it
                    // instead of finding out by being refused.
                    ->modalDescription(fn (Organization $record) => $record->missingBeforeApproval()
                        ? 'This cannot go live yet. Still needed: '
                            . implode('; ', $record->missingBeforeApproval())
                            . '. Edit the record first.'
                        : 'Generates their link and code, issues a portal password, and emails all of it '
                            . 'to ' . $record->contact_email . '. Their applicants will be quoted '
                            . $record->displayDefaultPrice('NGN') . ' per document, and the work is '
                            . 'notarized in-house.')
                    ->action(function (Organization $record, OrganizationOnboardingService $onboarding) {
                        [$ok, $message] = $onboarding->approve($record, auth()->id());

                        Notification::make()
                            ->title($ok ? 'Partnership live' : 'Not approved')
                            ->body($message)
                            ->status($ok ? 'success' : 'danger')
                            ->persistent()
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Decline')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (Organization $record) => $record->isPending())
                    ->modalHeading('Decline this application')
                    ->modalDescription('The record and everything they sent is kept, so this can be '
                        . 'revisited or approved later.')
                    ->form([
                        Forms\Components\Textarea::make('note')
                            ->label('Why — in your own words')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('They are emailed this, so write it to them. Optional.'),
                    ])
                    ->action(function (
                        Organization $record,
                        array $data,
                        OrganizationOnboardingService $onboarding,
                    ) {
                        [$ok, $message] = $onboarding->reject($record, $data['note'] ?? null, auth()->id());

                        Notification::make()
                            ->title($ok ? 'Declined' : 'Not declined')
                            ->body($message)
                            ->status($ok ? 'success' : 'danger')
                            ->send();
                    }),

                Tables\Actions\Action::make('resetPassword')
                    ->label('Reset portal password')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->visible(fn (Organization $record) => (bool) $record->email)
                    ->requiresConfirmation()
                    ->modalDescription('Issues a new password and emails it to them. Nobody here can read '
                        . 'the old one, so this is the only way back in for a body that has lost it.')
                    ->action(function (Organization $record, OrganizationOnboardingService $onboarding) {
                        [$ok, $message] = $onboarding->resetPortalPassword($record, auth()->id());

                        Notification::make()
                            ->title($ok ? 'New password sent' : 'Not sent')
                            ->body($message)
                            ->status($ok ? 'success' : 'danger')
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No organizations yet')
            ->emptyStateDescription('A body that applies through the public form appears here as pending.');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PricesRelationManager::class,
            RelationManagers\RequestsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'edit'   => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }

    /** What this body's applicants have actually paid us, in minor units. */
    public static function grossFor(Organization $organization): int
    {
        return (int) Payment::query()
            ->where('type', 'request_fee')
            ->where('status', 'successful')
            ->whereIn('request_id', NotarizationRequest::query()
                ->where('organization_id', $organization->id)
                ->select('id'))
            ->sum('amount');
    }

    /**
     * The public list, shown beside the body's own price.
     *
     * So that a mistyped figure is visible. ₦250,000 next to a public ₦25,000
     * is the arrangement; ₦2,500 next to it is two missing zeros, and there is
     * nothing else on this form that would show that.
     */
    private static function publicPriceList(): string
    {
        $notary = NotaryProfile::systemNative()->first();

        if (! $notary) {
            return '<span>There is no in-house notary profile yet.</span>';
        }

        $services = $notary->services()->where('active', true)->orderBy('service_type')->get();

        if ($services->isEmpty()) {
            return '<span>Our own notary has no active categories priced yet.</span>';
        }

        return '<ul>' . $services
            ->map(fn (NotaryService $service) => '<li>' . e($service->service_type) . ' — '
                . static::money($service->priceFor('NGN')) . '</li>')
            ->implode('') . '</ul>';
    }

    /** What a body sent in support of its application. */
    private static function documentList(?Organization $organization): string
    {
        $documents = $organization?->documents ?? collect();

        if ($documents->isEmpty()) {
            return '<span>Nothing uploaded.</span>';
        }

        return '<ul>' . $documents
            ->map(fn ($document) => '<li>' . e($document->typeLabel()) . ' — '
                . e($document->original_filename ?: 'file') . '</li>')
            ->implode('') . '</ul>';
    }
}
