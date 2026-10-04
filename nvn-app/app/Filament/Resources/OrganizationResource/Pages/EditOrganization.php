<?php

namespace App\Filament\Resources\OrganizationResource\Pages;

use App\Filament\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\OrganizationOnboardingService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * The correction path and the decision, on one screen.
 *
 * Deliberately the same form the public application wrote into, so fixing a
 * misspelled name or a wrong RC number before approving needs no second
 * screen and no copying. The three decisions sit in the header because this is
 * where an admin will already be when they make them.
 */
class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (Organization $record) => ! $record->isActive())
                ->requiresConfirmation()
                ->modalHeading('Take this partnership live')
                ->modalDescription(fn (Organization $record) => $record->missingBeforeApproval()
                    ? 'This cannot go live yet. Still needed: '
                        . implode('; ', $record->missingBeforeApproval())
                        . '. Save those above first.'
                    : 'Generates their link and code, issues a portal password, and emails all of it to '
                        . $record->contact_email . '. Their applicants will be quoted '
                        . $record->displayDefaultPrice('NGN') . ' per document, notarized in-house.')
                ->action(function (Organization $record, OrganizationOnboardingService $onboarding) {
                    [$ok, $message] = $onboarding->approve($record, auth()->id());

                    Notification::make()
                        ->title($ok ? 'Partnership live' : 'Not approved')
                        ->body($message)
                        ->status($ok ? 'success' : 'danger')
                        ->persistent()
                        ->send();

                    // So the link section and the status field show what just
                    // happened rather than the form state from before it.
                    if ($ok) {
                        $this->fillForm();
                    }
                }),

            Actions\Action::make('reject')
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

                    if ($ok) {
                        $this->fillForm();
                    }
                }),

            Actions\Action::make('resetPassword')
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

            Actions\DeleteAction::make()
                ->label('Delete')
                // Soft-deleted, and the requests they referred keep pointing at
                // the row — so a body deleted by mistake does not take the
                // history of what it sent with it.
                ->modalDescription('Their link and portal stop working. Work they already referred keeps '
                    . 'its attribution, and anything owed stays owed.'),
        ];
    }
}
