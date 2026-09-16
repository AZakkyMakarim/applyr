<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Jobs\TailorApplication;
use App\Pdf\DocumentRenderer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * The user's tracked intent to apply to a Job. Exactly one per Job.
 */
#[Fillable([
    'status',
    'current_tailored_application_id',
    'applied_at',
    'rejected_at',
    'previous_status',
    'edited_by_user',
])]
class Application extends Model
{
    /**
     * @return BelongsTo<Job, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * The TailoredApplication shown for this Application; older ones may still exist.
     *
     * @return BelongsTo<TailoredApplication, $this>
     */
    public function currentTailoredApplication(): BelongsTo
    {
        return $this->belongsTo(TailoredApplication::class, 'current_tailored_application_id');
    }

    /**
     * @return HasMany<TailoredApplication, $this>
     */
    public function tailoredApplications(): HasMany
    {
        return $this->hasMany(TailoredApplication::class);
    }

    /**
     * The transition table. A rejected Application may only return to the status it was rejected from.
     */
    public function canTransitionTo(ApplicationStatus $to): bool
    {
        return match ($this->status) {
            ApplicationStatus::PendingTailoring => in_array($to, [ApplicationStatus::NeedsReview, ApplicationStatus::TailoringFailed, ApplicationStatus::Rejected], true),
            ApplicationStatus::TailoringFailed => in_array($to, [ApplicationStatus::PendingTailoring, ApplicationStatus::Rejected], true),
            ApplicationStatus::NeedsReview => in_array($to, [ApplicationStatus::PendingTailoring, ApplicationStatus::Applied, ApplicationStatus::Rejected], true),
            ApplicationStatus::Applied => $to === ApplicationStatus::NeedsReview,
            ApplicationStatus::Rejected => $to === $this->previous_status,
        };
    }

    /**
     * Moves the Application to a status the transition table allows, keeping applied_at, rejected_at
     * and previous_status in step. Entering pending_tailoring queues tailoring; the current
     * TailoredApplication stays until tailoring replaces it.
     *
     * The move only lands if the stored status is still the one this model was loaded with, so a
     * status saved meanwhile (a reject while tailoring runs) is never overwritten. Either way the
     * model is refreshed from the database.
     *
     * @param  array<string, mixed>  $attributes  saved along with the new status
     * @return bool false when the table doesn't allow the move or the status changed meanwhile
     */
    public function transitionTo(ApplicationStatus $to, array $attributes = []): bool
    {
        if (! $this->canTransitionTo($to)) {
            return false;
        }

        $bookkeeping = match ($to) {
            ApplicationStatus::Applied => ['applied_at' => now(), 'previous_status' => $this->status],
            ApplicationStatus::Rejected => ['rejected_at' => now(), 'previous_status' => $this->status],
            default => match ($this->status) {
                ApplicationStatus::Applied => ['applied_at' => null, 'previous_status' => null],
                ApplicationStatus::Rejected => ['rejected_at' => null, 'previous_status' => null],
                default => [],
            },
        };

        $moved = static::query()
            ->whereKey($this->getKey())
            ->where('status', $this->status)
            ->update(['status' => $to, ...$bookkeeping, ...$attributes]) === 1;

        $this->refresh();

        if ($moved && $to === ApplicationStatus::PendingTailoring) {
            TailorApplication::dispatch($this)->afterCommit();
        }

        return $moved;
    }

    /**
     * Whether the user may edit the current TailoredApplication's reframeable text: only in needs_review.
     */
    public function canEditTailoredContent(): bool
    {
        return $this->status === ApplicationStatus::NeedsReview && $this->currentTailoredApplication !== null;
    }

    /**
     * Saves the user's edits to the reframeable text of the current TailoredApplication, renders both
     * PDFs again from the edited snapshot, and flags the Application as edited by the user. The status
     * stays as it is.
     *
     * Only allowed in needs_review. The edits only land if the Application is still in needs_review
     * with the same current TailoredApplication once the PDFs are rendered. Either way the model is
     * refreshed from the database.
     *
     * @param  array<string, mixed>  $edits  shaped as TailoredApplication::reviseText() expects
     * @return bool false when the Application isn't (or is no longer) in needs_review with documents
     */
    public function saveTailoredContent(array $edits, DocumentRenderer $documentRenderer): bool
    {
        if (! $this->canEditTailoredContent()) {
            return false;
        }

        $tailoredApplication = $this->currentTailoredApplication;

        $tailoredApplication->reviseText($edits);
        $tailoredApplication->fill($documentRenderer->render($this, $tailoredApplication->cv_data, $tailoredApplication->cover_letter_data));

        $saved = DB::transaction(function () use ($tailoredApplication) {
            $flagged = static::query()
                ->whereKey($this->getKey())
                ->where('status', ApplicationStatus::NeedsReview)
                ->where('current_tailored_application_id', $tailoredApplication->id)
                ->update(['edited_by_user' => true]) === 1;

            if ($flagged) {
                $tailoredApplication->save();
            }

            return $flagged;
        });

        $this->refresh();

        return $saved;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'previous_status' => ApplicationStatus::class,
            'applied_at' => 'datetime',
            'rejected_at' => 'datetime',
            'edited_by_user' => 'boolean',
        ];
    }
}
