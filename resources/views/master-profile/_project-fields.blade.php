<div class="grid grid-cols-2 gap-4">
    <div>
        <label for="{{ $formKey }}-name" class="block text-sm font-medium">Name</label>
        <input id="{{ $formKey }}-name" name="name" type="text" required value="{{ $fieldValue('name', $entry->name) }}" class="{{ $input }}">
        @if ($bag->has('name')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('name') }}</p> @endif
    </div>

    <div>
        <label for="{{ $formKey }}-link" class="block text-sm font-medium">Link</label>
        <input id="{{ $formKey }}-link" name="link" type="url" value="{{ $fieldValue('link', $entry->link) }}" placeholder="https://…" class="{{ $input }}">
        @if ($bag->has('link')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('link') }}</p> @endif
    </div>
</div>

@include('master-profile._entry-dates', ['startDateRequired' => false])

@include('master-profile._entry-list-fields', ['lists' => [
    ['field' => 'tech_stack', 'label' => 'Tech stack (comma-separated)', 'separator' => ', '],
]])

<div>
    <label for="{{ $formKey }}-description" class="block text-sm font-medium">Description</label>
    <textarea id="{{ $formKey }}-description" name="description" rows="3" class="{{ $input }}">{{ $fieldValue('description', $entry->description) }}</textarea>
    @if ($bag->has('description')) <p class="mt-1 text-sm text-red-700">{{ $bag->first('description') }}</p> @endif
</div>

@include('master-profile._entry-list-fields', ['lists' => [
    ['field' => 'achievements', 'label' => 'Achievements (one per line)', 'separator' => "\n"],
]])
