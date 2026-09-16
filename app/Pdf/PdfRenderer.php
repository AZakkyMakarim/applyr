<?php

namespace App\Pdf;

/**
 * Turns an HTML document into a PDF file.
 */
interface PdfRenderer
{
    /**
     * The private disk rendered PDFs are written to.
     */
    public const DISK = 'local';

    /**
     * Render $html to a PDF at $path on the PdfRenderer::DISK disk.
     *
     * @return string the path of the written PDF on that disk
     */
    public function render(string $html, string $path): string;
}
