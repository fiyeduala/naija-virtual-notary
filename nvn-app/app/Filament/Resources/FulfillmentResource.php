<?php

namespace App\Filament\Resources;

use App\Enums\RequestStatus;
use App\Filament\Resources\FulfillmentResource\Pages;
use App\Models\NotarizationRequest;
use App\Support\AuditLogger;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hard-copy fulfillment queue: completed requests that asked for a physical copy.
 * Fulfillment state is tracked in intake_data['fulfillment'] to avoid a new
 * table; statuses: pending → printed → dispatched.
 */
class FulfillmentResource extends Resource
{
    protected static ?string $model = NotarizationRequest::class;
    protected static ?string $slug = 'fulfillment';
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?string $navigationLabel = 'Hard-copy fulfillment';
    protected static ?string $modelLabel = 'fulfillment';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('hard_copy_requested', true)
            ->where('status', RequestStatus::Completed->value);
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getEloquentQuery()
            ->where(fn ($q) => $q->whereNull('intake_data->fulfillment->state')
                ->orWhere('intake_data->fulfillment->state', 'pending'))
            ->count() ?: null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')->searchable(),
                Tables\Columns\TextColumn::make('client.full_name')->label('Client'),
                Tables\Columns\TextColumn::make('delivery_address')->label('Address')
                    ->formatStateUsing(fn ($state) => $state ? collect($state)->filter()->implode(', ') : '—')
                    ->wrap(),
                Tables\Columns\TextColumn::make('intake_data')->label('Fulfillment')
                    ->formatStateUsing(fn ($state) => ucfirst($state['fulfillment']['state'] ?? 'pending'))
                    ->badge()
                    ->color(fn ($state) => match ($state['fulfillment']['state'] ?? 'pending') {
                        'dispatched' => 'success', 'printed' => 'info', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('completed_at')->dateTime('j M Y')->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('markPrinted')
                    ->icon('heroicon-o-printer')->color('info')
                    ->action(fn (NotarizationRequest $r) => static::setState($r, 'printed')),
                Tables\Actions\Action::make('dispatch')
                    ->icon('heroicon-o-truck')->color('success')
                    ->form([TextInput::make('tracking')->label('Courier tracking number')])
                    ->action(fn (NotarizationRequest $r, array $data) => static::setState($r, 'dispatched', $data['tracking'] ?? null)),
            ])
            ->defaultSort('completed_at', 'asc');
    }

    protected static function setState(NotarizationRequest $r, string $state, ?string $tracking = null): void
    {
        $intake = $r->intake_data ?? [];
        $intake['fulfillment'] = array_filter([
            'state'    => $state,
            'tracking' => $tracking ?? ($intake['fulfillment']['tracking'] ?? null),
            'updated'  => now()->toIso8601String(),
        ]);
        $r->update(['intake_data' => $intake]);

        AuditLogger::record('fulfillment.' . $state, 'notarization_request', $r->id, ['tracking' => $tracking]);
        Notification::make()->title('Marked ' . $state)->success()->send();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFulfillment::route('/')];
    }
}
