{{--
    One entry's own form: an edit form for a saved entry, or the add form when $entry is new.
    Each form has its own error bag; only the form that was rejected (the one whose bag has errors) takes old input back.
    $fields names the partial with the type's fields; it receives $entry, $formKey, $fieldValue, $bag and $input.
--}}
@php
    $formKey = \App\Http\Requests\MasterProfileEntryRequest::formKey($section, $entry->exists ? $entry : null);
    $bag = $errors->getBag($formKey);
    $fieldValue = fn (string $field, mixed $value) => $errors->hasBag($formKey) ? old($field) : $value;
@endphp

<div class="rounded-md border border-gray-200 bg-white p-4" data-entry>
    @unless ($entry->exists)
        <h3 class="mb-3 font-medium">Add {{ $noun }}</h3>
    @endunless

    <form method="POST" action="{{ $entry->exists ? route("master-profile.{$section}.update", $entry) : route("master-profile.{$section}.store") }}" class="space-y-4">
        @csrf
        @if ($entry->exists)
            @method('PUT')
        @endif

        @include($fields)

        <div class="flex items-center gap-4">
            <button type="submit" class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-700">{{ $entry->exists ? 'Save' : "Add {$noun}" }}</button>
            @if ($entry->exists)
                <button type="submit" form="{{ $formKey }}-delete" class="text-sm text-red-700 hover:text-red-900">Delete</button>
            @endif
        </div>
    </form>

    @if ($entry->exists)
        <form id="{{ $formKey }}-delete" method="POST" action="{{ route("master-profile.{$section}.destroy", $entry) }}" onsubmit="return confirm('Delete this {{ $noun }}?')">
            @csrf
            @method('DELETE')
        </form>
    @endif
</div>
