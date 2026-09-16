{{-- String-list fields: typed one per line in a textarea, or comma-separated in a text input. --}}
@foreach ($lists as ['field' => $field, 'label' => $label, 'separator' => $separator])
    @php
        $items = $fieldValue($field, $entry->{$field} ?? []);
        $text = is_array($items) ? implode($separator, $items) : $items;
    @endphp

    <div>
        <label for="{{ $formKey }}-{{ $field }}" class="block text-sm font-medium">{{ $label }}</label>
        @if ($separator === "\n")
            <textarea id="{{ $formKey }}-{{ $field }}" name="{{ $field }}" rows="3" class="{{ $input }}">{{ $text }}</textarea>
        @else
            <input id="{{ $formKey }}-{{ $field }}" name="{{ $field }}" type="text" value="{{ $text }}" class="{{ $input }}">
        @endif
        @if ($bag->has($field)) <p class="mt-1 text-sm text-red-700">{{ $bag->first($field) }}</p> @endif
        @if ($bag->has("{$field}.*")) <p class="mt-1 text-sm text-red-700">{{ $bag->first("{$field}.*") }}</p> @endif
    </div>
@endforeach
