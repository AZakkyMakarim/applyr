{{-- Edits only the reframeable text of the current TailoredApplication's snapshot; facts are shown as headings, never as inputs. --}}
@php
    $inputClass = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
    $cv = $tailoredApplication->cv_data;
    $coverLetter = $tailoredApplication->cover_letter_data;
    $joined = fn (mixed $items, string $separator) => is_array($items) ? implode($separator, $items) : $items;
    $sections = [
        'Experience' => collect($cv['experiences'])->map(fn (array $entry) => ["{$entry['title']} · {$entry['company']}", $entry]),
        'Education' => collect($cv['educations'])->map(fn (array $entry) => [$entry['institution'], $entry]),
        'Projects' => collect($cv['projects'])->map(fn (array $entry) => [$entry['name'], $entry]),
    ];
@endphp

<details id="editor" class="mt-6 rounded-md border border-gray-200 bg-white p-4" @if ($errors->any()) open @endif>
    <summary class="cursor-pointer text-lg font-semibold">Edit documents</summary>
    <p class="mt-2 text-sm text-gray-600">Only the summary, descriptions, achievements and cover-letter paragraphs can be edited. Saving renders both PDFs again.</p>

    <form method="POST" action="{{ route('applications.tailored-content.update', $application) }}" class="mt-4 space-y-6">
        @csrf
        @method('PUT')

        <div>
            <label for="professional_summary" class="block text-sm font-medium">Professional summary</label>
            <textarea id="professional_summary" name="professional_summary" rows="4" class="{{ $inputClass }}">{{ old('professional_summary', $cv['personal_info']['professional_summary']) }}</textarea>
            @error('professional_summary') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        @foreach ($sections as $sectionLabel => $entries)
            @if ($entries->isNotEmpty())
                <fieldset class="space-y-4">
                    <legend class="text-base font-semibold">{{ $sectionLabel }}</legend>

                    @foreach ($entries as $entryId => [$heading, $entry])
                        <div class="space-y-3 rounded-md border border-gray-100 p-3">
                            <p class="text-sm font-medium text-gray-700">{{ $heading }}</p>

                            <div>
                                <label for="{{ $entryId }}-description" class="block text-sm font-medium">Description</label>
                                <textarea id="{{ $entryId }}-description" name="entries[{{ $entryId }}][description]" rows="3" class="{{ $inputClass }}">{{ old("entries.{$entryId}.description", $entry['description']) }}</textarea>
                                @error("entries.{$entryId}.description") <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>

                            @if (array_key_exists('achievements', $entry))
                                <div>
                                    <label for="{{ $entryId }}-achievements" class="block text-sm font-medium">Achievements (one per line)</label>
                                    <textarea id="{{ $entryId }}-achievements" name="entries[{{ $entryId }}][achievements]" rows="4" class="{{ $inputClass }}">{{ $joined(old("entries.{$entryId}.achievements", $entry['achievements']), "\n") }}</textarea>
                                    @error("entries.{$entryId}.achievements") <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                    @error("entries.{$entryId}.achievements.*") <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                            @endif
                        </div>
                    @endforeach
                </fieldset>
            @endif
        @endforeach

        <fieldset class="space-y-4">
            <legend class="text-base font-semibold">Cover letter</legend>

            <div>
                <label for="opening_paragraph" class="block text-sm font-medium">Opening paragraph</label>
                <textarea id="opening_paragraph" name="opening_paragraph" rows="3" required class="{{ $inputClass }}">{{ old('opening_paragraph', $coverLetter['opening_paragraph']) }}</textarea>
                @error('opening_paragraph') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="body_paragraphs" class="block text-sm font-medium">Body paragraphs (separated by a blank line)</label>
                <textarea id="body_paragraphs" name="body_paragraphs" rows="8" class="{{ $inputClass }}">{{ $joined(old('body_paragraphs', $coverLetter['body_paragraphs']), "\n\n") }}</textarea>
                @error('body_paragraphs') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                @error('body_paragraphs.*') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="closing_paragraph" class="block text-sm font-medium">Closing paragraph</label>
                <textarea id="closing_paragraph" name="closing_paragraph" rows="3" required class="{{ $inputClass }}">{{ old('closing_paragraph', $coverLetter['closing_paragraph']) }}</textarea>
                @error('closing_paragraph') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </fieldset>

        <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Save and render PDFs</button>
    </form>
</details>
