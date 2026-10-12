<?php

namespace App\Models;

use App\Support\EmailNormalizer;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Somebody who has applied for a job. Not a user, and never becomes one.
 *
 * Authenticatable because a candidate signs in to see their own application,
 * through their own guard and their own session. They have no row in `users`,
 * which means no candidate session can ever reach a client dashboard, a notary
 * desk or the admin panel — those are all guarded by the `web` guard, and a
 * candidate is not on it. That is a structural boundary rather than a check
 * somebody has to remember to write.
 *
 * There is no password column. Access is a short-lived emailed token exchanged
 * for a session, so there is no credential here to be guessed, reused from
 * another site, or leaked. `getAuthPassword()` returns an empty string and no
 * code path attempts a password comparison.
 *
 * Deliberately not collected: date of birth, marital status, religion, a
 * photograph, a government ID number, bank details. None of it is needed to
 * decide whether somebody can do the job.
 */
class Candidate extends Authenticatable
{
    use Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'privacy_accepted_at'             => 'datetime',
            'future_opportunities_consent'    => 'boolean',
            'future_opportunities_consent_at' => 'datetime',
            'last_login_at'                   => 'datetime',
            'anonymised_at'                   => 'datetime',
        ];
    }

    /** There is no password. Nothing compares against this. */
    public function getAuthPassword(): string
    {
        return '';
    }

    /* ---- relations ----------------------------------------------------- */

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function loginTokens(): HasMany
    {
        return $this->hasMany(CandidateLoginToken::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    /* ---- helpers -------------------------------------------------------- */

    /**
     * Find or create by email, writing the canonical form alongside.
     *
     * The canonical form is only trimmed and lowercased. Dots are not stripped
     * and plus-aliases are not cut: those are Gmail conventions, and applying
     * them everywhere would treat first.last@acme.com and firstlast@acme.com
     * as one person, rejecting the second applicant for good.
     */
    public static function forEmail(string $email, array $attributes = []): self
    {
        $canonical = EmailNormalizer::canonical($email);

        $candidate = static::query()->where('email_canonical', $canonical)->first();

        if ($candidate) {
            // Their details may have changed since they last applied. The
            // newest application wins for contact details; nothing on an
            // existing application is touched.
            $candidate->fill(array_filter($attributes, fn ($value) => filled($value)));
            $candidate->email = EmailNormalizer::clean($email);
            $candidate->save();

            return $candidate;
        }

        return static::query()->create($attributes + [
            'email'           => EmailNormalizer::clean($email),
            'email_canonical' => $canonical,
        ]);
    }

    /** First name, for addressing an email without over-familiarity. */
    public function firstName(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->full_name)) ?: [];

        return (string) ($parts[0] ?? 'there');
    }

    /** Has a retention purge already stripped this record? */
    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    /** The masked address, for a screen or an audit note. */
    public function maskedEmail(): string
    {
        return EmailNormalizer::mask((string) $this->email);
    }

    /**
     * Candidates must never appear in the platform's own notification plumbing
     * as though they were staff. This is here so the intent is explicit.
     */
    public function isStaff(): bool
    {
        return false;
    }
}
