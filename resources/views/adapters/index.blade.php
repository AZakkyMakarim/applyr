@extends('layouts.dashboard')

@section('title', 'Adapters')

@section('content')
    <h1 class="text-2xl font-semibold">Adapters</h1>

    @if (session('status'))
        <p class="mt-4 rounded-md bg-green-50 px-4 py-2 text-sm text-green-800">{{ session('status') }}</p>
    @endif

    <ul class="mt-6 space-y-4">
        @foreach ($adapters as $health)
            @php
                $badgeClass = match ($health->status) {
                    \App\Enums\AdapterStatus::Healthy => 'bg-green-100 text-green-800',
                    \App\Enums\AdapterStatus::Failing => 'bg-amber-100 text-amber-800',
                    \App\Enums\AdapterStatus::Paused => 'bg-red-100 text-red-800',
                };
            @endphp
            <li class="rounded-md border border-gray-200 bg-white px-4 py-4">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <h2 class="text-lg font-medium">{{ $health->platform->label() }}</h2>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap {{ $badgeClass }}">{{ $health->statusLabel() }}</span>
                    </div>

                    @if ($health->isPaused())
                        <form method="POST" action="{{ route('adapters.resume', $health->platform) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Resume</button>
                        </form>
                    @endif
                </div>

                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Last success</dt>
                        <dd class="mt-1">{{ $health->last_success_at?->format('Y-m-d H:i:s T') ?? 'Never' }}</dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Last failure</dt>
                        <dd class="mt-1">
                            @if ($health->last_failure_category)
                                <p>
                                    {{ $health->last_failure_category->label() }}
                                    · status code {{ $health->last_failure_status_code ?? 'none' }}
                                </p>
                                <p class="mt-1 break-words font-mono text-xs text-gray-700">{{ $health->last_failure_message }}</p>
                                <p class="mt-1 text-gray-500">{{ $health->last_failure_at?->format('Y-m-d H:i:s T') }}</p>
                            @else
                                None
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($health->recent_failures)
                    <div class="mt-4 text-sm">
                        <h3 class="text-gray-500">Recent failures</h3>
                        <ul class="mt-1 space-y-1">
                            @foreach (array_reverse($health->recent_failures) as $failure)
                                <li>
                                    {{ \App\Enums\FailureCategory::from($failure['category'])->label() }}
                                    <span class="text-gray-500">· {{ \Illuminate\Support\Carbon::parse($failure['at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
@endsection
