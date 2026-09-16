{{-- Start/end month inputs and the "current" flag shared by every entry form. Ticking "current" clears and disables the end date. --}}
@php
    $isCurrent = (bool) $fieldValue('is_current', $entry->is_current);
@endphp

<div class="grid grid-cols-3 items-end gap-4">
    <div>
        <label for="{{ $formKey }}-start_date" class="block text-sm font-medium">Start{{ $startDateRequired ? '' : ' (optional)' }}</label>
        <input id="{{ $formKey }}-start_date" name="start_date" type="month" @required($startDateRequired) value="{{ $fieldValue('start_date', $entry->start_date?->format('Y-m')) }}" class="{{ $input }}">
    </div>

    <div>
        <label for="{{ $formKey }}-end_date" class="block text-sm font-medium">End</label>
        <input id="{{ $formKey }}-end_date" name="end_date" type="month" value="{{ $isCurrent ? '' : $fieldValue('end_date', $entry->end_date?->format('Y-m')) }}" class="{{ $input }}">
    </div>

    <label class="flex items-center gap-2 pb-2 text-sm">
        <input type="hidden" name="is_current" value="0">
        <input type="checkbox" name="is_current" value="1" data-current-toggle @checked($isCurrent)>
        Current
    </label>
</div>
@foreach (['start_date', 'end_date'] as $date)
    @if ($bag->has($date)) <p class="text-sm text-red-700">{{ $bag->first($date) }}</p> @endif
@endforeach
