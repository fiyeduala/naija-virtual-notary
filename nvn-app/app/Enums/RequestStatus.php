<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Draft           = 'draft';
    case Submitted       = 'submitted';
    case Paid            = 'paid';
    case Accepted        = 'accepted';
    case Scheduled       = 'scheduled';
    case InVerification  = 'in_verification';
    case Notarizing      = 'notarizing';
    case Completed       = 'completed';
    case Cancelled       = 'cancelled';
    case Refunded        = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Draft          => 'Draft',
            self::Submitted      => 'Awaiting payment',
            self::Paid           => 'Awaiting notary',
            self::Accepted       => 'Accepted',
            self::Scheduled      => 'Scheduled',
            self::InVerification => 'In verification',
            self::Notarizing     => 'Being notarized',
            self::Completed      => 'Completed',
            self::Cancelled      => 'Cancelled',
            self::Refunded       => 'Refunded',
        };
    }

    /** Statuses where the request is active and visible as "in progress". */
    public static function active(): array
    {
        return [
            self::Paid, self::Accepted, self::Scheduled,
            self::InVerification, self::Notarizing,
        ];
    }
}
