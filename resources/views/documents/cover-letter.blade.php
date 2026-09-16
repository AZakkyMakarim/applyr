{{-- The cover letter, rendered solely from a TailoredApplication's cover_letter_data snapshot. It never shows the photo. --}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>{{ $coverLetter['letterhead']['full_name'] }} · Cover Letter</title>
        @include('documents._styles')
        <style>
            header { padding-bottom: 8pt; border-bottom: 0.75pt solid #000; margin-bottom: 20pt; }
            main { font-size: 10.5pt; line-height: 1.55; }
            main > * + * { margin-top: 10pt; }
            main > .subject { margin-top: 16pt; font-weight: 600; }
            main > .sign-off { margin-top: 18pt; }
        </style>
    </head>
    <body>
        <header>
            <h1>{{ $coverLetter['letterhead']['full_name'] }}</h1>
            @include('documents._contact', ['contact' => $coverLetter['letterhead']])
        </header>

        <main>
            <p>{{ \App\Pdf\DocumentFormat::letterDate($coverLetter['date']) }}</p>
            <p class="subject">{{ $coverLetter['subject'] }}</p>
            <p>{{ $coverLetter['greeting'] }}</p>
            <p>{{ $coverLetter['opening_paragraph'] }}</p>
            @foreach ($coverLetter['body_paragraphs'] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
            <p>{{ $coverLetter['closing_paragraph'] }}</p>
            <p class="sign-off">{{ $coverLetter['sign_off'] }}</p>
        </main>
    </body>
</html>
