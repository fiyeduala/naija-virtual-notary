<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuoteRequestResource\Pages;
use App\Models\QuoteRequest;
use App\Support\AuditLogger;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class QuoteRequestResource extends Resource
{
    protected static ?string $model = QuoteRequest::class;
    protected static ?string $navigationIcon = 'heroicon-o-envelope';
    protected static ?string $navigationGroup = 'Content & settings';
    protected static ?string $navigationLabel = 'Quote requests';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'new')->count() ?: null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('company')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('contact_name')->label('Contact'),
                Tables\Columns\TextColumn::make('work_email')->label('Email')->copyable(),
                Tables\Columns\TextColumn::make('org_type')->label('Type')->badge(),
                Tables\Columns\TextColumn::make('monthly_volume')->label('Volume'),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'new' => 'warning', 'in_progress' => 'info', 'closed' => 'gray', default => 'gray',
                }),
                Tables\Columns\TextColumn::make('created_at')->dateTime('j M Y')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'new' => 'New', 'in_progress' => 'In progress', 'closed' => 'Closed',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('advance')
                    ->label(fn (QuoteRequest $q) => $q->status === 'new' ? 'Start' : 'Close')
                    ->icon('heroicon-o-arrow-right')
                    ->action(function (QuoteRequest $q) {
                        $next = $q->status === 'new' ? 'in_progress' : 'closed';
                        $q->update(['status' => $next]);
                        AuditLogger::record('quote.status_changed', 'quote_request', $q->id, ['status' => $next]);
                        Notification::make()->title('Quote ' . $next)->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListQuoteRequests::route('/')];
    }
}
