<?php

namespace App\Models;

use App\Casts\MonthDate;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Experience, Education or Project belonging to the MasterProfile. Its id is the
 * entry_id tailoring refers to. Dates are month and year only, stored as the 1st;
 * a current entry has no end date.
 */
abstract class MasterProfileEntry extends Model
{
    /**
     * The entry_id tailoring uses, in the prompt and in the snapshot alike. Row ids repeat across
     * the three entry tables, so it is prefixed with the entry's kind, e.g. "experience:12".
     */
    public function tailoringEntryId(): string
    {
        return $this->tailoringKind().":{$this->getKey()}";
    }

    /**
     * The entry_id prefix naming this kind of entry, e.g. "experience".
     */
    abstract protected function tailoringKind(): string;

    /**
     * The fields tailoring copies as-is and the AI may only read.
     *
     * @return array<string, mixed>
     */
    abstract public function tailoringFacts(): array;

    /**
     * The free-text fields the AI may reframe, as stored in the MasterProfile.
     *
     * @return array{description: ?string, achievements?: list<string>}
     */
    abstract public function reframeableText(): array;

    /**
     * @return BelongsTo<MasterProfile, $this>
     */
    public function masterProfile(): BelongsTo
    {
        return $this->belongsTo(MasterProfile::class);
    }

    /**
     * Newest start date first; undated entries (only Projects may be undated) go last.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByRaw('start_date is null')->orderByDesc('start_date')->orderByDesc('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => MonthDate::class,
            'end_date' => MonthDate::class,
            'is_current' => 'boolean',
        ];
    }
}
