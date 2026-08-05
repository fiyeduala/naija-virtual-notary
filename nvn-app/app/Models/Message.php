<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sender_role' => UserRole::class, 'read_at' => 'datetime'];
    }

    public function request(): BelongsTo { return $this->belongsTo(NotarizationRequest::class, 'request_id'); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_user_id'); }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_user_id'); }

    /** Display name — admin messages are attributed to the platform, not a person. */
    public function senderDisplayName(): string
    {
        return $this->sender_role === UserRole::Admin
            ? 'Naija Virtual Notary (Support)'
            : ($this->sender?->full_name ?? 'Unknown');
    }
}
