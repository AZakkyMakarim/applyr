<?php

namespace App\Tailoring;

use App\Models\MasterProfile;
use App\Models\MasterProfileEntry;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Fact-validates an AI response against TailoringPrompt::responseSchema() and the MasterProfile
 * before anything is built from it. Fields outside the schema are ignored, not rejected:
 * DocumentSnapshots never reads them.
 */
class TailoringResponseValidator
{
    public function __construct(private readonly MasterProfile $masterProfile) {}

    /**
     * Why the response can't become a TailoredApplication; empty when it can.
     *
     * @param  array<string, mixed>  $aiResponse
     * @return list<string>
     */
    public function failures(array $aiResponse): array
    {
        // How many achievements each entry_id may carry: never more than the MasterProfile entry has.
        $achievementLimits = $this->masterProfile->entries()->mapWithKeys(fn (MasterProfileEntry $entry) => [
            $entry->tailoringEntryId() => count($entry->reframeableText()['achievements'] ?? []),
        ]);

        return Validator::make($aiResponse, [
            'professional_summary' => ['required', 'string'],
            'entries' => ['present', 'list'],
            'entries.*' => ['bail', 'array', function (string $attribute, mixed $entry, Closure $fail) use ($achievementLimits) {
                $limit = is_string($entry['entry_id'] ?? null) ? $achievementLimits->get($entry['entry_id']) : null;

                if ($limit !== null && is_array($entry['achievements'] ?? null) && count($entry['achievements']) > $limit) {
                    $fail("{$attribute} has more achievements than the {$limit} its MasterProfile entry has.");
                }
            }],
            'entries.*.entry_id' => ['required', 'string', 'distinct', Rule::in($achievementLimits->keys()->all())],
            'entries.*.description' => ['present', 'string'],
            'entries.*.achievements' => ['present', 'list'],
            'entries.*.achievements.*' => ['string'],
            'cover_letter' => ['required', 'array'],
            'cover_letter.opening_paragraph' => ['required', 'string'],
            'cover_letter.body_paragraphs' => ['required', 'list'],
            'cover_letter.body_paragraphs.*' => ['required', 'string'],
            'cover_letter.closing_paragraph' => ['required', 'string'],
        ])->errors()->all();
    }
}
