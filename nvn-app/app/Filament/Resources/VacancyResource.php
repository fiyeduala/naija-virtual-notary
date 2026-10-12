<?php

namespace App\Filament\Resources;

use App\Enums\RecruitmentRole;
use App\Enums\VacancyState;
use App\Filament\Resources\VacancyResource\Pages;
use App\Models\AssessmentTemplate;
use App\Models\User;
use App\Models\Vacancy;
use App\Support\RecruitmentPermission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Openings.
 *
 * Nothing here publishes anything by itself. The Publish action calls
 * Vacancy::publicationBlockers() first and refuses while anything is
 * outstanding, listing exactly what — including the one people are surprised
 * by, which is that an administrator has to SAY whether pay is disclosed. A
 * blank salary field that quietly publishes is this application deciding a
 * policy on somebody's behalf.
 *
 * Closing is likewise two decisions, not one. Closing an advert stops new
 * applications; ending the candidacy of everybody halfway through it is a
 * separate thing, and the action asks which you meant rather than guessing.
 */
class VacancyResource extends Resource
{
    protected static ?string $model = Vacancy::class;
    protected static ?string $navigationIcon = 'heroicon-o-briefcase';
    protected static ?string $navigationGroup = 'Recruitment';
    protected static ?string $navigationLabel = 'Vacancies';
    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return RecruitmentPermission::check(RecruitmentPermission::MANAGE_VACANCIES)
            || RecruitmentPermission::check(RecruitmentPermission::REVIEW);
    }

    /**
     * A plain reviewer sees the vacancies they were assigned to and no others.
     *
     * Applied to the query rather than to the table, because a hidden row is
     * still a row: without this, a reviewer could sort, filter, count or read
     * the badge on an opening nobody put them on. RecruitmentPermission::REVIEW_ALL
     * is what lifts it, and an administrator holds every ability by definition.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (RecruitmentPermission::check(RecruitmentPermission::MANAGE_VACANCIES)
            || RecruitmentPermission::check(RecruitmentPermission::REVIEW_ALL)) {
            return $query;
        }

        $user = RecruitmentPermission::current();

        return $query->whereHas('reviewers', fn (Builder $inner) => $inner->whereKey($user?->id));
    }

    public static function canCreate(): bool
    {
        return RecruitmentPermission::allows(auth()->user(), RecruitmentPermission::MANAGE_VACANCIES);
    }

    public static function canEdit($record): bool
    {
        return RecruitmentPermission::allows(auth()->user(), RecruitmentPermission::MANAGE_VACANCIES);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Vacancy::query()->listed()->count() ?: null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Published and taking applications';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('The role')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(190)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Forms\Set $set, ?Vacancy $record) {
                            // Only before it has ever been public. A slug is a
                            // shared link, and a link in a WhatsApp group
                            // outlives the wording of the advert.
                            if (! $record?->published_at) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),

                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(190)
                        ->unique(ignoreRecord: true)
                        ->helperText('The public link. Changing it after publishing breaks every link already shared.'),

                    Forms\Components\Select::make('role_key')
                        ->label('Category')
                        ->options(RecruitmentRole::options())
                        ->required()
                        ->helperText('Decides which assessment and screening questions make sense.'),

                    Forms\Components\TextInput::make('department')
                        ->maxLength(120)
                        ->placeholder('Left blank, the category decides it'),

                    Forms\Components\Select::make('assessment_template_id')
                        ->label('Assessment')
                        ->options(fn () => AssessmentTemplate::query()
                            ->where('is_active', true)
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->helperText('Needed before this can be published: an invited candidate must have something to do.'),
                ])->columns(2),

            Forms\Components\Section::make('What the advert says')
                ->description('Plain text. Line breaks are kept; nothing here renders as HTML.')
                ->schema([
                    Forms\Components\Textarea::make('summary')
                        ->label('Overview')
                        ->rows(4)
                        ->maxLength(4000),

                    Forms\Components\Textarea::make('responsibilities')
                        ->label('What the person will be doing')
                        ->rows(6)
                        ->maxLength(6000),

                    Forms\Components\Textarea::make('requirements')
                        ->label('What you are looking for')
                        ->rows(6)
                        ->maxLength(6000),

                    Forms\Components\Textarea::make('remote_arrangements')
                        ->label('How remote working works here')
                        ->rows(4)
                        ->maxLength(4000),

                    Forms\Components\Textarea::make('apply_instructions')
                        ->label('Anything they should know before applying')
                        ->rows(3)
                        ->maxLength(2000)
                        ->helperText('Optional, and shown above the Apply button. Not the place for a requirement — those belong above.'),
                ]),

            Forms\Components\Section::make('The facts a candidate will ask about')
                ->description('Remote is not the same as worldwide, and it is not the same as flexible hours. Both of these are stated explicitly on the advert.')
                ->schema([
                    Forms\Components\TextInput::make('employment_type')
                        ->maxLength(80)
                        ->placeholder('Full-time, part-time, contract...')
                        ->helperText('Nothing here guesses this.'),

                    Forms\Components\TextInput::make('location_eligibility')
                        ->label('Where someone must be able to work from')
                        ->maxLength(190)
                        ->placeholder('Nigeria'),

                    Forms\Components\TextInput::make('working_hours')
                        ->label('Hours they must be available')
                        ->maxLength(190)
                        ->placeholder('09:00-17:00 West Africa Time, Monday to Friday')
                        ->helperText('Name the time zone. A candidate in another country cannot infer it.'),

                    Forms\Components\TextInput::make('hours_timezone')
                        ->label('The time zone those hours are in')
                        ->maxLength(60)
                        ->placeholder('Africa/Lagos')
                        ->helperText('Shown under the hours on the advert. A candidate three time zones away is working out whether they can take the job at all.'),

                    Forms\Components\TextInput::make('headcount')
                        ->label('How many people')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder('Leave blank if undecided'),

                    Forms\Components\DateTimePicker::make('opens_at')
                        ->label('Applications open')
                        ->seconds(false)
                        ->helperText('Optional. Blank means as soon as it is published.'),

                    Forms\Components\DateTimePicker::make('closes_at')
                        ->label('Applications close')
                        ->seconds(false)
                        ->required(fn (string $operation) => false)
                        ->helperText('Enforced on the server, not just by hiding the button.'),
                ])->columns(2),

            Forms\Components\Section::make('Pay')
                ->description('Either show it or say you are not showing it. Leaving the choice unmade blocks publication, because a blank salary that quietly publishes is a policy decided by accident.')
                ->schema([
                    Forms\Components\Radio::make('salary_disclosure')
                        ->label('On the advert')
                        ->options(Vacancy::SALARY_DISCLOSURE)
                        ->live()
                        ->required(),

                    Forms\Components\Select::make('salary_currency')
                        ->options(['NGN' => 'Naira (NGN)', 'USD' => 'US dollars (USD)', 'GBP' => 'Pounds (GBP)'])
                        ->visible(fn (Forms\Get $get) => $get('salary_disclosure') === Vacancy::SALARY_DISCLOSED),

                    Forms\Components\Select::make('pay_period')
                        ->options(Vacancy::PAY_PERIODS)
                        ->visible(fn (Forms\Get $get) => $get('salary_disclosure') === Vacancy::SALARY_DISCLOSED),

                    Forms\Components\TextInput::make('salary_min_minor')
                        ->label('From')
                        ->numeric()
                        ->helperText('In kobo or cents. 250000 naira is 25000000.')
                        ->visible(fn (Forms\Get $get) => $get('salary_disclosure') === Vacancy::SALARY_DISCLOSED),

                    Forms\Components\TextInput::make('salary_max_minor')
                        ->label('To')
                        ->numeric()
                        ->helperText('Optional. Blank shows a single figure.')
                        ->visible(fn (Forms\Get $get) => $get('salary_disclosure') === Vacancy::SALARY_DISCLOSED),

                    Forms\Components\Select::make('compensation_type')
                        ->label('How the person is paid')
                        ->options(Vacancy::COMPENSATION_TYPES)
                        ->placeholder('Not stated'),

                    Forms\Components\Textarea::make('compensation_note')
                        ->label('Anything to add about pay')
                        ->rows(2)
                        ->maxLength(1000)
                        ->helperText('Write the commission arrangement here in your own words. Nothing invents a rate.')
                        ->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make('Screening questions')
                ->description('A small set, asked on the application form itself. Keep it short — this is before anybody has read a word about them.')
                ->schema([
                    Forms\Components\Repeater::make('screening_questions')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\TextInput::make('label')
                                ->label('The question')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(2),

                            Forms\Components\Select::make('type')
                                ->options(AssessmentTemplate::TASK_TYPES)
                                ->default('long_text')
                                ->required(),

                            Forms\Components\Toggle::make('required')
                                ->label('Must be answered')
                                ->default(false),

                            Forms\Components\TagsInput::make('options')
                                ->label('Choices')
                                ->helperText('For "Choose one" only.')
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->addActionLabel('Add a question')
                        ->reorderable()
                        ->collapsed()
                        ->itemLabel(fn (array $state) => $state['label'] ?? 'New question')
                        ->maxItems(8),
                ])->collapsed(),

            Forms\Components\Section::make('The process')
                ->schema([
                    Forms\Components\TextInput::make('assessment_due_working_days')
                        ->label('Working days for the assessment')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(20)
                        ->placeholder((string) config('recruitment.assessment.due_working_days', 3))
                        ->helperText('Weekends and configured holidays are skipped. Blank uses the platform default.'),

                    Forms\Components\Select::make('hiring_lead_id')
                        ->label('Hiring lead')
                        ->options(fn () => User::query()->admins()->pluck('full_name', 'id'))
                        ->searchable(),

                    Forms\Components\Select::make('reviewers')
                        ->label('Reviewers')
                        ->multiple()
                        ->relationship('reviewers', 'full_name')
                        ->options(fn () => User::query()
                            ->whereNotNull('recruitment_role')
                            ->orWhere('role', 'admin')
                            ->pluck('full_name', 'id'))
                        ->helperText('A reviewer sees only the vacancies they are assigned to. That is applied to the query, not to the page.')
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('intro_video_enabled')
                        ->label('Offer an introduction video')
                        ->live()
                        ->helperText('A link, never an upload for now. We do not fetch it or embed a player.'),

                    Forms\Components\Toggle::make('intro_video_required')
                        ->label('Ask for it as part of the assessment')
                        ->visible(fn (Forms\Get $get) => (bool) $get('intro_video_enabled'))
                        ->helperText('A written introduction is always accepted instead, and is not scored lower.'),
                ])->columns(2),

            Forms\Components\Section::make('Pre-employment checks')
                ->description('Collected at the offer stage and never in the application form. Identity paperwork is readable only by a user holding the sensitive-data permission.')
                ->schema([
                    Forms\Components\Toggle::make('identity_documents_required')
                        ->label('Ask for an identity document at offer stage'),

                    Forms\Components\Toggle::make('guarantors_required')
                        ->label('Ask for a guarantor')
                        ->live()
                        ->helperText('Off by default, and deliberately. A guarantor is not a professional reference.'),

                    Forms\Components\Textarea::make('guarantor_reason')
                        ->label('Why this role needs one')
                        ->rows(2)
                        ->maxLength(1000)
                        ->visible(fn (Forms\Get $get) => (bool) $get('guarantors_required'))
                        ->helperText('Shown to the candidate. Publication is blocked until this is written.')
                        ->columnSpanFull(),
                ])->columns(2)->collapsed(),

            Forms\Components\Section::make('Notes for your own team')
                ->schema([
                    Forms\Components\Textarea::make('internal_notes')
                        ->hiddenLabel()
                        ->rows(3)
                        ->maxLength(4000)
                        ->helperText('Never shown on the advert or to a candidate.'),
                ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Vacancy $record) => $record->departmentLabel()),

                Tables\Columns\TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof VacancyState ? $state->label() : (string) $state)
                    ->color(fn ($state) => match ($state) {
                        VacancyState::Published => 'success',
                        VacancyState::Paused    => 'warning',
                        VacancyState::Draft     => 'gray',
                        VacancyState::Closed    => 'danger',
                        default                 => 'gray',
                    }),

                Tables\Columns\TextColumn::make('applications_count')
                    ->label('Applications')
                    ->counts('applications')
                    ->sortable(),

                Tables\Columns\TextColumn::make('closes_at')
                    ->label('Closes')
                    ->dateTime('j M Y')
                    ->timezone(config('recruitment.timezone'))
                    ->placeholder('Not set')
                    ->sortable(),

                Tables\Columns\IconColumn::make('ready')
                    ->label('Ready to publish')
                    ->boolean()
                    ->state(fn (Vacancy $record) => $record->canBePublished())
                    ->tooltip(fn (Vacancy $record) => $record->canBePublished()
                        ? 'Everything needed is filled in'
                        : implode(' ', $record->publicationBlockers())),

                // The thing that actually gets pasted into a WhatsApp group or
                // a job board. Copying the full address beats reading a slug
                // off the screen and typing it wrong.
                Tables\Columns\TextColumn::make('slug')
                    ->label('Link')
                    ->copyable()
                    ->copyableState(fn (Vacancy $record) => route('careers.show', $record->slug))
                    ->copyMessage('Link copied')
                    ->formatStateUsing(fn ($state) => '/careers/' . $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('reviewers.full_name')
                    ->label('Reviewers')
                    ->badge()
                    ->placeholder('Nobody assigned'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')
                    ->options(fn () => collect(VacancyState::cases())
                        ->mapWithKeys(fn (VacancyState $case) => [$case->value => $case->label()])
                        ->all()),

                Tables\Filters\SelectFilter::make('role_key')
                    ->label('Category')
                    ->options(RecruitmentRole::options()),
            ])
            ->actions(static::stateActions())
            ->defaultSort('id', 'desc');
    }

    /**
     * Moving a vacancy between states.
     *
     * Every one of these writes an audit entry, because "who put this advert
     * live" and "who closed it" are questions that get asked later.
     *
     * @return array<\Filament\Tables\Actions\Action>
     */
    public static function stateActions(): array
    {
        $manages = fn () => RecruitmentPermission::allows(auth()->user(), RecruitmentPermission::MANAGE_VACANCIES);

        return [
            Tables\Actions\EditAction::make(),

            Tables\Actions\Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->url(fn (Vacancy $record) => route('careers.show', ['vacancy' => $record->slug, 'preview' => 1]))
                ->openUrlInNewTab(),

            Tables\Actions\Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->visible(fn (Vacancy $record) => $manages() && in_array($record->state, [
                    VacancyState::Draft,
                    VacancyState::Paused,
                ], true))
                ->requiresConfirmation()
                ->modalHeading('Put this advert live')
                ->modalDescription(fn (Vacancy $record) => $record->canBePublished()
                    ? 'It will appear on /careers and start taking applications.'
                    : 'Not yet: ' . implode(' ', $record->publicationBlockers()))
                ->modalSubmitActionLabel('Publish it')
                ->action(function (Vacancy $record) {
                    // Checked here and not only in the modal copy: the modal is
                    // a courtesy and this is the rule.
                    if (! $record->canBePublished()) {
                        Notification::make()
                            ->title('Not published')
                            ->body(implode(' ', $record->publicationBlockers()))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $record->forceFill([
                        'state'        => VacancyState::Published,
                        'published_at' => $record->published_at ?? now(),
                        'published_by' => auth()->id(),
                        'closed_at'    => null,
                    ])->save();

                    \App\Support\AuditLogger::record('vacancy.published', 'vacancy', $record->id, [
                        'slug' => $record->slug,
                    ]);

                    Notification::make()->title('Live at /careers/' . $record->slug)->success()->send();
                }),

            Tables\Actions\Action::make('pause')
                ->label('Pause')
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->visible(fn (Vacancy $record) => $manages() && $record->state === VacancyState::Published)
                ->requiresConfirmation()
                ->modalDescription('The advert keeps its page so shared links still work, but it stops taking applications and leaves the /careers list. Nobody already in the pipeline is affected.')
                ->action(function (Vacancy $record) {
                    $record->forceFill(['state' => VacancyState::Paused])->save();

                    \App\Support\AuditLogger::record('vacancy.paused', 'vacancy', $record->id, []);

                    Notification::make()->title('Paused')->success()->send();
                }),

            Tables\Actions\Action::make('close')
                ->label('Close')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn (Vacancy $record) => $manages() && in_array($record->state, [
                    VacancyState::Published,
                    VacancyState::Paused,
                ], true))
                ->form([
                    Forms\Components\Radio::make('candidates')
                        ->label('The people already in the pipeline')
                        ->options(Vacancy::CLOSE_ACTIONS)
                        ->default(Vacancy::CLOSE_KEEP)
                        ->required()
                        ->helperText('Closing the advert and ending somebody\'s candidacy are two different acts. Nothing is decided for you here.'),

                    Forms\Components\Textarea::make('reason')
                        ->label('What should the declined candidates be told?')
                        ->rows(3)
                        ->maxLength(1000)
                        ->visible(fn (Forms\Get $get) => $get('candidates') === Vacancy::CLOSE_REJECT)
                        ->required(fn (Forms\Get $get) => $get('candidates') === Vacancy::CLOSE_REJECT),
                ])
                ->action(function (Vacancy $record, array $data) {
                    $choice = (string) ($data['candidates'] ?? Vacancy::CLOSE_KEEP);

                    // Stored, not just acted on. Six months from now the
                    // question is "were those people told, or were they left
                    // waiting" and the answer has to be on the record.
                    $record->forceFill([
                        'state'           => VacancyState::Closed,
                        'closed_at'       => now(),
                        'on_close_action' => $choice,
                    ])->save();

                    $declined = 0;

                    if ($choice === Vacancy::CLOSE_REJECT) {
                        $stages = app(\App\Services\Recruitment\ApplicationStageService::class);

                        foreach ($record->applications()->active()->get() as $application) {
                            $stages->reject($application, auth()->user(), (string) $data['reason']);
                            $declined++;
                        }
                    }

                    \App\Support\AuditLogger::record('vacancy.closed', 'vacancy', $record->id, [
                        'candidates' => $choice,
                        'declined'   => $declined,
                    ]);

                    Notification::make()
                        ->title('Closed')
                        ->body($declined > 0
                            ? $declined . ' candidate(s) were declined and told.'
                            : 'Everybody in the pipeline was left where they are.')
                        ->success()
                        ->send();
                }),

            Tables\Actions\Action::make('archive')
                ->label('Archive')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->visible(fn (Vacancy $record) => $manages() && $record->state === VacancyState::Closed)
                ->requiresConfirmation()
                ->modalDescription('It disappears from the public site entirely. The applications and their evidence are untouched.')
                ->action(function (Vacancy $record) {
                    $record->forceFill(['state' => VacancyState::Archived])->save();

                    \App\Support\AuditLogger::record('vacancy.archived', 'vacancy', $record->id, []);

                    Notification::make()->title('Archived')->success()->send();
                }),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVacancies::route('/'),
            'create' => Pages\CreateVacancy::route('/create'),
            'edit'   => Pages\EditVacancy::route('/{record}/edit'),
        ];
    }
}
