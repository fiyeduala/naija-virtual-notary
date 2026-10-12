<?php

namespace App\Filament\Resources\VacancyResource\Pages;

use App\Filament\Resources\VacancyResource;
use App\Support\AuditLogger;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing an opening.
 *
 * `state` is not on this form and is not writable from it. Publishing,
 * pausing, closing and archiving are the actions on the list, each of which
 * checks its own precondition and records who did it. Letting the state be
 * typed into a form would route around all of that.
 *
 * Editing a published advert is allowed and deliberately so — a typo in the
 * working hours should be fixable in ten seconds. What it does NOT change is
 * anything already in flight: the assessment somebody is halfway through was
 * snapshotted when it was sent, so rewriting the template here cannot move
 * the goalposts under them.
 */
class EditVacancy extends EditRecord
{
    protected static string $resource = VacancyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('Preview the advert')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn () => route('careers.show', [
                    'vacancy' => $this->record->slug,
                    'preview' => 1,
                ]))
                ->openUrlInNewTab(),
        ];
    }

    protected function afterSave(): void
    {
        AuditLogger::record('vacancy.updated', 'vacancy', $this->record->id, [
            'title' => $this->record->title,
            'state' => $this->record->state?->value,
        ]);
    }
}
