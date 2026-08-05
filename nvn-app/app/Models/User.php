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

    protected $hidden = ['password', 'remember_token', 'otp_code'];

    protected function casts(): array
    {
        return [
            'role'              => UserRole::class,
            'email_verified_at' => 'datetime',
            'otp_expires_at'    => 'datetime',
            'last_login_at'     => 'datetime',
            'mfa_enabled'       => 'boolean',
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

    // Role helpers
    public function isClient(): bool { return $this->role === UserRole::Client; }
    public function isNotary(): bool { return $this->role === UserRole::Notary; }
    public function isAdmin(): bool  { return $this->role === UserRole::Admin; }

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

    // Filament admin panel access — only active admins (Phase 8).
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin() && $this->status === 'active';
    }
}
