{{-- An entry's date range, left out for an undated entry. --}}
@php
    $dates = \App\Pdf\DocumentFormat::dateRange($entry['start_date'], $entry['end_date'], $entry['is_current']);
@endphp
@if ($dates !== null)
    <p class="dates">{{ $dates }}</p>
@endif
