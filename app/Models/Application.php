<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
