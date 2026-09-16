{{-- The contact lines under a name: email, phone and location, then links. --}}
<p class="contact">{{ collect([$contact['email'], $contact['phone'], $contact['location']])->filter()->implode(' · ') }}</p>
@if ($contact['links'] !== [])
    <p class="contact">{{ collect($contact['links'])->map(fn (array $link) => "{$link['label']}: {$link['url']}")->implode(' · ') }}</p>
@endif
