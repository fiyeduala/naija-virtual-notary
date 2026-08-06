<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailCampaignResource\Pages;
use App\Filament\Resources\EmailCampaignResource\RelationManagers;
use App\Mail\AdminBroadcastMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Services\EmailCampaignService;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;

/**
 * Compose and send email from the panel — to everyone, to one role, or to
 * named people.
 *
 * A campaign is never sent from the web request. Composing writes a recipient
 * row per person and puts a job on the queue for each; the screen then shows
 * the send working through. That is what makes a send survivable: a shared host
 * that drops the connection at its hourly SMTP limit leaves the remaining rows
 * 'pending', and pressing "Resume" picks up exactly where it stopped without
 * writing to anybody twice.
 */
class EmailCampaignResource extends Resource
{
    protected static ?string $model = EmailCampaign::class;
    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?string $navigationGroup = 'Content & settings';
    protected static ?string $navigationLabel = 'Email';
    protected static ?string $modelLabel = 'email';
    protected static ?string $pluralModelLabel = 'emails';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Section::make('Who receives this')
                ->schema([
                    Forms\Components\Radio::make('audience')
                        ->required()
                        ->default('all')
                        ->live()
                        ->options([
                            'all'        => 'Everyone with an account',
                            'clients'    => 'Clients only',
                            'notaries'   => 'Notaries only',
                            'individual' => 'Specific people I choose',
                        ])
                        ->descriptions([
                            'all'        => 'Clients, notaries and admins.',
                            'clients'    => 'People who request notarizations.',
                            'notaries'   => 'Partner notaries and the system notary.',
                            'individual' => 'One person, or a handful — used for correspondence, not announcements.',
                        ]),

                    Forms\Components\Select::make('recipient_ids')
                        ->label('People')
                        ->multiple()
                        ->searchable()
                        ->required()
                        ->visible(fn (Get $get) => $get('audience') === 'individual')
                        ->getSearchResultsUsing(fn (string $search) => User::query()
                            ->whereNotNull('email')
                            ->where(fn ($q) => $q->where('full_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%"))
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (User $u) => [$u->id => $u->full_name . ' — ' . $u->email])
                            ->all())
                        ->getOptionLabelsUsing(fn (array $values) => User::whereIn('id', $values)
                            ->get()
                            ->mapWithKeys(fn (User $u) => [$u->id => $u->full_name . ' — ' . $u->email])
                            ->all())
                        ->helperText('Search by name or email. People who unsubscribed from announcements are still reachable here — writing to one person about their own request is correspondence, not a broadcast.'),

                    Forms\Components\Placeholder::make('audience_count')
                        ->label('This will go to')
                        ->content(function (Get $get) {
                            $audience = $get('audience') ?? 'all';
                            $count = app(EmailCampaignService::class)
                                ->audienceCount($audience, $get('recipient_ids') ?? []);

                            $suffix = $audience === 'individual'
                                ? ''
                                : ' (people who unsubscribed are already excluded)';

                            return $count === 1
                                ? '1 person' . $suffix
                                : number_format($count) . ' people' . $suffix;
                        }),
                ]),

            Forms\Components\Section::make('The message')
                ->schema([
                    Forms\Components\TextInput::make('subject')
                        ->required()
                        ->maxLength(200)
                        ->helperText('Placeholders work here too.'),

                    Forms\Components\RichEditor::make('body')
                        ->required()
                        ->toolbarButtons([
                            'bold', 'italic', 'underline', 'strike', 'link',
                            'h2', 'h3', 'bulletList', 'orderedList', 'blockquote', 'undo', 'redo',
                        ])
                        ->helperText('Placeholders: {{ name }}, {{ first_name }}, {{ email }} — replaced per recipient. The site logo, footer and unsubscribe link are added automatically.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subject')->searchable()->limit(50)->wrap(),
                Tables\Columns\TextColumn::make('audience')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'all' => 'Everyone', 'clients' => 'Clients',
                        'notaries' => 'Notaries', default => 'Individual',
                    })
                    ->color(fn (string $state) => $state === 'individual' ? 'gray' : 'info'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'sent' => 'success', 'sending', 'queued' => 'warning',
                        'cancelled' => 'danger', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('progress')
                    ->label('Delivered')
                    ->state(fn (EmailCampaign $c) => $c->sent_count . ' / ' . $c->total_recipients
                        . ($c->failed_count ? "  ({$c->failed_count} failed)" : '')),
                Tables\Columns\TextColumn::make('author.full_name')->label('Sent by')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('j M Y, H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('audience')->options([
                    'all' => 'Everyone', 'clients' => 'Clients',
                    'notaries' => 'Notaries', 'individual' => 'Individual',
                ]),
                Tables\Filters\SelectFilter::make('status')->options([
                    'draft' => 'Draft', 'queued' => 'Queued', 'sending' => 'Sending',
                    'sent' => 'Sent', 'cancelled' => 'Cancelled',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // A draft, or a send the host cut short. Either way this only
                // ever touches rows still marked 'pending'.
                Tables\Actions\Action::make('send')
                    ->label(fn (EmailCampaign $c) => $c->isDraft() ? 'Send' : 'Resume')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (EmailCampaign $c) => ! $c->isFinished() && $c->pendingCount() > 0)
                    ->requiresConfirmation()
                    ->modalHeading(fn (EmailCampaign $c) => ($c->isDraft() ? 'Send' : 'Resume') . ' — ' . $c->subject)
                    ->modalDescription(fn (EmailCampaign $c) => 'Queues ' . number_format($c->pendingCount())
                        . ' email(s). Anyone already written to is skipped.')
                    ->action(function (EmailCampaign $c) {
                        $n = app(EmailCampaignService::class)->queue($c);
                        Notification::make()->title(number_format($n) . ' email(s) queued')
                            ->body('The queue worker sends them in the background.')->success()->send();
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('Stop')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (EmailCampaign $c) => $c->isRunning())
                    ->requiresConfirmation()
                    ->modalDescription('Stops before the next recipient. Emails already sent cannot be recalled.')
                    ->action(function (EmailCampaign $c) {
                        app(EmailCampaignService::class)->cancel($c);
                        Notification::make()->title('Send stopped')->success()->send();
                    }),

                // Sends the real thing, rendered exactly as a recipient sees it,
                // to the admin looking at the screen. No recipient row is
                // touched, so the campaign's own counters stay honest.
                Tables\Actions\Action::make('test')
                    ->label('Send test to me')
                    ->icon('heroicon-o-beaker')
                    ->color('gray')
                    ->action(function (EmailCampaign $c) {
                        static::sendTest($c, auth()->user());
                    }),

                Tables\Actions\ReplicateAction::make()
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->excludeAttributes(['status', 'total_recipients', 'sent_count', 'failed_count', 'queued_at', 'completed_at'])
                    ->beforeReplicaSaved(function (EmailCampaign $replica) {
                        $replica->status = 'draft';
                        $replica->created_by = auth()->id();
                        $replica->subject = $replica->subject . ' (copy)';
                    })
                    ->successRedirectUrl(fn (EmailCampaign $replica) => static::getUrl('view', ['record' => $replica])),
            ])
            ->defaultSort('created_at', 'desc')
            // A running send updates its counters from the queue worker, so the
            // list has to refresh itself or it looks stuck.
            ->poll(fn () => EmailCampaign::whereIn('status', ['queued', 'sending'])->exists() ? '5s' : null);
    }

