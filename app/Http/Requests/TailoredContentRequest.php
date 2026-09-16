<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\SplitsListInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The editor's reframeable text for an Application's current TailoredApplication. Achievements are
 * typed one per line and cover-letter body paragraphs are separated by a blank line. Fact fields have
 * no rules here; TailoredApplication::reviseText() ignores anything but the reframeable text anyway.
 */
class TailoredContentRequest extends FormRequest
{
    use SplitsListInput;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'professional_summary' => ['nullable', 'string', 'max:5000'],
            'entries' => ['array'],
            'entries.*' => ['array'],
            'entries.*.description' => ['nullable', 'string', 'max:5000'],
            'entries.*.achievements' => ['array'],
            'entries.*.achievements.*' => ['string', 'max:1000'],
            'opening_paragraph' => ['required', 'string', 'max:5000'],
            'body_paragraphs' => ['present', 'array'],
            'body_paragraphs.*' => ['string', 'max:5000'],
            'closing_paragraph' => ['required', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $entries = $this->input('entries');

        if (is_array($entries)) {
            foreach ($entries as $entryId => $entry) {
                if (is_array($entry) && array_key_exists('achievements', $entry)) {
                    $entries[$entryId]['achievements'] = $this->listItems($entry['achievements'], fn (string $text) => preg_split('/\R/', $text));
                }
            }
        }

        $this->merge([
            ...(is_array($entries) ? ['entries' => $entries] : []),
            'body_paragraphs' => $this->listItems($this->input('body_paragraphs'), fn (string $text) => preg_split('/\R\s*\R/', $text)),
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return route('applications.show', $this->route('application')).'#editor';
    }
}
