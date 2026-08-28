<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Explain a broken audit chain.
 *
 * "Tamper detected" is true but useless on its own: a row whose hash no longer
 * matches its contents proves that something changed, not what. And the most
 * common causes are not tampering at all — a foreign key quietly nulling an
 * actor when a user is deleted, a database dump moving every timestamp by an
 * hour, a driver handing an integer back as a string. All three look identical
 * to a forged record from the outside, and all three are provable.
 *
 * This walks the chain, reports the shape of the damage, and then tries to name
 * the cause of the first break by rebuilding that row's hash under every
 * explanation in turn. The one that reproduces the stored hash is what happened:
 * a hash cannot be reproduced by accident.
 *
 *   php artisan nvn:audit-check
 *   php artisan nvn:audit-check --row=1
 */
class AuditCheck extends Command
{
    protected $signature = 'nvn:audit-check
        {--row= : Explain this row instead of the first broken one}';

    protected $description = 'Verify the audit chain and explain what broke it';

    /** How far to search when asking which actor id would reproduce a hash. */
    private const ACTOR_SEARCH_LIMIT = 500;

    public function handle(): int
    {
        $this->newLine();
        $this->line('<options=bold>1. Where this is running</>');

        $driver = DB::connection()->getDriverName();
        $this->line('   database driver     ' . $driver);
        $this->line('   app timezone        ' . config('app.timezone'));

        if ($driver === 'mysql') {
            try {
                $tz = DB::selectOne('SELECT @@session.time_zone AS s, @@global.time_zone AS g');
                $this->line('   mysql time_zone     session ' . $tz->s . ', global ' . $tz->g);
            } catch (Throwable $e) {
                $this->line('   mysql time_zone     could not read (' . $e->getMessage() . ')');
            }
        }

        // How the driver hands numbers back. MySQL with emulated prepared
        // statements returns every column as a string, so a row written with
        // actor_user_id 1 reads back as "1" — and json_encode spells those two
        // differently. That alone once broke every hash in the table. The
        // payload builder normalises them now; this line is here so the answer
        // is visible rather than assumed.
        $sample = AuditLog::whereNotNull('actor_user_id')->orderBy('id')->first();

        if ($sample) {
            $this->line('   ids read back as    ' . get_debug_type($sample->actor_user_id)
                . ' (normalised before hashing either way)');
        }

        $this->newLine();
        $this->line('<options=bold>2. The chain</>');

        $result = AuditLogger::verify();

        if ($result['checked'] === 0) {
            $this->warn('   The audit log is empty. Nothing to verify.');

            return self::SUCCESS;
        }

        $this->line('   rows                ' . $result['checked']
            . ' (id ' . $result['first_id'] . ' to ' . $result['last_id'] . ')');

        if ($result['gaps'] !== []) {
            $this->line('   <fg=red>missing ids         ' . implode(', ', $result['gaps'])
                . (count($result['gaps']) >= 50 ? ' …' : '') . '</>');
            $this->line('   <fg=red>                    Rows have been deleted. That is not recoverable —</>');
            $this->line('   <fg=red>                    an append-only log cannot lose entries by itself.</>');
        }

        if ($result['broken'] === []) {
            $this->newLine();
            $this->info('   Chain intact. Every row hashes to what it says it does.');

            // --row asks about one specific entry, which is a fair question to
            // ask of a healthy log too.
            if (! $this->option('row')) {
                return self::SUCCESS;
            }
        }

        $count = count($result['broken']);

        if ($count > 0) {
            $this->line('   <fg=red>broken rows         ' . $count . ' of ' . $result['checked'] . '</>');
            $this->line('   first broken        #' . $result['first_broken']);

            // The shape of the damage narrows the cause before anything is tested.
            $this->newLine();

            if ($count === $result['checked']) {
                $this->line('   <fg=yellow>Every single row fails. Rows written years apart, by different</>');
                $this->line('   <fg=yellow>people, do not all get edited — this is almost always the reader</>');
                $this->line('   <fg=yellow>and the writer disagreeing about how a value is spelled.</>');
            } elseif ($count === 1) {
                $this->line('   <fg=yellow>Exactly one row fails. That is a single record having changed</>');
                $this->line('   <fg=yellow>after it was written — by an edit, or by a foreign key nulling</>');
                $this->line('   <fg=yellow>a column when the user it pointed at was deleted.</>');
            } else {
                $this->line('   <fg=yellow>Some rows fail and some do not. Whatever changed touched those</>');
                $this->line('   <fg=yellow>rows and not the others — most often every row written by one</>');
                $this->line('   <fg=yellow>deleted user. Their ids are listed above.</>');
            }
        }

        $this->newLine();
        $this->line('<options=bold>3. What changed</>');

        $rowId = (int) ($this->option('row') ?: $result['first_broken']);
        $row = AuditLog::find($rowId);

        if (! $row) {
            $this->error('   Row #' . $rowId . ' does not exist.');

            return self::FAILURE;
        }

        $this->line('   examining #' . $row->id . '  ' . $row->action
            . '  ' . $row->created_at?->format('j M Y H:i:s'));
        $this->newLine();

        $this->explain($row, $this->previousHashFor($row));

        return $count > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** The stored hash of the row immediately before this one, as verify() would use it. */
    private function previousHashFor(AuditLog $row): ?string
    {
        return AuditLog::where('id', '<', $row->id)->orderByDesc('id')->value('content_hash');
    }

    /**
     * Rebuild this row's hash under every explanation, and report the one that fits.
     *
     * Each candidate is a complete story about what the row used to say. Only a
     * true story reproduces a SHA-256, so a hit is proof rather than a guess.
     */
    private function explain(AuditLog $row, ?string $previousHash): void
    {
        $stored = (string) $row->content_hash;

        if ($stored === '') {
            $this->warn('   This row has no stored hash at all, so there is nothing to check it');
            $this->warn('   against. It was written before hashing, or the column was cleared.');

            return;
        }

        $iso = $row->created_at?->toIso8601String() ?? '';
        $meta = $row->metadata;

        // 1. Does it verify as it stands? (Only reachable via --row.)
        if (AuditLogger::hashOf($row, $previousHash) === $stored) {
            $this->info('   This row verifies. Nothing is wrong with it.');

            return;
        }

        // 2. A timestamp that moved. A database dumped on one server and loaded
        //    on another shifts every TIMESTAMP column by the difference between
        //    their session time zones, silently and uniformly.
        $shifts = $row->created_at === null ? [] : $this->timeShifts();

        foreach ($shifts as $label => $shifted) {
            $candidate = $row->created_at->copy()->addMinutes($shifted)->toIso8601String();

            if ($this->matches($row, $meta, $candidate, $previousHash, $stored)) {
                $this->found('The timestamp moved by ' . $label . '.');
                $this->line('   It reads ' . $iso . ' now and was ' . $candidate . ' when it was written.');
                $this->newLine();
                $this->line('   Nobody edited this record. A MySQL TIMESTAMP column is converted');
                $this->line('   using the session time zone on the way in and on the way out, so');
                $this->line('   dumping the database on one machine and importing it on another');
                $this->line('   with a different time_zone rewrites every timestamp in the table.');
                $this->line('   The hashes were taken over the old values and no longer fit.');

                return;
            }
        }

        // 3. The actor changed. This is the one a delete in phpMyAdmin causes:
        //    actor_user_id is `nullOnDelete`, so removing a user rewrites every
        //    audit row they ever wrote, without touching the hash.
        $actorNow = $row->actor_user_id === null ? 'NULL' : (string) $row->actor_user_id;

        for ($candidate = 1; $candidate <= self::ACTOR_SEARCH_LIMIT; $candidate++) {
            if ((int) $row->actor_user_id === $candidate) {
                continue;
            }

            $payload = AuditLogger::payload(
                $candidate, (string) $row->action, $row->entity_type,
                $row->entity_id, $meta, $iso, $previousHash,
            );

            if (hash('sha256', $payload) === $stored) {
                $this->found('The actor was changed. It said user #' . $candidate
                    . ' and now says ' . $actorNow . '.');
                $this->newLine();
                $this->line('   audit_log.actor_user_id is declared nullOnDelete, so deleting');
                $this->line('   user #' . $candidate . ' from the database set this column to NULL on every');
                $this->line('   row they ever wrote — and the hash was taken over the old value.');
                $this->line('   Nothing was forged. A user was deleted and the log was rewritten');
                $this->line('   underneath it as a side effect.');
                $this->newLine();
                $this->line('   Rows this would explain: '
                    . AuditLog::whereNull('actor_user_id')->count() . ' with no actor.');

                return;
            }
        }

        // 4. The other direction: it says someone now and said nobody before.
        if ($row->actor_user_id !== null) {
            $payload = AuditLogger::payload(
                null, (string) $row->action, $row->entity_type,
                $row->entity_id, $meta, $iso, $previousHash,
            );

            if (hash('sha256', $payload) === $stored) {
                $this->found('The actor was changed. It said NULL and now says #' . $row->actor_user_id . '.');

                return;
            }
        }

        // 5. A chain start that is not the start. If this row hashes correctly
        //    against no predecessor, the rows before it are gone.
        if ($previousHash !== null && AuditLogger::hashOf($row, null) === $stored) {
            $this->found('The rows before this one are missing.');
            $this->line('   This row hashes correctly as the first entry in the log, which means');
            $this->line('   it was the first entry — everything earlier has been deleted.');

            return;
        }

        // 6. Metadata read back differently from how it was written.
        $rawMeta = DB::table('audit_log')->where('id', $row->id)->value('metadata');

        if (is_string($rawMeta)) {
            $decoded = json_decode($rawMeta, true);

            if ($decoded !== $meta && $this->matches($row, $decoded, $iso, $previousHash, $stored)) {
                $this->found('The metadata is being read back in a different shape than it was stored.');

                return;
            }
        }

        // Nothing fits.
        $this->error('   No ordinary explanation reproduces this row\'s hash.');
        $this->newLine();
        $this->line('   Everything that would break a hash by accident has been ruled out:');
        $this->line('   the timestamp has not shifted, the actor has not been nulled or');
        $this->line('   changed, no earlier rows are missing, and the metadata reads back as');
        $this->line('   it was stored. What is left is that the contents of this row were');
        $this->line('   genuinely altered after it was written, and the hash is doing exactly');
        $this->line('   the job it exists for.');
        $this->newLine();
        $this->line('   Stored hash    ' . $stored);
        $this->line('   Recomputed     ' . AuditLogger::hashOf($row, $previousHash));
        $this->line('   Row as it now reads:');
        $this->line('     actor_user_id  ' . $actorNow);
        $this->line('     action         ' . $row->action);
        $this->line('     entity         ' . ($row->entity_type ?? '—') . ' #' . ($row->entity_id ?? '—'));
        $this->line('     created_at     ' . $iso);
        $this->line('     metadata       ' . json_encode($meta));
        $this->line('     previous_hash  ' . ($row->previous_hash ?? 'NULL')
            . ($row->previous_hash === $previousHash ? '' : '   <fg=red>(does not match the row before it)</>'));
    }

    /** Try one candidate reading of the row. */
    private function matches(AuditLog $row, ?array $metadata, string $iso, ?string $previousHash, string $stored): bool
    {
        return hash('sha256', AuditLogger::payload(
            $row->actor_user_id,
            (string) $row->action,
            $row->entity_type,
            $row->entity_id,
            $metadata,
            $iso,
            $previousHash,
        )) === $stored;
    }

    /**
     * Timestamp shifts worth testing, in minutes.
     *
     * Whole hours cover a server time zone change; the three-quarter-hour
     * offsets exist because some real zones use them and a dump between two of
     * them lands nowhere near an hour boundary.
     *
     * @return array<string, int>
     */
    private function timeShifts(): array
    {
        $shifts = [];

        foreach (range(-14, 14) as $hours) {
            foreach ([0, 15, 30, 45] as $minutes) {
                foreach ($minutes === 0 ? [1] : [1, -1] as $sign) {
                    $total = $hours * 60 + ($sign * $minutes);

                    if ($total === 0 || abs($total) > 14 * 60) {
                        continue;
                    }

                    $label = ($total > 0 ? '+' : '−') . intdiv(abs($total), 60) . 'h'
                        . (abs($total) % 60 ? ' ' . (abs($total) % 60) . 'm' : '');

                    $shifts[$label] = $total;
                }
            }
        }

        return $shifts;
    }

    private function found(string $line): void
    {
        $this->line('   <fg=green;options=bold>Found it.</> ' . $line);
    }
}
