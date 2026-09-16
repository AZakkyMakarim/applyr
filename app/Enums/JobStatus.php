<?php

namespace App\Enums;

enum JobStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Expired = 'expired';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Expired => 'Expired',
            self::Unknown => 'Unknown',
        };
    }
}
