<?php

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Enums\RequestStatus;
use App\Filament\Resources\NotarizationRequestResource;
use App\Filament\Resources\OrganizationResource;
use App\Models\NotarizationRequest;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * What this body has actually sent us.
 *
 * Read-only on purpose. Nothing about a notarization is edited from inside an
 * organization's record — this is here to answer "what have they sent, and what
 * has it earned", usually just before a conversation with them, and the row
 * links through to the request itself for anything else.
 *
 * The rate shown is the one frozen on each request rather than the body's
 * current one, which is the whole point of freezing it: a rate renegotiated
 * this month must not change what was earned last month.
 */
class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'requests';

    protected static ?string $title = 'Work they referred';

    protected static ?string $modelLabel = 'referred request';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->modifyQueryUsing(fn ($query) => $query->with('service', 'client'))
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Referred')
                    ->date('j M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('service.service_type')
                    ->label('Category')
                    ->placeholder('Not chosen yet'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->label())
                    ->color(fn ($state) => match ($state) {
                        RequestStatus::Completed => 'success',
                        RequestStatus::Cancelled, RequestStatus::Refunded => 'danger',
                        RequestStatus::Draft, RequestStatus::Submitted => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('fee')
                    ->label('Charged')
                    ->state(fn (NotarizationRequest $record) => $record->displayFeeOrPending())
                    ->alignRight(),
                Tables\Columns\TextColumn::make('paid')
                    ->label('Paid')
                    ->state(fn (NotarizationRequest $record) => OrganizationResource::money(
                        $record->amountPaidMinor(),
                        $record->currency ?: 'NGN',
                    ))
                    ->alignRight(),
                // Their share of what has actually come in, at the rate this
                // row was created under. Zero on a price_only body, and shown
                // as nothing rather than ₦0.00 — there is a difference between
                // "earned nothing yet" and "earns nothing by arrangement".
                Tables\Columns\TextColumn::make('share')
                    ->label('Their share')
                    ->state(fn (NotarizationRequest $record) => $record->organization_commission_rate > 0
                        ? OrganizationResource::money(
                            $record->organizationShareOf($record->amountPaidMinor()),
                            $record->currency ?: 'NGN',
                        ) . ' (' . $record->organization_commission_rate . '%)'
                        : '—')
                    ->alignRight(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(RequestStatus::cases())
                        ->mapWithKeys(fn (RequestStatus $case) => [$case->value => $case->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (NotarizationRequest $record) => NotarizationRequestResource::getUrl('view', [
                        'record' => $record,
                    ])),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nothing referred yet')
            ->emptyStateDescription('Work appears here as soon as somebody starts a request through '
                . 'their link.');
    }
}
