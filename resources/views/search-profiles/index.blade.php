@extends('layouts.dashboard')

@section('title', 'SearchProfiles')

@section('content')
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">SearchProfiles</h1>
        <a href="{{ route('search-profiles.create') }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">New SearchProfile</a>
    </div>

    @if (session('status'))
        <p class="mt-4 rounded-md bg-green-50 px-4 py-2 text-sm text-green-800">{{ session('status') }}</p>
    @endif

    @if ($searchProfiles->isEmpty())
        <p class="mt-8 text-gray-600">No SearchProfiles yet. Create one to start finding Jobs.</p>
    @else
        <ul class="mt-6 divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
            @foreach ($searchProfiles as $searchProfile)
                <li class="flex flex-wrap items-start justify-between gap-4 px-4 py-4">
                    <div class="min-w-0">
                        <p class="font-medium">{{ $searchProfile->name }}</p>
                        <p class="mt-1 text-sm text-gray-700">{{ implode(', ', $searchProfile->keyword) }}</p>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ $searchProfile->location ? $searchProfile->location.', ' : '' }}{{ $searchProfile->country_code }}
                            · {{ $searchProfile->post_date_range->label() }}
                            · {{ $searchProfile->work_arrangement->label() }}
                            · {{ $searchProfile->job_type->label() }}
                            @if ($searchProfile->min_experience_years !== null || $searchProfile->max_experience_years !== null)
                                · {{ $searchProfile->min_experience_years ?? 0 }}–{{ $searchProfile->max_experience_years ?? 'any' }} yrs experience
                            @endif
                        </p>
                    </div>

                    <div class="flex items-center gap-3 text-sm">
                        @if ($searchProfile->is_active)
                            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Active</span>
                        @else
                            <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-700">Paused</span>
                        @endif

                        <form method="POST" action="{{ route($searchProfile->is_active ? 'search-profiles.pause' : 'search-profiles.resume', $searchProfile) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="text-gray-700 hover:text-gray-900">{{ $searchProfile->is_active ? 'Pause' : 'Resume' }}</button>
                        </form>

                        <a href="{{ route('search-profiles.edit', $searchProfile) }}" class="text-gray-700 hover:text-gray-900">Edit</a>

                        <form method="POST" action="{{ route('search-profiles.destroy', $searchProfile) }}" onsubmit="return confirm('Delete this SearchProfile?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-700 hover:text-red-900">Delete</button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
