@php
    $badgeClass = match ($status) {
        \App\Enums\ApplicationStatus::PendingTailoring => 'bg-gray-200 text-gray-700',
        \App\Enums\ApplicationStatus::TailoringFailed => 'bg-red-100 text-red-800',
        \App\Enums\ApplicationStatus::NeedsReview => 'bg-amber-100 text-amber-800',
        \App\Enums\ApplicationStatus::Applied => 'bg-green-100 text-green-800',
        \App\Enums\ApplicationStatus::Rejected => 'bg-gray-100 text-gray-500',
    };
@endphp
<span class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap {{ $badgeClass }}">{{ $status->label() }}</span>
