<?php

namespace App\Filament\Resources\AssessmentTemplateResource\Pages;

use App\Filament\Resources\AssessmentTemplateResource;
use App\Support\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateAssessmentTemplate extends CreateRecord
{
    protected static string $resource = AssessmentTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['version']    = 1;
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        AuditLogger::record('assessment_template.created', 'assessment_template', $this->record->id, [
            'name' => $this->record->name,
            'role' => $this->record->role_key?->value,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
