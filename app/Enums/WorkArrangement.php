<?php

namespace App\Enums;

enum WorkArrangement: string
{
    case Remote = 'remote';
    case Onsite = 'onsite';
    case Hybrid = 'hybrid';
    case Unspecified = 'unspecified';
}
