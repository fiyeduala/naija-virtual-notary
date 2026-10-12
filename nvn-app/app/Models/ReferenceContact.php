<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody the candidate has named: a referee, or a guarantor.
 *
 * The two are kept apart in one column because they are not the same thing. A
 * referee speaks to how somebody works. A guarantor accepts a liability on
 * their behalf, which is a serious thing to ask of a friend and is off by
 * default — a vacancy has to switch it on and say why.
 *
 * `consent_confirmed` is the candidate telling us this person agreed to be
 * approached. It is not permission for the system to write to them: nothing
 * here contacts a third party. `contacted_at` is only ever set by a member of
 * staff taking a separate, deliberate action, because a stranger receiving
 * mail about somebody's job application is something a person should have
 * decided to send.
 */
class ReferenceContact extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public const REFERENCE = 'reference';
    public const GUARANTOR = 'guarantor';

    public const TYPES = [
        self::REFERENCE => 'Professional reference',
        self::GUARANTOR => 'Guarantor',
    ];

    public const OUTCOMES = [
        'positive'    => 'Responded positively',
        'concerns'    => 'Responded with concerns',
        'no_response' => 'No response',
    ];

    protected function casts(): array
    {
        return [
            'consent_confirmed'    => 'boolean',
            'consent_confirmed_at' => 'datetime',
            'contacted_at'         => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function contactedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contacted_by');
    }

    /**
     * A guarantor's details are sensitive whatever the row says.
     *
     * They belong to somebody who is not even applying for the job and who
     * never agreed to be in an export.
     */
    public function isSensitive(): bool
    {
        return true;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->contact_type] ?? 'Professional reference';
    }

    /** May a member of staff write to this person yet? */
    public function readyToContact(): bool
    {
        return $this->consent_confirmed
            && filled($this->email)
            && $this->contacted_at === null;
    }
}
