<?php

namespace App\Filament\Resources\JobApplicationResource\Pages;

use App\Enums\ApplicationStage;
use App\Exceptions\StageTransitionException;
use App\Filament\Resources\JobApplicationResource;
use App\Models\ApplicationDocument;
use App\Models\JobApplication;
use App\Models\Offer;
use App\Models\OnboardingItem;
use App\Models\ReviewerReview;
use App\Models\User;
use App\Services\Recruitment\ApplicationStageService;
use App\Services\Recruitment\AssessmentService;
use App\Services\Recruitment\InterviewService;
use App\Services\Recruitment\OfferService;
use App\Services\Recruitment\RecruitmentDocumentService;
use App\Services\Recruitment\RecruitmentOutbox;
use App\Support\RecruitmentPermission;
use App\Support\RecruitmentTemplates;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * One application, everything about it, on one page.
 *
 * The brief asks for a single detail page carrying the answers, the CV, the
 * assessments, the scores, the interviews, the private notes, the
 * communications and the stage history — so that is what this is, rather than
 * eight tabs that each hide something somebody needed.
 *
 * Two boundaries are enforced here and nowhere else would do:
 *
 *   - `canView()` asks RecruitmentPermission::canReview() about THIS record. A
 *     reviewer who types the id of an application on somebody else's vacancy
 *     gets a 403, not a page. The list query scopes the set; this scopes the
 *     record.
 *   - Identity and guarantor documents are behind VIEW_SENSITIVE, checked per
 *     document by ApplicationDocument::readableBy(). An ordinary reviewer sees
 *     that an item was received and whether somebody has checked it. They do
 *     not see the file and cannot download it.
 *
 * Every action on this page goes through a service. There is no state written
 * directly from a form anywhere in it.
 */
class ViewApplication extends ViewRecord
{
    protected static string $resource = JobApplicationResource::class;

    protected static string $view = 'filament.recruitment.application';

    public function mount(int | string $record): void
    {
        parent::mount($record);

        abort_unless(
            RecruitmentPermission::canReview(RecruitmentPermission::current(), $this->record),
            403,
        );
    }

    public function getTitle(): string
    {
        return $this->record->full_name . ' — ' . $this->record->reference;
    }

    public function getSubheading(): ?string
    {
        return $this->record->vacancy?->title
            . ' · applied ' . $this->record->applied_at
                ?->timezone(config('recruitment.timezone'))
                ->format('j M Y');
    }

    /**
     * Everything the view renders, resolved once.
     *
     * Note `documents`: filtered through readableBy() HERE, so the view is
     * handed a collection it is allowed to render in full rather than a
     * collection plus a rule it has to remember to apply.
     */
    protected function getViewData(): array
    {
        $user = RecruitmentPermission::current();

        $this->record->load([
            'vacancy.reviewers',
            'candidate',
            'answers',
            'assignments' => fn ($q) => $q->orderByDesc('id'),
            'scores.reviewer',
            'reviews.reviewer',
            'interviews.interviewer',
            'offers.approver',
            'onboardingItems.verifier',
            'referenceContacts',
            'notes.author',
            'messages',
            'stageEvents.actor',
        ]);

        return [
            'application' => $this->record,
            'stages'      => app(ApplicationStageService::class),
            'canSensitive' => RecruitmentPermission::allows($user, RecruitmentPermission::VIEW_SENSITIVE),
            'documents'   => $this->record->documents()
                ->orderByDesc('id')
                ->get()
                ->filter(fn (ApplicationDocument $d) => $d->readableBy($user)),
            'hiddenDocuments' => $this->record->documents()
                ->whereIn('kind', ApplicationDocument::ALWAYS_SENSITIVE)
                ->count(),
            'zone' => config('recruitment.timezone'),
        ];
    }

    /**
     * @return array<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                $this->beginReviewAction(),
                $this->inviteAssessmentAction(),
                $this->extendDeadlineAction(),
                $this->reopenAssessmentAction(),
            ])->label('Assessment')->icon('heroicon-o-clipboard-document-check')->button()->color('gray'),

            Actions\ActionGroup::make([
                $this->scheduleInterviewAction(),
                $this->rescheduleInterviewAction(),
                $this->interviewFeedbackAction(),
            ])->label('Interview')->icon('heroicon-o-calendar-days')->button()->color('gray'),

            Actions\ActionGroup::make([
                $this->prepareOfferAction(),
                $this->approveOfferAction(),
                $this->sendOfferAction(),
                $this->buildChecklistAction(),
            ])->label('Offer')->icon('heroicon-o-document-text')->button()->color('gray'),

            $this->scoreAction(),
            $this->noteAction(),

            Actions\ActionGroup::make([
                $this->moveStageAction(),
                $this->hireAction(),
                $this->declineAction(),
            ])->label('Decision')->icon('heroicon-o-check-badge')->button()->color('primary'),
        ];
    }

    /* ---- helpers --------------------------------------------------------- */

