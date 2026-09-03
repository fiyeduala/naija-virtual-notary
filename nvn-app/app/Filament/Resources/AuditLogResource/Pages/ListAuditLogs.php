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
     * the actor on every row they wrote, restoring a dump onto a server in
     * another time zone rewrites every timestamp, and changing APP_TIMEZONE
     * changes the offset the hash was taken over without moving the clock at
     * all. The shape of the failure separates those from a real edit, so it
     * belongs in the message rather than in a follow-up investigation.
     *
     * What it must not do is pick one of them and state it as the likely cause.
     * Whoever reads this is deciding whether the log can be trusted, and naming
     * a deleted user when the real answer was a config change sends them
     * looking for a culprit who does not exist.
     *
     * @param  array{checked:int, broken:list<int>, first_broken:?int, gaps:list<int>, first_id:?int, last_id:?int}  $result
     */
    private function damageReport(array $result): string
    {
        $lines = [];
        $count = count($result['broken']);

        if ($count > 0) {
            $lines[] = $count === 1
                ? 'Entry #' . $result['first_broken'] . ' no longer matches its hash, out of ' . $result['checked'] . ' checked.'
                : $count . ' of ' . $result['checked'] . ' entries no longer match their hash'
                    . ', starting at #' . $result['first_broken'] . '.';

            // Failing from the very first entry onwards is its own signature:
            // something that was true when the log was young and is not now.
            $fromTheStart = $result['first_broken'] === $result['first_id'];

            $lines[] = match (true) {
                $count === $result['checked'] => 'Every entry fails, including ones written months apart. '
                    . 'That points at a change made to the whole table at once — a restored backup, '
                    . 'or a server whose time zone moved — rather than at any one record.',
                $fromTheStart => 'They are the oldest entries, and everything written since verifies. '
                    . 'That is the signature of a setting that changed after they were written, rather '
                    . 'than of an edit: APP_TIMEZONE is hashed into every timestamp, so moving it breaks '
                    . 'precisely the rows that predate the move and nothing after it.',
                $count === 1 => 'Only that one entry fails, so only that one record changed.',
                default => 'The rest verify, so whatever changed touched only these entries. Several '
                    . 'ordinary things do that — a deleted user, a renamed action, a changed setting — '
                    . 'and they are told apart by which field moved.',
            };
        }

        if ($result['gaps'] !== []) {
            // An id is issued before its transaction commits, and record() runs
            // inside a larger one, so every rollback burns an id. Scattered
            // single ids are the ordinary trace of that and mean nothing at
            // all. Calling them "missing outright" reported a deletion that
            // had not happened, in the same breath as a tamper warning.
            $lines[] = 'Unused ids: ' . implode(', ', array_slice($result['gaps'], 0, 10))
                . (count($result['gaps']) > 10 ? ' and more' : '') . '.';

            $lines[] = $this->gapsLookDeliberate($result['gaps'])
                ? 'Those run consecutively, which is what deleted rows look like.'
                : 'Normal — a transaction that rolls back still consumes its id.';
        }

        $lines[] = 'Run "php artisan nvn:audit-check'
            . ($result['first_broken'] !== null ? ' --row=' . $result['first_broken'] : '')
            . '" on the server; it names the field that changed.';

        return implode(' ', $lines);
    }

    /**
     * Whether the unused ids sit next to each other.
     *
     * One id here and another two hundred later is a rollback. A block of them
     * in a row is the trace of a DELETE, because rollbacks do not queue up.
     *
     * @param  list<int>  $gaps
     */
    private function gapsLookDeliberate(array $gaps): bool
    {
        foreach ($gaps as $i => $id) {
            if ($i > 0 && $id === $gaps[$i - 1] + 1) {
                return true;
            }
        }

        return false;
    }
}
