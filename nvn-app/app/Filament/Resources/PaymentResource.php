<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Services\OfflinePaymentService;
use App\Support\SettlementMethod;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Payments & payouts';
    protected static ?string $navigationLabel = 'Payments';

    public static function money(?int $minor, string $currency = 'NGN'): string
    {
        return ($currency === 'USD' ? '$' : '₦') . number_format(((int) $minor) / 100, 2);
    }

    /**
     * The fields that describe a payment someone received by hand.
     *
     * Shared by the "record" and "confirm" actions so both capture the same
     * evidence — a half-recorded payment is worse than none, because it looks
     * complete.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function settlementFields(): array
    {
        return [
            Forms\Components\Select::make('method')
                ->label('How was it received?')
                ->options(SettlementMethod::OPTIONS)
                ->default('bank_transfer')
                ->required(),
            Forms\Components\DateTimePicker::make('received_at')
                ->label('When did the money arrive?')
                ->helperText('Not necessarily today. This is the date the fee counts from.')
                ->default(now())
                ->maxDate(now())
                ->required(),
            Forms\Components\TextInput::make('reference')
                ->label('Their reference')
                ->placeholder('e.g. the sender\'s transfer session ID, or a teller number')
                ->helperText('Optional, but it is how you match this to your bank statement later.')
                ->maxLength(255),
            Forms\Components\Textarea::make('note')
                ->label('Note')
                ->placeholder('Who confirmed it, which account it landed in, anything unusual')
                ->rows(2)
                ->maxLength(1000),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('paystack_reference')->label('Reference')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('user.full_name')->label('User')->searchable(),
                Tables\Columns\TextColumn::make('request.reference')->label('Request')->placeholder('—'),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('amount')->label('Amount')->numeric()
                    ->formatStateUsing(fn ($state, Payment $p) => static::money($state, $p->currency)),
                Tables\Columns\TextColumn::make('currency')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'successful' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'refunded' => 'gray', default => 'gray',
                }),
                // Paystack or a person. The distinction matters when a figure is
                // ever questioned: one has a webhook behind it, the other has an
                // admin's word and whatever they typed in.
                Tables\Columns\TextColumn::make('settlement_method')->label('How')
                    ->state(fn (Payment $p) => $p->settlementLabel())
                    ->badge()
                    ->color(fn (Payment $p) => $p->isOffline() ? 'gray' : 'info')
                    ->description(fn (Payment $p) => $p->isOffline() && $p->recordedBy
                        ? 'recorded by ' . $p->recordedBy->full_name
                        : $p->settlement_reference),
                Tables\Columns\TextColumn::make('completed_at')->dateTime('j M Y H:i')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options([
                    'request_fee' => 'Request fee', 'onboarding_fee' => 'Onboarding fee',
                    'offsite_fee' => 'Offsite sealing fee', 'payout' => 'Payout',
                ]),
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'successful' => 'Successful', 'failed' => 'Failed', 'refunded' => 'Refunded',
                ]),
                Tables\Filters\TernaryFilter::make('settlement_method')
                    ->label('Handled offline')
                    ->placeholder('All payments')
                    ->trueLabel('Recorded by hand')
                    ->falseLabel('Through Paystack')
                    ->queries(
                        true:  fn ($query) => $query->whereNotNull('settlement_method'),
                        false: fn ($query) => $query->whereNull('settlement_method'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('confirmOffline')
                    ->label('Received offline')->icon('heroicon-o-inbox-arrow-down')->color('success')
                    // Only for a fee still waiting. A successful payment needs no
                    // confirming, and a refunded one is a different conversation.
                    ->visible(fn (Payment $p) => in_array($p->status, ['pending', 'failed'], true))
                    ->modalHeading('Confirm this fee was paid outside Paystack')
                    ->modalDescription(fn (Payment $p) => 'This clears '
                        . static::money($p->amount, $p->currency) . ' for '
                        . ($p->user?->full_name ?? 'this user')
                        . ' exactly as a card payment would: '
                        . ($p->type === 'onboarding_fee'
                            ? 'their application is activated.'
                            : 'the notary is notified and the job goes live.')
                        . ' Only do this once the money is actually in the account.')
                    ->form(static::settlementFields())
                    ->action(function (Payment $p, array $data, OfflinePaymentService $offline) {
                        $offline->recordExisting($p, $data, auth()->id());

                        Notification::make()
                            ->title('Payment recorded')
                            ->body('Cleared as ' . SettlementMethod::label($data['method']) . '.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reverseOffline')
                    ->label('Undo')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    // Never offered on a Paystack payment: that is Paystack's
                    // fact to state, not an admin's to withdraw.
                    // Not offered once the fee has been rolled into a payout:
                    // undoing it there would leave the payout overstated.
                    ->visible(fn (Payment $p) => $p->isOffline()
                        && $p->status === 'successful'
                        && $p->payout_id === null)
                    ->modalHeading('Undo a payment recorded in error')
                    ->modalDescription('Marks the payment failed. The request is deliberately NOT rewound — '
                        . 'the notary has already been told about it and may have started work, so speak to '
                        . 'them rather than letting the job vanish underneath them.')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('What went wrong?')
                            ->required()
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (Payment $p, array $data, OfflinePaymentService $offline) {
                        $ok = $offline->reverse($p, $data['reason'], auth()->id());

                        Notification::make()
                            ->title($ok ? 'Payment undone' : 'Nothing to undo')
                            ->body($ok
                                ? 'The fee no longer counts. Check whether the job needs to be paused.'
                                : ($p->payout_id !== null
                                    ? 'This fee is already part of a payout. Cancel or regenerate that payout first.'
                                    : 'Only a payment recorded by hand can be undone here.'))
                            ->status($ok ? 'warning' : 'danger')
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayments::route('/')];
    }
}
