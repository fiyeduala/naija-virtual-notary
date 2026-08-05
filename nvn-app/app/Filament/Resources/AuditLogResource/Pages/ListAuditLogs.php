<?php

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verifyChain')
                ->label('Verify integrity')
                ->icon('heroicon-o-shield-check')
                ->action(function () {
                    $broken = AuditLogger::verifyChain();
                    if ($broken === null) {
                        Notification::make()->title('Audit chain intact')->success()->send();
                    } else {
                        Notification::make()
                            ->title('Tamper detected')
                            ->body('Chain breaks at entry #' . $broken)
                            ->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
