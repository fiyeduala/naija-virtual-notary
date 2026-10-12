<?php

namespace App\Filament\Resources\AssessmentTemplateResource\Pages;

use App\Filament\Resources\AssessmentTemplateResource;
use App\Support\AuditLogger;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing an exercise.
 *
 * `version` is bumped here, by this class, whenever the substance changes —
 * the brief, the tasks, the rubric or the scale. Not on a rename, and not on
 * a change to the internal notes, because a version number that goes up for
 * a typo in a private comment tells nobody anything.
 *
 * Why it matters: every assignment stores `template_version` beside its
 * snapshot. When somebody asks in three months' time what exactly this
 * candidate was asked to do, the answer is the snapshot, and the version is
 * how two snapshots are told apart. Nothing in flight is touched either way —
 * the snapshot was taken when the invitation was sent.
 */
class EditAssessmentTemplate extends EditRecord
{
    protected static string $resource = AssessmentTemplateResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $substantive = ['brief', 'tasks', 'rubric', 'score_scale_max'];

        $changed = collect($substantive)->contains(
            fn (string $key) => json_encode($data[$key] ?? null) !== json_encode($this->record->getOriginal($key)),
        );

        if ($changed) {
            $data['version'] = (int) $this->record->version + 1;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // The old wording is NOT written into the audit metadata. The audit
        // log says that the exercise changed and to which version; the wording
        // itself lives on the snapshots of the assignments that used it, which
        // is where it is actually needed.
        AuditLogger::record('assessment_template.updated', 'assessment_template', $this->record->id, [
            'version' => (int) $this->record->version,
        ]);
    }

    public function getSubheading(): ?string
    {
        return 'Version ' . $this->record->version . '. Changing the brief, the tasks, the rubric or the scale moves it to the next version; exercises already sent keep the wording they were sent with.';
    }
}
