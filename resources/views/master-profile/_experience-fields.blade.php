@php
    $employmentType = $fieldValue('employment_type', $entry->employment_type?->value);
@endphp

<div class="grid grid-cols-2 gap-4">
    <div>
        <label for="{{ $formKey }}-title" class="block text-sm font-medium">Title</label>
        <input id="{{ $formKey }}-title" name="title" type="text" required value="{{ $fieldValue('title', $entry->title) }}" class="{{ $input }}">
        @if ($bag->has('title')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('title') }}</p> @endif
    </div>

    <div>
        <label for="{{ $formKey }}-company" class="block text-sm font-medium">Company</label>
        <input id="{{ $formKey }}-company" name="company" type="text" required value="{{ $fieldValue('company', $entry->company) }}" class="{{ $input }}">
        @if ($bag->has('company')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('company') }}</p> @endif
    </div>

    <div>
        <label for="{{ $formKey }}-location" class="block text-sm font-medium">Location</label>
        <input id="{{ $formKey }}-location" name="location" type="text" value="{{ $fieldValue('location', $entry->location) }}" class="{{ $input }}">
        @if ($bag->has('location')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('location') }}</p> @endif
    </div>

    <div>
        <label for="{{ $formKey }}-employment_type" class="block text-sm font-medium">Employment type</label>
        <select id="{{ $formKey }}-employment_type" name="employment_type" class="{{ $input }}">
            <option value="">—</option>
            @foreach (\App\Enums\EmploymentType::cases() as $type)
                <option value="{{ $type->value }}" @selected($employmentType === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
        @if ($bag->has('employment_type')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('employment_type') }}</p> @endif
    </div>
</div>

@include('master-profile._entry-dates', ['startDateRequired' => true])

<div>
    <label for="{{ $formKey }}-description" class="block text-sm font-medium">Description</label>
    <textarea id="{{ $formKey }}-description" name="description" rows="3" class="{{ $input }}">{{ $fieldValue('description', $entry->description) }}</textarea>
    @if ($bag->has('description')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('description') }}</p> @endif
</div>

@include('master-profile._entry-list-fields', ['lists' => [
    ['field' => 'achievements', 'label' => 'Achievements (one per line)', 'separator' => "\n"],
    ['field' => 'skills_used', 'label' => 'Skills used (comma-separated)', 'separator' => ', '],
]])