    private function actor(): ?User
    {
        return RecruitmentPermission::current();
    }

    /** Every service call lands here, so the error a candidate never sees is the one staff do. */
    private function run(callable $work, string $success): void
    {
        try {
            $work();
        } catch (StageTransitionException | \RuntimeException $e) {
            Notification::make()
                ->title('Not done')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->record->refresh();

        Notification::make()->title($success)->success()->send();
    }

    private function can(string $ability): bool
    {
        return RecruitmentPermission::allows($this->actor(), $ability);
    }

    /* ---- assessment ------------------------------------------------------ */

    private function beginReviewAction(): Actions\Action
    {
        return Actions\Action::make('begin_review')
            ->label('Start reviewing')
            ->icon('heroicon-o-eye')
            ->visible(fn () => $this->can(RecruitmentPermission::MOVE_STAGE)
                && $this->record->stage === ApplicationStage::Applied)
            ->requiresConfirmation()
            ->modalDescription('Records that somebody has picked this up. Nothing is sent to the candidate.')
            ->action(fn () => $this->run(
                fn () => app(ApplicationStageService::class)->beginReview($this->record, $this->actor()),
                'Marked as being reviewed',
            ));
    }

    /**
     * The invitation.
     *
     * The default is immediate, and immediate means "as soon as this form is
     * submitted" — which is already after a person decided to submit it. The
     * delay options are whole working days, chosen from a list, never
     * randomised: a candidate who gets their exercise at 03:14 on a Sunday
     * learns something true and unflattering about the employer.
     */
    private function inviteAssessmentAction(): Actions\Action
    {
        return Actions\Action::make('invite_assessment')
            ->label('Send the exercise')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn () => $this->can(RecruitmentPermission::MOVE_STAGE)
                && $this->record->isActive()
                && in_array($this->record->stage, [
                    ApplicationStage::Applied,
                    ApplicationStage::InitialReview,
                ], true))
            ->form([
                Forms\Components\Placeholder::make('which')
                    ->label('Which exercise')
                    ->content(fn () => $this->record->vacancy?->template?->name
                        ?? 'None attached to this vacancy — set one on the vacancy first.'),

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
                    ->helperText('Working days in ' . config('recruitment.timezone') . ', skipping weekends and any configured holidays. Before it sends, the stage, the deadline and whether they withdrew are all checked again.'),

                Forms\Components\TextInput::make('due_working_days')
                    ->label('Working days they get to do it')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(20)
                    ->placeholder((string) ($this->record->vacancy?->assessmentDueWorkingDays() ?? 3))
                    ->helperText('Leave blank for the vacancy\'s own setting.'),
            ])
            ->action(fn (array $data) => $this->run(
                fn () => app(AssessmentService::class)->invite($this->record, $this->actor(), [
                    'delay_working_days' => (int) ($data['delay_working_days'] ?? 0),
                    'due_working_days'   => filled($data['due_working_days'] ?? null)
                        ? (int) $data['due_working_days']
                        : null,
                ]),
                'The exercise is queued for sending',
            ));
    }

    private function extendDeadlineAction(): Actions\Action
    {
        return Actions\Action::make('extend_deadline')
            ->label('Give them longer')
            ->icon('heroicon-o-clock')
            // isOpen(), not acceptsChanges(). An overdue assignment accepts no
            // changes from the candidate — that is the point of the deadline —
            // and gating this on the same question hid the button in the one
            // situation it exists for: somebody who missed the date and has a
            // reason. Giving her longer is what reopens the form.
            ->visible(fn () => $this->can(RecruitmentPermission::MOVE_STAGE)
                && $this->record->currentAssignment()?->isOpen())
            ->form([
                Forms\Components\TextInput::make('days')
                    ->label('Extra working days')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(20)
                    ->default(2)
                    ->required(),

                Forms\Components\Textarea::make('reason')
                    ->label('Why')
                    ->rows(2)
                    ->required()
                    ->maxLength(500)
                    ->helperText('Kept with the extension. Every change to a deadline is on the record with who made it.'),
            ])
            ->action(fn (array $data) => $this->run(
                fn () => app(AssessmentService::class)->extend(
                    $this->record->currentAssignment(),
                    $this->actor(),
                    (int) $data['days'],
                    (string) $data['reason'],
                ),
                'The deadline has moved and the candidate has been told',
            ));
    }

