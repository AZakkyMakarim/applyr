<?php

namespace App\Enums;

use App\Models\Application;

/**
 * A named action the user takes on an Application from the dashboard. Each action names the status
 * it moves to; whether it may is up to the Application's transition table.
 */
enum ApplicationAction: string
{
    case MarkApplied = 'mark-applied';
    case Retry = 'retry';
    case Regenerate = 'regenerate';
    case Reject = 'reject';
    case Undo = 'undo';

    public function label(): string
    {
        return match ($this) {
            self::MarkApplied => 'Mark Applied',
            self::Retry => 'Retry',
            self::Regenerate => 'Regenerate',
            self::Reject => 'Reject',
            self::Undo => 'Undo',
        };
    }

    /**
     * How the action reads once done, as in "Application marked applied."
     */
    public function pastTense(): string
    {
        return match ($this) {
            self::MarkApplied => 'marked applied',
            self::Retry => 'retried',
            self::Regenerate => 'regenerated',
            self::Reject => 'rejected',
            self::Undo => 'undone',
        };
    }

    /**
     * The status this action would move the Application to, or null when it has none from here.
     * Retry and Regenerate both land in pending_tailoring and are told apart by where they start.
     */
    public function targetFor(Application $application): ?ApplicationStatus
    {
        return match ($this) {
            self::MarkApplied => ApplicationStatus::Applied,
            self::Retry => $application->status === ApplicationStatus::TailoringFailed ? ApplicationStatus::PendingTailoring : null,
            self::Regenerate => $application->status === ApplicationStatus::NeedsReview ? ApplicationStatus::PendingTailoring : null,
            self::Reject => ApplicationStatus::Rejected,
            self::Undo => match ($application->status) {
                ApplicationStatus::Applied => ApplicationStatus::NeedsReview,
                ApplicationStatus::Rejected => $application->previous_status,
                default => null,
            },
        };
    }

    public function isAvailableFor(Application $application): bool
    {
        $target = $this->targetFor($application);

        return $target !== null && $application->canTransitionTo($target);
    }

    /**
     * @return list<self>
     */
    public static function availableFor(Application $application): array
    {
        return array_values(array_filter(self::cases(), fn (self $action) => $action->isAvailableFor($application)));
    }
}
