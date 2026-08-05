<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotaryProfileResource\Pages;
use App\Models\NotaryProfile;
use App\Notifications\NotaryApplicationReviewed;
use App\Support\AuditLogger;
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
                Tables\Columns\IconColumn::make('onboarding_fee_paid_at')->label('Fee paid')->boolean(),
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
