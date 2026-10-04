<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationPayoutResource\Pages;
use App\Models\OrganizationPayout;
use App\Services\OrganizationPayoutService;
use App\Support\SettlementMethod;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * What the platform owes its partner bodies, and what it has paid them.
 *
 * Deliberately the same screen as PayoutResource so the two read alike, with
 * one difference that is not an oversight: **there is no "Send via Paystack"**.
 * The transfer path is built on notary_bank_details.paystack_recipient_code,
 * and a second recipient pipeline is its own piece of work — so organization
 * commission is settled by hand, which is already the platform's default for
 * notaries too. The button is absent rather than disabled, because a control
 * that can never work is noise.
 *
 * A payout here claims its payments through `organization_payout_id`, a column
 * of its own. The notary ledger's `payout_id` is untouched, so the same fee can
 * sit on a notary payout and an organization payout without either run being
 * able to see the other's claim.
 */
class OrganizationPayoutResource extends Resource
{
    protected static ?string $model = OrganizationPayout::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'Payments & payouts';
    protected static ?string $navigationLabel = 'Organization payouts';
    protected static ?string $modelLabel = 'organization payout';

    /** Anything owed but unsettled is worth a nudge in the sidebar. */
    public static function getNavigationBadge(): ?string
    {
        $count = OrganizationPayout::outstanding()->count();

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
     * Where to send it, spelled out on the settlement form.
     *
     * The moment an admin is about to type an account number into their bank
     * app is the one moment the digits are genuinely wanted, and having them
     * here beats a second tab open on the organization's record.
     */
    public static function accountLine(OrganizationPayout $payout): HtmlString
    {
        $organization = $payout->organization;

        if (! $organization?->account_number) {
            return new HtmlString('No account on file — ask them for one before paying.');
        }

        return new HtmlString(
            e($organization->bank_name ?: 'Bank not recorded') . '<br>'
            . e($organization->account_name ?: $organization->name) . '<br>'
            . '<strong>' . e($organization->account_number) . '</strong>'
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('organization:id,name,bank_name,account_name'))
            ->columns([
                Tables\Columns\TextColumn::make('reference')->label('Ref')
                    ->searchable()->copyable()->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('organization.name')->label('Organization')
                    ->searchable()->sortable(),
                Tables\Columns\TextColumn::make('amount')->label('They are paid')
                    ->formatStateUsing(fn ($state, OrganizationPayout $p) => static::money($state, $p->currency))
                    ->sortable(),
                Tables\Columns\TextColumn::make('gross_amount')->label('Clients paid')
                    ->formatStateUsing(fn ($state, OrganizationPayout $p) => static::money($state, $p->currency))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('platform')->label('Platform kept')
                    ->state(fn (OrganizationPayout $p) => static::money($p->platformAmount(), $p->currency))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('payments_count')->label('Jobs')
                    ->counts('payments')->alignCenter(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success', 'pending' => 'warning', 'failed' => 'danger', default => 'gray',
                    })
                    ->description(fn (OrganizationPayout $p) => $p->failure_reason),
                Tables\Columns\TextColumn::make('settlement_method')->label('How')
                    ->state(fn (OrganizationPayout $p) => $p->isPaid() ? $p->settlementLabel() : '—')
                    ->badge()
                    ->color('gray')
                    ->description(fn (OrganizationPayout $p) => $p->settlement_reference),
                Tables\Columns\TextColumn::make('period_end')->label('Up to')->date()->placeholder('—'),
                Tables\Columns\TextColumn::make('processed_at')->label('Settled')
                    ->dateTime('j M Y')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed',
                ]),
                Tables\Filters\SelectFilter::make('organization_id')
                    ->label('Organization')
                    ->relationship('organization', 'name')
                    ->searchable()->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('breakdown')
                    ->label('Jobs')->icon('heroicon-o-list-bullet')->color('gray')
                    ->modalHeading('What this payout settles')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (OrganizationPayout $p) => new HtmlString(static::breakdownHtml($p))),

