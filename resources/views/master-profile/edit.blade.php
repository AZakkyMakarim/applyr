@extends('layouts.dashboard')

@section('title', 'MasterProfile')

@php
    $links = old('links', $masterProfile->links);
    $skills = old('skills', $masterProfile->skills);
    $input = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2';
@endphp

@section('content')
    <h1 class="text-2xl font-semibold">MasterProfile</h1>

    @if (session('status'))
        <p class="mt-4 rounded-md bg-green-50 px-4 py-2 text-sm text-green-800">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('master-profile.update') }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-10">
        @csrf
        @method('PUT')

        <section class="space-y-5">
            <h2 class="text-lg font-semibold">Personal info</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="full_name" class="block text-sm font-medium">Full name</label>
                    <input id="full_name" name="full_name" type="text" required value="{{ old('full_name', $masterProfile->full_name) }}" class="{{ $input }}">
                    @error('full_name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium">Email</label>
                    <input id="email" name="email" type="email" required value="{{ old('email', $masterProfile->email) }}" class="{{ $input }}">
                    @error('email') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium">Phone</label>
                    <input id="phone" name="phone" type="text" value="{{ old('phone', $masterProfile->phone) }}" class="{{ $input }}">
                    @error('phone') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="location" class="block text-sm font-medium">Location</label>
                    <input id="location" name="location" type="text" value="{{ old('location', $masterProfile->location) }}" placeholder="Jakarta, Indonesia" class="{{ $input }}">
                    @error('location') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="professional_summary" class="block text-sm font-medium">Professional summary</label>
                <textarea id="professional_summary" name="professional_summary" rows="5" class="{{ $input }}">{{ old('professional_summary', $masterProfile->professional_summary) }}</textarea>
                @error('professional_summary') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Links</h2>

            <div id="links" class="space-y-3">
                @foreach ($links as $index => $link)
                    @include('master-profile._link-row')
                @endforeach
            </div>
            @error('links') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            @error('links.*') <p class="text-sm text-red-700">{{ $message }}</p> @enderror

            <template id="link-row">
                @include('master-profile._link-row', ['index' => '__INDEX__', 'link' => []])
            </template>

            <button type="button" data-add-row="link-row" data-target="links" class="text-sm font-medium text-gray-700 hover:text-gray-900">+ Add link</button>
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Skills</h2>

            <div id="skills" class="space-y-3">
                @foreach ($skills as $index => $group)
                    @include('master-profile._skill-row')
                @endforeach
            </div>
            @error('skills') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            @error('skills.*') <p class="text-sm text-red-700">{{ $message }}</p> @enderror

            <template id="skill-row">
                @include('master-profile._skill-row', ['index' => '__INDEX__', 'group' => []])
            </template>

            <button type="button" data-add-row="skill-row" data-target="skills" class="text-sm font-medium text-gray-700 hover:text-gray-900">+ Add skill category</button>
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Photo</h2>

            @if ($masterProfile->photo_path)
                <img src="{{ route('master-profile.photo', ['v' => $masterProfile->updated_at?->timestamp]) }}" alt="Profile photo" class="h-24 w-24 rounded-lg object-cover">
            @endif

            <div>
                <label for="photo" class="block text-sm font-medium">{{ $masterProfile->photo_path ? 'Replace photo' : 'Upload photo' }}</label>
                <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block text-sm">
                @error('photo') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="show_photo" value="0">
                <input type="checkbox" name="show_photo" value="1" @checked(old('show_photo', $masterProfile->show_photo))>
                Show photo on CV
            </label>
        </section>

        <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Save MasterProfile</button>
    </form>

    @if ($masterProfile->exists)
        @foreach ([
            ['section' => 'experiences', 'heading' => 'Experience', 'noun' => 'experience', 'model' => \App\Models\Experience::class],
            ['section' => 'educations', 'heading' => 'Education', 'noun' => 'education', 'model' => \App\Models\Education::class],
            ['section' => 'projects', 'heading' => 'Projects', 'noun' => 'project', 'model' => \App\Models\Project::class],
        ] as ['section' => $section, 'heading' => $heading, 'noun' => $noun, 'model' => $model])
            <section id="{{ $section }}" class="mt-10 max-w-2xl space-y-4">
                <h2 class="text-lg font-semibold">{{ $heading }}</h2>

                @foreach ($masterProfile->{$section} as $entry)
                    @include('master-profile._entry-form', ['fields' => "master-profile._{$noun}-fields"])
                @endforeach

                @include('master-profile._entry-form', ['entry' => new $model, 'fields' => "master-profile._{$noun}-fields"])
            </section>
        @endforeach
    @else
        <p class="mt-10 max-w-2xl text-sm text-gray-600">Save your personal info first to add experience, education and projects.</p>
    @endif

    <script>
        // Rendered rows are keyed 0..n-1; new rows continue after them so names never collide.
        let nextRowIndex = {{ max(count($links), count($skills)) }};

        document.addEventListener('click', (event) => {
            const add = event.target.closest('[data-add-row]');
            if (add) {
                const html = document.getElementById(add.dataset.addRow).innerHTML.replaceAll('__INDEX__', nextRowIndex++);
                document.getElementById(add.dataset.target).insertAdjacentHTML('beforeend', html);
            }

            const remove = event.target.closest('[data-remove-row]');
            if (remove) {
                remove.closest('[data-row]').remove();
            }
        });

        // A current entry has no end date, so ticking "current" clears and disables it.
        const syncEndDate = (toggle) => {
            const endDate = toggle.form.elements.end_date;
            endDate.disabled = toggle.checked;
            if (toggle.checked) {
                endDate.value = '';
            }
        };

        document.querySelectorAll('[data-current-toggle]').forEach(syncEndDate);
        document.addEventListener('change', (event) => {
            if (event.target.matches('[data-current-toggle]')) {
                syncEndDate(event.target);
            }
        });
    </script>
@endsection
