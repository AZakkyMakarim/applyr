{{-- An entry's description and achievements, each left out when empty. --}}
@if (filled($entry['description']))
    <p>{{ $entry['description'] }}</p>
@endif
@if (($entry['achievements'] ?? []) !== [])
    <ul>
        @foreach ($entry['achievements'] as $achievement)
            <li>{{ $achievement }}</li>
        @endforeach
    </ul>
@endif
