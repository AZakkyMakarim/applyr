<div class="flex items-start gap-3" data-row>
    <input name="links[{{ $index }}][label]" type="text" value="{{ $link['label'] ?? '' }}" placeholder="LinkedIn" aria-label="Link label" class="mt-1 w-1/3 rounded-md border border-gray-300 px-3 py-2">
    <input name="links[{{ $index }}][url]" type="url" value="{{ $link['url'] ?? '' }}" placeholder="https://linkedin.com/in/…" aria-label="Link URL" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2">
    <button type="button" data-remove-row class="mt-3 text-sm text-red-700 hover:text-red-900">Remove</button>
</div>
