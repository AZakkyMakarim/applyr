{{-- Minimal CV rendered solely from a TailoredApplication's cv_data snapshot. --}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>{{ $cv['personal_info']['full_name'] }} · CV</title>
    </head>
    <body>
        <header>
            <h1>{{ $cv['personal_info']['full_name'] }}</h1>
            <p>{{ collect([$cv['personal_info']['email'], $cv['personal_info']['phone'], $cv['personal_info']['location']])->filter()->implode(' · ') }}</p>
            @foreach ($cv['personal_info']['links'] as $link)
                <p>{{ $link['label'] }}: {{ $link['url'] }}</p>
            @endforeach
        </header>

        @if (filled($cv['personal_info']['professional_summary']))
            <section>
                <h2>Professional Summary</h2>
                <p>{{ $cv['personal_info']['professional_summary'] }}</p>
            </section>
        @endif

        @if ($cv['skills'] !== [])
            <section>
                <h2>Skills</h2>
                @foreach ($cv['skills'] as $group)
                    <p>{{ $group['category'] }}: {{ implode(', ', $group['skills']) }}</p>
                @endforeach
            </section>
        @endif

        @if ($cv['experiences'] !== [])
            <section>
                <h2>Experience</h2>
                @foreach ($cv['experiences'] as $experience)
                    <article>
                        <h3>{{ $experience['title'] }} · {{ $experience['company'] }}</h3>
                        <p>{{ $experience['start_date'] }} – {{ $experience['is_current'] ? 'Present' : $experience['end_date'] }}</p>
                        <p>{{ $experience['description'] }}</p>
                        <ul>
                            @foreach ($experience['achievements'] as $achievement)
                                <li>{{ $achievement }}</li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </section>
        @endif

        @if ($cv['educations'] !== [])
            <section>
                <h2>Education</h2>
                @foreach ($cv['educations'] as $education)
                    <article>
                        <h3>{{ collect([$education['degree'], $education['field_of_study']])->filter()->implode(', ') }} · {{ $education['institution'] }}</h3>
                        <p>{{ $education['start_date'] }} – {{ $education['is_current'] ? 'Present' : $education['end_date'] }}</p>
                        <p>{{ $education['description'] }}</p>
                    </article>
                @endforeach
            </section>
        @endif

        @if ($cv['projects'] !== [])
            <section>
                <h2>Projects</h2>
                @foreach ($cv['projects'] as $project)
                    <article>
                        <h3>{{ $project['name'] }}</h3>
                        <p>{{ $project['description'] }}</p>
                        <ul>
                            @foreach ($project['achievements'] as $achievement)
                                <li>{{ $achievement }}</li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </section>
        @endif
    </body>
</html>
