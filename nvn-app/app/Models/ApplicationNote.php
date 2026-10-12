<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A private note on an application.
 *
 * There is no candidate-facing route that reads this table, and that is the
 * only reliable way to keep a note private. Nothing here is ever rendered in
 * the candidate area, included in an export, or put in an email.
 */
class ApplicationNote extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
