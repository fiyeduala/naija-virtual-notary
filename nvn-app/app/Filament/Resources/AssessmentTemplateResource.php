<?php

namespace App\Filament\Resources;

use App\Enums\RecruitmentRole;
use App\Filament\Resources\AssessmentTemplateResource\Pages;
use App\Models\AssessmentTemplate;
use App\Support\RecruitmentPermission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The exercises, and the rubrics they are marked against.
 *
 * The one thing to understand before editing anything here: a template is a
 * MASTER COPY, not the exercise anybody is actually sitting. When an
 * invitation goes out, AssessmentService writes
 * AssessmentTemplate::snapshot() onto the assignment — the tasks as worded
 * that day, the rubric as worded that day, the scale and the stated effort.
 * So rewriting a task this afternoon cannot change what somebody was set this
 * morning, and a reviewer marking an old submission is still marking it
 * against the rubric it was set under.
 *
 * `version` is bumped automatically whenever the substance changes, which is
 * what makes the snapshots legible afterwards: "template v3" is a real,
 * recoverable statement about which wording somebody was given.
 *
 * Deliberately absent: a delete action. A template with assignments behind it
 * is the record of what people were asked to do; it is deactivated instead,
 * which takes it out of the vacancy picker and leaves the history intact.
 */
class AssessmentTemplateResource extends Resource
{
    protected static ?string $model = AssessmentTemplate::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Recruitment';
    protected static ?string $navigationLabel = 'Assessments';
    protected static ?string $modelLabel = 'assessment';
    protected static ?int $navigationSort = 3;
    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return RecruitmentPermission::check(RecruitmentPermission::MANAGE_VACANCIES);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('The exercise')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(160)
                        ->placeholder('Customer service — written exercise'),

                    Forms\Components\Select::make('role_key')
                        ->label('For which role')
                        ->options(collect(RecruitmentRole::cases())
                            ->mapWithKeys(fn (RecruitmentRole $r) => [$r->value => $r->label()])
                            ->all())
                        ->required()
                        ->native(false),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Available to attach to a vacancy')
                        ->default(true)
                        ->helperText('Switching this off hides it from the vacancy picker. Exercises already sent are unaffected.'),

                    Forms\Components\Textarea::make('brief')
                        ->label('The brief the candidate reads first')
                        ->rows(6)
                        ->maxLength(8000)
                        ->columnSpanFull()
                        ->helperText('For a customer service exercise this is where the approved company and process facts go, so nobody has to guess what we actually offer. Fictional scenarios only — never a real client, a real document or a live campaign.'),

                    Forms\Components\TextInput::make('effort_minutes_min')
                        ->label('Expected effort, from')
                        ->numeric()
                        ->suffix('minutes')
                        ->minValue(5)
                        ->maxValue(240),

