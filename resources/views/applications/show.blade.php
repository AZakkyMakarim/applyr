@extends('layouts.dashboard')

@section('title', $application->job->title)

@section('content')
    @php
        $job = $application->job;
        $tailoredApplication = $application->currentTailoredApplication;
    @endphp

    <a href="{{ route('applications.index') }}" class="text-sm text-gray-600 hover:text-gray-900">&larr; Applications</a>

    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold">{{ $job->title }}</h1>
            <p class="mt-1 text-gray-700">{{ $job->company_name }} · {{ $job->platform->label() }}</p>
        </div>

        @include('applications._status-badge', ['status' => $application->status])
    </div>

    <section class="mt-6 rounded-md border border-gray-200 bg-white p-4">
        <h2 class="text-lg font-semibold">Job</h2>

        <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-gray-500">Location</dt>
                <dd>{{ $job->location ? $job->location.', ' : '' }}{{ $job->country_code }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Work arrangement</dt>
                <dd>{{ $job->work_arrangement->label() }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Job type</dt>
                <dd>{{ $job->job_type->label() }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Experience</dt>
                <dd>
                    @if ($job->min_years_experience !== null || $job->max_years_experience !== null)
                        {{ $job->min_years_experience ?? 0 }}–{{ $job->max_years_experience ?? 'any' }} yrs
                    @else
                        Not specified
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-gray-500">Salary</dt>
                <dd>{{ $job->salaryRange() ?? 'Not advertised' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Posted</dt>
                <dd>{{ $job->posted_date->format('Y-m-d') }} · {{ $job->status->label() }}</dd>
            </div>
        </dl>

        <p class="mt-4 text-sm">
            <a href="{{ $job->url }}" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-700 hover:text-blue-900">View on {{ $job->platform->label() }} &rarr;</a>
        </p>

        <h3 class="mt-6 text-sm font-medium text-gray-500">Description</h3>
        <div class="mt-2 text-sm whitespace-pre-line text-gray-800">{{ $job->description }}</div>
    </section>

    <section class="mt-6 rounded-md border border-gray-200 bg-white p-4">
        <h2 class="text-lg font-semibold">Matched SearchProfiles</h2>

        @if ($job->searchProfiles->isEmpty())
            <p class="mt-2 text-sm text-gray-600">No SearchProfile matches recorded.</p>
        @else
            <ul class="mt-2 divide-y divide-gray-100 text-sm">
                @foreach ($job->searchProfiles as $searchProfile)
                    <li class="flex justify-between gap-4 py-2">
                        <span>{{ $searchProfile->name }}</span>
                        <span class="text-gray-500">matched {{ \Illuminate\Support\Carbon::parse($searchProfile->pivot->matched_at)->format('Y-m-d H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($tailoredApplication)
        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach ([['CV', 'applications.cv'], ['Cover letter', 'applications.cover-letter']] as [$documentLabel, $documentRoute])
                <section class="rounded-md border border-gray-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold">{{ $documentLabel }}</h2>
                        <a href="{{ route($documentRoute, [$application, 'download' => 1]) }}" class="text-sm font-medium text-gray-700 hover:text-gray-900">Download</a>
                    </div>

                    <iframe src="{{ route($documentRoute, $application) }}" title="{{ $documentLabel }} preview" class="mt-4 h-[42rem] w-full rounded border border-gray-200"></iframe>
                </section>
            @endforeach
        </div>
    @endif
@endsection
