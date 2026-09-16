<div class="grid grid-cols-2 gap-4">
    <div class="col-span-2">
        <label for="{{ $formKey }}-institution" class="block text-sm font-medium">Institution</label>
        <input id="{{ $formKey }}-institution" name="institution" type="text" required value="{{ $fieldValue('institution', $entry->institution) }}" class="{{ $input }}">
        @if ($bag->has('institution')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('institution') }}</p> @endif
    </div>

    <div>
        <label for="{{ $formKey }}-degree" class="block text-sm font-medium">Degree</label>
        <input id="{{ $formKey }}-degree" name="degree" type="text" value="{{ $fieldValue('degree', $entry->degree) }}" class="{{ $input }}">
        @if ($bag->has('degree')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('degree') }}</p> @endif
    </div>

    <div>
        <label for="{{ $formKey }}-field_of_study" class="block text-sm font-medium">Field of study</label>
        <input id="{{ $formKey }}-field_of_study" name="field_of_study" type="text" value="{{ $fieldValue('field_of_study', $entry->field_of_study) }}" class="{{ $input }}">
        @if ($bag->has('field_of_study')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('field_of_study') }}</p> @endif
    </div>
</div>

@include('master-profile._entry-dates', ['startDateRequired' => true])

<div>
    <label for="{{ $formKey }}-description" class="block text-sm font-medium">Description</label>
    <textarea id="{{ $formKey }}-description" name="description" rows="3" class="{{ $input }}">{{ $fieldValue('description', $entry->description) }}</textarea>
    @if ($bag->has('description')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('description') }}</p> @endif
</div>
