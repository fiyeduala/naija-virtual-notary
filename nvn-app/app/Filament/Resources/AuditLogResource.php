<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'Disputes & audit';
    protected static ?string $navigationLabel = 'Audit log';
    protected static ?string $modelLabel = 'audit entry';

    // Read-only: no create/edit/delete.
    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('j M Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('actor.full_name')->label('Actor')->placeholder('system'),
                Tables\Columns\TextColumn::make('action')->badge()->searchable(),
                Tables\Columns\TextColumn::make('entity_type')->label('Entity')->placeholder('—'),
                Tables\Columns\TextColumn::make('entity_id')->label('ID')->placeholder('—'),
                Tables\Columns\TextColumn::make('ip_address')->label('IP')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('content_hash')->label('Hash')
                    ->formatStateUsing(fn ($state) => $state ? substr($state, 0, 10) . '…' : '—')
                    ->copyable()->copyableState(fn ($state) => $state)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('entity_type')
                    ->options(fn () => AuditLog::query()->distinct()->pluck('entity_type', 'entity_type')->filter()->all()),
                Tables\Filters\Filter::make('action')
                    ->form([\Filament\Forms\Components\TextInput::make('action')])
                    ->query(fn ($query, array $data) => $data['action']
                        ? $query->where('action', 'like', "%{$data['action']}%") : $query),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}
