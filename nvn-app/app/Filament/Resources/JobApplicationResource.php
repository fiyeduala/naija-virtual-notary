<?php

namespace App\Filament\Resources;

use App\Enums\ApplicationStage;
use App\Enums\RecruitmentRole;
use App\Exceptions\StageTransitionException;
use App\Filament\Resources\JobApplicationResource\Pages;
use App\Jobs\InviteSelectionToAssessment;
use App\Models\JobApplication;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Recruitment\ApplicationStageService;
use App\Services\Recruitment\AssessmentService;
use App\Support\CsvSafe;
use App\Support\RecruitmentPermission;
use App\Support\SourceAttribution;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
     * There are four, and they are separate on purpose, because they do
     * different things to a person: marking a pile as picked up tells nobody
     * anything, sending the exercise puts an email in somebody's inbox with a
     * deadline on it, moving a stage forward is an internal record, and
     * declining ends it. One "advance" button covering all of that would mean
     * a mixed selection quietly emailing half of it, so each button is named
     * after the thing it actually does and refuses the rows it does not cover.
     *
     * On bulk decline, which this class previously refused to offer: the
     * objection was that declining twenty people with one click is the kind of
     * automation the brief rules out. On reflection that conflated two things.
     * What the brief rules out is a machine deciding — a score, a rank, an
     * inference. A reviewer who has read twenty applications and decided
     * against them is not automation, and making them press the same button
     * twenty times does not make the decision any more considered. So it is
     * here, and what the brief asks for is kept where it belongs: a written
     * reason is required, every candidate gets their own stage event, their
     * own audit row and their own email, and nothing picks the selection but
     * the person who made it.
     *
     * Every one of these goes through ApplicationStageService, so the state
     * machine, the prerequisites, the row lock and the per-row permission
     * check all still apply to each candidate in the batch exactly as they do
     * to one on their own screen.
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
                    $stages = app(ApplicationStageService::class);
                    $actor  = RecruitmentPermission::current();

                    static::runBatch($records, 'marked as being reviewed', function (JobApplication $application) use ($stages, $actor) {
                        // Checked here rather than left to the service: moving
                        // to the stage somebody is already at is a deliberate
                        // no-op in move(), so without this they would be
                        // counted as moved and nobody would be any the wiser.
                        if ($application->stage !== ApplicationStage::Applied) {
                            throw new StageTransitionException('They were already past this point.');
                        }

                        $stages->beginReview($application, $actor);
                    });
                }),

            /*
             * The shortlist action, and the one the pile is actually worked
             * into: these are the applications that have been read and are
             * worth an exercise.
             *
             * It is its own button and not part of "move to the next stage"
             * because the assessment stage cannot be reached without an
             * exercise existing — the service refuses it, correctly, since a
             * candidate recorded as invited to something nobody sent has a
             * deadline they were never told about. Pressing this sends real
             * email, so it says so.
             */
            Tables\Actions\BulkAction::make('invite_assessment')
                ->label('Send the exercise to these')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn () => RecruitmentPermission::check(RecruitmentPermission::MOVE_STAGE))
                ->modalHeading('Send the exercise')
                ->modalDescription(fn (?Collection $records) => static::invitePreview($records))
                ->form([
                    Forms\Components\Radio::make('delay_working_days')
                        ->label('When should it go out?')
                        ->options([
                            0 => 'Now',
                            1 => 'In one working day',
                            2 => 'In two working days',
                            3 => 'In three working days',
                        ])
                        ->default(0)
                        ->required()
                        ->helperText('Working days in ' . config('recruitment.timezone') . ', skipping weekends and any configured holidays. Before each one sends, the stage, the deadline and whether they withdrew are all checked again.'),

                    Forms\Components\TextInput::make('due_working_days')
                        ->label('Working days they get to do it')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(20)
                        ->helperText('Leave blank for each vacancy\'s own setting, which is the usual answer when the selection spans more than one role.'),
                ])
                ->deselectRecordsAfterCompletion()
                ->action(function ($records, array $data) {
                    $assessments = app(AssessmentService::class);
                    $actor       = RecruitmentPermission::current();

                    $options = [
                        'delay_working_days' => (int) ($data['delay_working_days'] ?? 0),
                        'due_working_days'   => filled($data['due_working_days'] ?? null)
                            ? (int) $data['due_working_days']
                            : null,
                    ];

                    // Above a few pages, the work goes to the queue instead of
                    // finishing in this request. See InviteSelectionToAssessment
                    // for why: the per-candidate cost is a transaction, and a
                    // few thousand of them do not fit in one page load.
                    if (($records?->count() ?? 0) > InviteSelectionToAssessment::THRESHOLD) {
                        InviteSelectionToAssessment::dispatch(
                            $records->modelKeys(),
                            $options,
                            $actor?->getKey(),
                        );

                        Notification::make()
                            ->title(number_format($records->count()) . ' being worked through')
                            ->body('Too many to do on this screen, so it is running in the background. You can leave this page. '
                                . 'They are queued at ' . static::hourlyRate() . ', and you will get an alert when the last one is placed.')
                            ->status('success')
                            ->send();

                        return;
                    }

                    static::runBatch($records, 'queued for sending', function (JobApplication $application) use ($assessments, $actor, $options) {
                        // Mirrors the single-record action's own condition, so
                        // a mixed selection says "they are past this point"
                        // rather than reporting a state-machine error.
                        if (! in_array($application->stage, [ApplicationStage::Applied, ApplicationStage::InitialReview], true)) {
                            throw new StageTransitionException('They are past the point where an exercise is sent.');
                        }

                        $assessments->invite($application, $actor, $options);
                    });
                }),

            /*
             * One step along the pipeline, for the moves that are an internal
             * record and nothing else: interviewed, so on to the checks.
             *
             * It walks each candidate from where they actually are, so a
             * mixed selection works and nobody is moved anywhere they could
             * not have been moved one at a time. The two stages that assert
             * something about the world — invited, and submitted — refuse
             * themselves through the service's prerequisites and are reported
             * with the service's own wording.
             */
            Tables\Actions\BulkAction::make('advance')
                ->label('Move to the next stage')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('gray')
                ->visible(fn () => RecruitmentPermission::check(RecruitmentPermission::MOVE_STAGE))
                ->requiresConfirmation()
                ->modalHeading('Move these to the next stage')
                ->modalDescription(fn (?Collection $records) => static::advancePreview($records))
                ->deselectRecordsAfterCompletion()
                ->action(function ($records) {
                    $stages = app(ApplicationStageService::class);
                    $actor  = RecruitmentPermission::current();

                    static::runBatch($records, 'moved on', function (JobApplication $application) use ($stages, $actor) {
                        $next = $application->stage->next();

                        if ($next === null) {
                            throw new StageTransitionException($application->stage->isActive()
                                ? 'There is nothing after ' . $application->stage->label() . '.'
                                : 'This one is closed. Reopening it is a decision with a reason, taken on its own screen.');
                        }

                        if ($next === ApplicationStage::AssessmentInvited) {
                            throw new StageTransitionException(
                                'Use "Send the exercise to these" — the assessment stage needs an exercise actually sent.',
                            );
                        }

                        $stages->move($application, $next, $actor);
                    });
                }),

            /*
             * The end of it, for the ones who are not going forward.
             *
             * The reason is required and is kept internally: it is what a
             * colleague reads next year when the same person applies again,
             * and it is never in the email. The email itself is one per
             * candidate, addressed to them, and is on by default because the
             * alternative is leaving people waiting indefinitely.
             */
            Tables\Actions\BulkAction::make('decline')
                ->label('Decline these')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => RecruitmentPermission::check(RecruitmentPermission::MOVE_STAGE))
                ->modalHeading('Decline these applications')
                ->modalDescription(fn (?Collection $records) => ($records?->count() ?? 0)
                    . ' selected. Each is recorded as declined with the reason below, and anything still queued to be sent to them is called off. Anyone already closed is named and left alone.')
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Why')
                        ->rows(3)
                        ->required()
                        ->maxLength(1000)
                        ->helperText('Kept internally and recorded against every candidate in the selection. They are told they were not successful; this reasoning is not sent to them. If the selection needs more than one reason, it needs more than one pass.'),

                    Forms\Components\Toggle::make('notify')
                        ->label('Tell them')
                        ->default(true)
                        ->helperText('On, because leaving somebody waiting indefinitely is the worst version of this. One email each, to them. Turn it off only if you are writing to them yourself.'),
                ])
                ->deselectRecordsAfterCompletion()
                ->action(function ($records, array $data) {
                    $stages = app(ApplicationStageService::class);
                    $actor  = RecruitmentPermission::current();
                    $reason = (string) $data['reason'];
                    $notify = (bool) ($data['notify'] ?? true);

                    static::runBatch($records, 'declined', function (JobApplication $application) use ($stages, $actor, $reason, $notify) {
                        if (! $application->isActive()) {
                            throw new StageTransitionException('They were already closed.');
                        }

                        $stages->reject($application, $actor, $reason, $notify);
                    });
                }),
        ];
    }

    /**
     * What sending the exercise to this selection actually involves.
     *
     * The count and the deadline rule were always here. What is new is the
     * time: an admin pressing this on a filtered page of thirty is doing
     * something that finishes while they watch, and an admin pressing it on
     * four thousand is starting something that runs for days. Those are
     * different decisions, and the second one should not look like the first.
     *
     * The estimate is deliberately coarse — the real rate depends on how often
     * the host's cron fires — so it is given in whole hours or days and
     * described as "about". An estimate stated to the minute would be a
     * precision this cannot honour.
     *
     * @param  \Illuminate\Support\Collection<int, JobApplication>|null  $records
     */
    private static function invitePreview(?Collection $records): string
    {
        $count = $records?->count() ?? 0;

        $preview = number_format($count) . ' selected. Each one gets the exercise attached to their own vacancy, '
            . 'with their own deadline counted from when it actually goes out. Anyone whose vacancy has no exercise '
            . 'set, or who is past this point already, is named and left alone.';

        $perHour = static::perHour();

        if ($perHour <= 0 || $count <= $perHour) {
            return $preview;
        }

        $hours = (int) ceil($count / $perHour);

        $span = $hours < 24
            ? 'about ' . $hours . ' ' . str('hour')->plural($hours)
            : 'about ' . ($days = (int) ceil($hours / 24)) . ' ' . str('day')->plural($days);

        $preview .= ' At ' . static::hourlyRate() . ', the last of these reaches somebody in ' . $span
            . '. The deadline is counted from each person\'s own send, so nobody is shortchanged by waiting —'
            . ' but if that is longer than you intended, invite a smaller group first.';

        if ($count > InviteSelectionToAssessment::THRESHOLD) {
            $preview .= ' This is too many to do on this screen, so it runs in the background and alerts you when it is done.';
        }

        return $preview;
    }

    /** The configured ceiling on paceable mail, or 0 when there is none. */
    private static function perHour(): int
    {
        return max(0, (int) config('recruitment.outbox.per_hour', 0));
    }

    /** The sending rate in words, for a screen rather than a config file. */
    private static function hourlyRate(): string
    {
        $perHour = static::perHour();

        return $perHour > 0
            ? 'up to ' . number_format($perHour) . ' an hour'
            : 'no configured rate limit';
    }

    /**
     * What "the next stage" means for this particular selection.
     *
     * Shown before the move, grouped, so somebody who has selected a whole
     * filtered page can see that it is about to send four people to the
     * checks and leave two where they are — which is the question you have
     * when you press a button on a list rather than on a person.
     *
     * @param  \Illuminate\Support\Collection<int, JobApplication>  $records
     */
    private static function advancePreview(?Collection $records): string
    {
        $counts = [];

        foreach ($records ?? collect() as $application) {
            $next = $application->stage->next();

            $key = $next === null || $next === ApplicationStage::AssessmentInvited
                ? 'left where they are'
                : 'to ' . $next->label();

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $parts = [];

        foreach ($counts as $label => $count) {
            $parts[] = $count . ' ' . $label;
        }

        return ($records?->count() ?? 0) . ' selected: ' . implode(', ', $parts)
            . '. Nothing is sent to anybody. Each one is checked on its own, and whoever cannot move is named afterwards.';
    }

    /**
     * Run a bulk action one candidate at a time, and say what happened.
     *
     * The loop is per candidate and so is the failure: one who cannot move
     * must not stop the rest, and must not vanish either. Refusals are
     * grouped by the reason the service gave, because "four of them have not
     * submitted the exercise" is the useful form of that information and a
     * flat list of twelve references is not.
     *
     * @param  iterable<JobApplication>  $records
     * @param  callable(JobApplication): void  $work
     */
    private static function runBatch(iterable $records, string $done, callable $work): void
    {
        $count   = 0;
        $skipped = [];

        foreach ($records as $application) {
            try {
                $work($application);
                $count++;
            } catch (\Throwable $e) {
                // Including the unexpected: a batch that dies halfway leaves
                // the desk with no idea which half it got through.
                $skipped[$e->getMessage()][] = $application->reference;
            }
        }

        $groups = [];

        foreach ($skipped as $reason => $references) {
            $shown = array_slice($references, 0, 8);
            $rest  = count($references) - count($shown);

            $groups[] = $reason . ' (' . implode(', ', $shown)
                . ($rest > 0 ? ' and ' . $rest . ' more' : '') . ')';
        }

        Notification::make()
            ->title($count . ' ' . $done)
            ->body($groups === [] ? null : 'Left alone: ' . implode(' ', $groups))
            ->status($count > 0 ? 'success' : 'warning')
            ->send();
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