                    Forms\Components\TextInput::make('effort_minutes_max')
                        ->label('to')
                        ->numeric()
                        ->suffix('minutes')
                        ->minValue(5)
                        ->maxValue(240)
                        ->helperText('Shown on the advert and in the invitation. 30 to 45 minutes is the house default; much more than that is unpaid work.'),
                ])
                ->columns(2),

            Forms\Components\Section::make('The tasks')
                ->description('Two or three is usually right. Each one is a question the candidate answers in their own words.')
                ->schema([
                    Forms\Components\Repeater::make('tasks')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\TextInput::make('key')
                                ->label('Reference')
                                ->required()
                                ->maxLength(60)
                                ->rules(['regex:/^[a-z0-9_]+$/'])
                                ->helperText('Lower case, no spaces. The answers are stored against this, so changing it on a live exercise orphans what people have already written.'),

                            Forms\Components\TextInput::make('label')
                                ->label('The task')
                                ->required()
                                ->maxLength(255),

                            Forms\Components\Select::make('type')
                                ->label('Answer type')
                                ->options(AssessmentTemplate::TASK_TYPES)
                                ->default('long_text')
                                ->required()
                                ->live()
                                ->native(false),

                            Forms\Components\Textarea::make('help')
                                ->label('Anything they need to know')
                                ->rows(3)
                                ->maxLength(2000)
                                ->columnSpanFull(),

                            Forms\Components\TagsInput::make('options')
                                ->label('The choices')
                                ->visible(fn (Forms\Get $get) => $get('type') === 'select')
                                ->columnSpanFull(),

                            Forms\Components\Toggle::make('required')
                                ->label('Must be answered')
                                ->default(true),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->maxItems(6)
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                        ->addActionLabel('Add a task'),
                ]),

            Forms\Components\Section::make('How it is marked')
                ->description('The criteria a reviewer scores against. Anchors are optional but they are what stops two reviewers using the same number to mean different things.')
                ->schema([
                    Forms\Components\TextInput::make('score_scale_max')
                        ->label('Marked out of')
                        ->numeric()
                        ->default(5)
                        ->minValue(2)
                        ->maxValue(10)
                        ->required(),

                    Forms\Components\Repeater::make('rubric')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\TextInput::make('key')
                                ->label('Reference')
                                ->required()
                                ->maxLength(60)
                                ->rules(['regex:/^[a-z0-9_]+$/']),

                            Forms\Components\TextInput::make('label')
                                ->label('Criterion')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('Empathy'),

                            Forms\Components\KeyValue::make('anchors')
                                ->label('What each mark means')
                                ->keyLabel('Mark')
                                ->valueLabel('What it describes')
                                ->addActionLabel('Describe a mark')
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->maxItems(8)
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                        ->addActionLabel('Add a criterion')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make('Notes for your own team')
                ->collapsed()
                ->schema([
                    Forms\Components\Textarea::make('internal_notes')
                        ->hiddenLabel()
                        ->rows(3)
                        ->maxLength(4000)
                        ->helperText('Never shown to a candidate and never in an email.'),
                ]),        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (AssessmentTemplate $record) => $record->role_key?->label()),

                Tables\Columns\TextColumn::make('version')
                    ->label('Version')
                    ->prefix('v')
                    ->sortable(),

                // How many tasks and criteria, read off the normalised arrays
                // rather than a raw count, so a half-finished row in the
                // repeater is not counted as a task somebody will be set.
                Tables\Columns\TextColumn::make('tasks')
                    ->label('Tasks')
                    ->formatStateUsing(fn (AssessmentTemplate $record) => count($record->normalisedTasks())),

                Tables\Columns\TextColumn::make('rubric')
                    ->label('Criteria')
                    ->formatStateUsing(fn (AssessmentTemplate $record) => count($record->normalisedRubric())),

                Tables\Columns\TextColumn::make('effort')
                    ->label('Effort')
                    ->formatStateUsing(fn (AssessmentTemplate $record) => $record->effortLine() ?? '—'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Available')
                    ->boolean(),

                Tables\Columns\TextColumn::make('assignments_count')
                    ->label('Times sent')
                    ->counts('assignments')
                    ->sortable(),

                Tables\Columns\TextColumn::make('vacancies_count')
                    ->label('On vacancies')
                    ->counts('vacancies'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role_key')
                    ->label('Role')
                    ->options(collect(RecruitmentRole::cases())
                        ->mapWithKeys(fn (RecruitmentRole $r) => [$r->value => $r->label()])
                        ->all()),

                Tables\Filters\TernaryFilter::make('is_active')->label('Available'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Copying beats editing when an exercise is already in use:
                // the copy is a fresh v1 nobody has been sent, so the original
                // wording stays exactly as the people sitting it were given it.
                Tables\Actions\Action::make('duplicate')
                    ->label('Copy')
                    ->icon('heroicon-o-document-duplicate')
                    ->requiresConfirmation()
                    ->modalDescription('Makes an inactive copy at version 1. Nothing that has already been sent is touched.')
                    ->action(function (AssessmentTemplate $record) {
                        $copy = $record->replicate(['created_at', 'updated_at']);
                        $copy->name      = \Illuminate\Support\Str::limit($record->name . ' (copy)', 160, '');
                        $copy->version   = 1;
                        $copy->is_active = false;
                        $copy->created_by = auth()->id();
                        $copy->save();

                        \App\Support\AuditLogger::record('assessment_template.copied', 'assessment_template', $copy->id, [
                            'from' => $record->id,
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title('Copied')
                            ->body('The copy is inactive until you switch it on.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No exercises yet')
            ->emptyStateDescription('A vacancy can be published without one, but then nobody can be invited to an assessment.')
            ->defaultSort('role_key');
    }


    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAssessmentTemplates::route('/'),
            'create' => Pages\CreateAssessmentTemplate::route('/create'),
            'edit'   => Pages\EditAssessmentTemplate::route('/{record}/edit'),
        ];
    }
}
