<?php

namespace App\Enums;

enum JobStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Expired = 'expired';
    case Unknown = 'unknown';
}
