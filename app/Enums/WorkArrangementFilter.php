<?php

namespace App\Enums;

enum WorkArrangementFilter: string
{
    case Remote = 'remote';
    case Onsite = 'onsite';
    case Hybrid = 'hybrid';
    case Any = 'any';

    public function label(): string
    {
        return match ($this) {
            self::Remote => 'Remote',
            self::Onsite => 'Onsite',
            self::Hybrid => 'Hybrid',
            self::Any => 'Any arrangement',
        };
    }
}
