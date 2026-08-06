<?php

namespace App\Filament\Resources\EmailCampaignResource\Pages;

use App\Filament\Resources\EmailCampaignResource;
use App\Models\EmailCampaign;
use App\Services\EmailCampaignService;
use Filament\Resources\Pages\EditRecord;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Drafts only. Once a campaign has been queued its wording is part of the
 * record of what people were actually told, and editing it after the fact
 * would make the ledger a lie.
 */
class EditEmailCampaign extends EditRecord
{
    protected static string $resource = EmailCampaignResource::class;

    protected static ?string $title = 'Edit draft';

    /** @var array<int> */
    private array $recipientIds = [];

    public function mount(int | string $record): void
    {
        parent::mount($record);

        if (! $this->record instanceof EmailCampaign || ! $this->record->isDraft()) {
            throw new NotFoundHttpException('Only a draft can be edited.');
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['recipient_ids'] = $this->record->recipients()->pluck('user_id')->filter()->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->recipientIds = $data['recipient_ids'] ?? [];
        unset($data['recipient_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        // Nothing has gone out yet, so the frozen list can simply be rebuilt
        // from the (possibly changed) audience.
        $this->record->recipients()->delete();

        app(EmailCampaignService::class)->buildRecipients($this->record, $this->recipientIds);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
