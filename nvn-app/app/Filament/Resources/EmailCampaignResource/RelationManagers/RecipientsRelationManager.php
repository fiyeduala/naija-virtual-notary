<?php

namespace App\Filament\Resources\EmailCampaignResource\RelationManagers;

use App\Jobs\SendCampaignEmail;
use App\Models\EmailCampaignRecipient;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The delivery ledger for one campaign: who it reached, who it did not, and
 * what the mail server said when it refused.
 */
class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Delivery';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'sent' => 'success', 'failed' => 'danger',
                        'skipped' => 'gray', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('sent_at')->dateTime('j M, H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('error')->wrap()->limit(120)->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'sent' => 'Sent',
                    'failed' => 'Failed', 'skipped' => 'Skipped',
                ]),
            ])
            ->actions([
                // Puts one address back in the queue — the usual fix for a
                // single mailbox that was full or briefly greylisted.
                Tables\Actions\Action::make('retry')
                    ->label('Try again')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (EmailCampaignRecipient $r) => $r->status === 'failed')
                    ->action(function (EmailCampaignRecipient $r) {
                        $r->forceFill(['status' => 'pending', 'error' => null])->save();
                        SendCampaignEmail::dispatch($r->id);

                        Notification::make()->title('Queued again for ' . $r->email)->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('retryAll')
                    ->label('Try failed again')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        $n = 0;

                        foreach ($records as $r) {
                            if ($r->status !== 'failed') {
                                continue;
                            }

                            $r->forceFill(['status' => 'pending', 'error' => null])->save();
                            SendCampaignEmail::dispatch($r->id);
                            $n++;
                        }

                        Notification::make()->title($n . ' requeued')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('id')
            ->paginated([25, 50, 100]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
