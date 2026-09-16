<?php

namespace App\Enums;

enum WorkArrangement: string
{
    case Remote = 'remote';
    case Onsite = 'onsite';
    case Hybrid = 'hybrid';
    case Unspecified = 'unspecified';

    public function label(): string
    {
        return match ($this) {
            self::Remote => 'Remote',
            self::Onsite => 'Onsite',
            self::Hybrid => 'Hybrid',
            self::Unspecified => 'Unspecified',
        };
    }
}
