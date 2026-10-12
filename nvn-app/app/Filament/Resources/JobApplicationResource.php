<?php

namespace App\Filament\Resources;

use App\Enums\ApplicationStage;
use App\Enums\RecruitmentRole;
use App\Filament\Resources\JobApplicationResource\Pages;
use App\Models\JobApplication;
use App\Models\User;
use App\Models\Vacancy;
use App\Support\CsvSafe;
use App\Support\RecruitmentPermission;
use App\Support\SourceAttribution;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Response;

/**
 * The pipeline.
 *
 * Three rules shape this class, and all three are enforced in the query or the
 * service rather than in the markup:
 *
 *   - A reviewer sees the applications on the vacancies they were assigned to.
 *     That is `getEloquentQuery()`, so it also governs sorting, counting,
 *     searching and the navigation badge. A row a reviewer may not see does
 *     not exist as far as this screen is concerned.
 *   - Nothing moves a candidate forward on its own. Every stage action calls
 *     ApplicationStageService, which locks the row, re-checks the transition,
 *     demands a reason where one is owed and writes the stage event. There is
 *     no `update(['stage' => ...])` anywhere in this file.
 *   - Nothing here scores, ranks or sorts a candidate by anything a machine
 *     inferred. The only ordering offered is the date they applied and the
 *     stage they are at.
 *
 * Deliberately absent: a delete action. An application is removed through
 * RetentionService, which also deals with the files, the messages and the
 * audit trail, and which refuses to run until the owner has set a retention
 * policy.
 */
