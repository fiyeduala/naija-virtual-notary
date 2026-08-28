<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Append-only, tamper-evident audit logger.
 *
 * Each row stores a content hash chained to the previous row's hash, so any
 * later tampering with an earlier row breaks the chain and is detectable.
 * This is the platform's legal evidence trail — see Build Plan section 6.
 */
class AuditLogger
{
    public static function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $metadata = [],
        ?int $actorUserId = null,
    ): AuditLog {
        return DB::transaction(function () use ($action, $entityType, $entityId, $metadata, $actorUserId) {
            $previous = AuditLog::orderByDesc('id')->lockForUpdate()->first();
            $previousHash = $previous?->content_hash;

            $actorUserId ??= auth()->id();
            $createdAt = now();

            $contentHash = hash('sha256', static::payload(
                $actorUserId,
                $action,
                $entityType,
                $entityId,
                $metadata,
                $createdAt->toIso8601String(),
                $previousHash,
            ));

            return AuditLog::create([
                'actor_user_id' => $actorUserId,
                'action'        => $action,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'metadata'      => $metadata,
                'ip_address'    => request()->ip(),
                'user_agent'    => substr((string) request()->userAgent(), 0, 255),
                'content_hash'  => $contentHash,
                'previous_hash' => $previousHash,
                'created_at'    => $createdAt,
            ]);
        });
    }

    /**
     * The exact bytes a row's hash is taken over.
     *
     * One method, used by both the writer and every verifier, because the hash
     * is only meaningful if both sides agree byte for byte on what was hashed.
     *
     * The two integer ids are cast rather than passed through. At write time
     * they are always real ints — record() types them that way — but a database
     * driver may hand them back as strings, and json_encode writes "1" and 1
     * differently. That difference alone would report every row on the platform
     * as tampered with while nothing had actually been touched. Casting on the
     * way in restores agreement with what was written; it can never make a
     * genuinely altered row verify, because the value itself still has to match.
     */
    public static function payload(
        int|string|null $actorUserId,
        string $action,
        ?string $entityType,
        int|string|null $entityId,
        ?array $metadata,
        string $createdAtIso,
        ?string $previousHash,
        bool $normaliseIds = true,
    ): string {
        $id = fn (int|string|null $value) => match (true) {
            $value === null => null,
            $normaliseIds   => (int) $value,
            default         => $value,
        };

        return json_encode([
            'actor_user_id' => $id($actorUserId),
            'action'        => $action,
            'entity_type'   => $entityType,
            'entity_id'     => $id($entityId),
            'metadata'      => $metadata,
            'created_at'    => $createdAtIso,
            'previous_hash' => $previousHash,
        ]);
    }

    /** The hash a stored row should carry, given the hash of the row before it. */
    public static function hashOf(AuditLog $row, ?string $previousHash): string
    {
        return hash('sha256', static::payload(
            $row->actor_user_id,
            (string) $row->action,
            $row->entity_type,
            $row->entity_id,
            $row->metadata,
            // A row with no timestamp cannot match any hash, but it must not
            // throw either: one broken row has to be reportable, not fatal to
            // the whole verification.
            $row->created_at?->toIso8601String() ?? '',
            $previousHash,
        ));
    }

    /** Verify the entire chain is intact. Returns the id of the first broken row, or null if valid. */
    public static function verifyChain(): ?int
    {
        return static::verify()['first_broken'];
    }

    /**
     * Walk the whole chain and report everything, not just the first failure.
     *
     * verifyChain() stops at the first bad row, which answers "is it intact?"
     * and nothing else. The shape of the damage is what actually tells you what
     * happened: one bad row in the middle is an edited record, every row bad
     * from the start is the reader and the writer disagreeing about how a value
     * is spelled, and a gap in the ids is rows having been deleted outright.
     *
     * @return array{checked:int, broken:list<int>, first_broken:?int, gaps:list<int>, first_id:?int, last_id:?int}
     */
    public static function verify(): array
    {
        $previousHash = null;
        $checked = 0;
        $broken = [];
        $gaps = [];
        $expectedId = null;
        $firstId = null;
        $lastId = null;

        foreach (AuditLog::orderBy('id')->cursor() as $row) {
            $firstId ??= (int) $row->id;
            $lastId = (int) $row->id;
            $checked++;

            if ($expectedId !== null) {
                for ($missing = $expectedId; $missing < (int) $row->id; $missing++) {
                    if (count($gaps) < 50) {
                        $gaps[] = $missing;
                    }
                }
            }

            $expectedId = (int) $row->id + 1;

            if (static::hashOf($row, $previousHash) !== $row->content_hash) {
                $broken[] = (int) $row->id;
            }

            // The chain continues from what is stored, not from what we
            // recomputed. A row that fails must not cascade a second failure
            // into the row after it — that would hide whether the damage is one
            // row or all of them, which is the whole question.
            $previousHash = $row->content_hash;
        }

        return [
            'checked'      => $checked,
            'broken'       => $broken,
            'first_broken' => $broken[0] ?? null,
            'gaps'         => $gaps,
            'first_id'     => $firstId,
            'last_id'      => $lastId,
        ];
    }
}
