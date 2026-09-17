<?php

namespace App\Tailoring;

use App\Models\Job;
use App\Models\MasterProfile;
use App\Models\MasterProfileEntry;
use Illuminate\Database\Eloquent\Collection;

/**
 * Assembles a TailoredApplication's cv_data and cover_letter_data: facts copied from the Job
 * and MasterProfile, with only the free text taken from the AI's output.
 */
class DocumentSnapshots
{
    /**
     * @param  array<string, mixed>  $aiResponse  the AI response, shaped by TailoringPrompt::responseSchema()
     * @param  ?string  $photoPath  the snapshot's own copy of the MasterProfile photo, if there is one
     */
    public function __construct(
        private readonly Job $job,
        private readonly MasterProfile $masterProfile,
        private readonly array $aiResponse,
        private readonly ?string $photoPath,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function cvData(): array
    {
        return [
            'personal_info' => [
                ...$this->contactDetails(),
                'professional_summary' => $this->aiResponse['professional_summary'],
                'photo_path' => $this->photoPath,
                'show_photo' => $this->masterProfile->show_photo,
            ],
            'skills' => $this->masterProfile->skills,
            'experiences' => $this->entries($this->masterProfile->experiences),
            'educations' => $this->entries($this->masterProfile->educations),
            'projects' => $this->entries($this->masterProfile->projects),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function coverLetterData(): array
    {
        $coverLetter = $this->aiResponse['cover_letter'];

        return [
            'letterhead' => $this->contactDetails(),
            'date' => now()->toDateString(),
            'subject' => "Re: Application for {$this->job->title}",
            'greeting' => "Dear {$this->job->company_name} Hiring Team",
            'opening_paragraph' => $coverLetter['opening_paragraph'],
            'body_paragraphs' => $coverLetter['body_paragraphs'],
            'closing_paragraph' => $coverLetter['closing_paragraph'],
            'sign_off' => "Sincerely, {$this->masterProfile->full_name}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactDetails(): array
    {
        return [
            'full_name' => $this->masterProfile->full_name,
            'email' => $this->masterProfile->email,
            'phone' => $this->masterProfile->phone,
            'location' => $this->masterProfile->location,
            'links' => $this->masterProfile->links,
        ];
    }

    /**
     * Entries keyed by their entry_id, newest first. The AI's text replaces only the fields the
     * entry lets it reframe; anything it didn't return, or a blank description, keeps the
     * MasterProfile text. Empty achievements are kept: the AI may curate them down to none.
     *
     * @param  Collection<int, MasterProfileEntry>  $entries
     * @return array<string, array<string, mixed>>
     */
    private function entries(Collection $entries): array
    {
        $reframed = collect($this->aiResponse['entries'])->keyBy('entry_id');

        return $entries->mapWithKeys(function (MasterProfileEntry $entry) use ($reframed) {
            $original = $entry->reframeableText();
            $reframedText = array_intersect_key($reframed->get($entry->tailoringEntryId(), []), $original);

            if (blank($reframedText['description'] ?? null)) {
                unset($reframedText['description']);
            }

            return [$entry->tailoringEntryId() => [
                'entry_id' => $entry->tailoringEntryId(),
                ...$entry->tailoringFacts(),
                ...array_replace($original, $reframedText),
            ]];
        })->all();
    }
}
