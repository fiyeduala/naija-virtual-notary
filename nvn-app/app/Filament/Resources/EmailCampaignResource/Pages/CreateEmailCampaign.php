<?php

namespace App\Filament\Resources\EmailCampaignResource\Pages;

use App\Filament\Resources\EmailCampaignResource;
use App\Services\EmailCampaignService;
use Filament\Resources\Pages\CreateRecord;

/**
 * Composing saves a draft and freezes the recipient list — it does not send.
 *
 * The send button lives on the review screen you land on next, with the exact
 * head count and a "send test to me" beside it. One extra click is a cheap
 * price for never having accidentally written to every account on the platform.
 */
class CreateEmailCampaign extends CreateRecord
{
    protected static string $resource = EmailCampaignResource::class;

    protected static ?string $title = 'Compose email';

    /** @var array<int> */
    private array $recipientIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Not a column on email_campaigns — it becomes recipient rows below.
        $this->recipientIds = $data['recipient_ids'] ?? [];
        unset($data['recipient_ids']);

        $data['status']     = 'draft';
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        app(EmailCampaignService::class)->buildRecipients($this->record, $this->recipientIds);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Draft saved — nothing has been sent yet';
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Save and review'),
            $this->getCancelFormAction(),
        ];
    }
}
