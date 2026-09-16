<?php

namespace Tests\Fakes;

use App\Pdf\PdfRenderer;
use Illuminate\Support\Facades\Storage;

/**
 * Records the HTML it is asked to render and writes a placeholder file in place of a PDF.
 */
class FakePdfRenderer implements PdfRenderer
{
    /** @var array<string, string> HTML received, keyed by the path it was rendered to */
    public array $rendered = [];

    public function render(string $html, string $path): string
    {
        $this->rendered[$path] = $html;

        Storage::disk(self::DISK)->put($path, '%PDF-placeholder');

        return $path;
    }
}
