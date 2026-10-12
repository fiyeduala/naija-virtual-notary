<?php

namespace App\Models;

use App\Enums\UserRole;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasName
{
    use Notifiable, SoftDeletes;

    protected $fillable = [
        'full_name', 'email', 'phone', 'password', 'role', 'status',
        'email_verified_at', 'otp_code', 'otp_expires_at',
        'mfa_enabled', 'last_login_at',
    ];

    protected $hidden = ['password', 'legacy_password', 'remember_token', 'otp_code'];

    protected function casts(): array
    {
        return [
            'role'              => UserRole::class,
            'email_verified_at' => 'datetime',
            'otp_expires_at'    => 'datetime',
            'last_login_at'     => 'datetime',
            'mfa_enabled'       => 'boolean',
            'bulk_email_opt_out' => 'boolean',
            'organization_referred_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    // Relationships
    public function notaryProfile(): HasOne
    {
        return $this->hasOne(NotaryProfile::class);
    }

    public function clientRequests(): HasMany
    {
        return $this->hasMany(NotarizationRequest::class, 'client_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_user_id');
    }

    /**
     * The partner body whose link brought this person to the platform.
     *
     * First touch and never overwritten — see App\Support\OrganizationReferral.
     * Deliberately not in $fillable: it is set once, by the registration
     * controller, from a cookie rather than from form input.
     */
    public function referredByOrganization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organization::class, 'referred_by_organization_id');
    }

    // Role helpers
    public function isClient(): bool { return $this->role === UserRole::Client; }
    public function isNotary(): bool { return $this->role === UserRole::Notary; }
    public function isAdmin(): bool  { return $this->role === UserRole::Admin; }

    /** Every admin, for alerts that go to the desk rather than to one person. */
    public function scopeAdmins($query)
    {
        return $query->where('role', UserRole::Admin);
    }

    public function hasVerifiedEmail(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    /** Use our own branded mail rather than Laravel's default reset notification. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\PasswordResetNotification($token));
    }

    public function getFilamentName(): string
    {
        return $this->full_name;
    }

    /**
     * Filament admin panel access.
     *
     * Two kinds of account get in, and they see very different panels:
     *
     *   - an active administrator, as before; and
     *   - an active user carrying a `recruitment_role`, who reaches the
     *     Recruitment group and nothing else. Every resource, page and widget
     *     that predates this module carries App\Filament\Concerns\AdminOnly
     *     and refuses them, so an HR reviewer invited to mark exercises has no
     *     route to a payment, a payout, a user record or a client's document.
     *
     * A recruitment role is additive and nullable, so every account that
     * existed before this module is unaffected: the column is NULL and the
     * answer is exactly what it was.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return $this->isAdmin() || filled($this->recruitment_role);
    }
}
