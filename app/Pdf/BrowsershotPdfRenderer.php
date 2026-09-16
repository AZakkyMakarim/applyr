<?php

namespace App\Pdf;

use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;

/**
 * Renders PDFs with headless Chromium through Browsershot.
 */
class BrowsershotPdfRenderer implements PdfRenderer
{
    public function render(string $html, string $path): string
    {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(dirname($path));

        Browsershot::html($html)
            ->format('A4')
            ->margins(16, 16, 16, 16)
            ->showBackground()
            ->save($disk->path($path));

        return $path;
    }
}
