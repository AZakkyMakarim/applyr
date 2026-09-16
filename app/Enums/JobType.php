<?php

namespace App\Enums;

enum JobType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case Internship = 'internship';
    case Unspecified = 'unspecified';
}
