<?php

namespace App\Filament\Resources\NotarizationRequestResource\Pages;

use App\Enums\RequestStatus;
use App\Filament\Resources\NotarizationRequestResource;
use App\Models\NotaryService;
use App\Models\Payment;
use App\Services\RequestCategoryService;
use App\Services\RequestFulfillmentService;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewNotarizationRequest extends ViewRecord
{
    protected static string $resource = NotarizationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('mark_paid')
                ->label('Confirm payment')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm payment manually')
                ->modalDescription('Use this to mark the payment as received when the Paystack webhook could not reach your server (e.g. local development). Only use if you have confirmed the payment on Paystack\'s dashboard.')
                ->visible(fn ($record) => in_array($record->status, [RequestStatus::Submitted, RequestStatus::Draft], true))
                ->action(function ($record) {
                    $payment = Payment::where('request_id', $record->id)
                        ->where('type', 'request_fee')
                        ->where('status', 'pending')
                        ->latest()
                        ->first();

                    if (! $payment) {
                        Notification::make()->title('No pending payment found for this request')->warning()->send();
                        return;
                    }

                    app(RequestFulfillmentService::class)->markPaid($payment->paystack_reference);
                    $this->record->refresh();
                    Notification::make()->title('Payment confirmed — request is now paid')->success()->send();
                }),

            // The same conversation the list offers, from the page where you
            // can actually see what they asked for and what it costs.
            Actions\Action::make('followUpPayment')
                ->label('Follow up on payment')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('warning')
                ->visible(fn ($record) => $record->awaitingPayment() && $record->client)
                ->modalHeading('Ask why this has not been paid')
                ->modalDescription('Posts a message on this request. The client gets an email and a '
                    . 'notification, and their reply comes back to the same thread.')
                ->modalSubmitActionLabel('Send message')
                ->modalWidth('2xl')
                ->form(fn ($record) => NotarizationRequestResource::followUpFormSchema($record))
                ->action(function ($record, array $data) {
                    NotarizationRequestResource::sendFollowUp($record, $data['body']);
                    $this->record->refresh();

                    Notification::make()
                        ->title('Follow-up sent')
                        ->body('Messaged ' . ($record->client?->full_name ?? 'the client') . '.')
                        ->success()
                        ->send();
                }),

            // Wrong category. The admin gets this on every live request, not
            // just their own desk's, because they are the only person who sees
            // a partner's work before the client does — and a document filed
            // under the wrong service is sealed wrongly or not at all.
            Actions\Action::make('queryCategory')
                ->label('Wrong category')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn ($record) => $record->isPriced()
                    && ! $record->hasOpenCategoryQuery()
                    && in_array($record->status, RequestStatus::active(), true))
                ->modalHeading('Send this back to the client')
                ->modalDescription('Nothing is refunded and nothing is cancelled. What they have paid '
                    . 'stays on the request; they re-pick and are charged only the difference, if there is one.')
                ->modalSubmitActionLabel('Send it back')
                ->modalWidth('2xl')
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('What is wrong with it?')
                        ->helperText('The client sees this word for word, so write it to them.')
                        ->placeholder('e.g. This is a deed of assignment, not an affidavit — it needs two witnesses and a different jurat.')
                        ->rows(3)
                        ->required()
                        ->maxLength(1000),
                    Forms\Components\Select::make('service_id')
                        ->label('What should it be?')
                        ->helperText('A recommendation only — the client still chooses, so the price they '
                            . 'end up paying is one they agreed to. Leave blank to let them decide.')
                        // The assigned notary's own list. Anything else would
                        // price this job at a notary who is not doing it.
                        ->options(fn ($record) => NotaryService::where('notary_profile_id', $record->notary_id)
                            ->where('active', true)
                            ->where('id', '!=', $record->service_id)
                            ->orderBy('service_type')
                            ->get()
                            ->mapWithKeys(fn (NotaryService $s) => [
                                $s->id => $s->service_type . ' — ' . $s->displayPrice($record->currency ?: 'NGN'),
                            ]))
                        ->searchable()
                        ->native(false),
                ])
                ->action(function ($record, array $data) {
                    $suggested = ! empty($data['service_id'])
                        ? NotaryService::where('notary_profile_id', $record->notary_id)
                            ->where('active', true)
                            ->find($data['service_id'])
                        : null;

                    app(RequestCategoryService::class)->query(
                        $record, auth()->user(), $data['reason'], $suggested,
                    );

                    $this->record->refresh();

                    Notification::make()
                        ->title('Sent back to the client')
                        ->body('Their payment is untouched. They will be asked only for the difference.')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('withdrawCategoryQuery')
                ->label('Withdraw query')
                ->icon('heroicon-o-arrow-uturn-right')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Withdraw the category query')
                ->modalDescription('The request goes back on the desk exactly as it was booked. The client is not emailed again.')
                ->visible(fn ($record) => $record->hasOpenCategoryQuery())
                ->action(function ($record) {
                    app(RequestCategoryService::class)->withdraw($record, auth()->user());
                    $this->record->refresh();

                    Notification::make()->title('Query withdrawn')->success()->send();
                }),

            // Available for every sealed request, whoever notarized it.
            Actions\Action::make('view_notarized')
                ->label('View notarized document')
                ->icon('heroicon-o-document-check')
                ->color('success')
                ->visible(fn ($record) => (bool) $record->finalDocument)
                ->url(fn ($record) => route('admin.requests.notarized', $record))
                ->openUrlInNewTab(),

            Actions\Action::make('download_notarized')
                ->label('Download')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn ($record) => (bool) $record->finalDocument)
                ->url(fn ($record) => route('client.documents.download', $record)),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Request')->schema([
                TextEntry::make('reference'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => $state->label()),
                TextEntry::make('client.full_name')->label('Client'),
                TextEntry::make('notary.user.full_name')->label('Notary')->placeholder('—'),
                TextEntry::make('handledBy.full_name')->label('Handled by (fallback)')->placeholder('—'),
                TextEntry::make('currency'),
                TextEntry::make('fee')
                    ->label('Fee')
                    // "not set yet" rather than ₦0.00 — an unpriced request has
                    // no service, and a zero here reads as free.
                    ->state(fn ($record) => $record->displayFeeOrPending()
                        . ($record->isPriced() && $record->billableDocumentCount() > 1
                            ? ' (' . $record->billableDocumentCount() . ' documents)'
                            : '')),
                // Part payments are possible, so "paid" is a sum against a total
                // rather than a yes/no. An outstanding balance is shown in red
                // because it is the one number here that needs chasing.
                TextEntry::make('balance')
                    ->label('Received')
                    ->state(fn ($record) => \App\Models\NotarizationRequest::money(
                        $record->amountPaidMinor(), $record->currency,
                    ) . ($record->balanceMinor() > 0
                        ? ' — ' . $record->displayBalance() . ' outstanding'
                        : ''))
                    ->badge()
                    ->color(fn ($record) => $record->balanceMinor() > 0 ? 'danger' : 'success'),
                TextEntry::make('document_use')->label('Reason')->columnSpanFull(),
            ])->columns(2),
            // Only present once someone has queried the category, and then it
            // is the most important thing on the page: a request that looks
            // Paid and on time is going nowhere while this is open.
            Section::make('Category query')
                ->description('The desk said this document is not what it was booked as. The payment was left where it is.')
                ->visible(fn ($record) => $record->category_query_at !== null)
                ->schema([
                    TextEntry::make('category_state')
                        ->label('State')
                        ->badge()
                        ->state(fn ($record) => match (true) {
                            $record->hasOpenCategoryQuery()        => 'Waiting on the client',
                            $record->awaitingCategoryDifference()  => 'Answered — ' . $record->displayBalance() . ' outstanding',
                            default                               => 'Settled',
                        })
                        ->color(fn ($record) => $record->isCategoryBlocked() ? 'warning' : 'success'),
                    TextEntry::make('categoryQueriedBy.full_name')->label('Raised by')->placeholder('—'),
                    TextEntry::make('category_query_at')->label('Raised')->dateTime('j M Y · g:i A')->placeholder('—'),
                    TextEntry::make('category_query_resolved_at')->label('Answered')->dateTime('j M Y · g:i A')->placeholder('Not yet'),
                    TextEntry::make('categorySuggestedService.service_type')->label('Recommended')->placeholder('Left to the client'),
                    // Surfaced only when it happens, because a credit is a
                    // decision someone has to make and nothing here makes it.
                    TextEntry::make('overpaid')
                        ->label('In credit')
                        ->badge()
                        ->color('warning')
                        ->state(fn ($record) => $record->displayOverpaid() . ' — refund or credit the client')
                        ->visible(fn ($record) => $record->overpaidMinor() > 0),
                    TextEntry::make('category_query_reason')->label('Reason given')->columnSpanFull()->placeholder('—'),
                ])->columns(3),
            Section::make('Scheduling')->schema([
                TextEntry::make('session.scheduled_start_at')->label('Scheduled')->dateTime()->placeholder('—'),
                TextEntry::make('session.identity_verified')->label('Identity verified')->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Verified' : 'Not yet'),
                TextEntry::make('session.verification_method')->label('Method')->placeholder('—'),
            ])->columns(3),
            // Before the sealed section, because on a stalled draft this is the
            // only section with anything in it — and reading what the client
            // uploaded is how you decide whether the request is worth chasing.
            Section::make('Uploaded documents')
                ->description('What the client sent, including identification and signature. Available from the moment of upload, whether or not a notary has been chosen.')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('uploadedDocuments')
                        ->label('')
                        ->placeholder('Nothing uploaded yet')
                        ->columnSpanFull()
                        ->schema([
                            TextEntry::make('original_filename')
                                ->label('File')
                                ->placeholder('—'),
                            TextEntry::make('file_type')
                                ->label('Type')
                                ->badge()
                                ->color(fn ($state) => $state === 'document' ? 'primary' : 'gray'),
                            TextEntry::make('created_at')
                                ->label('Uploaded')
                                ->dateTime('j M Y · g:i A'),
                            // Opens over the page rather than in a new tab: the
                            // point of reading these is to judge the request,
                            // and the request is what a new tab covers up.
                            ViewEntry::make('preview')
                                ->label('Preview')
                                ->view('filament.infolists.document-preview')
                                ->viewData(['kind' => 'upload']),
                        ])->columns(4),
                ]),

            Section::make('Notarized documents')
                ->description('One sealed PDF per document notarized — whether a partner notary or the admin desk completed it.')
                ->schema([
                    TextEntry::make('sealed_by')
                        ->label('Sealed by')
                        ->state(fn ($record) => $record->handledBy?->full_name
                            ?? $record->notary?->user?->full_name)
                        ->placeholder('—'),
                    TextEntry::make('sealed_count')
                        ->label('Documents sealed')
                        ->state(fn ($record) => $record->finalDocuments->count()
                            . ' of ' . $record->billableDocumentCount())
                        // A count that does not match is the one thing worth
                        // spotting here: it means part of what the client paid
                        // for has not been produced.
                        ->color(fn ($record) => $record->finalDocuments->count() >= $record->billableDocumentCount()
                            ? 'success'
                            : 'warning')
                        ->badge(),
                    RepeatableEntry::make('finalDocuments')
                        ->label('')
                        ->placeholder('Not sealed yet')
                        ->columnSpanFull()
                        ->schema([
                            TextEntry::make('original_filename')->label('File'),
                            TextEntry::make('created_at')
                                ->label('Sealed at')
                                ->dateTime('j M Y · g:i A'),
                            ViewEntry::make('preview')
                                ->label('Document')
                                ->view('filament.infolists.document-preview')
                                ->viewData(['kind' => 'sealed']),
                        ])->columns(3),
                ])->columns(2),

            Section::make('Delivery')->schema([
                TextEntry::make('hard_copy_requested')->label('Hard copy')
                    ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
                TextEntry::make('delivery_address')->label('Address')
                    ->formatStateUsing(fn ($state) => $state ? collect($state)->filter()->implode(', ') : '—'),
            ]),
        ]);
    }
}
