<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to one task, at one revision.
 *
 * Drafts and the final submission live in the same table, distinguished by
 * `is_final`, and earlier revisions are kept. That matters when a reviewer
 * reopens a task: what was originally submitted is still on record, so
 * "they changed it after we asked" is a question with an answer.
 *
 * `answer` is plain text, escaped on the way out like everything else a
 * stranger wrote. It never passes through the HTML sanitiser, which exists to
 * let some tags through and is therefore the wrong tool for this.
 */
class AssessmentResponse extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_final' => 'boolean',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AssessmentAssignment::class, 'assessment_assignment_id');
    }
}
