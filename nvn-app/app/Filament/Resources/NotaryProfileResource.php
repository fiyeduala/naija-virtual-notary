<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotaryProfileResource\Pages;
use App\Models\NotaryProfile;
use App\Notifications\NotaryApplicationReviewed;
use App\Notifications\NotaryAssetsReminder;
use App\Notifications\NotaryListingReviewed;
use App\Services\OfflinePaymentService;
use App\Support\AuditLogger;
use Filament\Forms;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotaryProfileResource extends Resource
{
    protected static ?string $model = NotaryProfile::class;
    protected static ?string $navigationIcon = 'heroicon-o-identification';
    protected static ?string $navigationGroup = 'Users & notaries';
    protected static ?string $navigationLabel = 'Notaries';
    protected static ?string $modelLabel = 'notary';

    public static function getNavigationBadge(): ?string
    {
        // Both queues, counted together: an application waiting to be approved
        // and a listing waiting to be looked at are the same thing to the
        // partner sitting on the other end of them — nothing happening.
        $count = static::getModel()::where('verification_status', 'pending')
            ->whereNotNull('onboarding_fee_paid_at')->count()
            + static::getModel()::query()->awaitingListingReview()->count();

        return $count ?: null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Textarea::make('review_notes')->label('Review notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.full_name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('entity_type')->badge(),
                Tables\Columns\TextColumn::make('license_ref')->label('License ref'),
                Tables\Columns\IconColumn::make('onboarding_fee_paid_at')->label('Joined')->boolean(),
                // The renewal date, not just "did they ever pay" — a partner who
                // paid two years ago and one who paid last week look identical
                // through that icon, and only one of them is still listed.
                Tables\Columns\TextColumn::make('membership_expires_at')
                    ->label('Membership')
                    ->sortable()
                    ->badge()
                    ->color(fn (NotaryProfile $r) => match (true) {
                        $r->is_system_native        => 'gray',
                        ! $r->membership_expires_at => 'gray',
                        $r->membershipLapsed()      => 'danger',
                        $r->membershipEndingSoon()  => 'warning',
                        default                     => 'success',
                    })
                    // state(), not formatStateUsing(): a null expiry has to say
                    // "never paid" out loud, and a formatter is not reached once
                    // the column decides the state is blank.
                    ->state(fn (NotaryProfile $r) => match (true) {
                        $r->is_system_native              => 'In-house',
                        ! $r->membership_expires_at       => 'Never paid',
                        $r->membershipLapsed()            => 'Ended ' . $r->membership_expires_at->format('j M Y'),
                        default                           => $r->membership_expires_at->format('j M Y')
                            . ' (' . $r->membershipDaysLeft() . 'd)',
                    }),
                Tables\Columns\TextColumn::make('verification_status')->badge()->color(fn ($state) => match ($state) {
                    'approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'gray',
                }),
                // Three states, not two. A boolean icon showed a notary who is
                // waiting on a decision as identical to one who never asked —
                // and the waiting one is the only one needing anything.
                Tables\Columns\TextColumn::make('public_listing_enabled')
                    ->label('Listed')
                    ->badge()
                    ->color(fn (NotaryProfile $r) => match (true) {
                        $r->public_listing_enabled    => 'success',
                        $r->isAwaitingListingReview() => 'warning',
                        default                       => 'gray',
                    })
                    ->state(fn (NotaryProfile $r) => match (true) {
                        $r->public_listing_enabled    => 'Listed',
                        $r->isAwaitingListingReview() => 'Awaiting review',
                        default                       => 'Not listed',
                    }),
                Tables\Columns\IconColumn::make('is_system_native')->label('System')->boolean(),
                Tables\Columns\TextColumn::make('commission_rate')->label('Comm %')->suffix('%'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('verification_status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'suspended' => 'Suspended']),

                Tables\Filters\SelectFilter::make('listing')
                    ->label('Listing')
                    ->options([
                        'awaiting' => 'Awaiting review',
                        'listed'   => 'Listed',
                        'not'      => 'Not listed',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'awaiting' => $query->awaitingListingReview(),
                        'listed'   => $query->where('public_listing_enabled', true),
                        'not'      => $query->where('public_listing_enabled', false),
                        default    => $query,
                    }),

                // The two lists an admin actually chases: who has already fallen
                // off the marketplace, and who is about to.
                Tables\Filters\SelectFilter::make('membership')
                    ->label('Membership')
                    ->options([
                        'lapsed' => 'Lapsed',
                        'ending' => 'Ending within ' . NotaryProfile::RENEWAL_NOTICE_DAYS . ' days',
                        'active' => 'Paid up',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'lapsed' => $query->where('is_system_native', false)
                            ->whereNotNull('membership_expires_at')
                            ->where('membership_expires_at', '<=', now()),
                        'ending' => $query->where('is_system_native', false)
                            ->where('membership_expires_at', '>', now())
                            ->where('membership_expires_at', '<=', now()->addDays(NotaryProfile::RENEWAL_NOTICE_DAYS)),
                        'active' => $query->where('is_system_native', false)
                            ->where('membership_expires_at', '>', now()->addDays(NotaryProfile::RENEWAL_NOTICE_DAYS)),
                        default  => $query,
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (NotaryProfile $r) => $r->verification_status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (NotaryProfile $r) {
                        $r->update(['verification_status' => 'approved', 'approved_at' => now(), 'approved_by' => auth()->id()]);
                        $r->user->update(['status' => 'active']);
                        AuditLogger::record('notary.approved', 'notary_profile', $r->id);
                        $r->user->notify(new NotaryApplicationReviewed('approved'));
                        Notification::make()->title('Notary approved')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (NotaryProfile $r) => $r->verification_status === 'pending')
                    ->form([Textarea::make('notes')->label('Reason (sent to applicant)')->required()])
                    ->action(function (NotaryProfile $r, array $data) {
                        $r->update(['verification_status' => 'rejected', 'review_notes' => $data['notes']]);
                        AuditLogger::record('notary.rejected', 'notary_profile', $r->id, ['notes' => $data['notes']]);
                        $r->user->notify(new NotaryApplicationReviewed('rejected', $data['notes']));
                        Notification::make()->title('Application rejected')->success()->send();
                    }),
                // Most partners will renew by bank transfer rather than opening
                // the checkout page, and a transfer leaves no payment row for an
                // admin to confirm. This makes one and settles it, so a renewal
                // paid into the account looks exactly like one paid by card.
                Tables\Actions\Action::make('recordMembership')
                    ->label('Record membership payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->visible(fn (NotaryProfile $r) => ! $r->is_system_native)
                    ->modalHeading('Record a partner membership fee')
                    ->modalDescription('For a notary who paid their yearly fee into the company account. '
                        . 'It adds twelve months to their membership and, if this is their first payment, '
                        . 'activates their application for review — the same as paying on the checkout page. '
                        . 'Only do it once the money has arrived.')
                    ->modalSubmitActionLabel('Record payment')
                    ->form(fn (NotaryProfile $r) => [
                        Forms\Components\Placeholder::make('current')
                            ->label('Membership now')
                            ->content(fn () => match (true) {
                                ! $r->membership_expires_at => 'Never paid. This will be their first year.',
                                $r->membershipLapsed()      => 'Ended ' . $r->membership_expires_at->format('j F Y')
                                    . '. A year is added from today.',
                                default                     => 'Runs to ' . $r->membership_expires_at->format('j F Y')
                                    . '. A year is added onto that date, so no time is lost.',
                            })
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('amount_major')
                            ->label('Amount received')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->prefix('₦')
                            ->default(fn () => round(static::membershipFeeMinor() / 100, 2))
                            ->helperText('Standard fee: ' . PaymentResource::money(static::membershipFeeMinor())
                                . '. Change it if a different figure actually landed.'),

                        ...PaymentResource::settlementFields(),
                    ])
                    ->action(function (NotaryProfile $r, array $data, OfflinePaymentService $offline) {
                        if (! $r->user) {
                            Notification::make()->title('This profile has no user account')->danger()->send();

                            return;
                        }

                        $offline->recordMembershipFee($r->user, $data + [
                            'amount' => (int) round((float) $data['amount_major'] * 100),
                        ], auth()->id());

                        $expires = $r->fresh()->membership_expires_at;

                        Notification::make()
                            ->title('Membership recorded')
                            ->body('Membership now runs to ' . $expires?->format('j F Y') . '.')
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                // The scheduled run (nvn:asset-reminders) chases these on its
                // own. This is for when you have just spoken to someone and
                // want the email in front of them now, and it deliberately
                // ignores the spacing that stops the automatic one nagging.
                Tables\Actions\Action::make('remindAssets')
                    ->label('Ask for missing marks')
                    ->icon('heroicon-o-envelope')
                    ->color('warning')
                    ->visible(fn (NotaryProfile $r) => $r->verification_status === 'approved'
                        && $r->user
                        && ! $r->canSeal())
                    ->requiresConfirmation()
                    ->modalHeading('Ask for the missing marks')
                    ->modalDescription(fn (NotaryProfile $r) => 'Emails ' . $r->user?->email
                        . ' asking for their ' . implode(', ', $r->missingSealingAssets())
                        . ', with a link to the upload page. They are also chased automatically '
                        . 'once a week until they do it, so this is only needed to send one now.')
                    ->modalSubmitActionLabel('Send it')
                    ->action(function (NotaryProfile $r) {
                        $r->update([
                            'assets_reminded_at'    => now(),
                            'assets_reminders_sent' => $r->assets_reminders_sent + 1,
                        ]);

                        $r->user->notify(new NotaryAssetsReminder($r));

                        AuditLogger::record('notary.asset_reminder_sent', 'notary_profile', $r->id, [
                            'missing' => $r->missingSealingAssets(),
                            'by_hand' => true,
                        ]);

                        Notification::make()
                            ->title('Reminder sent')
                            ->body('Asked ' . $r->user->email . ' for their '
                                . implode(', ', $r->missingSealingAssets()) . '.')
                            ->success()
                            ->send();
                    }),
                // Listing is granted here and nowhere else. A notary can ask
                // (NotaryProfileController::requestListing) but cannot let
                // themselves in, because the only check code can run is whether
                // three files exist — not whether the marks on them are real.
                Tables\Actions\Action::make('listNotary')
                    ->label('List')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->visible(fn (NotaryProfile $r) => $r->verification_status === 'approved'
                        && ! $r->public_listing_enabled)
                    ->modalHeading('Put this notary in the marketplace')
                    ->modalDescription('Look at the three marks below before you decide. Once listed, '
                        . 'clients can book them and whatever is on these images goes onto real documents.')
                    ->modalWidth('2xl')
                    ->modalSubmitActionLabel('List them')
                    ->form(fn (NotaryProfile $r) => [
                        Forms\Components\Placeholder::make('marks')
                            ->label('Signature, stamp and seal')
                            ->content(fn () => view('filament.notary-marks', ['profile' => $r->load('assets')]))
                            ->columnSpanFull(),

                        // A checkbox is not security; it is the half-second in
                        // which somebody actually looks up at the images they
                        // just scrolled past.
                        Forms\Components\Checkbox::make('looked')
                            ->label('I have looked at these images and they are this notary\'s genuine marks')
                            ->accepted()
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->action(function (NotaryProfile $r) {
                        // A notary with no seal on file cannot notarize anything —
                        // the client would book them and the session would dead-end.
                        if (! static::hasSealingAssets($r)) {
                            Notification::make()
                                ->title('Cannot list this notary')
                                ->body('Their signature, stamp and seal must be on file first — use "Manage notarial assets".')
                                ->warning()
                                ->send();

                            return;
                        }

                        $r->update([
                            'public_listing_enabled' => true,
                            'listed_at'              => now(),
                            'listing_requested_at'   => null,
                            'listing_review_notes'   => null,
                        ]);

                        AuditLogger::record('notary.listed', 'notary_profile', $r->id, ['by_admin' => auth()->id()]);

                        $r->user?->notify(new NotaryListingReviewed(true));

                        Notification::make()
                            ->title('Listed')
                            ->body(($r->user?->full_name ?? 'This notary') . ' is now findable and bookable.')
                            ->success()
                            ->send();
                    }),

                // Declining is a separate action from unlisting on purpose: one
                // answers a request, the other takes back something already
                // given, and only the first should disappear once answered.
                Tables\Actions\Action::make('declineListing')
                    ->label('Decline listing')
                    ->icon('heroicon-o-hand-raised')
                    ->color('danger')
                    ->visible(fn (NotaryProfile $r) => $r->isAwaitingListingReview())
                    ->modalHeading('Decline this listing request')
                    ->modalWidth('2xl')
                    ->modalSubmitActionLabel('Decline and tell them')
                    ->form(fn (NotaryProfile $r) => [
                        Forms\Components\Placeholder::make('marks')
                            ->label('What they sent')
                            ->content(fn () => view('filament.notary-marks', ['profile' => $r->load('assets')]))
                            ->columnSpanFull(),

                        Textarea::make('notes')
                            ->label('What they need to fix')
                            ->required()
                            ->rows(3)
                            ->helperText('Emailed to them word for word. Name the mark and what is wrong '
                                . 'with it — "your seal is a photo of a stamp pad, we need the seal itself" '
                                . 'gets fixed; "assets rejected" gets a reply asking what you meant.')
                            ->columnSpanFull(),
                    ])
                    ->action(function (NotaryProfile $r, array $data) {
                        $r->update([
                            'listing_requested_at' => null,
                            'listing_review_notes' => $data['notes'],
                        ]);

                        AuditLogger::record('notary.listing_declined', 'notary_profile', $r->id, [
                            'notes' => $data['notes'],
                        ]);

                        $r->user?->notify(new NotaryListingReviewed(false, $data['notes']));

                        Notification::make()->title('Declined — they have been told why')->success()->send();
                    }),

                Tables\Actions\Action::make('unlist')
                    ->label('Unlist')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (NotaryProfile $r) => $r->public_listing_enabled)
                    ->modalHeading('Take this notary out of the marketplace')
                    ->modalDescription('They stop appearing to clients immediately. Work already booked '
                        . 'is untouched — nothing in flight looks at the listing.')
                    ->modalSubmitActionLabel('Unlist')
                    ->form([
                        Textarea::make('notes')
                            ->label('Reason (emailed to them)')
                            ->rows(3)
                            ->helperText('Leave empty to unlist quietly, without an email.'),
                    ])
                    ->action(function (NotaryProfile $r, array $data) {
                        $r->update([
                            'public_listing_enabled' => false,
                            'listing_requested_at'   => null,
                            'listing_review_notes'   => $data['notes'] ?: null,
                        ]);

                        AuditLogger::record('notary.unlisted', 'notary_profile', $r->id, [
                            'notes' => $data['notes'] ?: null,
                        ]);

                        if (filled($data['notes'])) {
                            $r->user?->notify(new NotaryListingReviewed(false, $data['notes']));
                        }

                        Notification::make()->title('Unlisted')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** The yearly partner fee in kobo, as the checkout page reads it. */
    public static function membershipFeeMinor(): int
    {
        return (int) \App\Models\PlatformSetting::get('onboarding_fee_ngn', config('nvn.onboarding_fee_ngn'));
    }

    /** Signature, stamp and seal — the three images a sealed PDF needs. */
    public static function hasSealingAssets(NotaryProfile $profile): bool
    {
        return $profile->canSeal();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotaryProfiles::route('/'),
            'view'  => Pages\ViewNotaryProfile::route('/{record}'),
        ];
    }
}
