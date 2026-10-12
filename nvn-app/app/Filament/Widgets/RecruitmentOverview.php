<?php

namespace App\Filament\Widgets;

use App\Enums\ApplicationStage;
use App\Filament\Resources\JobApplicationResource;
use App\Models\AssessmentAssignment;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\RecruitmentMessage;
use App\Models\Vacancy;
use App\Support\RecruitmentPermission;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four recruitment numbers worth interrupting somebody for.
 *
 * Every count is bounded by JobApplication::scopeVisibleTo(), the same scope
 * the applications table uses — so a reviewer assigned to one vacancy sees
 * their own backlog here, not the platform's. A widget that quietly counted
 * across every opening would leak how many people applied for the roles
 * somebody was deliberately not put on.
 *
 * It appears for anybody holding a recruitment ability, unlike the other
 * widgets on this dashboard, which are admin-only: these figures ARE a
 * reviewer's business, and not one of them is money.
 *
 * Nothing here ranks or scores anybody. Four counts and a link.
 */
class RecruitmentOverview extends BaseWidget
{
    protected static ?int $sort = 7;

    public static function canView(): bool
    {
        return RecruitmentPermission::allowsAny(RecruitmentPermission::current());
    }

    protected function getStats(): array
    {
        $user = RecruitmentPermission::current();

        /** A reusable bound set of ids, so each count below asks the same question. */
        $mine = fn () => JobApplication::query()->visibleTo($user)->select('job_applications.id');

        $waiting = JobApplication::query()
            ->visibleTo($user)
            ->whereIn('stage', [ApplicationStage::Applied->value, ApplicationStage::InitialReview->value])
            ->count();

        $openings = Vacancy::query()->listed()->count();

        // Overdue means a live assignment whose deadline has passed with
        // nothing submitted — not "the status column says overdue", because
        // that column is only written when the nightly sweep runs, and a
        // deadline that passed an hour ago is already somebody's problem.
        $overdue = AssessmentAssignment::query()
            ->whereNull('cancelled_at')
            ->whereNull('submitted_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereIn('job_application_id', $mine())
            ->whereHas('application', fn ($query) => $query->active())
            ->count();

        $interviews = Interview::query()
            ->whereIn('status', [Interview::SCHEDULED, Interview::RESCHEDULED])
            ->whereBetween('scheduled_at', [now(), now()->addDays(7)])
            ->whereIn('job_application_id', $mine())
            ->count();

        // A failed email is an operational fault rather than a candidate fact,
        // so it is counted for whoever can see every application and reported
        // as nothing to the reviewer who could not act on it anyway.
        $failed = RecruitmentPermission::check(RecruitmentPermission::REVIEW_ALL)
            ? RecruitmentMessage::query()->where('status', RecruitmentMessage::FAILED)->count()
            : 0;

        return [
            Stat::make('Waiting to be read', number_format($waiting))
                ->description($openings . ' ' . str('opening')->plural($openings) . ' currently listed')
                ->descriptionIcon('heroicon-m-inbox')
                ->color($waiting > 0 ? 'warning' : 'gray')
                ->url(JobApplicationResource::getUrl('index')),

            Stat::make('Assessments overdue', number_format($overdue))
                ->description($overdue > 0
                    ? 'A deadline has passed with nothing submitted'
                    : 'Nothing is past its deadline')
                ->descriptionIcon('heroicon-m-clock')
                ->color($overdue > 0 ? 'danger' : 'gray'),

            Stat::make('Interviews this week', number_format($interviews))
                ->description('Scheduled in the next seven days')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($interviews > 0 ? 'info' : 'gray'),

            Stat::make('Emails that failed', number_format($failed))
                ->description($failed > 0 ? 'Somebody was not told something' : 'Nothing has failed')
                ->descriptionIcon('heroicon-m-envelope')
                ->color($failed > 0 ? 'danger' : 'gray'),
        ];
    }
}
