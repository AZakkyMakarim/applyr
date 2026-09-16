@php
    $skillList = $group['skills'] ?? '';
@endphp

<div class="flex items-start gap-3" data-row>
    <input name="skills[{{ $index }}][category]" type="text" value="{{ $group['category'] ?? '' }}" placeholder="Backend" aria-label="Skill category" class="mt-1 w-1/3 rounded-md border border-gray-300 px-3 py-2">
    <input name="skills[{{ $index }}][skills]" type="text" value="{{ is_array($skillList) ? implode(', ', $skillList) : $skillList }}" placeholder="PHP, Laravel, MySQL" aria-label="Skills (comma-separated)" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2">
    <button type="button" data-remove-row class="mt-3 text-sm text-red-700 hover:text-red-900">Remove</button>
</div>
