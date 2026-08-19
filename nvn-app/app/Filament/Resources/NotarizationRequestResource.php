<?php

namespace App\Filament\Resources;

use App\Enums\RequestStatus;
use App\Filament\Resources\NotarizationRequestResource\Pages;
use App\Models\NotarizationRequest;
use App\Services\MessagingService;
use App\Support\AuditLogger;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class NotarizationRequestResource extends Resource
{
    protected static ?string $model = NotarizationRequest::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-check';
    protected static ?string $navigationGroup = 'Requests & sessions';
    protected static ?string $navigationLabel = 'Requests';
    protected static ?string $modelLabel = 'request';

    public static function table(Table $table): Table
    {
        return $table
            // finalDocument decides whether the "Notarized doc" action shows —
            // eager load it so the list doesn't fire a query per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('finalDocument'))
            ->columns([
                Tables\Columns\TextColumn::make('reference')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('client.full_name')->label('Client')->searchable(),
                // The notary of record — whose seal is on the document — even
                // when the platform did the work. See was_fallback for that.
                Tables\Columns\TextColumn::make('notary.user.full_name')->label('Notary')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())
                    ->color(fn ($state) => match ($state) {
                        RequestStatus::Completed => 'success',
                        RequestStatus::Paid, RequestStatus::Accepted, RequestStatus::Scheduled,
                        RequestStatus::InVerification, RequestStatus::Notarizing => 'warning',
                        RequestStatus::Cancelled, RequestStatus::Refunded => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('hard_copy_requested')->label('Hard copy')->boolean(),
                Tables\Columns\IconColumn::make('was_fallback')->label('Platform-covered')->boolean()
                    ->tooltip('The platform did the work on the assigned notary\'s behalf, under their seal.'),
                Tables\Columns\TextColumn::make('currency'),
                // Only ever set on an unpaid request, so an empty cell on a
                // completed job is not a gap — there was nothing to chase.
                Tables\Columns\TextColumn::make('payment_followed_up_at')
                    ->label('Chased')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, NotarizationRequest $record) => $record->payment_followups_sent
                        . 'x · ' . $state?->diffForHumans())
                    ->tooltip('Last time a client was asked about this unpaid request.')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('j M Y')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(RequestStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                Tables\Filters\TernaryFilter::make('hard_copy_requested')->label('Hard copy'),
                Tables\Filters\TernaryFilter::make('was_fallback')->label('Platform-covered'),
                // The whole point of the follow-up feature is finding these
                // people, and "status is draft or submitted" is two clicks and
                // a thing you have to know.
                Tables\Filters\Filter::make('awaiting_payment')
                    ->label('Waiting on payment')
                    ->query(fn (Builder $query) => $query->unpaid()),
                Tables\Filters\Filter::make('never_followed_up')
                    ->label('Not chased yet')
                    ->query(fn (Builder $query) => $query->unpaid()->whereNull('payment_followed_up_at')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('messages')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->url(fn (NotarizationRequest $r) => route('admin.messages.show', $r)),
                Tables\Actions\Action::make('followUpPayment')
                    ->label('Follow up on payment')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('warning')
                    ->visible(fn (NotarizationRequest $r) => $r->awaitingPayment() && $r->client)
                    ->modalHeading('Ask why this has not been paid')
                    ->modalDescription('Posts a message on this request. The client gets an email and a '
                        . 'notification, and their reply comes back to the same thread — so this is a '
                        . 'conversation, not a reminder.')
                    ->modalSubmitActionLabel('Send message')
                    ->modalWidth('2xl')
                    ->form(fn (NotarizationRequest $r) => static::followUpFormSchema($r))
                    ->action(function (NotarizationRequest $r, array $data) {
                        static::sendFollowUp($r, $data['body']);

                        Notification::make()
                            ->title('Follow-up sent')
                            ->body('Messaged ' . ($r->client?->full_name ?? 'the client')
                                . ' about ' . $r->reference . '.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('notarized')
                    ->label('Notarized doc')
                    ->icon('heroicon-o-document-check')
                    ->color('success')
                    ->visible(fn (NotarizationRequest $r) => (bool) $r->finalDocument)
                    ->url(fn (NotarizationRequest $r) => route('admin.requests.notarized', $r))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                // Same message to several stalled clients at once. The template
                // is re-rendered per client, so each one still reads as though
                // it were written to them — name, reference and their own fee.
                Tables\Actions\BulkAction::make('followUpPaymentBulk')
                    ->label('Follow up on payment')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('warning')
                    ->modalHeading('Ask several clients why they have not paid')
                    ->modalDescription('Only unpaid requests are messaged — anything else in the '
                        . 'selection is skipped. Each message is personalised with that client\'s '
                        . 'name, reference and fee. Clients who never chose a notary are asked '
                        . 'about that instead, whichever message you pick.')
                    ->modalSubmitActionLabel('Send messages')
                    ->form([
                        Select::make('template')
                            ->label('Message')
                            ->options(static::followUpTemplates())
                            ->default('check_in')
                            ->live()
                            ->required(),
                        Textarea::make('body')
                            ->label('Your own wording')
                            ->rows(10)
                            ->maxLength(5000)
                            ->required(fn (callable $get) => $get('template') === 'custom')
                            ->visible(fn (callable $get) => $get('template') === 'custom')
                            ->helperText('Sent word for word to everyone selected, so keep it general.'),
                    ])
                    ->action(function (Collection $records, array $data) {
                        $sent = $skipped = 0;

                        foreach ($records as $record) {
                            if (! $record->awaitingPayment() || ! $record->client) {
                                $skipped++;

                                continue;
                            }

                            $body = $data['template'] === 'custom'
                                ? $data['body']
                                : static::followUpBody($data['template'], $record);

                            static::sendFollowUp($record, $body);
                            $sent++;
                        }

                        Notification::make()
                            ->title($sent . ' follow-up' . ($sent === 1 ? '' : 's') . ' sent')
                            ->body($skipped > 0
                                ? $skipped . ' skipped — already paid, or no client account.'
                                : 'Every selected client has been messaged.')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotarizationRequests::route('/'),
            'view'  => Pages\ViewNotarizationRequest::route('/{record}'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Payment follow-up
    |--------------------------------------------------------------------------
    |
    | Deliberately manual. "Why has this not been paid" has answers — the card
    | was declined, the price is wrong, they changed their mind — and every one
    | of them wants a person reading the reply. An automatic third chaser would
    | just be pressure applied to somebody who has already decided.
    |
    | It posts into the request's own message thread rather than sending a
    | standalone email, so the client can reply in one tap and the answer lands
    | back on the desk instead of in a mailbox nobody watches.
    */

    /**
     * @return array<string, string>
     *
     * Given a request, the list narrows to what makes sense for it. A client
     * who never chose a notary has no price and has never seen a payment
     * screen, so "your payment was declined" and "the price may be the
     * problem" are both about something that has not happened to them yet.
     */
    public static function followUpTemplates(?NotarizationRequest $request = null): array
    {
        if ($request && ! $request->isPriced()) {
            return [
                'no_notary' => 'Stopped before choosing a notary',
                'check_in'  => 'Friendly check-in — did something go wrong?',
                'custom'    => 'Write my own',
            ];
        }

        return [
            'check_in'        => 'Friendly check-in — did something go wrong?',
            'payment_trouble' => 'Payment did not go through / offer bank transfer',
            'pricing'         => 'The price may be the problem',
            'no_notary'       => 'Stopped before choosing a notary',
            'custom'          => 'Write my own',
        ];
    }

    /**
     * The opening message for a template, filled in for one client.
     *
     * Written to be answerable: each one ends on a question and says a person
     * will read it, because the point of this is the reply, not the nudge.
     */
    public static function followUpBody(?string $template, NotarizationRequest $request): string
    {
        $name = str($request->client?->full_name ?? '')->before(' ')->toString() ?: 'there';
        $ref  = $request->reference;
        $fee  = $request->displayFeeOrPending();

        // The bulk action picks one template for a whole selection, which can
        // mix clients who stalled at the payment screen with clients who never
        // reached it. Telling someone their payment was declined when they
        // never chose a notary is worse than not writing at all, so an
        // unpriced request answers the question it is actually in.
        if (! $request->isPriced() && in_array($template, ['payment_trouble', 'pricing'], true)) {
            $template = 'no_notary';
        }

        // "Waiting on payment" is only true once there is something to pay.
        $stalled = $request->isPriced()
            ? 'is still waiting on payment'
            : 'is still open — it has not been sent to a notary yet';

        return match ($template) {
            'check_in' => <<<TXT
            Hello {$name},

            We noticed your notarization request {$ref} {$stalled}, and we wanted to check in rather than leave you to it.

            Did you run into a hitch finishing it — a card that would not go through, a page that would not load, or something about the request itself you would like changed before you pay? If the price is the sticking point, tell us that too.

            Just reply to this message and a person will read it.
            TXT,

            'no_notary' => <<<TXT
            Hello {$name},

            Thank you for uploading your document to us — your request {$ref} is saved and nothing has been lost.

            It looks like you stopped at the point of choosing a notary, and we wanted to ask what got in the way rather than assume. The usual reasons are that the choice was not obvious, none of the available times worked, or the cost was not what you expected. Any of those we can help with directly — we can recommend a notary for the kind of document you uploaded, find you a different time, or tell you what it would come to before you commit to anything.

            Reply to this message and a person will read it and pick it up from there.
            TXT,

            'payment_trouble' => <<<TXT
            Hello {$name},

            Your notarization request {$ref} is still showing as unpaid, and we wanted to make sure it is not our end that is the problem.

            If the payment was declined, that is usually a card limit or a bank block on online payments rather than anything wrong with your card. Two things that help: try again on a different card, or let us take it by bank transfer instead — reply here and we will send you the account details and mark the request paid ourselves once it lands.

            The fee on this request is {$fee}. Reply if anything about that looks wrong.
            TXT,

            'pricing' => <<<TXT
            Hello {$name},

            Your notarization request {$ref} is priced at {$fee} and has not been paid yet. If the cost is the reason, we would much rather hear it than lose you quietly.

            Reply and tell us what you were expecting to pay. Depending on the document and how many pages it runs to there may be a cheaper way to do it, and some requests end up priced higher than they need to be simply because of how they were entered.

            A person reads these — we can look at your request specifically.
            TXT,

            default => '',
        };
    }

    /**
     * Post the follow-up and remember that we did.
     *
     * The counter is what stops two admins chasing the same client an hour
     * apart, which is worse than not chasing at all.
     */
    public static function sendFollowUp(NotarizationRequest $request, string $body): void
    {
        app(MessagingService::class)->post($request, auth()->user(), $body);

        $request->update([
            'payment_followed_up_at' => now(),
            'payment_followups_sent' => $request->payment_followups_sent + 1,
        ]);

        AuditLogger::record('request.payment_followed_up', 'notarization_request', $request->id, [
            'followup' => $request->payment_followups_sent,
            'balance'  => $request->balanceMinor(),
        ]);
    }

    /** The modal, shared by the row action and the header action on the record page. */
    public static function followUpFormSchema(NotarizationRequest $request): array
    {
        return [
            Placeholder::make('context')
                ->label('This request')
                ->content(fn () => $request->reference
                    . ' · ' . ($request->client?->full_name ?? 'unknown client')
                    . ' · ' . $request->displayFeeOrPending()
                    . ($request->isPriced() ? '' : ' · no notary chosen')
                    . ' · created ' . $request->created_at?->diffForHumans()
                    . ($request->payment_followups_sent > 0
                        ? ' · already chased ' . $request->payment_followups_sent . 'x, last '
                            . $request->payment_followed_up_at?->diffForHumans()
                        : ' · never chased')),

            Select::make('template')
                ->label('Opening message')
                ->options(static::followUpTemplates($request))
                ->default($request->isPriced() ? 'check_in' : 'no_notary')
                ->live()
                ->afterStateUpdated(fn ($state, Set $set) => $set('body', static::followUpBody($state, $request)))
                ->helperText('Pick one and edit it — it is a starting point, not a form letter.'),

            Textarea::make('body')
                ->label('Message')
                ->default(fn () => static::followUpBody(
                    $request->isPriced() ? 'check_in' : 'no_notary',
                    $request,
                ))
                ->rows(14)
                ->required()
                ->maxLength(5000)
                ->helperText('Sent as a message on this request. The client gets an email and a '
                    . 'notification, and anything they reply comes back to this thread.'),
        ];
    }
}
