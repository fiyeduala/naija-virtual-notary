<?php

namespace App\Models;

use App\Enums\ApplicationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded move from one stage to another.
 *
 * Append-only in practice: nothing in this module updates or deletes a row
 * here. The history of a hiring decision is the evidence that it was made by
 * people, in an order, for reasons — and a history you can edit is not
 * evidence of anything.
 *
 * `metadata` carries what was skipped, what was invalidated and which message
 * was called off. It never carries a document's contents, a login token or a
 * candidate's full sensitive answers.
 */
class ApplicationStageEvent extends Model
{
    protected $guarded = ['id'];

    public const ACTOR_STAFF     = 'staff';
    public const ACTOR_CANDIDATE = 'candidate';
    public const ACTOR_SYSTEM    = 'system';

    protected function casts(): array
    {
        return [
            'from_stage' => ApplicationStage::class,
            'to_stage'   => ApplicationStage::class,
            'metadata'   => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** Who did it, in words, for the history panel. */
    public function actorLabel(): string
    {
        return match ($this->actor_type) {
            self::ACTOR_CANDIDATE => 'The candidate',
            self::ACTOR_SYSTEM    => 'Automatically',
            default               => $this->actor?->name ?? 'A member of staff',
        };
    }
}
