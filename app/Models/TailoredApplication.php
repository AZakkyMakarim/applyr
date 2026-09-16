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
     * Replaces the snapshot's reframeable text with the user's edits, leaving every fact as it is.
     * Only fields the snapshot already has are replaced, so an unknown entry or a field the entry
     * doesn't have (an Education's achievements) is ignored, and anything not submitted keeps its text.
     * The snapshot is changed on the model but not saved.
     *
     * @param  array{professional_summary?: ?string, entries?: array<string, array{description?: ?string, achievements?: list<string>}>, opening_paragraph?: string, body_paragraphs?: list<string>, closing_paragraph?: string}  $edits
     */
    public function reviseText(array $edits): void
    {
        $cvData = $this->cv_data;

        if (array_key_exists('professional_summary', $edits)) {
            $cvData['personal_info']['professional_summary'] = $edits['professional_summary'];
        }

        foreach (['experiences', 'educations', 'projects'] as $section) {
            foreach ($cvData[$section] as $entryId => $entry) {
                $cvData[$section][$entryId] = array_replace($entry, array_intersect_key(
                    $edits['entries'][$entryId] ?? [],
                    array_flip(['description', 'achievements']),
                    $entry,
                ));
            }
        }

        $this->cv_data = $cvData;
        $this->cover_letter_data = array_replace($this->cover_letter_data, array_intersect_key(
            $edits,
            array_flip(['opening_paragraph', 'body_paragraphs', 'closing_paragraph']),
        ));
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
