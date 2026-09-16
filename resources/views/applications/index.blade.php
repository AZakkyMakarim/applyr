@extends('layouts.dashboard')

@section('title', 'Applications')

@section('content')
    <h1 class="text-2xl font-semibold">Applications</h1>

    <nav class="mt-4 flex flex-wrap gap-2 text-sm">
        <a href="{{ route('applications.index') }}"
           class="rounded-full px-3 py-1 {{ $status === null ? 'bg-gray-900 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:text-gray-900' }}">All</a>
        @foreach (\App\Enums\ApplicationStatus::cases() as $case)
            <a href="{{ route('applications.index', ['status' => $case->value]) }}"
               class="rounded-full px-3 py-1 {{ $status === $case ? 'bg-gray-900 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:text-gray-900' }}">{{ $case->label() }}</a>
        @endforeach
    </nav>

    @if ($applications->isEmpty())
        <p class="mt-8 text-gray-600">No Applications {{ $status ? 'with status "'.$status->label().'"' : 'yet' }}.</p>
    @else
        <ul class="mt-6 divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
            @foreach ($applications as $application)
                <li>
                    <a href="{{ route('applications.show', $application) }}" class="flex flex-wrap items-start justify-between gap-4 px-4 py-4 hover:bg-gray-50">
                        <div class="min-w-0">
                            <p class="font-medium">{{ $application->job->title }}</p>
                            <p class="mt-1 text-sm text-gray-700">{{ $application->job->company_name }}</p>
                            <p class="mt-1 text-sm text-gray-500">{{ $application->job->platform->label() }}</p>
                        </div>

                        @include('applications._status-badge', ['status' => $application->status])
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-6">
            {{ $applications->links() }}
        </div>
    @endif
@endsection