    /**
     * Reopening.
     *
     * The only way a finalised submission becomes editable again. The earlier
     * answers are kept as history rather than overwritten, so what was sent
     * first is still readable afterwards.
     */
    private function reopenAssessmentAction(): Actions\Action
    {
        return Actions\Action::make('reopen_assessment')
            ->label('Let them submit again')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn () => $this->can(RecruitmentPermission::MOVE_STAGE)
                && $this->record->currentAssignment()?->isSubmitted())
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Why are you reopening this?')
                    ->rows(2)
                    ->required()
                    ->maxLength(500),

                Forms\Components\TextInput::make('extra_days')
                    ->label('Working days to allow')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(20)
                    ->default(2),
            ])
            ->action(fn (array $data) => $this->run(
                fn () => app(AssessmentService::class)->reopen(
                    $this->record->currentAssignment(),
                    $this->actor(),
                    (string) $data['reason'],
                    filled($data['extra_days'] ?? null) ? (int) $data['extra_days'] : null,
                ),
                'Reopened. What they sent before is kept.',
            ));
    }

    /* ---- interviews ------------------------------------------------------ */

    /**
     * Booking one.
     *
     * The meeting URL is typed in. Nothing here provisions a Meet or a Zoom
     * room: that is a third-party integration with its own credentials and its
     * own failure modes, and pasting a link somebody already made takes four
     * seconds.
     */
    private function scheduleInterviewAction(): Actions\Action
    {
        return Actions\Action::make('schedule_interview')
            ->label('Book an interview')
            ->icon('heroicon-o-calendar-days')
            ->visible(fn () => $this->can(RecruitmentPermission::SCHEDULE_INTERVIEW)
                && $this->record->isActive())
            ->form(fn () => $this->interviewForm())
            ->action(fn (array $data) => $this->run(
                fn () => app(InterviewService::class)->schedule($this->record, $this->actor(), $data),
                'Booked. The invitation is queued with a calendar attachment.',
            ));
    }

    private function rescheduleInterviewAction(): Actions\Action
    {
        return Actions\Action::make('reschedule_interview')
            ->label('Move the interview')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn () => $this->can(RecruitmentPermission::SCHEDULE_INTERVIEW)
                && $this->record->upcomingInterview() !== null)
            ->form(fn () => $this->interviewForm(reschedule: true))
            ->action(fn (array $data) => $this->run(
                fn () => app(InterviewService::class)->reschedule(
                    $this->record->upcomingInterview(),
                    $this->actor(),
                    $data,
                ),
                'Moved. The calendar update keeps the same appointment, so it replaces the old one rather than adding a second.',
            ));
    }

    /** @return array<\Filament\Forms\Components\Component> */
    private function interviewForm(bool $reschedule = false): array
    {
        $existing = $reschedule ? $this->record->upcomingInterview() : null;
        $zone     = config('recruitment.timezone');

        return [
            Forms\Components\DateTimePicker::make('scheduled_at')
                ->label('When')
                ->seconds(false)
                ->required()
                ->default($existing?->scheduled_at?->timezone($zone))
                ->helperText('Entered in ' . $zone . '. Stored in UTC and shown to the candidate in their own time zone'
                    . ($this->record->timezone ? ' (' . $this->record->timezone . ').' : '.')),

            Forms\Components\TextInput::make('duration_minutes')
                ->label('How long, in minutes')
                ->numeric()
                ->minValue(10)
                ->maxValue(240)
                ->default($existing?->duration_minutes ?? 30)
                ->required(),

            Forms\Components\Select::make('interviewer_id')
                ->label('Who is taking it')
                ->options(fn () => User::query()
                    ->where(fn ($q) => $q->whereNotNull('recruitment_role')->orWhere('role', 'admin'))
                    ->orderBy('full_name')
                    ->pluck('full_name', 'id')
                    ->all())
                ->searchable()
                ->default($existing?->interviewer_id ?? $this->actor()?->id)
                ->visible(! $reschedule),

            Forms\Components\TextInput::make('meeting_url')
                ->label('Meeting link')
                ->url()
                ->maxLength(2048)
                ->default($existing?->meeting_url)
                ->helperText('Paste the Meet, Zoom or Teams link. Checked for http(s), and nothing on this server opens it.'),

            Forms\Components\Textarea::make('candidate_instructions')
                ->label('Anything they should know')
                ->rows(3)
                ->maxLength(2000)
                ->default($existing?->candidate_instructions)
                ->helperText('Goes into the invitation as written. Plain text.'),

            Forms\Components\Textarea::make('reason')
                ->label('Why it is moving')
                ->rows(2)
                ->maxLength(500)
                ->visible($reschedule)
                ->helperText('For the record. The candidate is told the new time, not this.'),
        ];
    }

    /** Internal, and the form says so. */
    private function interviewFeedbackAction(): Actions\Action
    {
        return Actions\Action::make('interview_feedback')
            ->label('Record what you thought')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn () => $this->record->interviews()->exists()
                && RecruitmentPermission::canReview($this->actor(), $this->record))
            ->form([
                Forms\Components\Select::make('interview')
                    ->label('Which interview')
                    ->options(fn () => $this->record->interviews
                        ->mapWithKeys(fn ($i) => [
                            $i->id => 'Round ' . $i->round . ' - '
                                . $i->scheduled_at?->timezone(config('recruitment.timezone'))->format('j M Y, H:i'),
                        ])
                        ->all())
                    ->default(fn () => $this->record->interviews->last()?->id)
                    ->required(),

                Forms\Components\Select::make('outcome')
                    ->label('Your recommendation')
                    ->options(ReviewerReview::RECOMMENDATIONS)
                    ->native(false),

                Forms\Components\Textarea::make('feedback')
                    ->label('Notes')
                    ->rows(5)
                    ->maxLength(8000)
                    ->helperText('Never shown to the candidate, never in an email, and not in the ordinary export.'),

                Forms\Components\Textarea::make('salary_discussion_note')
                    ->label('What was said about pay')
                    ->rows(2)
                    ->maxLength(4000)
                    ->helperText('Record what was actually discussed. Nothing here is quoted to them as an offer.'),

                Forms\Components\Textarea::make('decision_note')
                    ->label('Anything else for the decision')
                    ->rows(2)
                    ->maxLength(4000),
            ])
            ->action(function (array $data) {
                $interview = $this->record->interviews->firstWhere('id', (int) $data['interview']);

                if (! $interview) {
                    Notification::make()->title('That interview is not on this application')->danger()->send();

                    return;
                }

                $this->run(
                    fn () => app(InterviewService::class)->recordFeedback($interview, $this->actor(), $data),
                    'Noted',
                );
            });
    }

    /* ---- offer and checks ------------------------------------------------ */

    /**
     * Preparing the offer.
     *
     * The wording is either typed here or uploaded as a document. Nothing
     * generates employment terms, and the form does not pre-fill a salary from
     * the vacancy's advertised range — a range is what was advertised, and an
     * offer is a figure somebody decided.
     *
     * Three steps, not one: draft, approve, send. Each is a separate action
     * with its own permission, because an offer that goes out before anybody
     * read it is the expensive kind of mistake.
     */
    private function prepareOfferAction(): Actions\Action
    {
        return Actions\Action::make('prepare_offer')
            ->label('Prepare an offer')
            ->icon('heroicon-o-document-plus')
            ->visible(fn () => $this->can(RecruitmentPermission::APPROVE_OFFER)
                && $this->record->isActive())
            ->form([
                Forms\Components\Textarea::make('body')
                    ->label('The offer, as the candidate will read it')
                    ->rows(10)
                    ->maxLength(20000)
                    ->helperText('Plain text. Write it or paste it from your approved template. This application does not write employment terms, name a salary you have not set, or invent a start date.'),

                Forms\Components\TextInput::make('salary_amount')
                    ->label('Pay')
                    ->numeric()
                    ->helperText('The figure being offered, in whole units of the currency. Stored to the minor unit.'),

                Forms\Components\Select::make('currency')
                    ->label('Currency')
                    ->options(['NGN' => 'NGN', 'USD' => 'USD'])
                    ->default('NGN'),

                Forms\Components\Select::make('pay_period')
                    ->label('Per')
                    ->options(\App\Models\Vacancy::PAY_PERIODS)
                    ->native(false),

                Forms\Components\Select::make('compensation_type')
                    ->label('Made up of')
                    ->options(\App\Models\Vacancy::COMPENSATION_TYPES)
                    ->native(false),

                Forms\Components\Textarea::make('compensation_note')
                    ->label('How any commission or bonus works')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('In your own words. No rate is assumed or filled in for you.'),

                Forms\Components\DatePicker::make('start_date')
                    ->label('Proposed start')
                    ->helperText('Leave blank if it has not been agreed. A blank date is not shown to the candidate at all.'),
            ])
            ->action(fn (array $data) => $this->run(
                function () use ($data) {
                    $amount = $data['salary_amount'] ?? null;

                    app(OfferService::class)->draft($this->record, $this->actor(), [
                        'body'              => $data['body'] ?? null,
                        'salary_minor'      => filled($amount) ? (int) round(((float) $amount) * 100) : null,
                        'currency'          => $data['currency'] ?? null,
                        'pay_period'        => $data['pay_period'] ?? null,
                        'compensation_type' => $data['compensation_type'] ?? null,
                        'compensation_note' => $data['compensation_note'] ?? null,
                        'start_date'        => $data['start_date'] ?? null,
                    ]);
                },
                'Drafted. Nothing has been sent — approve it next.',
            ));
    }

    private function approveOfferAction(): Actions\Action
    {
        return Actions\Action::make('approve_offer')
            ->label('Approve the draft offer')
            ->icon('heroicon-o-check')
            ->visible(fn () => $this->can(RecruitmentPermission::APPROVE_OFFER)
                && $this->record->offer?->status === Offer::DRAFT)
            ->requiresConfirmation()
            ->modalDescription('Records that you have read these terms and they are the ones to send. It does not send them.')
            ->action(fn () => $this->run(
                fn () => app(OfferService::class)->approve($this->record->offer, $this->actor()),
                'Approved and ready to send',
            ));
    }

    private function sendOfferAction(): Actions\Action
    {
        return Actions\Action::make('send_offer')
            ->label('Send the offer')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->visible(fn () => $this->can(RecruitmentPermission::APPROVE_OFFER)
                && $this->record->offer?->isSendable())
            ->requiresConfirmation()
            ->modalDescription('The candidate gets an email telling them an offer is waiting, and a link to read it after signing in. The terms are not in the email and the document is never attached.')
            ->action(fn () => $this->run(
                fn () => app(OfferService::class)->send($this->record->offer, $this->actor()),
                'Queued for sending',
            ));
    }

    /**
     * The checks.
     *
     * Identity documents and guarantors are collected here and only here —
     * late, once an offer exists, and only for a vacancy that explicitly asked
     * for them. The initial application never asks for any of it.
     *
     * Guarantors are off unless the vacancy turned them on AND wrote down why,
     * and OfferService enforces that rather than this button.
     */
    private function buildChecklistAction(): Actions\Action
    {
        return Actions\Action::make('build_checklist')
            ->label('Ask for the checks')
            ->icon('heroicon-o-clipboard-document-list')
            ->visible(fn () => $this->can(RecruitmentPermission::APPROVE_OFFER)
                && $this->record->isActive()
                && $this->record->offer?->status === Offer::ACCEPTED)
            ->requiresConfirmation()
            ->modalHeading('Ask for the pre-employment checks')
            ->modalDescription(fn () => 'Builds the checklist from what this vacancy asks for'
                . ($this->record->vacancy?->guarantors_required
                    ? ', including a guarantor, because this role has that turned on with a written reason.'
                    : '. Guarantors are off for this role, so nothing asks for one.')
                . ' Running this twice adds nothing and overwrites nothing already submitted.')
            ->action(fn () => $this->run(
                function () {
                    $made = app(OfferService::class)->buildChecklist($this->record, $this->actor());

                    if ($made === 0) {
                        throw new \RuntimeException(
                            'Nothing to add — the checklist is already there, or this role asks for no checks.',
                        );
                    }
                },
                'The checklist is ready and the candidate has been asked',
            ));
    }

    /* ---- scoring and notes ----------------------------------------------- */

    /**
     * Scoring.
     *
     * Against the rubric snapshotted onto this candidate's own assignment, so
     * two people assessed in different weeks are marked against what each was
     * actually given.
     *
     * A blank criterion stays blank. It is not a zero, it is not an average of
     * the others, and the screen shows it as a gap — because "nobody has read
     * this yet" is a different fact from "this was poor", and the one thing
     * that must not happen is a decision made on numbers that look complete
     * and are not.
     *
     * Nothing reads the total. There is no threshold and no ranking.
     */
    private function scoreAction(): Actions\Action
    {
        return Actions\Action::make('score')
            ->label('Score the exercise')
            ->icon('heroicon-o-star')
            ->color('gray')
            ->visible(fn () => RecruitmentPermission::canReview($this->actor(), $this->record)
                && $this->record->currentAssignment()?->isSubmitted())
            ->modalWidth('2xl')
            ->modalDescription('Your own marks against the rubric this candidate was given. Leave anything you have not judged blank — a gap is honest and a zero is not.')
            ->form(fn () => $this->scoreForm())
            ->fillForm(fn () => $this->scoreDefaults())
            ->action(fn (array $data) => $this->saveScores($data));
    }

    private function noteAction(): Actions\Action
    {
        return Actions\Action::make('note')
            ->label('Add a private note')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('gray')
            ->visible(fn () => RecruitmentPermission::canReview($this->actor(), $this->record))
            ->form([
                Forms\Components\Textarea::make('body')
                    ->label('Note')
                    ->rows(5)
                    ->required()
                    ->maxLength(8000)
                    ->helperText('For your colleagues. Never shown to the candidate, never emailed, and not in the ordinary export.'),
            ])
            ->action(function (array $data) {
                $this->record->notes()->create([
                    'user_id' => $this->actor()?->id,
                    'body'    => $data['body'],
                ]);

                $this->record->refresh();

                Notification::make()->title('Noted')->success()->send();
            });
    }

    /** @return array<\Filament\Forms\Components\Component> */
    private function scoreForm(): array
    {
        $assignment = $this->record->currentAssignment();
        $max        = $assignment?->scaleMax() ?? 5;
        $fields     = [];

        foreach ($assignment?->rubric() ?? [] as $criterion) {
            $key = (string) ($criterion['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $fields[] = Forms\Components\Select::make('score_' . $key)
                ->label((string) ($criterion['label'] ?? $key))
                ->options(static::scaleOptions($max, $criterion['anchors'] ?? []))
                ->native(false)
                ->placeholder('Not judged yet')
                ->helperText(filled($criterion['anchors'] ?? null) ? null : 'Leave blank if you have not formed a view.');

            $fields[] = Forms\Components\Textarea::make('comment_' . $key)
                ->label('Why')
                ->rows(2)
                ->maxLength(2000);
        }

        if ($fields === []) {
            $fields[] = Forms\Components\Placeholder::make('none')
                ->label('No rubric')
                ->content('This exercise was sent without a rubric, so there is nothing to score against. Use a private note instead.');
        }

        $fields[] = Forms\Components\Select::make('recommendation')
            ->label('Your recommendation')
            ->options(ReviewerReview::RECOMMENDATIONS)
            ->native(false)
            ->helperText('Advice to the people deciding. It moves nobody by itself.');

        $fields[] = Forms\Components\Textarea::make('summary')
            ->label('In a sentence or two')
            ->rows(3)
            ->maxLength(4000);

        return $fields;
    }

    /**
     * The scale, with its anchors spelled out.
     *
     * "4" on its own means whatever the reader assumes. "4 — clear and
     * accurate, would need little supervision" means the same thing to two
     * different reviewers, which is the only way separate marks are
     * comparable at all.
     *
     * @param  array<int|string, mixed>  $anchors
     * @return array<int, string>
     */
    private static function scaleOptions(int $max, mixed $anchors): array
    {
        $anchors = is_array($anchors) ? $anchors : [];
        $options = [];

        for ($i = 1; $i <= max(2, $max); $i++) {
            $anchor = $anchors[$i] ?? $anchors[(string) $i] ?? null;

            $options[$i] = filled($anchor) ? $i . ' — ' . $anchor : (string) $i;
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private function scoreDefaults(): array
    {
        $mine = $this->record->scores->where('user_id', $this->actor()?->id);
        $data = [];

        foreach ($mine as $score) {
            $data['score_' . $score->criterion_key]   = $score->score;
            $data['comment_' . $score->criterion_key] = $score->comment;
        }

        $review = $this->record->reviews->firstWhere('user_id', $this->actor()?->id);

        $data['recommendation'] = $review?->recommendation;
        $data['summary']        = $review?->summary;

        return $data;
    }

    /**
     * Saving one reviewer's marks.
     *
     * Keyed on (application, assignment, reviewer, criterion), so a reviewer
     * revising their own mark updates their own row and never touches a
     * colleague's. Separate reviews by separate people is the point; a single
     * shared score would just be whoever pressed save last.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveScores(array $data): void
    {
        $assignment = $this->record->currentAssignment();
        $actor      = $this->actor();

        if (! $actor) {
            return;
        }

        $max   = $assignment?->scaleMax() ?? 5;
        $saved = 0;

        foreach ($assignment?->rubric() ?? [] as $criterion) {
            $key = (string) ($criterion['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $raw = $data['score_' . $key] ?? null;

            // Blank stays blank, and the row is still written so the gap is
            // visible on the screen rather than merely absent from it.
            $score = filled($raw) ? max(1, min($max, (int) $raw)) : null;

            $this->record->scores()->updateOrCreate(
                [
                    'assessment_assignment_id' => $assignment?->id,
                    'user_id'                  => $actor->id,
                    'criterion_key'            => $key,
                ],
                [
                    'criterion_label' => (string) ($criterion['label'] ?? $key),
                    'score'           => $score,
                    'scale_max'       => $max,
                    'comment'         => filled($data['comment_' . $key] ?? null)
                        ? \Illuminate\Support\Str::limit((string) $data['comment_' . $key], 2000, '')
                        : null,
                ],
            );

            $saved++;
        }

        if (filled($data['recommendation'] ?? null) || filled($data['summary'] ?? null)) {
            $this->record->reviews()->updateOrCreate(
                ['user_id' => $actor->id],
                [
                    'recommendation' => $data['recommendation'] ?? null,
                    'summary'        => filled($data['summary'] ?? null)
                        ? \Illuminate\Support\Str::limit((string) $data['summary'], 4000, '')
                        : null,
                    'submitted_at'   => now(),
                ],
            );
        }

        \App\Support\AuditLogger::record('recruitment_review.scored', 'job_application', $this->record->id, [
            'criteria' => $saved,
        ], $actor->id);

        $this->record->refresh();

        Notification::make()
            ->title('Your marks are saved')
            ->body('They are yours alone and sit beside anybody else\'s. Nothing has moved this candidate.')
            ->success()
            ->send();
    }

    /* ---- decisions ------------------------------------------------------- */

    /**
     * Moving a stage by hand.
     *
     * The options come from ApplicationStageService::allowedFrom(), so the
     * select cannot offer a transition the service would refuse — and the
     * service refuses it again anyway, because this form is not the authority.
     *
     * A skip or a step backwards needs a reason, and the form says which it
     * is before you type. That is requiresReason(), not a rule invented here.
     */
    private function moveStageAction(): Actions\Action
    {
        return Actions\Action::make('move_stage')
            ->label('Move to another stage')
            ->icon('heroicon-o-arrow-right-circle')
            ->visible(fn () => $this->can(RecruitmentPermission::MOVE_STAGE))
            ->form(function () {
                $stages = app(ApplicationStageService::class);
                $from   = $this->record->stage;

                return [
                    Forms\Components\Select::make('to')
                        ->label('Move to')
                        ->options(collect($stages->allowedFrom($from))
                            ->mapWithKeys(fn (ApplicationStage $s) => [$s->value => $s->label()])
                            ->all())
                        ->required()
                        ->live()
                        ->native(false),

                    Forms\Components\Textarea::make('reason')
                        ->label('Reason')
                        ->rows(3)
                        ->maxLength(1000)
                        ->required(fn (Forms\Get $get) => filled($get('to'))
                            && $stages->requiresReason($from, ApplicationStage::from($get('to'))))
                        ->helperText(fn (Forms\Get $get) => filled($get('to'))
                            && $stages->requiresReason($from, ApplicationStage::from($get('to')))
                                ? 'Required, because this skips a stage, steps back, or reopens a closed application.'
                                : 'Optional for a move to the next stage.'),
                ];
            })
            ->action(fn (array $data) => $this->run(
                fn () => app(ApplicationStageService::class)->move(
                    $this->record,
                    ApplicationStage::from($data['to']),
                    $this->actor(),
                    ['reason' => $data['reason'] ?? null],
                ),
                'Moved',
            ));
    }

    /**
     * Hiring.
     *
     * By hand, after the checks are resolved, and the modal names anything
     * still outstanding rather than letting somebody find out afterwards.
     *
     * What it does NOT do: create a user account, grant a role, or give this
     * person a single permission anywhere on the platform. A new employee's
     * access is a separate, deliberate act by an administrator, and nothing
     * about being hired here produces it.
     */
    private function hireAction(): Actions\Action
    {
        return Actions\Action::make('hire')
            ->label('Mark as hired')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn () => $this->can(RecruitmentPermission::MOVE_STAGE)
                && $this->record->stage === ApplicationStage::OfferChecks)
            ->requiresConfirmation()
            ->modalHeading('Mark as hired')
            ->modalDescription(function () {
                $outstanding = $this->record->onboardingItems
                    ->filter(fn (OnboardingItem $i) => $i->is_required && $i->isOutstanding())
                    ->pluck('label');

                return $outstanding->isEmpty()
                    ? 'Every required check is resolved. This creates no account and grants no access — that is a separate decision somebody makes deliberately.'
                    : 'Still outstanding: ' . $outstanding->implode('; ')
                        . '. You can go ahead, but it will be recorded that these were open at the time.';
            })
            ->action(fn () => $this->run(
                fn () => app(ApplicationStageService::class)->hire($this->record, $this->actor()),
                'Recorded as hired. No account has been created.',
            ));
    }

    private function declineAction(): Actions\Action
    {
        return Actions\Action::make('decline')
            ->label('Decline')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn () => $this->can(RecruitmentPermission::MOVE_STAGE)
                && $this->record->isActive())
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Why')
                    ->rows(3)
                    ->required()
                    ->maxLength(1000)
                    ->helperText('Kept internally. The candidate is told they were not successful; this reasoning is not sent to them.'),

                Forms\Components\Toggle::make('notify')
                    ->label('Tell them')
                    ->default(true)
                    ->helperText('On, because leaving somebody waiting indefinitely is the worst version of this. Turn it off only if you are writing to them yourself.'),
            ])
            ->action(fn (array $data) => $this->run(
                fn () => app(ApplicationStageService::class)->reject(
                    $this->record,
                    $this->actor(),
                    (string) $data['reason'],
                    (bool) ($data['notify'] ?? true),
                ),
                'Declined. Every pending message for them has been cancelled.',
            ));
    }

    /* ---- actions rendered next to a row ---------------------------------- */

    /*
     * The two below are mounted from the page body with an argument rather
     * than from the header, because they act on one row: this checklist item,
     * this failed email. They are public because the view renders them by
     * name, and both re-resolve their record from this application's own
     * relation — an id posted from the browser can only ever reach a row that
     * belongs to the application already on screen.
     */

    /**
     * Recording the outcome of a check.
     *
     * Behind VIEW_SENSITIVE, which the service enforces again. The wording is
     * deliberate: a file arriving is not a check passing, so "Received" and
     * "Verified" are different statuses and somebody has to say which.
     */
    public function verifyItemAction(): Actions\Action
    {
        return Actions\Action::make('verifyItem')
            ->label('Record outcome')
            ->icon('heroicon-o-shield-check')
            ->modalHeading('Record the outcome of this check')
            ->form([
                Forms\Components\Radio::make('status')
                    ->label('Outcome')
                    ->options([
                        OnboardingItem::SUBMITTED    => 'Received — not checked yet',
                        OnboardingItem::VERIFIED     => 'Verified — I have checked it myself',
                        OnboardingItem::NOT_VERIFIED => 'Not verified — it did not check out',
                        OnboardingItem::WAIVED       => 'Waived — not needed for this person',
                    ])
                    ->required()
                    ->helperText('Received and verified are not the same thing. Only tick verified if you have actually looked.'),

                Forms\Components\Textarea::make('note')
                    ->label('Note')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Internal. Kept on the item, not sent anywhere.'),
            ])
            ->action(function (array $arguments, array $data) {
                $item = $this->record->onboardingItems()
                    ->whereKey($arguments['item'] ?? null)
                    ->first();

                if (! $item) {
                    Notification::make()->title('That item is no longer here.')->danger()->send();

                    return;
                }

                $this->run(
                    fn () => app(RecruitmentDocumentService::class)->recordVerification(
                        $item,
                        $this->actor(),
                        (string) $data['status'],
                        $data['note'] ?? null,
                    ),
                    'Recorded, with your name and the date against it.',
                );
            });
    }

    /**
     * Sending a failed email again.
     *
     * RecruitmentOutbox::retry() puts the row back to pending for the ordinary
     * dispatcher to pick up; it does not send anything inline. A message that
     * already went out is not retryable, so a second click cannot produce a
     * second copy of something the candidate has already read.
     */
    public function retryMessageAction(): Actions\Action
    {
        return Actions\Action::make('retryMessage')
            ->label('Try again')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalHeading('Queue this email again')
            ->modalDescription('It goes back into the queue for the next run. Anything already delivered cannot be queued twice.')
            ->action(function (array $arguments) {
                $message = $this->record->messages()
                    ->whereKey($arguments['message'] ?? null)
                    ->first();

                if (! $message) {
                    Notification::make()->title('That message is no longer here.')->danger()->send();

                    return;
                }

                $queued = app(RecruitmentOutbox::class)->retry($message);

                $this->record->refresh();

                Notification::make()
                    ->title($queued ? 'Queued again' : 'Nothing to retry')
                    ->body($queued
                        ? 'It will go out on the next dispatch run.'
                        : 'This one has either already been sent or has run out of attempts.')
                    ->status($queued ? 'success' : 'warning')
                    ->send();
            });
    }
}
