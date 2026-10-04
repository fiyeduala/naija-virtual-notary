<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * A partner body that sends work: an embassy, an agency, a law firm.
 *
 * Authenticatable because an organization signs in to its own portal through
 * its own guard. It is not a User and deliberately has no row in `users`:
 * users.role is an enum, and widening it would mean raw per-driver SQL and a
 * fourth kind of account that every role check on the platform would have to
 * learn about.
 *
 * Two arrangements, and the difference is money:
 *
 *   commission  — the body earns a percentage of what its referrals pay.
 *   price_only  — the body is charged its own negotiated rate and earns
 *                 nothing. Stored as a fact, never as a 0% commission; see
 *                 OrganizationPayoutService::generateFor() for the ledger
 *                 trap that distinction avoids.
 */
class Organization extends Authenticatable
{
    use Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'account_number'];

    /** The arrangement under which a body earns a share of what it refers. */
    public const COMMISSION = 'commission';

    /** The arrangement under which a body is simply charged its own rate. */
    public const PRICE_ONLY = 'price_only';

    public const ARRANGEMENTS = [
        self::COMMISSION => 'Earns commission',
        self::PRICE_ONLY => 'Charged a rate only',
    ];

    public const STATUSES = [
        'pending'  => 'Awaiting review',
        'active'   => 'Active',
        'paused'   => 'Paused',
        'rejected' => 'Rejected',
    ];

    /**
     * What the public application form is allowed to write.
     *
     * Listed once, here, because two things read it: the FormRequest that
     * validates a submission, and a test that asserts every one of these
     * fields is editable in the admin panel. Correcting what a body sent is
     * the whole reason the application IS the organization record, and a field
     * that can be submitted but not corrected would quietly break that.
     *
     * Note what is absent: arrangement, commission_rate and the prices. Those
     * are the negotiation, and an applicant does not propose their own terms.
     */
    public const APPLICATION_FIELDS = [
        'name',
        'registration_number',
        'sector',
        'website',
        'address',
        'about',
        'expected_volume',
        'contact_name',
        'contact_role',
        'contact_email',
        'phone',
    ];

    protected function casts(): array
    {
        return [
            'default_price_ngn' => 'integer',
            'default_price_usd' => 'integer',
            'commission_rate'   => 'integer',
            'attribution_days'  => 'integer',
            'account_number'    => 'encrypted',
            'password'          => 'hashed',
            'applied_at'        => 'datetime',
            'reviewed_at'       => 'datetime',
            'last_login_at'     => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function documents(): HasMany
    {
        return $this->hasMany(OrganizationDocument::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(OrganizationPrice::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(OrganizationPayout::class);
    }

    /** Work this body sent. */
    public function requests(): HasMany
    {
        return $this->hasMany(NotarizationRequest::class);
    }

    /** People who arrived through this body's link and registered. */
    public function referredUsers(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_organization_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    /** Reachable, and allowed to attribute new work. */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Earns a share of what it refers. */
    public function earnsCommission(): bool
    {
        return $this->arrangement === self::COMMISSION && $this->commission_rate > 0;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function arrangementLabel(): string
    {
        return self::ARRANGEMENTS[$this->arrangement] ?? 'Not set';
    }

    /**
     * Everything that must be settled before this body can go live.
     *
     * Returned as a list of sentences rather than a boolean so the admin is
     * told what is missing instead of being refused without a reason. A body
     * that is reachable but unpriced would quote its referrals ₦0.00.
     */
    public function missingBeforeApproval(): array
    {
        $missing = [];

        if (! in_array($this->arrangement, [self::COMMISSION, self::PRICE_ONLY], true)) {
            $missing[] = 'the arrangement — does this body earn a commission, or is it charged a rate only?';
        }

        if (! $this->default_price_ngn) {
            $missing[] = 'a default naira price for this body\'s referrals';
        }

        if ($this->arrangement === self::COMMISSION) {
            if (! $this->commission_rate) {
                $missing[] = 'a commission rate';
            }

            if (! $this->bank_name || ! $this->account_name || ! $this->account_number) {
                $missing[] = 'bank details, so the commission can be paid';
            }
        }

        if (! $this->contact_email) {
            $missing[] = 'a contact email to send the link to';
        }

        return $missing;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /*
    |--------------------------------------------------------------------------
    | Attribution
    |--------------------------------------------------------------------------
    */

    /** The public link handed to this body's applicants. */
    public function landingUrl(): string
    {
        return $this->slug ? route('organization.landing', $this->slug) : '';
    }

    /** The short form, for a letter or a poster. */
    public function shortUrl(): string
    {
        return $this->slug ? url('/o/' . $this->slug) : '';
    }

    /**
     * How long a referral keeps counting, in days. Zero means forever.
     *
     * Falls back to config rather than defaulting in the column, so changing
     * the house rule does not require touching every row.
     */
    public function attributionDays(): int
    {
        return $this->attribution_days ?? (int) config('nvn.organizations.attribution_days', 30);
    }

    /** Is a visit or registration from $at still inside the window? */
    public function withinAttributionWindow(?\DateTimeInterface $at): bool
    {
        if ($at === null) {
            return false;
        }

        $days = $this->attributionDays();

        return $days === 0 || \Illuminate\Support\Carbon::instance($at)->gt(now()->subDays($days));
    }

    /*
    |--------------------------------------------------------------------------
    | Approval
    |--------------------------------------------------------------------------
    */

    /** A slug nobody else holds, derived from the body's own name. */
    public static function generateSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $n    = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }

    /** A short code someone can read down a telephone. */
    public static function generateCode(string $name): string
    {
        $base = Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $name), 6, ''));
        $base = $base !== '' ? $base : 'ORG';

        do {
            $code = $base . Str::upper(Str::random(4));
        } while (static::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /*
    |--------------------------------------------------------------------------
    | Display
    |--------------------------------------------------------------------------
    */

    /**
     * Where mail to this body goes.
     *
     * The contact, not the portal login. They are usually the same address,
     * but the contact is a person who answers and the login is a credential —
     * and a pending body has a contact and no login at all, which is exactly
     * when we most need to be able to write to it.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->contact_email ?: $this->email;
    }

    /**
     * Where a portal reset link goes.
     *
     * To the login address, not the contact — a reset is about a credential,
     * and the link must arrive at the inbox whoever asked for it just typed.
     * Sent on demand for that reason, which is also why the notification has
     * to carry the body itself.
     */
    public function sendPasswordResetNotification($token): void
    {
        \Illuminate\Support\Facades\Notification::route('mail', $this->email ?: $this->contact_email)
            ->notify(new \App\Notifications\Organization\OrganizationPasswordReset($this, $token));
    }

    /** Safe to show on screen and in logs. */
    public function maskedAccountNumber(): string
    {
        $number = (string) $this->account_number;

        return $number === ''
            ? '—'
            : str_repeat('•', max(0, strlen($number) - 4)) . substr($number, -4);
    }

    /** This body's default price, formatted. */
    public function displayDefaultPrice(string $currency = 'NGN'): string
    {
        $minor = $currency === 'USD' ? $this->default_price_usd : $this->default_price_ngn;

        return $minor === null
            ? 'not set'
            : NotarizationRequest::money((int) $minor, $currency);
    }
}
