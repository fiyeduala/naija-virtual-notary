<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Explain a broken audit chain.
 *
 * "Tamper detected" is true but useless on its own: a row whose hash no longer
 * matches its contents proves that something changed, not what. And the most
 * common causes are not tampering at all — a foreign key nulling an actor when
 * a user is deleted, a database dump moving every timestamp, an application
 * timezone changed in .env long after the rows were written. All of those look
 * identical to a forged record from the outside, and all of them are provable.
 *
 * The method is to rebuild the row's hash under each explanation in turn. A
 * SHA-256 cannot be reproduced by accident, so a candidate that matches is not
 * a theory about what the row used to say — it is what the row used to say.
 *
 *   php artisan nvn:audit-check
 *   php artisan nvn:audit-check --row=1
 */
class AuditCheck extends Command
{
    protected $signature = 'nvn:audit-check
        {--row= : Explain this row instead of the first broken one}';

    protected $description = 'Verify the audit chain and explain what broke it';

    /** How far to sweep when asking which id would reproduce a hash. */
    private const ID_SEARCH_LIMIT = 500;

    /**
     * Whether the pass currently running spells the two id columns as numbers.
     *
     * The hash is taken over JSON, and JSON writes 2 and "2" differently. The
     * writer normalises to numbers now, but a row hashed by an older build, or
     * on a host whose driver handed ids back as text, would have been sealed
     * over the text spelling — and no amount of searching for the right *value*
     * will reproduce that hash while the search insists on the wrong *type*.
     */
    private bool $normaliseIds = true;

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
        // differently. The payload builder normalises them; this line is here
        // so the answer is visible rather than assumed.
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

        $this->reportGaps($result['gaps']);

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
            $this->line('   which               #' . implode(', #', array_slice($result['broken'], 0, 40))
                . ($count > 40 ? ' and more' : ''));

            // The shape of the damage narrows the cause before anything is tested.
            $this->newLine();

