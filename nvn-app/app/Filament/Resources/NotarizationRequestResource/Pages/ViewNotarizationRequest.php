<?php

namespace App\Filament\Resources\NotarizationRequestResource\Pages;

use App\Enums\RequestStatus;
use App\Filament\Resources\NotarizationRequestResource;
use App\Models\Payment;
use App\Services\RequestFulfillmentService;
use Filament\Actions;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
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
                    ->state(fn ($record) => $record->displayFee()
                        . ($record->billableDocumentCount() > 1
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
            Section::make('Scheduling')->schema([
                TextEntry::make('session.scheduled_start_at')->label('Scheduled')->dateTime()->placeholder('—'),
                TextEntry::make('session.identity_verified')->label('Identity verified')->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Verified' : 'Not yet'),
                TextEntry::make('session.verification_method')->label('Method')->placeholder('—'),
            ])->columns(3),
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
                            TextEntry::make('open')
                                ->label('Document')
                                ->state('Open in a new tab')
                                ->badge()
                                ->color('success')
                                ->url(fn ($record) => route('admin.requests.notarized', [
                                    $record->request_id, 'document' => $record->id,
                                ]))
                                ->openUrlInNewTab(),
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
