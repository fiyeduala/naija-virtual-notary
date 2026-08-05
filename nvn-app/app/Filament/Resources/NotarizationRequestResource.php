<?php

namespace App\Filament\Resources;

use App\Enums\RequestStatus;
use App\Filament\Resources\NotarizationRequestResource\Pages;
use App\Models\NotarizationRequest;
use App\Support\AuditLogger;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotarizationRequestResource extends Resource
{
    protected static ?string $model = NotarizationRequest::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-check';
    protected static ?string $navigationGroup = 'Requests & sessions';
    protected static ?string $navigationLabel = 'Requests';
    protected static ?string $modelLabel = 'request';

    public static function table(Table $table): Table
    {
        return $table
            // finalDocument decides whether the "Notarized doc" action shows —
            // eager load it so the list doesn't fire a query per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('finalDocument'))
            ->columns([
                Tables\Columns\TextColumn::make('reference')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('client.full_name')->label('Client')->searchable(),
                // The notary of record — whose seal is on the document — even
                // when the platform did the work. See was_fallback for that.
                Tables\Columns\TextColumn::make('notary.user.full_name')->label('Notary')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())
                    ->color(fn ($state) => match ($state) {
                        RequestStatus::Completed => 'success',
                        RequestStatus::Paid, RequestStatus::Accepted, RequestStatus::Scheduled,
                        RequestStatus::InVerification, RequestStatus::Notarizing => 'warning',
                        RequestStatus::Cancelled, RequestStatus::Refunded => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('hard_copy_requested')->label('Hard copy')->boolean(),
                Tables\Columns\IconColumn::make('was_fallback')->label('Platform-covered')->boolean()
                    ->tooltip('The platform did the work on the assigned notary\'s behalf, under their seal.'),
                Tables\Columns\TextColumn::make('currency'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('j M Y')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(RequestStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                Tables\Filters\TernaryFilter::make('hard_copy_requested')->label('Hard copy'),
                Tables\Filters\TernaryFilter::make('was_fallback')->label('Platform-covered'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('messages')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->url(fn (NotarizationRequest $r) => route('admin.messages.show', $r)),
                Tables\Actions\Action::make('notarized')
                    ->label('Notarized doc')
                    ->icon('heroicon-o-document-check')
                    ->color('success')
                    ->visible(fn (NotarizationRequest $r) => (bool) $r->finalDocument)
                    ->url(fn (NotarizationRequest $r) => route('admin.requests.notarized', $r))
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotarizationRequests::route('/'),
            'view'  => Pages\ViewNotarizationRequest::route('/{record}'),
        ];
    }
}
