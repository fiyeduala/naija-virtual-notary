<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotaryProfileResource\Pages;
use App\Models\NotaryProfile;
use App\Notifications\NotaryApplicationReviewed;
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
        $count = static::getModel()::where('verification_status', 'pending')
            ->whereNotNull('onboarding_fee_paid_at')->count();

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
                Tables\Columns\IconColumn::make('public_listing_enabled')->label('Listed')->boolean(),
                Tables\Columns\IconColumn::make('is_system_native')->label('System')->boolean(),
                Tables\Columns\TextColumn::make('commission_rate')->label('Comm %')->suffix('%'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('verification_status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'suspended' => 'Suspended']),

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
                Tables\Actions\Action::make('toggleListing')
                    ->label(fn (NotaryProfile $r) => $r->public_listing_enabled ? 'Unlist' : 'List')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (NotaryProfile $r) => $r->verification_status === 'approved')
                    ->action(function (NotaryProfile $r) {
                        // A notary with no seal on file cannot notarize anything —
                        // the client would book them and the session would dead-end.
                        // Self-service listing already checks this
                        // (NotaryProfileController::goLive); this is the same rule
                        // for the admin, who can otherwise flip the switch freely.
                        if (! $r->public_listing_enabled && ! static::hasSealingAssets($r)) {
                            Notification::make()
                                ->title('Cannot list this notary')
                                ->body('Their signature, stamp and seal must be on file first — use "Manage notarial assets".')
                                ->warning()
                                ->send();

                            return;
                        }

                        $r->update(['public_listing_enabled' => ! $r->public_listing_enabled]);
                        AuditLogger::record('notary.listing_toggled', 'notary_profile', $r->id, ['listed' => $r->public_listing_enabled]);
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
