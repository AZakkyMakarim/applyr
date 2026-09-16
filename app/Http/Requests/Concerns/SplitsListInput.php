<?php

namespace App\Http\Requests\Concerns;

/**
 * Turns list fields typed as text into lists of trimmed, non-blank items before validation.
 */
trait SplitsListInput
{
    /**
     * Text split into trimmed, non-blank items; an already-split list works too, and missing text is
     * an empty list. Anything else is left for validation to reject.
     *
     * @param  callable(string): array<int, string>  $split
     */
    protected function listItems(mixed $items, callable $split): mixed
    {
        $items ??= [];

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
