<?php

namespace App\Enums;

enum UserRole: string
{
    case Client = 'client';
    case Notary = 'notary';
    case Admin  = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Client',
            self::Notary => 'Notary Public',
            self::Admin  => 'Administrator',
        };
    }
}
