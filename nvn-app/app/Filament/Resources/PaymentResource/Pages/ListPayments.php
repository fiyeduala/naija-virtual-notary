<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Enums\RequestStatus;
use App\Filament\Resources\PaymentResource;
use App\Models\NotarizationRequest;
use App\Services\OfflinePaymentService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('recordOffline')
                ->label('Record a payment')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('primary')
                ->modalHeading('Record a fee paid outside Paystack')
                ->modalDescription('For a client who paid into the company account instead of using the '
                    . 'checkout page. It clears their request exactly as a card payment would — the notary '
                    . 'is notified and the job goes live — so only do it once the money has arrived. '
                    . 'Use it again on the same request to record a balance: a client who paid for one '
                    . 'document and transferred the rest is recorded as two payments, not one edited row.')
                ->modalSubmitActionLabel('Record payment')
                ->form([
                    Forms\Components\Select::make('request_id')
                        ->label('Which request?')
                        ->options(fn () => static::unpaidRequests())
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn ($state, Forms\Set $set) => $set(
                            'amount_major',
                            ($r = static::request($state)) ? round($r->balanceMinor() / 100, 2) : null,
                        ))
                        ->helperText('Requests still owing money — including part-paid ones.')
                        ->columnSpanFull(),

                    // Prefilled with what is still owed but editable: a client
                    // may have sent a different figure, and recording what they
                    // actually paid beats recording what they should have.
                    Forms\Components\TextInput::make('amount_major')
                        ->label('Amount received')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->prefix(fn (Forms\Get $get) => static::request($get('request_id'))?->currency === 'USD' ? '$' : '₦')
                        ->helperText(fn (Forms\Get $get) => ($r = static::request($get('request_id')))
                            ? static::amountHelp($r)
                            : 'Pick a request first.'),

                    ...PaymentResource::settlementFields(),
                ])
                ->action(function (array $data, OfflinePaymentService $offline) {
                    $request = NotarizationRequest::find($data['request_id']);

                    if (! $request) {
                        Notification::make()->title('That request no longer exists')->danger()->send();

                        return;
                    }

                    // Someone else may have settled it in full while this form sat
                    // open. A request that is only PART paid is not refused —
                    // this is how the balance gets recorded.
                    if ($request->isFullyPaid() && ($paid = $offline->settledFee($request))) {
                        Notification::make()
                            ->title('This fee has already been paid in full')
                            ->body(PaymentResource::money($paid->amount, $paid->currency) . ' cleared on '
                                . $paid->completed_at?->format('j M Y') . ' (' . $paid->settlementLabel()
                                . '). Nothing was recorded — if money genuinely arrived twice, that is a refund.')
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $topUp = $request->amountPaidMinor() > 0;

                    $payment = $offline->recordRequestFee($request, $data + [
                        'amount' => (int) round((float) $data['amount_major'] * 100),
                    ], auth()->id());

                    // Read the balance again afterwards: a top-up that does not
                    // close the gap must say so, or an admin walks away thinking
                    // the job is fully paid when it is not.
                    $outstanding = $request->fresh()->balanceMinor();

                    Notification::make()
                        ->title($topUp ? 'Balance recorded' : 'Payment recorded')
                        ->body(PaymentResource::money($payment->amount, $payment->currency)
                            . ' cleared for ' . $request->reference . '. '
                            . ($outstanding > 0
                                ? PaymentResource::money($outstanding, $request->currency) . ' is still outstanding.'
                                : ($topUp
                                    ? 'The fee is now paid in full.'
                                    : 'The notary has been notified.')))
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    /**
     * Requests still waiting on money, newest first.
     *
     * "Waiting" is not the same as "unpaid". A request that has moved past
     * Submitted can still owe money — a client who paid for one document and
     * sent the balance by transfer is the case this exists for — so anything in
     * flight with a balance is listed too, and labelled with what is left.
     *
     * Capped rather than paginated: a hundred is far past the point where an
     * admin would scroll instead of typing into the search box.
     */
    private static function unpaidRequests(): array
    {
        return NotarizationRequest::query()
            ->where(fn ($q) => $q
                ->whereIn('status', [RequestStatus::Draft->value, RequestStatus::Submitted->value])
                ->orWhere(fn ($q) => $q->inFlight()))
            ->with('client:id,full_name', 'service:id,service_type', 'notarizableDocuments:id,request_id,file_type')
            ->latest('id')
            ->limit(100)
            ->get()
            ->reject(fn (NotarizationRequest $r) => $r->isFullyPaid())
            ->mapWithKeys(fn (NotarizationRequest $r) => [
                $r->id => $r->reference . ' — ' . ($r->client?->full_name ?? 'unknown client')
                    . ' · ' . str($r->service?->service_type ?? 'no service')->headline()
                    . ' · ' . ($r->amountPaidMinor() > 0
                        ? $r->displayBalance() . ' still owed'
                        : $r->displayFee()),
            ])
            ->all();
    }

    private static function request(mixed $id): ?NotarizationRequest
    {
        return $id ? NotarizationRequest::with('service')->find($id) : null;
    }

    /**
     * What the admin needs to know before typing a figure.
     *
     * Three facts, and only the ones that apply: what the job costs, how that
     * was arrived at when there is more than one document, and what is left if
     * money has already come in. The last one is the point of this text — the
     * field is prefilled with the balance, and an admin who cannot see how that
     * number was reached will not trust it.
     */
    private static function amountHelp(NotarizationRequest $r): string
    {
        $parts = ['Full price: ' . PaymentResource::money($r->feeMinor(), $r->currency)];

        if ($r->billableDocumentCount() > 1) {
            $parts[0] .= ' (' . PaymentResource::money($r->service?->priceFor($r->currency) ?? 0, $r->currency)
                . ' × ' . $r->billableDocumentCount() . ' documents)';
        }

        if (($paid = $r->amountPaidMinor()) > 0) {
            $parts[] = 'Already received: ' . PaymentResource::money($paid, $r->currency);
            $parts[] = 'Outstanding: ' . PaymentResource::money($r->balanceMinor(), $r->currency);
        }

        return implode(' · ', $parts);
    }
}
