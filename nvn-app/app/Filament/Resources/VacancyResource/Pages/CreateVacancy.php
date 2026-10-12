<?php

namespace App\Filament\Resources\VacancyResource\Pages;

use App\Enums\VacancyState;
use App\Filament\Resources\VacancyResource;
use App\Support\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

/**
 * A new opening is always a draft.
 *
 * The state is forced here rather than defaulted on the form, because a
 * default is something a stray payload can overwrite and "published by
 * accident on first save" is the one outcome this module must not have. Going
 * live is the Publish action on the list, which checks the record is complete
 * first.
 */
class CreateVacancy extends CreateRecord
{
    protected static string $resource = VacancyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['state']        = VacancyState::Draft;
        $data['published_at'] = null;
        $data['closed_at']    = null;
        $data['created_by']   = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        AuditLogger::record('vacancy.created', 'vacancy', $this->record->id, [
            'title' => $this->record->title,
            'role'  => $this->record->role_key?->value,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
