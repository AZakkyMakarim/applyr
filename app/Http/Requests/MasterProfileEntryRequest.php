<?php

namespace App\Http\Requests;

use App\Models\MasterProfileEntry;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared rules for Experience, Education and Project forms. Dates arrive as month inputs
 * (YYYY-MM) and are stored as the 1st of that month. A current entry never keeps an end
 * date; one that is not current needs an end date no earlier than its start.
 *
 * Every entry on the page is its own form, so each gets its own error bag, named by
 * formKey(), and a rejected form sends the user back to its section.
 */
abstract class MasterProfileEntryRequest extends FormRequest
{
    /**
     * The URL segment and page section of this entry type, e.g. "experiences".
     */
    abstract protected function section(): string;

    /**
     * Rules for the fields other than the dates and the current flag.
     *
     * @return array<string, mixed>
     */
    abstract protected function fieldRules(): array;

    /**
     * Whether an entry of this type must have a start date.
     */
    protected function requiresStartDate(): bool
    {
        return true;
    }

    /**
     * List fields normalized from their form text before validation.
     *
     * @return array<string, mixed>
     */
    protected function listFields(): array
    {
        return [];
    }

    /**
     * The error bag and old-input key of the form an entry is edited in; "new" for the add form.
     */
    public static function formKey(string $section, ?MasterProfileEntry $entry): string
    {
        return $section.'-'.($entry?->getKey() ?? 'new');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isDated = $this->requiresStartDate() || $this->filled('start_date');

        return [
            ...$this->fieldRules(),
            'start_date' => $this->requiresStartDate()
                ? ['required', 'date_format:Y-m']
                : ['nullable', 'date_format:Y-m', 'required_with:end_date'],
            'end_date' => [
                'nullable',
                'date_format:Y-m',
                Rule::requiredIf($isDated && ! $this->boolean('is_current')),
                $this->notBeforeStartDate(...),
            ],
            'is_current' => ['required', 'boolean'],
        ];
    }

    /**
     * The validated entry, with each month date turned into the 1st of that month.
     *
     * @return array<string, mixed>
     */
    public function entry(): array
    {
        $entry = $this->validated();

        foreach (['start_date', 'end_date'] as $date) {
            $entry[$date] = $entry[$date] === null ? null : $entry[$date].'-01';
        }

        return $entry;
    }

    protected function prepareForValidation(): void
    {
        $entry = collect($this->route()?->parameters() ?? [])->first();
        $this->errorBag = self::formKey($this->section(), $entry);

        $isCurrent = $this->boolean('is_current');

        $this->merge([
            'is_current' => $isCurrent,
            ...($isCurrent ? ['end_date' => null] : []),
            ...$this->listFields(),
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return route('master-profile.edit').'#'.$this->section();
    }

    /**
     * Zero-padded YYYY-MM strings sort chronologically, so valid months compare as strings.
     */
    private function notBeforeStartDate(string $attribute, mixed $value, Closure $fail): void
    {
        $startDate = $this->input('start_date');

        if ($this->isMonth($value) && $this->isMonth($startDate) && $value < $startDate) {
            $fail('validation.after_or_equal')->translate(['date' => 'start date']);
        }
    }

    private function isMonth(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    /**
     * One item per non-blank line of a textarea; an already-split list works too.
     */
    protected function lines(string $key): mixed
    {
        return $this->items($key, fn (string $text) => preg_split('/\R/', $text));
    }

    /**
     * Comma-separated text split into items; an already-split list works too.
     */
    protected function commaSeparated(string $key): mixed
    {
        return $this->items($key, fn (string $text) => explode(',', $text));
    }

    /**
     * Trims each item and drops blank ones. A missing field is an empty list.
     *
     * @param  callable(string): array<int, string>  $split
     */
    private function items(string $key, callable $split): mixed
    {
        $items = $this->input($key) ?? [];

        if (is_string($items)) {
            $items = $split($items);
        }

        if (! is_array($items)) {
            return $items;
        }

        return array_values(array_filter(
            array_map(fn ($item) => is_string($item) ? trim($item) : $item, $items),
            fn ($item) => $item !== '' && $item !== null,
        ));
    }
}
