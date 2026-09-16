<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case PendingTailoring = 'pending_tailoring';
    case TailoringFailed = 'tailoring_failed';
    case NeedsReview = 'needs_review';
    case Applied = 'applied';
    case Rejected = 'rejected';
}
