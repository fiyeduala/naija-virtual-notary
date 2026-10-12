<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An offer of employment.
 *
 * Two rules this class exists to hold:
 *
 *   Nothing composes employment terms. An offer is either an approved template
 *   filled in by a person, or a document somebody uploaded. There is no code
 *   path that writes a salary, a start date or a notice period that an
 *   administrator did not type.
 *
 *   `accepted` is a status here and NOT an application stage. Checks may still
 *   be outstanding when somebody accepts, and conflating the two is how a
 *   person ends up marked Hired before a reference was ever looked at. Hiring
 *   is a separate, manual act.
 *
 * A candidate clicking "I accept" is a recorded indication of intent. It is
 * not a certified electronic signature and the wording shown to them says so.
 */
class Offer extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public const DRAFT     = 'draft';
    public const APPROVED  = 'approved';
    public const SENT      = 'sent';
    public const ACCEPTED  = 'accepted';
    public const DECLINED  = 'declined';
    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::DRAFT     => 'Draft',
        self::APPROVED  => 'Approved, not yet sent',
        self::SENT      => 'Sent to the candidate',
        self::ACCEPTED  => 'Accepted',
        self::DECLINED  => 'Declined',
        self::WITHDRAWN => 'Withdrawn',
    ];

    protected function casts(): array
    {
        return [
            'start_date'   => 'date',
            'approved_at'  => 'datetime',
            'sent_at'      => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ApplicationDocument::class, 'document_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Has it actually got something in it to send? */
    public function isSendable(): bool
    {
        return $this->status === self::APPROVED
            && (filled($this->body) || $this->document_id !== null);
    }

    public function awaitingResponse(): bool
    {
        return $this->status === self::SENT;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? 'Draft';
    }

    /** The money, written out. Minor units in, a sentence out. */
    public function salaryLine(): ?string
    {
        if (blank($this->salary_minor)) {
            return null;
        }

        $currency = strtoupper((string) ($this->currency ?: 'NGN'));
        $symbol   = match ($currency) {
            'NGN'   => '₦',
            'USD'   => '$',
            'GBP'   => '£',
            default => $currency . ' ',
        };

        $period = Vacancy::PAY_PERIODS[$this->pay_period] ?? '';

        return trim($symbol . number_format(((int) $this->salary_minor) / 100) . ' ' . $period);
    }
}
