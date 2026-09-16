<?php

namespace App\Enums;

enum Platform: string
{
    case Glints = 'glints';
    case JobStreet = 'jobstreet';

    public function label(): string
    {
        return match ($this) {
            self::Glints => 'Glints',
            self::JobStreet => 'JobStreet',
        };
    }
}