class JobApplicationResource extends Resource
{
    protected static ?string $model = JobApplication::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Recruitment';
    protected static ?string $navigationLabel = 'Applications';
    protected static ?string $modelLabel = 'application';
    protected static ?int $navigationSort = 2;
    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        return RecruitmentPermission::check(RecruitmentPermission::REVIEW);
    }

    public static function canCreate(): bool
    {
        // An application is something a person submits. Nobody types one in on
        // their behalf: the consent, the privacy version and the source would
        // all be fabricated, and the record would claim things that never
        // happened.
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * The scoping rule, applied once.
     *
     * RecruitmentPermission::canReview() is the per-record version of this and
     * is what the detail page and every service call use. This is the set
     * version, because a list screen needs it as SQL.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['vacancy', 'candidate']);

        if (RecruitmentPermission::check(RecruitmentPermission::REVIEW_ALL)) {
            return $query;
        }

        $user = RecruitmentPermission::current();

        return $query->whereHas(
            'vacancy.reviewers',
            fn (Builder $inner) => $inner->whereKey($user?->id),
        );
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()
            ->whereIn('stage', [ApplicationStage::Applied->value, ApplicationStage::InitialReview->value])
            ->count() ?: null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting to be looked at';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Reference copied')
                    ->weight('medium'),

                // Through the relation, not through the accessor: the name
                // lives on the candidates row, and searching or sorting has
                // to be SQL the database can actually run.
                Tables\Columns\TextColumn::make('candidate.full_name')
                    ->label('Applicant')
                    ->searchable()
                    ->sortable()
                    ->description(fn (JobApplication $record) => $record->email),

                Tables\Columns\TextColumn::make('vacancy.title')
                    ->label('Role')
                    ->sortable()
                    ->description(fn (JobApplication $record) => $record->vacancy?->departmentLabel()),

                Tables\Columns\TextColumn::make('stage')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof ApplicationStage
                        ? $state->label()
                        : (string) $state)
                    ->color(fn ($state) => $state instanceof ApplicationStage ? $state->colour() : 'gray')
                    ->sortable(),

                // Shown because it is the single most useful thing on the row:
                // an exercise that went out four days ago and has not come
                // back is the one that needs a decision today.
                Tables\Columns\TextColumn::make('due')
                    ->label('Exercise due')
                    ->state(fn (JobApplication $record) => $record->currentAssignment()?->dueForHumans())
                    ->placeholder('—')
                    ->color(fn (JobApplication $record) => $record->currentAssignment()?->isOverdue()
                        ? 'danger'
                        : null),

                Tables\Columns\TextColumn::make('applied_at')
                    ->label('Applied')
                    ->dateTime('j M Y')
                    ->timezone(config('recruitment.timezone'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('source_channel')
                    ->label('Source')
                    ->formatStateUsing(fn (JobApplication $record) => $record->sourceLabel())
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('candidate.country')
                    ->label('Country')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters(static::pipelineFilters())
            ->actions([
                Tables\Actions\ViewAction::make()->label('Open'),
            ])
            ->bulkActions(static::bulkActions())
            ->headerActions(static::exportActions())
            ->defaultSort('applied_at', 'desc')
            ->persistFiltersInSession()
            ->emptyStateHeading('No applications yet')
            ->emptyStateDescription('They appear here as soon as somebody applies through /careers.');
    }

    /**
     * The filters the desk actually uses.
     *
     * Role, stage, date, source and reviewer — the five things somebody asks
     * for when they sit down to work through a pile.
     *
     * @return array<\Filament\Tables\Filters\BaseFilter>
     */
    public static function pipelineFilters(): array
    {
        return [
            Tables\Filters\SelectFilter::make('stage')
                ->label('Stage')
                ->multiple()
                ->options(fn () => collect(ApplicationStage::cases())
                    ->mapWithKeys(fn (ApplicationStage $case) => [$case->value => $case->label()])
                    ->all()),

            Tables\Filters\SelectFilter::make('vacancy_id')
                ->label('Role')
                ->relationship('vacancy', 'title')
                ->searchable()
                ->preload(),

            Tables\Filters\SelectFilter::make('role_key')
                ->label('Category')
                ->options(RecruitmentRole::options())
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->whereHas('vacancy', fn (Builder $inner) => $inner->where('role_key', $data['value']))
                    : $query),

            Tables\Filters\SelectFilter::make('source_channel')
                ->label('Source')
                ->options(SourceAttribution::options()),

            // Only offered to someone who can see the whole pipeline: for a
            // plain reviewer the query is already their own assignments, and
            // the filter would be a list of colleagues' names for no purpose.
            Tables\Filters\SelectFilter::make('reviewer')
                ->label('Assigned reviewer')
                ->options(fn () => User::query()
                    ->whereNotNull('recruitment_role')
                    ->orderBy('full_name')
                    ->pluck('full_name', 'id')
                    ->all())
                ->visible(fn () => RecruitmentPermission::check(RecruitmentPermission::REVIEW_ALL))
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->whereHas(
                        'vacancy.reviewers',
                        fn (Builder $inner) => $inner->whereKey($data['value']),
                    )
                    : $query),

            Tables\Filters\Filter::make('applied_between')
                ->form([
                    Forms\Components\DatePicker::make('from')->label('Applied from'),
                    Forms\Components\DatePicker::make('until')->label('Applied until'),
                ])
                ->query(function (Builder $query, array $data) {
                    return $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('applied_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('applied_at', '<=', $date));
                }),

            Tables\Filters\Filter::make('overdue')
                ->label('Exercise overdue')
                ->toggle()
                ->query(fn (Builder $query) => $query->whereHas(
                    'assignments',
                    fn (Builder $inner) => $inner
                        ->whereNull('cancelled_at')
                        ->whereNull('submitted_at')
                        ->whereNotNull('due_at')
                        ->where('due_at', '<', now()),
                )),

            // Not on by default: the stage tabs already answer this, and a
            // filter switched on behind them would leave the Declined and
            // Withdrawn tabs permanently empty with no visible cause.
            Tables\Filters\Filter::make('open_only')
                ->label('Still in the running')
                ->toggle()
                ->query(fn (Builder $query) => $query->active()),
        ];
    }

    /**
     * Bulk actions.
     *
     * Each one previews what it is about to do, confirms, then validates every
     * candidate individually and reports what actually happened. A bulk action
     * that silently skips half a selection is worse than one that refuses.
     *
     * Note what is NOT here: bulk reject. Declining twenty people with one
     * click and one reason is exactly the automation the brief rules out, and
     * the per-record Decline action asks for a reason each time for that
     * reason. Closing a vacancy is where a batch decline lives, and there it
     * is an explicit, named choice.
     *
     * @return array<\Filament\Tables\Actions\BulkAction>
     */
    public static function bulkActions(): array
    {
        return [
            Tables\Actions\BulkAction::make('begin_review')
                ->label('Mark as being reviewed')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn () => RecruitmentPermission::check(RecruitmentPermission::MOVE_STAGE))
                ->requiresConfirmation()
                ->modalHeading('Mark these as being reviewed')
                ->modalDescription('This only records that somebody has picked them up. No candidate is told anything and nothing is sent.')
                ->deselectRecordsAfterCompletion()
                ->action(function ($records) {
                    $stages = app(\App\Services\Recruitment\ApplicationStageService::class);
                    $actor  = RecruitmentPermission::current();

                    $moved = 0;
                    $left  = [];

                    foreach ($records as $application) {
                        // Per candidate, not per selection: one that cannot
                        // move must not stop the rest, and must be named.
                        if ($application->stage !== ApplicationStage::Applied) {
                            $left[] = $application->reference;
                            continue;
                        }

                        try {
                            $stages->beginReview($application, $actor);
                            $moved++;
                        } catch (\Throwable $e) {
                            $left[] = $application->reference;
                        }
                    }

                    Notification::make()
                        ->title($moved . ' marked as being reviewed')
                        ->body($left === []
                            ? null
                            : 'Left alone: ' . implode(', ', array_slice($left, 0, 10))
                                . (count($left) > 10 ? ' and ' . (count($left) - 10) . ' more' : '')
                                . '. They were already past this point.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * The export.
     *
     * Three things about it, all of which the brief asks for explicitly:
     *
     *   - It is behind RecruitmentPermission::EXPORT, which an ordinary
     *     reviewer does not hold. The button is hidden AND the closure checks
     *     again, because a hidden button is not a permission check.
     *   - Every cell goes through CsvSafe, so a motivation statement beginning
     *     `=HYPERLINK(...)` is text in the spreadsheet and not code.
     *   - It carries no identity document, no guarantor detail, no reviewer
     *     score and no internal note. An export is the easiest thing in any
     *     system to email to the wrong person, so the sensitive columns are
     *     not in it to be leaked. They are readable one at a time, by a named
     *     person, on the record itself.
     *
     * It exports the filtered set, not the whole table — what is on screen is
     * what comes out, which is also what the person pressing it expects.
     *
     * @return array<\Filament\Tables\Actions\Action>
     */
    public static function exportActions(): array
    {
        return [
            Tables\Actions\Action::make('export')
                ->label('Export to CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => RecruitmentPermission::check(RecruitmentPermission::EXPORT))
                ->requiresConfirmation()
                ->modalHeading('Export the applications shown')
                ->modalDescription('The filters you have on now decide what comes out. The file holds contact details and answers; it holds no identity document, no guarantor detail and no reviewer score or note.')
                ->modalSubmitActionLabel('Download')
                ->action(function ($livewire) {
                    abort_unless(RecruitmentPermission::check(RecruitmentPermission::EXPORT), 403);

                    $rows = $livewire->getFilteredTableQuery()
                        ->with(['vacancy'])
                        ->orderBy('id')
                        ->get()
                        ->map(fn (JobApplication $a) => [
                            $a->reference,
                            $a->applied_at?->timezone(config('recruitment.timezone')),
                            $a->vacancy?->title,
                            $a->vacancy?->roleLabel(),
                            $a->stage?->label(),
                            $a->full_name,
                            $a->email,
                            $a->phone,
                            $a->country,
                            $a->city,
                            $a->timezone,
                            $a->expectedSalaryLine(),
                            $a->availability_start?->format('Y-m-d') ?: $a->notice_period,
                            $a->available_during_hours,
                            $a->sourceLabel(),
                            $a->heard_about,
                            $a->linkedin_url,
                            $a->portfolio_url,
                            $a->currentAssignment()?->submitted_at,
                            $a->hired_at,
                        ]);

                    $csv = CsvSafe::build([
                        'Reference', 'Applied', 'Role', 'Category', 'Stage',
                        'Name', 'Email', 'Phone', 'Country', 'City', 'Time zone',
                        'Expected pay', 'Available from', 'Available during hours',
                        'Source', 'Heard about us', 'LinkedIn', 'Portfolio',
                        'Exercise submitted', 'Hired',
                    ], $rows);

                    \App\Support\AuditLogger::record('recruitment.exported', 'job_application', null, [
                        'rows' => $rows->count(),
                    ]);

                    return Response::streamDownload(
                        fn () => print($csv),
                        CsvSafe::filename('recruitment-applications'),
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJobApplications::route('/'),
            'view'  => Pages\ViewApplication::route('/{record}'),
        ];
    }
}
