<?php

namespace App\Filament\Resources\AssessmentTemplateResource\Pages;

use App\Filament\Resources\AssessmentTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAssessmentTemplates extends ListRecords
{
    protected static string $resource = AssessmentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Write a new exercise')];
    }

    public function getSubheading(): ?string
    {
        return 'An exercise is snapshotted onto each invitation, so editing one here never changes what somebody is part-way through.';
    }
}
