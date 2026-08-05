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
                    . 'is notified and the job goes live — so only do it once the money has arrived.')
                ->modalSubmitActionLabel('Record payment')
                ->form([
                    Forms\Components\Select::make('request_id')
                        ->label('Which request?')
                        ->options(fn () => static::unpaidRequests())
                        ->searchable()
                        ->required()
                        ->live()
                        ->helperText('Only requests still waiting on payment are listed.')
                        ->columnSpanFull(),

                    // Prefilled from the booked service but editable: a client
                    // may have sent a different figure, and recording what they
                    // actually paid beats recording what they should have.
                    Forms\Components\TextInput::make('amount_major')
                        ->label('Amount received')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->prefix(fn (Forms\Get $get) => static::request($get('request_id'))?->currency === 'USD' ? '$' : '₦')
                        ->helperText(fn (Forms\Get $get) => ($r = static::request($get('request_id')))
                            ? 'Booked price: ' . PaymentResource::money($r->service?->priceFor($r->currency) ?? 0, $r->currency)
                            : 'Pick a request first.'),

                    ...PaymentResource::settlementFields(),
                ])
                ->action(function (array $data, OfflinePaymentService $offline) {
                    $request = NotarizationRequest::find($data['request_id']);

                    if (! $request) {
                        Notification::make()->title('That request no longer exists')->danger()->send();

                        return;
                    }

                    // Someone else may have recorded it while this form sat open.
                    if ($paid = $offline->settledFee($request)) {
                        Notification::make()
                            ->title('This fee has already been paid')
                            ->body(PaymentResource::money($paid->amount, $paid->currency) . ' cleared on '
                                . $paid->completed_at?->format('j M Y') . ' (' . $paid->settlementLabel()
                                . '). Nothing was recorded — if money genuinely arrived twice, that is a refund.')
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $payment = $offline->recordRequestFee($request, $data + [
                        'amount' => (int) round((float) $data['amount_major'] * 100),
                    ], auth()->id());

                    Notification::make()
                        ->title('Payment recorded')
                        ->body(PaymentResource::money($payment->amount, $payment->currency)
                            . ' cleared for ' . $request->reference . '. The notary has been notified.')
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    /**
     * Requests still waiting on money, newest first.
     *
     * Capped rather than paginated: a hundred is far past the point where an
     * admin would scroll instead of typing into the search box.
     */
    private static function unpaidRequests(): array
    {
        return NotarizationRequest::query()
            ->whereIn('status', [RequestStatus::Draft->value, RequestStatus::Submitted->value])
            ->with('client:id,full_name', 'service:id,service_type')
            ->latest('id')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (NotarizationRequest $r) => [
                $r->id => $r->reference . ' — ' . ($r->client?->full_name ?? 'unknown client')
                    . ' · ' . str($r->service?->service_type ?? 'no service')->headline(),
            ])
            ->all();
    }

    private static function request(mixed $id): ?NotarizationRequest
    {
        return $id ? NotarizationRequest::with('service')->find($id) : null;
    }
}
