<?php

namespace App\Tailoring;

use App\Models\Job;
use App\Models\MasterProfile;
use App\Models\MasterProfileEntry;

/**
 * What the AI is asked for one Application: the Job, the MasterProfile's entries with their
 * facts as read-only context, and the narrow schema of the text it may write.
 */
class TailoringPrompt
{
    private const INSTRUCTIONS = <<<'TEXT'
        You tailor a job applicant's CV and cover letter to one job posting.

        The context below is JSON. "job" is the posting. "master_profile" is the applicant's own
        ground truth. Each item in "entries" is one experience, education or project entry
        identified by its entry_id.

        Rules:
        - Everything under "facts" is read-only context. Never change, contradict or add to
          titles, companies, institutions, project names, dates, locations or skills.
        - Rewrite only the professional summary and each entry's description and achievements,
          emphasising what is relevant to the job. Use only information present in the context.
        - Return each entry under its exact entry_id. You may keep fewer achievements than an
          entry lists, but never more, and never invent new accomplishments. Entries without
          achievements get an empty achievements list.
        - Write the cover letter's opening paragraph, body paragraphs and closing paragraph only.
          Do not write a date, subject line, greeting or sign-off; the app adds those.
        - Write in the same language as the applicant's professional summary.

        Context:
        TEXT;

    public function __construct(
        private readonly Job $job,
        private readonly MasterProfile $masterProfile,
    ) {}

    public function text(): string
    {
        $context = [
            'job' => [
                'title' => $this->job->title,
                'company_name' => $this->job->company_name,
                'description' => $this->job->description,
            ],
            'master_profile' => [
                'facts' => [
                    'skills' => $this->masterProfile->skills,
                ],
                'reframeable' => [
                    'professional_summary' => $this->masterProfile->professional_summary,
                ],
            ],
            'entries' => $this->masterProfile->entries()->map(fn (MasterProfileEntry $entry) => [
                'entry_id' => $entry->tailoringEntryId(),
                'facts' => $entry->tailoringFacts(),
                'reframeable' => $entry->reframeableText(),
            ])->all(),
        ];

        return self::INSTRUCTIONS."\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Only what the AI writes; every fact is filled in by the app.
     *
     * @return array<string, mixed>
     */
    public static function responseSchema(): array
    {
        $string = ['type' => 'STRING'];
        $strings = ['type' => 'ARRAY', 'items' => $string];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'professional_summary' => $string,
                'entries' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'entry_id' => $string,
                            'description' => $string,
                            'achievements' => $strings,
                        ],
                        'required' => ['entry_id', 'description', 'achievements'],
                        'propertyOrdering' => ['entry_id', 'description', 'achievements'],
                    ],
                ],
                'cover_letter' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'opening_paragraph' => $string,
                        'body_paragraphs' => $strings,
                        'closing_paragraph' => $string,
                    ],
                    'required' => ['opening_paragraph', 'body_paragraphs', 'closing_paragraph'],
                    'propertyOrdering' => ['opening_paragraph', 'body_paragraphs', 'closing_paragraph'],
                ],
            ],
            'required' => ['professional_summary', 'entries', 'cover_letter'],
            'propertyOrdering' => ['professional_summary', 'entries', 'cover_letter'],
        ];
    }
}