                Tables\Actions\Action::make('markPaid')
                    ->label('Record as paid')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (OrganizationPayout $p) => $p->isSettleable())
                    ->modalHeading('Record a payment you sent yourself')
                    ->modalDescription(fn (OrganizationPayout $p) => 'Confirms that '
                        . static::money($p->amount, $p->currency) . ' has already reached '
                        . ($p->organization?->name ?? 'the organization')
                        . '. This settles the fees on the ledger — it does not move any money.')
                    ->form([
                        Forms\Components\Placeholder::make('account')
                            ->label('Their account')
                            ->content(fn (OrganizationPayout $p) => static::accountLine($p)),
                        Forms\Components\Select::make('method')
                            ->label('How was it paid?')
                            ->options(SettlementMethod::OPTIONS)
                            ->default('bank_transfer')
                            ->required(),
                        Forms\Components\DateTimePicker::make('paid_at')
                            ->label('When')
                            ->default(now())
                            ->maxDate(now())
                            ->required(),
                        Forms\Components\TextInput::make('reference')
                            ->label('Your bank reference')
                            ->placeholder('e.g. the transfer session ID from your bank app')
                            ->helperText('Optional, but it is what lets you match this to your '
                                . 'statement later.')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('note')
                            ->label('Note')
                            ->rows(2)
                            ->maxLength(1000),
                    ])
                    ->action(function (
                        OrganizationPayout $p,
                        array $data,
                        OrganizationPayoutService $payouts,
                    ) {
                        [$ok, $message] = $payouts->settleOffline($p, $data, auth()->id());

                        Notification::make()
                            ->title($ok ? 'Payout recorded' : 'Not recorded')
                            ->body($message)
                            ->status($ok ? 'success' : 'danger')
                            ->send();
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('Cancel')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (OrganizationPayout $p) => $p->isPending())
                    ->requiresConfirmation()
                    ->modalDescription('The fees go back into what this body is owed and can be '
                        . 're-generated later.')
                    ->form([
                        Forms\Components\TextInput::make('reason')
                            ->label('Why')
                            ->placeholder('e.g. generated against the wrong period')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Kept on the audit trail — this is money becoming owed again.'),
                    ])
                    ->action(function (
                        OrganizationPayout $p,
                        array $data,
                        OrganizationPayoutService $payouts,
                    ) {
                        [$ok, $message] = $payouts->cancel($p, $data['reason'], auth()->id());

                        Notification::make()
                            ->title($ok ? 'Payout cancelled' : 'Not cancelled')
                            ->body($message)
                            ->status($ok ? 'success' : 'danger')
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No organization payouts yet')
            ->emptyStateDescription('Generate payouts once a body that earns a share has completed work.');
    }

    /**
     * The fees inside a payout — one line per job.
     *
     * The share is recomputed from the rate frozen on each request rather than
     * read off the payout, so this table is a check on the total above it and
     * not a restatement of it.
     */
    private static function breakdownHtml(OrganizationPayout $payout): string
    {
        $payments = $payout->payments()->with('request.service:id,service_type')->get();

        if ($payments->isEmpty()) {
            return '<p class="text-sm text-gray-500">No fees are attached to this payout.</p>';
        }

        $rows = $payments->map(function ($payment) use ($payout) {
            $request = $payment->request;
            $share = $request?->organizationShareOf($payment->amount) ?? 0;
            $rate = (int) ($request?->organization_commission_rate ?? 0);

            return '<tr class="border-t border-gray-200 dark:border-gray-700">'
                . '<td class="py-2 pr-4">' . e($request?->reference ?? '—') . '</td>'
                . '<td class="py-2 pr-4">' . e($request?->service?->service_type ?? '—') . '</td>'
                . '<td class="py-2 pr-4 text-right">' . $rate . '%</td>'
                . '<td class="py-2 pr-4 text-right">' . static::money($payment->amount, $payout->currency) . '</td>'
                . '<td class="py-2 text-right font-medium">' . static::money($share, $payout->currency) . '</td>'
                . '</tr>';
        })->implode('');

        return '<table class="w-full text-sm">'
            . '<thead><tr class="text-left text-gray-500">'
            . '<th class="pb-2 pr-4">Reference</th><th class="pb-2 pr-4">Job</th>'
            . '<th class="pb-2 pr-4 text-right">Rate</th>'
            . '<th class="pb-2 pr-4 text-right">Client paid</th>'
            . '<th class="pb-2 text-right">They earn</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot><tr class="border-t-2 border-gray-300 dark:border-gray-600 font-semibold">'
            . '<td class="pt-2" colspan="3">Total</td>'
            . '<td class="pt-2 pr-4 text-right">' . static::money($payout->gross_amount, $payout->currency) . '</td>'
            . '<td class="pt-2 text-right">' . static::money($payout->amount, $payout->currency) . '</td>'
            . '</tr></tfoot></table>';
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOrganizationPayouts::route('/')];
    }
}
