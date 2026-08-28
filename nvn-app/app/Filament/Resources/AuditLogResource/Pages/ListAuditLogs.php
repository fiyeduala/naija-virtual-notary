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
                    $result = AuditLogger::verify();

                    if ($result['checked'] === 0) {
                        Notification::make()->title('Nothing to verify yet')->send();

                        return;
                    }

                    if ($result['broken'] === [] && $result['gaps'] === []) {
                        Notification::make()
                            ->title('Audit chain intact')
                            ->body('All ' . $result['checked'] . ' entries hash to what they say they do.')
                            ->success()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Audit chain does not verify')
                        ->body($this->damageReport($result))
                        ->danger()->persistent()->send();
                }),
        ];
    }

    /**
     * Say what the damage looks like, not just that there is some.
     *
     * A failing hash proves a row changed after it was written. It does not say
     * a person forged anything, and usually nobody did: deleting a user nulls
     * the actor on every row they wrote, and restoring a database dump onto a
     * server in another time zone rewrites every timestamp. The shape of the
     * failure separates those from a real edit, so it belongs in the message
     * rather than in a follow-up investigation.
     *
     * @param  array{checked:int, broken:list<int>, first_broken:?int, gaps:list<int>, first_id:?int, last_id:?int}  $result
     */
    private function damageReport(array $result): string
    {
        $lines = [];
        $count = count($result['broken']);

        if ($count > 0) {
            $lines[] = $count . ' of ' . $result['checked'] . ' entries no longer match their hash'
                . ', starting at #' . $result['first_broken'] . '.';

            $lines[] = match (true) {
                $count === $result['checked'] => 'Every entry fails, including ones written months apart. '
                    . 'That points at a change made to the whole table at once — a restored backup, '
                    . 'or a server whose time zone moved — rather than at any one record.',
                $count === 1 => 'Only that one entry fails, so only that one record changed.',
                default => 'The rest verify, so whatever changed touched only these entries — '
                    . 'most often every entry written by a user who was later deleted.',
            };
        }

        if ($result['gaps'] !== []) {
            $lines[] = 'Entries are missing outright: ' . implode(', ', array_slice($result['gaps'], 0, 10))
                . (count($result['gaps']) > 10 ? ' and more' : '') . '.';
        }

        $lines[] = 'Run "php artisan nvn:audit-check" on the server to find out which field changed.';

        return implode(' ', $lines);
    }
}
