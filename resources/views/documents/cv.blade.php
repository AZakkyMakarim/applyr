{{-- The CV, rendered solely from a TailoredApplication's cv_data snapshot. An empty section is left out entirely. --}}
@php
    $personalInfo = $cv['personal_info'];
    $photo = $personalInfo['show_photo'] ? \App\Pdf\DocumentFormat::photoDataUri($personalInfo['photo_path']) : null;
    $skills = collect($cv['skills'])->filter(fn (array $group) => $group['skills'] !== []);
@endphp
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>{{ $personalInfo['full_name'] }} · CV</title>
        @include('documents._styles')
        <style>
            header { display: flex; align-items: center; gap: 14pt; margin-bottom: 14pt; }
            .photo { width: 100px; height: 100px; border-radius: 10px; object-fit: cover; flex: none; }
            section { margin-top: 12pt; }
            h2 { font-size: 10.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; padding-bottom: 2pt; border-bottom: 0.75pt solid #000; margin-bottom: 6pt; }
            .entry + .entry { margin-top: 8pt; }
            .entry { break-inside: avoid; }
            .entry-heading { display: flex; justify-content: space-between; gap: 12pt; }
            h3 { font-size: 10pt; font-weight: 600; }
            .dates { flex: none; }
            .entry-heading ~ * { margin-top: 2pt; }
            ul { padding-left: 13pt; }
            li + li { margin-top: 1pt; }
            .skill-line + .skill-line { margin-top: 2pt; }
            .skill-category { font-weight: 600; }
        </style>
    </head>
    <body>
        <header>
            @if ($photo !== null)
                <img class="photo" src="{{ $photo }}" alt="{{ $personalInfo['full_name'] }}">
            @endif
            <div>
                <h1>{{ $personalInfo['full_name'] }}</h1>
                @include('documents._contact', ['contact' => $personalInfo])
            </div>
        </header>

        @if (filled($personalInfo['professional_summary']))
            <section class="summary">
                <h2>Professional Summary</h2>
                <p>{{ $personalInfo['professional_summary'] }}</p>
            </section>
        @endif

        @if ($skills->isNotEmpty())
            <section class="skills">
                <h2>Skills</h2>
                @foreach ($skills as $group)
                    <p class="skill-line"><span class="skill-category">{{ $group['category'] }}:</span> {{ implode(', ', $group['skills']) }}</p>
                @endforeach
            </section>
        @endif

        @if ($cv['experiences'] !== [])
            <section>
                <h2>Experience</h2>
                @foreach ($cv['experiences'] as $experience)
                    <article class="entry experience">
                        <div class="entry-heading">
                            <h3>{{ $experience['title'] }} · {{ $experience['company'] }}</h3>
                            @include('documents._dates', ['entry' => $experience])
                        </div>
                        @if (filled($experience['location']))
                            <p>{{ $experience['location'] }}</p>
                        @endif
                        @include('documents._entry-text', ['entry' => $experience])
                    </article>
                @endforeach
            </section>
        @endif

        @if ($cv['educations'] !== [])
            <section>
                <h2>Education</h2>
                @foreach ($cv['educations'] as $education)
                    <article class="entry education">
                        <div class="entry-heading">
                            <h3>{{ $education['institution'] }}</h3>
                            @include('documents._dates', ['entry' => $education])
                        </div>
                        @if (filled($education['degree']) || filled($education['field_of_study']))
                            <p>{{ collect([$education['degree'], $education['field_of_study']])->filter()->implode(', ') }}</p>
                        @endif
                        @include('documents._entry-text', ['entry' => $education])
                    </article>
                @endforeach
            </section>
        @endif

        @if ($cv['projects'] !== [])
            <section>
                <h2>Projects</h2>
                @foreach ($cv['projects'] as $project)
                    <article class="entry project">
                        <div class="entry-heading">
                            <h3>{{ $project['name'] }}</h3>
                            @include('documents._dates', ['entry' => $project])
                        </div>
                        @if (filled($project['tech_stack']) || filled($project['link']))
                            <p>{{ collect([implode(', ', $project['tech_stack'] ?? []), $project['link']])->filter()->implode(' · ') }}</p>
                        @endif
                        @include('documents._entry-text', ['entry' => $project])
                    </article>
                @endforeach
            </section>
        @endif
    </body>
</html>
