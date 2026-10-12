<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reviewer's mark against one criterion.
 *
 * `score` is nullable, and that is the useful part: a criterion nobody has
 * looked at shows as a gap on the screen rather than as a zero. "Scored badly"
 * and "not yet read" are completely different facts about an application and
 * must not be rendered the same way.
 *
 * Nothing in this module reads these numbers and decides anything. There is no
 * ranking, no threshold, no automatic rejection. A score is one person's note
 * to the people in the room.
 */
class ReviewerScore extends Model
{
    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AssessmentAssignment::class, 'assessment_assignment_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
