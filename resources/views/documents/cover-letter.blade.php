{{-- Minimal cover letter rendered solely from a TailoredApplication's cover_letter_data snapshot. --}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>{{ $coverLetter['letterhead']['full_name'] }} · Cover Letter</title>
    </head>
    <body>
        <header>
            <h1>{{ $coverLetter['letterhead']['full_name'] }}</h1>
            <p>{{ collect([$coverLetter['letterhead']['email'], $coverLetter['letterhead']['phone'], $coverLetter['letterhead']['location']])->filter()->implode(' · ') }}</p>
        </header>

        <p>{{ $coverLetter['date'] }}</p>
        <p>{{ $coverLetter['subject'] }}</p>
        <p>{{ $coverLetter['greeting'] }}</p>

        <p>{{ $coverLetter['opening_paragraph'] }}</p>
        @foreach ($coverLetter['body_paragraphs'] as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
        <p>{{ $coverLetter['closing_paragraph'] }}</p>

        <p>{{ $coverLetter['sign_off'] }}</p>
    </body>
</html>