            if ($count === $result['checked']) {
                $this->line('   <fg=yellow>Every single row fails. Rows written months apart, by different</>');
                $this->line('   <fg=yellow>people, do not all get edited — this is the whole table having</>');
                $this->line('   <fg=yellow>been rewritten at once, or the reader and the writer disagreeing</>');
                $this->line('   <fg=yellow>about how a value is spelled.</>');
            } elseif ($count === 1) {
                $this->line('   <fg=yellow>Exactly one row fails. That is a single record having changed</>');
                $this->line('   <fg=yellow>after it was written.</>');
            } else {
                $this->line('   <fg=yellow>Some rows fail and some do not, so whatever changed touched only</>');
                $this->line('   <fg=yellow>those. If they are the oldest rows, look for something that was</>');
                $this->line('   <fg=yellow>true early on and is not now — a setting, or a user.</>');
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

        if ($count > 1) {
            $this->line('   (the others: run --row=' . implode(' and --row=', array_slice(
                array_values(array_diff($result['broken'], [$rowId])), 0, 5
            )) . ')');
        }

        $this->newLine();

        $this->explain($row, $this->previousHashFor($row));

        return $count > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Missing ids are worth reporting and are not, by themselves, evidence.
     *
     * An auto-increment id is handed out before the transaction commits, so
     * every rolled-back write burns one for good. AuditLogger::record() runs in
     * a transaction and is usually called from inside a larger one, so a failed
     * payment or a validation error further up leaves exactly this trace. It
     * only starts to mean deletion when whole runs of ids vanish at once.
     *
     * @param  list<int>  $gaps
     */
    private function reportGaps(array $gaps): void
    {
        if ($gaps === []) {
            return;
        }

        $this->line('   unused ids          ' . implode(', ', array_slice($gaps, 0, 20))
            . (count($gaps) > 20 ? ' and more' : ''));

        $this->line('                       ' . (count($gaps) > 10
            ? 'That is a lot. Runs of missing ids suggest rows were deleted.'
            : 'Normal: a transaction that rolls back still consumes its id.'));
    }

    /** The stored hash of the row immediately before this one, as verify() would use it. */
    private function previousHashFor(AuditLog $row): ?string
    {
        return AuditLog::where('id', '<', $row->id)->orderByDesc('id')->value('content_hash');
    }

    /**
     * Search for the version of this row that produces its stored hash.
     *
     * Each field gets a list of candidate values it might have held when the
     * row was written. Searching them one field at a time finds a single change;
     * searching them in pairs finds the common case where one event changed two
     * things at once — a restored backup that moved the timestamps and dropped
     * a user, say. Anything beyond pairs is left alone deliberately: the search
     * space stops being cheap, and by then the honest answer is that the row was
     * edited.
     */
    private function explain(AuditLog $row, ?string $previousHash): void
    {
        $stored = (string) $row->content_hash;

        if ($stored === '') {
            $this->warn('   This row has no stored hash at all, so there is nothing to check it');
            $this->warn('   against. It was written before hashing, or the column was cleared.');

            return;
        }

        $now = [
            'actor'  => $row->actor_user_id === null ? null : (int) $row->actor_user_id,
            'action' => (string) $row->action,
            'type'   => $row->entity_type,
            'entity' => $row->entity_id === null ? null : (int) $row->entity_id,
            'meta'   => $row->metadata,
            'time'   => $row->created_at?->toIso8601String() ?? '',
            'prev'   => $previousHash,
        ];

        if ($this->hash($row, $now) === $stored) {
            $this->info('   This row verifies. Nothing is wrong with it.');

            return;
        }

        // The whole search runs once per spelling of the id columns. A row
        // sealed over "2" cannot be reproduced by any search that writes 2, no
        // matter how many values it tries, so the type is a dimension of its
        // own rather than a detail of one.
        foreach ([true, false] as $normalised) {
            $this->normaliseIds = $normalised;

            if ($this->searchAllShapes($row, $stored, $now, $previousHash)) {
                return;
            }
        }

        $this->normaliseIds = true;

        $this->tamperReport($row, $now, $stored, $previousHash);
    }

    /**
     * Every field alone, then every pair, under the spelling currently set.
     *
     * @param  array<string, mixed>  $now
     */
    private function searchAllShapes(AuditLog $row, string $stored, array $now, ?string $previousHash): bool
    {
        if (! $this->normaliseIds) {
            $now['actor'] = $now['actor'] === null ? null : (string) $now['actor'];
            $now['entity'] = $now['entity'] === null ? null : (string) $now['entity'];

            // A row sealed under the other spelling fails even untouched, so
            // the spelling alone is the finding and there is nothing else to
            // look for.
            if ($this->hash($row, $now) === $stored) {
                $this->reportSpelling();

                return true;
            }
        }

        $candidates = [
            'actor'  => $this->actorCandidates(),
            'action' => $this->columnCandidates('action', 'the action was ', nullable: false),
            'type'   => $this->columnCandidates('entity_type', 'the entity type was '),
            'entity' => $this->entityCandidates($row),
            'meta'   => $this->metadataCandidates($row),
            'time'   => $this->timeCandidates($row),
            'prev'   => $this->previousHashCandidates($row, $previousHash),
        ];

        // One field at a time first, so a simple cause is reported simply.
        foreach ($candidates as $field => $values) {
            if ($found = $this->sweep($row, $stored, $now, [$field => $values])) {
                $this->report($found);

                return true;
            }
        }

        // Then pairs. Timestamps are in every pairing because the two events
        // that rewrite a whole table — a restored dump and a timezone change —
        // both move them, and both tend to arrive alongside something else.
        foreach ([
            ['time', 'actor'], ['time', 'meta'], ['time', 'entity'], ['time', 'prev'],
            ['time', 'action'], ['time', 'type'], ['actor', 'meta'], ['action', 'type'],
            ['action', 'meta'], ['prev', 'actor'], ['prev', 'meta'], ['entity', 'type'],
        ] as [$a, $b]) {
            if ($found = $this->sweep($row, $stored, $now, [$a => $candidates[$a], $b => $candidates[$b]])) {
                $this->report($found);

                return true;
            }
        }

        return false;
    }

    private function reportSpelling(): void
    {
        $this->line('   <fg=green;options=bold>Found it.</> The row is untouched. Its hash was taken while the id');
        $this->line('   columns were text and is being checked while they are numbers.');
        $this->newLine();
        $this->line('   The hash covers JSON, and JSON writes 2 and "2" differently. A host');
        $this->line('   whose database driver hands ids back as strings seals rows over the');
        $this->line('   text spelling; move the same rows somewhere that returns integers and');
        $this->line('   every one of them fails while nothing in them has changed. Nobody');
        $this->line('   edited anything — the rows predate the platform settling on one');
        $this->line('   spelling, and only rows written before that can fail this way.');
    }

    /**
     * Try every combination of the given fields' candidates.
     *
     * @param  array<string, array<string, mixed>>  $dimensions  field => (label => value)
     * @return array<string, array{label:string, value:mixed}>|null  the fields that had to change
     */
    private function sweep(AuditLog $row, string $stored, array $now, array $dimensions): ?array
    {
        $fields = array_keys($dimensions);
        $first = $fields[0];
        $second = $fields[1] ?? null;

        foreach ($dimensions[$first] as $labelA => $valueA) {
            $trial = $now;
            $trial[$first] = $valueA;

            if ($second === null) {
                if ($valueA !== $now[$first] && $this->hash($row, $trial) === $stored) {
                    return [$first => ['label' => $labelA, 'value' => $valueA]];
                }

                continue;
            }

            foreach ($dimensions[$second] as $labelB => $valueB) {
                if ($valueA === $now[$first] && $valueB === $now[$second]) {
                    continue;
                }

                $trial[$second] = $valueB;

                if ($this->hash($row, $trial) === $stored) {
                    return [
                        $first  => ['label' => $labelA, 'value' => $valueA],
                        $second => ['label' => $labelB, 'value' => $valueB],
                    ];
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $values */
    private function hash(AuditLog $row, array $values): string
    {
        return hash('sha256', AuditLogger::payload(
            $values['actor'],
            $values['action'],
            $values['type'],
            $values['entity'],
            $values['meta'],
            $values['time'],
            $values['prev'],
            $this->normaliseIds,
        ));
    }

    /**
     * Actor ids this row might have carried.
     *
     * NULL is first because it is the likely one: actor_user_id is declared
     * nullOnDelete, so removing a user silently blanks it on every row they
     * ever wrote. The sweep over real ids catches the reverse — a row now
     * pointing at somebody it did not point at before.
     *
     * @return array<string, int|null>
     */
    private function actorCandidates(): array
    {
        $out = ['nobody' => null];

        for ($id = 1; $id <= self::ID_SEARCH_LIMIT; $id++) {
            $out['user #' . $id] = $this->normaliseIds ? $id : (string) $id;
        }

        return $out;
    }

    /**
     * The values a text column has held anywhere in this table.
     *
     * Action names get renamed as a product changes — notary.listing_toggled
     * became notary.listed and notary.unlisted when self-listing was removed —
     * and a rename applied to old rows with an UPDATE would break exactly the
     * rows that predate it, which is the shape of damage worth testing against.
     * Every value the column has ever legitimately held is the search space.
     *
     * @return array<string, string|null>
     */
    private function columnCandidates(string $column, string $prefix, bool $nullable = true): array
    {
        $out = $nullable ? [$prefix . 'blank' => null] : [];
        $out[$prefix . 'an empty string'] = '';

        foreach (AuditLog::query()->distinct()->orderBy($column)->pluck($column) as $value) {
            if ($value !== null) {
                $out[$prefix . $value] = (string) $value;
            }
        }

        return $out;
    }

    /** @return array<string, int|null> */
    private function entityCandidates(AuditLog $row): array
    {
        $out = ['nothing' => null];

        for ($id = 1; $id <= self::ID_SEARCH_LIMIT; $id++) {
            $out[($row->entity_type ?? 'record') . ' #' . $id] = $this->normaliseIds ? $id : (string) $id;
        }

        return $out;
    }

    /**
     * Timestamps this row might have carried.
     *
     * Three different things can be wrong with a stored time, and they produce
     * different strings, so all three are tried:
     *
     * - the instant moved, and the offset it is printed with did not: a dump
     *   restored on a server whose clock or session time_zone differs.
     * - the digits stayed and the offset changed: nothing touched the database
     *   at all, APP_TIMEZONE was edited. The row still says 16:35 and now says
     *   it in +01:00 where it used to say it in +00:00. This is the one that
     *   breaks only the oldest rows and nothing since.
     * - both: the same instant re-expressed in another zone.
     *
     * @return array<string, string>
     */
    private function timeCandidates(AuditLog $row): array
    {
        if ($row->created_at === null) {
            return [];
        }

        $at = CarbonImmutable::instance($row->created_at);
        $digits = $at->format('Y-m-d H:i:s');

        /** @var array<string, string> $byIso keyed by the string, so each is tried once */
        $byIso = [];

        // Small drifts first, and in seconds. A database engine that rounds a
        // fractional second where the hash truncated it, a row copied between
        // servers whose clocks disagreed, an import that re-derived the time
        // from a date and lost the rest — none of those move an hour, and a
        // sweep that starts at fifteen minutes steps straight over all of them.
        foreach ([...range(-120, 120), ...range(-31 * 86400, 31 * 86400, 86400)] as $seconds) {
            if ($seconds === 0) {
                continue;
            }

            $byIso[$at->addSeconds($seconds)->toIso8601String()] ??= abs($seconds) <= 120
                ? 'the moment was ' . ($seconds > 0 ? '+' : '−') . abs($seconds) . 's from what it reads'
                : 'the moment was ' . ($seconds > 0 ? '+' : '−') . abs(intdiv($seconds, 86400)) . ' days from what it reads';
        }

        for ($minutes = -14 * 60; $minutes <= 14 * 60; $minutes += 15) {
            if ($minutes === 0) {
                continue;
            }

            $sign = $minutes > 0 ? '+' : '-';
            $abs = abs($minutes);
            $offset = sprintf('%s%02d:%02d', $sign, intdiv($abs, 60), $abs % 60);
            $moved = ($minutes > 0 ? '+' : '−') . intdiv($abs, 60) . 'h'
                . ($abs % 60 ? ' ' . ($abs % 60) . 'm' : '');

            $byIso[$at->addMinutes($minutes)->toIso8601String()] ??= 'the moment was ' . $moved . ' from what it reads';
            $byIso[CarbonImmutable::parse($digits, $offset)->toIso8601String()] ??= 'the clock read the same but the timezone was ' . $offset;
            $byIso[$at->setTimezone($offset)->toIso8601String()] ??= 'the same moment written in ' . $offset;
        }

        return array_flip($byIso);
    }

    /**
     * Metadata this row might have carried.
     *
     * Only shapes something could plausibly have turned into: the same keys
     * with a value's type changed (a boolean written where the database now
     * returns an integer), a key dropped, or nothing at all. Key order is worth
     * permuting because MySQL's JSON type does not store objects in the order
     * they were given.
     *
     * @return array<string, array<mixed>|null>
     */
    private function metadataCandidates(AuditLog $row): array
    {
        $meta = $row->metadata;
        $out = ['no metadata' => null, 'empty metadata' => []];

        if (! is_array($meta) || $meta === []) {
            return $out;
        }

        foreach ($meta as $key => $value) {
            foreach ($this->typeVariants($value) as $label => $variant) {
                $copy = $meta;
                $copy[$key] = $variant;
                $out[$key . ' was ' . $label] = $copy;
            }

            $copy = $meta;
            unset($copy[$key]);
            $out['there was no ' . $key . ' key'] = $copy;
        }

        if (count($meta) > 1 && count($meta) <= 5) {
            foreach ($this->permutations(array_keys($meta)) as $order) {
                $copy = [];

                foreach ($order as $key) {
                    $copy[$key] = $meta[$key];
                }

                if ($copy !== $meta) {
                    $out['the keys were in the order ' . implode(', ', $order)] = $copy;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function typeVariants(mixed $value): array
    {
        if (is_array($value) || $value === null) {
            return [];
        }

        return array_filter([
            'true'         => true,
            'false'        => false,
            'the number 0' => 0,
            'the number 1' => 1,
            'the text "0"' => '0',
            'the text "1"' => '1',
            'an empty string' => '',
        ], fn ($variant) => $variant !== $value);
    }

    /**
     * @param  list<string|int>  $keys
     * @return list<list<string|int>>
     */
    private function permutations(array $keys): array
    {
        if (count($keys) <= 1) {
            return [$keys];
        }

        $out = [];

        foreach ($keys as $index => $key) {
            $rest = $keys;
            unset($rest[$index]);

            foreach ($this->permutations(array_values($rest)) as $tail) {
                $out[] = [$key, ...$tail];
            }
        }

        return $out;
    }

    /**
     * The link back to the previous row.
     *
     * A row that hashes correctly against no predecessor was the first entry in
     * the log, which means everything before it is gone. A row that hashes
     * against its own stored previous_hash rather than the one the chain
     * actually offers means a row was inserted or removed in between.
     *
     * @return array<string, string|null>
     */
    private function previousHashCandidates(AuditLog $row, ?string $previousHash): array
    {
        $out = [
            'there was no row before it' => null,
            'the link was an empty string' => '',
        ];

        if ($row->previous_hash !== null && $row->previous_hash !== $previousHash) {
            $out['the row it names, not the row that precedes it'] = $row->previous_hash;
        }

        // Any other row's hash. If this row was chained to a different entry
        // than the one that sits before it now, something was inserted between
        // them or removed from between them, and the search says which.
        foreach (AuditLog::where('id', '!=', $row->id)->orderBy('id')->pluck('content_hash', 'id') as $id => $hash) {
            if ($hash !== null && $hash !== $previousHash) {
                $out['it followed entry #' . $id] = $hash;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $now
     * @param  array<string, array{label:string, value:mixed}>  $found
     */
    private function report(array $found): void
    {
        $names = [
            'actor'  => 'the actor',
            'action' => 'the action name',
            'type'   => 'the entity type',
            'entity' => 'the record it points at',
            'meta'   => 'the metadata',
            'time'   => 'the timestamp',
            'prev'   => 'the link to the previous row',
        ];

        $changed = array_map(fn ($field) => $names[$field], array_keys($found));

        $this->line('   <fg=green;options=bold>Found it.</> When this row was written, '
            . $this->sentenceList($changed) . ' differed from what it says now.');
        $this->newLine();

        foreach ($found as $field => $hit) {
            $this->line('   ' . str_pad($names[$field], 30) . $hit['label']);
        }

        $this->newLine();

        // The narration is the point. Each of these is something that happened
        // to the database rather than something done to this record, and saying
        // which one it was is the difference between a fixable operational note
        // and an accusation.
        if (isset($found['time']) && str_contains($found['time']['label'], 'timezone was')) {
            $this->line('   Nobody edited this record and the database was not touched. The');
            $this->line('   clock digits are unchanged — only the offset they are read with.');
            $this->line('   APP_TIMEZONE was changed in .env after this row was written, so the');
            $this->line('   hash was taken over one spelling of the time and is now checked');
            $this->line('   against another. Only rows written before the change can fail this');
            $this->line('   way, which is why the newer ones all verify.');

            return;
        }

        if (isset($found['time'])) {
            $this->line('   A MySQL TIMESTAMP is converted using the session time zone on the');
            $this->line('   way in and on the way out, so dumping the database on one machine');
            $this->line('   and importing it on another rewrites every timestamp in the table.');
            $this->line('   The hashes were taken over the old values and no longer fit.');
        }

        if (isset($found['actor']) && $found['actor']['value'] === null) {
            $this->line('   This row was written before anyone was signed in — the actor was');
            $this->line('   blank and has since been filled in.');
        } elseif (isset($found['actor'])) {
            $this->line('   audit_log.actor_user_id is declared nullOnDelete, so deleting');
            $this->line('   ' . $found['actor']['label'] . ' set this column to NULL on every row they');
            $this->line('   ever wrote — and each hash was taken over the old value. Nothing was');
            $this->line('   forged. A user was deleted and the log was rewritten underneath it.');
            $this->newLine();
            $this->line('   Rows with no actor at all: ' . AuditLog::whereNull('actor_user_id')->count() . '.');
        }

        if (isset($found['prev']) && $found['prev']['value'] === null) {
            $this->line('   This row hashes correctly as the first entry in the log, so it was');
            $this->line('   the first entry — everything written earlier has been deleted.');
        }
    }

    /**
     * @param  array<string, mixed>  $now
     */
    private function tamperReport(AuditLog $row, array $now, string $stored, ?string $previousHash): void
    {
        $this->error('   No ordinary explanation reproduces this row\'s hash.');
        $this->newLine();
        $this->line('   Ruled out, alone and in pairs: the timestamp has not moved by seconds,');
        $this->line('   hours or days and is not being read in a different timezone; the actor');
        $this->line('   and the record id have not been changed or nulled; the action name and');
        $this->line('   entity type are not values this table has ever used elsewhere; the row');
        $this->line('   was not chained to a different entry; and the ids were not sealed as');
        $this->line('   text. So this row\'s contents were altered after it was written, and');
        $this->line('   the hash is doing the job it exists for.');
        $this->newLine();
        $this->line('   The one thing that cannot be ruled out is the metadata. Every other');
        $this->line('   field is searched over values that still exist somewhere in this table,');
        $this->line('   so an old one can be recovered and named. Metadata is free-form: the');
        $this->line('   search can reshuffle and retype the keys this row still carries, but');
        $this->line('   nothing can reconstruct a key it no longer has. If this row was edited,');
        $this->line('   the metadata is where to look, and the old value is not recoverable');
        $this->line('   from here — only from a backup taken before the change.');
        $this->newLine();
        $this->line('   Stored hash    ' . $stored);
        $this->line('   Recomputed     ' . $this->hash($row, $now));
        $this->line('   Row as it now reads:');
        $this->line('     actor_user_id  ' . ($now['actor'] ?? 'NULL'));
        $this->line('     action         ' . $row->action);
        $this->line('     entity         ' . ($row->entity_type ?? '—') . ' #' . ($now['entity'] ?? '—'));
        $this->line('     created_at     ' . $now['time']);
        $this->line('     metadata       ' . json_encode($now['meta']));
        $this->line('     previous_hash  ' . ($row->previous_hash ?? 'NULL')
            . ($row->previous_hash === $previousHash ? '' : '   (does not match the row before it)'));
        $this->newLine();
        $this->line('   The hashed bytes, so the two can be compared by hand:');
        $this->line('   ' . AuditLogger::payload(
            $now['actor'], (string) $row->action, $row->entity_type,
            $now['entity'], $now['meta'], $now['time'], $now['prev'],
        ));
    }

    /** @param  list<string>  $items */
    private function sentenceList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
