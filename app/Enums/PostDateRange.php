<?php

namespace App\Enums;

enum PostDateRange: string
{
    case Past24Hours = 'PAST_24_HOURS';
    case PastWeek = 'PAST_WEEK';
    case PastMonth = 'PAST_MONTH';
    case AnyTime = 'ANY_TIME';

    public function label(): string
    {
        return match ($this) {
            self::Past24Hours => 'Past 24 hours',
            self::PastWeek => 'Past week',
            self::PastMonth => 'Past month',
            self::AnyTime => 'Any time',
        };
    }
}
