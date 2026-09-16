<?php

namespace App\Pdf;

use App\Models\Application;
use Illuminate\Support\Str;

/**
 * Renders an Application's CV and cover letter PDFs from a TailoredApplication snapshot, and from
 * nothing else, so rendering the same snapshot again always gives the same documents.
 */
class DocumentRenderer
{
    public function __construct(private readonly PdfRenderer $pdfRenderer) {}

    /**
     * Each render writes to a fresh directory, so earlier PDFs are never overwritten.
     *
     * @param  array<string, mixed>  $cvData
     * @param  array<string, mixed>  $coverLetterData
     * @return array{cv_pdf_path: string, cover_letter_pdf_path: string}
     */
    public function render(Application $application, array $cvData, array $coverLetterData): array
    {
        $directory = "tailored-applications/{$application->id}/".Str::ulid();

        return [
            'cv_pdf_path' => $this->pdfRenderer->render(view('documents.cv', ['cv' => $cvData])->render(), "{$directory}/cv.pdf"),
            'cover_letter_pdf_path' => $this->pdfRenderer->render(
                view('documents.cover-letter', ['coverLetter' => $coverLetterData])->render(),
                "{$directory}/cover_letter.pdf",
            ),
        ];
    }
}