    /** Renders the campaign against a throwaway recipient row for the given user. */
    public static function sendTest(EmailCampaign $campaign, User $user): void
    {
        if (blank($user->email)) {
            Notification::make()->title('Your account has no email address')->danger()->send();

            return;
        }

        // Not persisted: a test must never appear in the ledger, or it would be
        // counted as delivered to somebody.
        $preview = new EmailCampaignRecipient([
            'email_campaign_id' => $campaign->id,
            'user_id'           => $user->id,
            'email'             => $user->email,
            'name'              => $user->full_name,
            'status'            => 'pending',
        ]);
        $preview->setRelation('campaign', $campaign);

        try {
            Mail::to($user->email, $user->full_name)->send(new AdminBroadcastMail($preview));

            Notification::make()->title('Test sent to ' . $user->email)
                ->body(config('mail.default') === 'log'
                    ? 'MAIL_MAILER is "log" on this machine — look in storage/logs/laravel.log.'
                    : 'Check your inbox.')
                ->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Test failed')->body($e->getMessage())->danger()->send();
        }
    }

    public static function getRelations(): array
    {
        return [RelationManagers\RecipientsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListEmailCampaigns::route('/'),
            'create' => Pages\CreateEmailCampaign::route('/compose'),
            'view'   => Pages\ViewEmailCampaign::route('/{record}'),
            'edit'   => Pages\EditEmailCampaign::route('/{record}/edit'),
        ];
    }
}
