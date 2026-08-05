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

            $payload = json_encode([
                'actor_user_id' => $actorUserId,
                'action'        => $action,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'metadata'      => $metadata,
                'created_at'    => $createdAt->toIso8601String(),
                'previous_hash' => $previousHash,
            ]);

            $contentHash = hash('sha256', $payload);

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

    /** Verify the entire chain is intact. Returns the id of the first broken row, or null if valid. */
    public static function verifyChain(): ?int
    {
        $previousHash = null;

        foreach (AuditLog::orderBy('id')->cursor() as $row) {
            $payload = json_encode([
                'actor_user_id' => $row->actor_user_id,
                'action'        => $row->action,
                'entity_type'   => $row->entity_type,
                'entity_id'     => $row->entity_id,
                'metadata'      => $row->metadata,
                'created_at'    => $row->created_at->toIso8601String(),
                'previous_hash' => $previousHash,
            ]);

            if (hash('sha256', $payload) !== $row->content_hash) {
                return $row->id;
            }

            $previousHash = $row->content_hash;
        }

        return null;
    }
}
