<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reviewer's overall note and recommendation on an application.
 *
 * A recommendation is advice, not an instruction. `submitted_at` is what makes
 * an incomplete review visible as incomplete, so nobody decides on the
 * strength of two opinions while believing they had four.
 *
 * Reviewer identities and summaries are internal. No candidate-facing route
 * reads this table.
 */
class ReviewerReview extends Model
{
    protected $guarded = ['id'];

    public const ADVANCE = 'advance';
    public const HOLD    = 'hold';
    public const DECLINE = 'decline';

    public const RECOMMENDATIONS = [
        self::ADVANCE => 'Take forward',
        self::HOLD    => 'Hold / undecided',
        self::DECLINE => 'Do not take forward',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function recommendationLabel(): ?string
    {
        return self::RECOMMENDATIONS[$this->recommendation] ?? null;
    }
}
