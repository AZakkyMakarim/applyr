<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fact-valid CV and cover letter content for one Application, as self-contained snapshots,
 * plus the two PDFs rendered from them. Superseded rows may remain; the Application points
 * at its current one.
 */
#[Fillable([
    'cv_data',
    'cover_letter_data',
    'cv_pdf_path',
    'cover_letter_pdf_path',
])]
class TailoredApplication extends Model
{
    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cv_data' => 'array',
            'cover_letter_data' => 'array',
        ];
    }
}
