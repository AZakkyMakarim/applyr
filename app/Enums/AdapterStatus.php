<?php

namespace App\Enums;

enum AdapterStatus: string
{
    case Healthy = 'healthy';
    case Failing = 'failing';
    case Paused = 'paused';
}
