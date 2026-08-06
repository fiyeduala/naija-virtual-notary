<?php

namespace App\Filament\Resources\EmailCampaignResource\Pages;

use App\Filament\Resources\EmailCampaignResource;
use App\Models\EmailCampaign;
use App\Services\EmailCampaignService;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * The review-and-send screen. Everything that actually posts email is here,
 * behind a confirmation that states the head count out loud.
 */
class ViewEmailCampaign extends ViewRecord
{
    protected static string $resource = EmailCampaignResource::class;

    public function getTitle(): string
    {
        return $this->record->subject;
    }

    /** Keeps the counters moving while the queue works through the list. */
    public function getPollingInterval(): ?string
    {
        return $this->record->isRunning() ? '5s' : null;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('audience')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            'all' => 'Everyone', 'clients' => 'Clients',
                            'notaries' => 'Notaries', default => 'Specific people',
                        }),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'sent' => 'success', 'sending', 'queued' => 'warning',
                            'cancelled' => 'danger', default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('total_recipients')
                        ->label('Recipients')
                        ->formatStateUsing(fn ($state) => number_format((int) $state)),
                    Infolists\Components\TextEntry::make('sent_count')
                        ->label('Delivered')
                        ->formatStateUsing(fn (EmailCampaign $record) => number_format($record->sent_count)
                            . ($record->failed_count ? ' — ' . number_format($record->failed_count) . ' failed' : '')),
                ]),

            Infolists\Components\Section::make('Preview')
                ->description('Placeholders are shown as written; each recipient sees their own name.')
                ->schema([
                    Infolists\Components\TextEntry::make('subject')->weight('bold'),
                    Infolists\Components\TextEntry::make('body')->label('')->html(),
                ]),

            Infolists\Components\Section::make('Trail')
                ->columns(3)
                ->collapsed()
                ->schema([
                    Infolists\Components\TextEntry::make('author.full_name')->label('Composed by')->placeholder('—'),
                    Infolists\Components\TextEntry::make('queued_at')->dateTime('j M Y, H:i')->placeholder('Not sent yet'),
                    Infolists\Components\TextEntry::make('completed_at')->dateTime('j M Y, H:i')->placeholder('—'),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('send')
                ->label(fn (EmailCampaign $record) => $record->isDraft() ? 'Send now' : 'Resume send')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (EmailCampaign $record) => ! $record->isFinished() && $record->pendingCount() > 0)
                ->requiresConfirmation()
                ->modalHeading('Send this email')
                ->modalDescription(fn (EmailCampaign $record) => 'It will go to '
                    . number_format($record->pendingCount())
                    . ' recipient(s). Anyone already written to is skipped. Email cannot be recalled once it leaves.')
                ->modalSubmitActionLabel('Send')
                ->action(function (EmailCampaign $record) {
                    $n = app(EmailCampaignService::class)->queue($record);

                    Notification::make()
                        ->title(number_format($n) . ' email(s) queued')
                        ->body('The queue worker delivers them in the background — this page updates as it goes.')
                        ->success()->send();
                }),

            Actions\Action::make('test')
                ->label('Send test to me')
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->action(fn (EmailCampaign $record) => EmailCampaignResource::sendTest($record, auth()->user())),

            Actions\EditAction::make()
                ->label('Edit draft')
                ->visible(fn (EmailCampaign $record) => $record->isDraft()),

            Actions\Action::make('cancel')
                ->label('Stop sending')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (EmailCampaign $record) => $record->isRunning())
                ->requiresConfirmation()
                ->modalDescription('Stops before the next recipient. Anything already sent has gone.')
                ->action(function (EmailCampaign $record) {
                    app(EmailCampaignService::class)->cancel($record);
                    Notification::make()->title('Send stopped')->success()->send();
                }),
        ];
    }
}
