<?php

namespace App\Filament\Resources\OrganizationResource\Pages;

use App\Filament\Resources\OrganizationResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateOrganization extends CreateRecord
{
    protected static string $resource = OrganizationResource::class;

    protected static ?string $title = 'Onboard an organization';

    /**
     * A body onboarded from here starts pending, exactly as an applicant does.
     *
     * Not a formality. Approval is what mints the slug, the code and the portal
     * password and emails them — so a record created straight into `active`
     * would be a live partner with no link and no way to sign in. One door into
     * being live, and it is the Approve button.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'pending';
        $data['applied_at'] ??= now();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Saved, not live yet')
            ->body('Press Approve when the arrangement is settled — that is what generates their link '
                . 'and emails it to them.');
    }
}
