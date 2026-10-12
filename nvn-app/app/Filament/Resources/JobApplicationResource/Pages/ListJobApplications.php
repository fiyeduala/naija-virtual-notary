<?php

namespace App\Filament\Resources\JobApplicationResource\Pages;

use App\Enums\ApplicationStage;
use App\Filament\Resources\JobApplicationResource;
use App\Support\RecruitmentPermission;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The pipeline, as tabs.
 *
 * This is the "usable pipeline view if straightforward" the brief asks for,
 * and tabs are what straightforward looks like in this panel: one click per
 * stage, the counts visible without opening anything, and the same filters,
 * search and permissions as the plain list because it is the same table.
 *
 * A board with drag-and-drop columns was the alternative. It would have needed
 * its own component, its own authorization path and its own transition code —
 * and dropping a card is exactly the kind of accidental, reason-less stage
 * change ApplicationStageService exists to refuse.
 */
class ListJobApplications extends ListRecords
{
    protected static string $resource = JobApplicationResource::class;

    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('Everyone')
                ->badge(fn () => $this->stageCount(null)),
        ];

        foreach (ApplicationStage::cases() as $stage) {
            $tabs[$stage->value] = Tab::make($stage->label())
                ->badge(fn () => $this->stageCount($stage))
                ->badgeColor($stage->colour())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('stage', $stage->value));
        }

        return $tabs;
    }

    /** Counted off the resource's own scoped query, so a reviewer's counts are their own. */
    private function stageCount(?ApplicationStage $stage): ?string
    {
        $query = JobApplicationResource::getEloquentQuery();

        if ($stage) {
            $query->where('stage', $stage->value);
        }

        return (string) $query->count() ?: null;
    }

    public function getSubheading(): ?string
    {
        return RecruitmentPermission::check(RecruitmentPermission::REVIEW_ALL)
            ? null
            : 'You are seeing the applications on the vacancies you are assigned to.';
    }
}
