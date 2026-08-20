<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayoutResource\Pages;
use App\Models\Payout;
use App\Services\PayoutService;
use App\Support\AuditLogger;
use App\Support\SettlementMethod;
use App\Support\Settings;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;

class PayoutResource extends Resource
{
    protected static ?string $model = Payout::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-right';
    protected static ?string $navigationGroup = 'Payments & payouts';
    protected static ?string $navigationLabel = 'Payouts';

    /** Anything still owed but unsent is worth a nudge in the sidebar. */
    public static function getNavigationBadge(): ?string
    {
        $count = Payout::outstanding()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function money(?int $minor, string $currency = 'NGN'): string
    {
        return ($currency === 'USD' ? '$' : '₦') . number_format(((int) $minor) / 100, 2);
    }

    /**
     * Where to send it, spelled out.
     *
     * Shown on the manual-settlement form because that is the moment the admin
     * is about to type an account number into their bank app, and having it in
     * front of them beats a second tab open on the notary's profile. The number
     * itself sits behind a Show button — this form is the reason that button
     * exists, so it is the one place the digits are genuinely wanted.
     *
     * Returns markup rather than a string: a Placeholder renders an Htmlable,
     * and there is no way to put a working button inside a plain one.
     */
    public static function accountLine(Payout $payout): HtmlString|View
    {
        $bank = $payout->notaryProfile?->bankDetails;

        if (! $bank) {
            return new HtmlString('No payout account on file — ask them to add one before paying.');
        }

        return view('filament.payout-account-line', [
            'bank' => $bank,
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')->label('Ref')
                    ->searchable()->copyable()->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('notaryProfile.user.full_name')->label('Notary')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Notary is paid')
                    ->formatStateUsing(fn ($state, Payout $p) => static::money($state, $p->currency)),
                Tables\Columns\TextColumn::make('commission_amount')->label('Platform kept')
                    ->formatStateUsing(fn ($state, Payout $p) => static::money($state, $p->currency))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('payments_count')->label('Jobs')
                    ->counts('payments')->alignCenter(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'paid' => 'success', 'pending' => 'warning', 'processing' => 'info', 'failed' => 'danger', default => 'gray',
                })
                    // The reason a transfer bounced belongs next to the badge, not
                    // three clicks away — it is usually the whole story.
                    ->description(fn (Payout $p) => $p->failure_reason),
                Tables\Columns\TextColumn::make('settlement_method')->label('How')
                    ->state(fn (Payout $p) => $p->status === 'paid' ? $p->settlementLabel() : '—')
                    ->badge()
                    ->color(fn (Payout $p) => $p->isOffline() ? 'gray' : 'info')
                    ->description(fn (Payout $p) => $p->settlement_reference),
                // Only meaningful while the platform is actually sending
                // transfers; paying by hand needs no Paystack recipient.
                Tables\Columns\IconColumn::make('payable')->label('Account ready')
                    ->boolean()
                    ->visible(fn () => Settings::paystackTransfersEnabled())
                    ->state(fn (Payout $p) => $p->notaryProfile?->bankDetails?->isPayable() === true)
                    ->trueIcon('heroicon-o-check-circle')->falseIcon('heroicon-o-exclamation-triangle')
                    ->falseColor('warning')
                    ->tooltip(fn (Payout $p) => $p->notaryProfile?->bankDetails?->isPayable()
                        ? 'Verified account with a Paystack recipient'
                        : 'No verified account — this cannot be sent automatically'),
                Tables\Columns\TextColumn::make('period_end')->label('Up to')->date()->placeholder('—'),
                Tables\Columns\TextColumn::make('processed_at')->label('Processed')->dateTime('j M Y')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'processing' => 'Processing', 'paid' => 'Paid', 'failed' => 'Failed',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('breakdown')
                    ->label('Jobs')->icon('heroicon-o-list-bullet')->color('gray')
                    ->modalHeading('What this payout settles')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (Payout $p) => new HtmlString(static::breakdownHtml($p))),

                Tables\Actions\Action::make('send')
                    ->label('Send via Paystack')->icon('heroicon-o-paper-airplane')->color('success')
                    // Hidden entirely when automatic transfers are off, rather
                    // than shown greyed out: a button that can never work is
                    // noise, and "Record as paid" is the real workflow then.
                    ->visible(fn (Payout $p) => Settings::paystackTransfersEnabled()
                        && in_array($p->status, ['pending', 'failed'], true))
                    ->disabled(fn (Payout $p) => ! $p->isSendable())
                    ->requiresConfirmation()
                    ->modalDescription(fn (Payout $p) => 'This moves ' . static::money($p->amount, $p->currency)
                        . ' out of your Paystack balance to '
                        . ($p->notaryProfile?->user?->full_name ?? 'the notary')
                        . '. It cannot be undone from here.')
                    ->action(function (Payout $p, PayoutService $payouts) {
                        [$ok, $message] = $payouts->send($p, auth()->id());

                        Notification::make()
                            ->title($ok ? 'Transfer sent' : 'Transfer not sent')
                            ->body($message)
                            ->status($ok ? 'success' : 'danger')
                            ->persistent()
                            ->send();
                    }),

                Tables\Actions\Action::make('markPaid')
                    ->label('Record as paid')->icon('heroicon-o-check')
                    // The primary action when the platform pays by hand, and a
                    // quiet fallback when Paystack is doing the work.
                    ->color(fn () => Settings::paystackTransfersEnabled() ? 'gray' : 'success')
                    ->visible(fn (Payout $p) => $p->isSettleable())
                    ->modalHeading('Record a payout you sent yourself')
                    ->modalDescription(fn (Payout $p) => 'Confirms that '
                        . static::money($p->amount, $p->currency) . ' has already reached '
                        . ($p->notaryProfile?->user?->full_name ?? 'the notary')
                        . '. This settles the fees on the ledger — it does not move any money.'
                        // The one genuinely risky case: a Paystack transfer is
                        // still in flight and may yet land on top of this.
                        . ($p->isProcessing()
                            ? ' ⚠ A Paystack transfer for this payout is still processing. If it '
                            . 'goes through as well, the notary will have been paid twice — confirm '
                            . 'on your Paystack dashboard that it failed before recording this.'
                            : ''))
                    ->form([
                        \Filament\Forms\Components\Placeholder::make('account')
                            ->label('Their account')
                            ->content(fn (Payout $p) => static::accountLine($p)),
                        \Filament\Forms\Components\Select::make('method')
                            ->label('How was it paid?')
                            ->options(SettlementMethod::OPTIONS)
                            ->default('bank_transfer')
                            ->required(),
                        \Filament\Forms\Components\DateTimePicker::make('paid_at')
                            ->label('When')
                            ->default(now())
                            ->maxDate(now())
                            ->required(),
                        \Filament\Forms\Components\TextInput::make('reference')
                            ->label('Your bank\'s reference')
                            ->placeholder('e.g. the transfer session ID from your bank app')
                            ->helperText('Optional, but it is what lets you match this to your statement later.')
                            ->maxLength(255),
                        \Filament\Forms\Components\Textarea::make('note')
                            ->label('Note')
                            ->placeholder('Anything worth remembering about this payment')
                            ->rows(2)
                            ->maxLength(1000),
                    ])
                    ->action(function (Payout $p, array $data, PayoutService $payouts) {
                        [$ok, $message] = $payouts->settleOffline($p, $data, auth()->id());

                        Notification::make()
                            ->title($ok ? 'Payout recorded' : 'Not recorded')
                            ->body($message)
                            ->status($ok ? 'success' : 'danger')
                            ->send();
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('Cancel')->icon('heroicon-o-x-mark')->color('danger')
                    // Only a payout that never reached Paystack can be cancelled.
                    ->visible(fn (Payout $p) => $p->isPending())
                    ->requiresConfirmation()
                    ->modalDescription('The fees go back into what this notary is owed and can be re-generated later.')
                    ->action(function (Payout $p) {
                        $p->payments()->update(['payout_id' => null]);
                        $p->delete();

                        AuditLogger::record('payout.cancelled', 'payout', $p->id);

                        Notification::make()->title('Payout cancelled')->body('Those fees are owed again.')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** Plain list of the fees inside a payout — one line per job. */
    private static function breakdownHtml(Payout $payout): string
    {
        $payments = $payout->payments()->with('request.service:id,service_type')->get();

        if ($payments->isEmpty()) {
            return '<p class="text-sm text-gray-500">No fees are attached to this payout '
                . '(a failed transfer releases them back into what the notary is owed).</p>';
        }

        $rows = $payments->map(function ($payment) use ($payout) {
            $share = $payout->notaryProfile?->notaryShare($payment->amount) ?? 0;

            return '<tr class="border-t border-gray-200 dark:border-gray-700">'
                . '<td class="py-2 pr-4">' . e($payment->request?->reference ?? '—') . '</td>'
                . '<td class="py-2 pr-4">' . e(str($payment->request?->service?->service_type ?? '—')->headline()) . '</td>'
                . '<td class="py-2 pr-4 text-right">' . static::money($payment->amount, $payout->currency) . '</td>'
                . '<td class="py-2 text-right font-medium">' . static::money($share, $payout->currency) . '</td>'
                . '</tr>';
        })->implode('');

        return '<table class="w-full text-sm">'
            . '<thead><tr class="text-left text-gray-500">'
            . '<th class="pb-2 pr-4">Reference</th><th class="pb-2 pr-4">Job</th>'
            . '<th class="pb-2 pr-4 text-right">Client paid</th><th class="pb-2 text-right">Notary earns</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot><tr class="border-t-2 border-gray-300 dark:border-gray-600 font-semibold">'
            . '<td class="pt-2" colspan="2">Total</td>'
            . '<td class="pt-2 text-right">' . static::money($payout->grossAmount(), $payout->currency) . '</td>'
            . '<td class="pt-2 text-right">' . static::money($payout->amount, $payout->currency) . '</td>'
            . '</tr></tfoot></table>';
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayouts::route('/')];
    }
}
