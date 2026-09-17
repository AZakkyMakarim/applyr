<?php

namespace App\Enums;

enum SalaryPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Weekly = 'weekly';
    case Daily = 'daily';
    case Hourly = 'hourly';
    case PerProject = 'per_project';
    case Unspecified = 'unspecified';
}
