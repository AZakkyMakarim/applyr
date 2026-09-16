<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case PendingTailoring = 'pending_tailoring';
    case TailoringFailed = 'tailoring_failed';
    case NeedsReview = 'needs_review';
    case Applied = 'applied';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingTailoring => 'Pending tailoring',
            self::TailoringFailed => 'Tailoring failed',
            self::NeedsReview => 'Needs review',
            self::Applied => 'Applied',
            self::Rejected => 'Rejected',
        };
    }
}
