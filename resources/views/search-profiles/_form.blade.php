@php
    use App\Enums\JobTypeFilter;
    use App\Enums\PostDateRange;
    use App\Enums\WorkArrangementFilter;

    $keywordText = old('keyword', $searchProfile->keyword ?? []);
    $keywordText = is_array($keywordText) ? implode("\n", $keywordText) : $keywordText;

    $choices = [
        'post_date_range' => ['Posted within', PostDateRange::cases(), $searchProfile->post_date_range ?? PostDateRange::PastWeek],
        'work_arrangement' => ['Work arrangement', WorkArrangementFilter::cases(), $searchProfile->work_arrangement ?? WorkArrangementFilter::Any],
        'job_type' => ['Job type', JobTypeFilter::cases(), $searchProfile->job_type ?? JobTypeFilter::Any],
    ];
@endphp

<div class="max-w-xl space-y-5">
    <div>
        <label for="name" class="block text-sm font-medium">Name</label>
        <input id="name" name="name" type="text" required value="{{ old('name', $searchProfile->name) }}" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2">
        @error('name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="keyword" class="block text-sm font-medium">Keywords <span class="font-normal text-gray-500">(one per line; any may match)</span></label>
        <textarea id="keyword" name="keyword" rows="4" required class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2">{{ $keywordText }}</textarea>
        @error('keyword') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        @error('keyword.*') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-3 gap-4">
        <div class="col-span-2">
            <label for="location" class="block text-sm font-medium">Location</label>
            <input id="location" name="location" type="text" value="{{ old('location', $searchProfile->location) }}" placeholder="Jakarta Selatan" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2">
            @error('location') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="country_code" class="block text-sm font-medium">Country code</label>
            <input id="country_code" name="country_code" type="text" required value="{{ old('country_code', $searchProfile->country_code) }}" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2">
            @error('country_code') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label for="min_experience_years" class="block text-sm font-medium">Min experience (years)</label>
            <input id="min_experience_years" name="min_experience_years" type="number" min="0" value="{{ old('min_experience_years', $searchProfile->min_experience_years) }}" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2">
            @error('min_experience_years') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="max_experience_years" class="block text-sm font-medium">Max experience (years)</label>
            <input id="max_experience_years" name="max_experience_years" type="number" min="0" value="{{ old('max_experience_years', $searchProfile->max_experience_years) }}" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2">
            @error('max_experience_years') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
    </div>

    @foreach ($choices as $field => [$label, $cases, $current])
        <div>
            <label for="{{ $field }}" class="block text-sm font-medium">{{ $label }}</label>
            <select id="{{ $field }}" name="{{ $field }}" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2">
                @foreach ($cases as $case)
                    <option value="{{ $case->value }}" @selected(old($field, $current->value) === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </select>
            @error($field) <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
    @endforeach

    <div class="flex items-center gap-4">
        <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ $submitLabel }}</button>
        <a href="{{ route('search-profiles.index') }}" class="text-sm text-gray-700 hover:text-gray-900">Cancel</a>
    </div>
</div>
