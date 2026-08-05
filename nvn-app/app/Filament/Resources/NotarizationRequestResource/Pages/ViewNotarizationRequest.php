<?php

namespace App\Filament\Resources\NotarizationRequestResource\Pages;

use App\Enums\RequestStatus;
use App\Filament\Resources\NotarizationRequestResource;
use App\Models\Payment;
use App\Services\RequestFulfillmentService;
use Filament\Actions;
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
                TextEntry::make('document_use')->label('Reason')->columnSpanFull(),
            ])->columns(2),
            Section::make('Scheduling')->schema([
                TextEntry::make('session.scheduled_start_at')->label('Scheduled')->dateTime()->placeholder('—'),
                TextEntry::make('session.identity_verified')->label('Identity verified')->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Verified' : 'Not yet'),
                TextEntry::make('session.verification_method')->label('Method')->placeholder('—'),
            ])->columns(3),
            Section::make('Notarized document')
                ->description('The sealed PDF produced at the end of the session — whether a partner notary or the admin desk completed it.')
                ->schema([
                    TextEntry::make('finalDocument.original_filename')
                        ->label('File')
                        ->placeholder('Not sealed yet'),
                    TextEntry::make('finalDocument.created_at')
                        ->label('Sealed at')
                        ->dateTime('j M Y · g:i A')
                        ->placeholder('—'),
                    TextEntry::make('sealed_by')
                        ->label('Sealed by')
                        ->state(fn ($record) => $record->handledBy?->full_name
                            ?? $record->notary?->user?->full_name)
                        ->placeholder('—'),
                    TextEntry::make('open_notarized')
                        ->label('Document')
                        ->state(fn ($record) => $record->finalDocument ? 'Open in a new tab' : null)
                        ->placeholder('No sealed document on file yet')
                        ->badge()
                        ->color('success')
                        ->url(fn ($record) => $record->finalDocument
                            ? route('admin.requests.notarized', $record)
                            : null)
                        ->openUrlInNewTab(),
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
