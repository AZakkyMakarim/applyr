<?php

namespace App\Pdf;

use App\Models\MasterProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Formatting and embedded assets for the CV and cover-letter views. Everything a document needs is
 * inlined, so Chromium renders it without any network access.
 */
class DocumentFormat
{
    /**
     * The bundled Inter subsets, with the Unicode ranges each one covers.
     */
    private const INTER_SUBSETS = [
        'inter-latin-wght-normal.woff2' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
        'inter-latin-ext-wght-normal.woff2' => 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF',
    ];

    /**
     * @font-face rules for Inter, with the bundled .woff2 files embedded as data URIs.
     */
    public static function interFontFaces(): string
    {
        return collect(self::INTER_SUBSETS)->map(fn (string $unicodeRange, string $file) => implode("\n", [
            '@font-face {',
            "  font-family: 'Inter';",
            '  font-style: normal;',
            '  font-weight: 100 900;',
            '  src: url(data:font/woff2;base64,'.base64_encode(file_get_contents(resource_path("fonts/inter/{$file}"))).") format('woff2');",
            "  unicode-range: {$unicodeRange};",
            '}',
        ]))->implode("\n");
    }

    /**
     * An entry's dates, e.g. "Jan 2022 – Mar 2024" or "Jan 2022 – Present"; null for an undated entry.
     *
     * @param  ?string  $start  a Y-m month, as stored in the snapshot
     * @param  ?string  $end  a Y-m month, as stored in the snapshot
     */
    public static function dateRange(?string $start, ?string $end, bool $isCurrent): ?string
    {
        if ($start === null) {
            return null;
        }

        $end = $isCurrent ? 'Present' : ($end === null ? null : self::month($end));

        return $end === null ? self::month($start) : self::month($start).' – '.$end;
    }

    /**
     * A cover letter's Y-m-d date, e.g. "16 September 2026".
     */
    public static function letterDate(string $date): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('j F Y');
    }

    /**
     * The snapshot's photo as a data URI, or null when the file is missing.
     */
    public static function photoDataUri(?string $path): ?string
    {
        $disk = Storage::disk(MasterProfile::PHOTO_DISK);

        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        return 'data:'.$disk->mimeType($path).';base64,'.base64_encode($disk->get($path));
    }

    private static function month(string $month): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->format('M Y');
    }
}
