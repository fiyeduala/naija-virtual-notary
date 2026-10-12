<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to one screening question, as asked at the time.
 *
 * The label and type are copied onto the row rather than looked up. An
 * administrator who rewords or removes a question later has not changed what
 * this person was asked, and the answer still reads as an answer to something.
 */
class ApplicationAnswer extends Model
{
    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }
}
