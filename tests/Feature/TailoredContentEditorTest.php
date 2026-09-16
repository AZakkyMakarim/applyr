<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Job;
use App\Pdf\PdfRenderer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakePdfRenderer;
use Tests\TestCase;

class TailoredContentEditorTest extends TestCase
{
    private FakePdfRenderer $pdfRenderer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake(PdfRenderer::DISK);

        $this->pdfRenderer = new FakePdfRenderer;
        $this->app->instance(PdfRenderer::class, $this->pdfRenderer);
    }

    public function test_saving_replaces_the_reframeable_text_in_the_snapshot(): void
    {
        $application = $this->makeApplication(ApplicationStatus::NeedsReview);

        $this->put(route('applications.tailored-content.update', $application), $this->edits())
            ->assertRedirect(route('applications.show', $application))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Documents saved.');

        $tailored = $application->fresh()->currentTailoredApplication;
        $cv = $tailored->cv_data;
        $coverLetter = $tailored->cover_letter_data;

        $this->assertSame('Edited summary.', $cv['personal_info']['professional_summary']);
        $this->assertSame('Edited experience description.', $cv['experiences']['experience:1']['description']);
        $this->assertSame(['Edited first achievement', 'Edited second achievement'], $cv['experiences']['experience:1']['achievements']);
        $this->assertSame('Edited education description.', $cv['educations']['education:1']['description']);
        $this->assertArrayNotHasKey('achievements', $cv['educations']['education:1']);
        $this->assertSame('Edited project description.', $cv['projects']['project:1']['description']);
        $this->assertSame([], $cv['projects']['project:1']['achievements']);
        $this->assertSame('Edited opening.', $coverLetter['opening_paragraph']);
        $this->assertSame(['Edited body one.', 'Edited body two.'], $coverLetter['body_paragraphs']);
        $this->assertSame('Edited closing.', $coverLetter['closing_paragraph']);
    }

    public function test_saving_never_changes_facts_even_when_fact_fields_are_submitted(): void
    {
        $application = $this->makeApplication(ApplicationStatus::NeedsReview);

        $this->put(route('applications.tailored-content.update', $application), [
            ...$this->edits(),
            'personal_info' => ['full_name' => 'Mallory', 'email' => 'mallory@example.com'],
            'full_name' => 'Mallory',
            'skills' => [['category' => 'Invented', 'skills' => ['Cobol']]],
            'subject' => 'Re: Something else',
            'greeting' => 'Hi there',
            'sign_off' => 'Cheers, Mallory',
            'entries' => [
                ...$this->edits()['entries'],
                'experience:1' => [
                    ...$this->edits()['entries']['experience:1'],
                    'title' => 'Chief Executive',
                    'company' => 'Megacorp',
                    'start_date' => '2001-01',
                    'skills_used' => ['Cobol'],
                ],
                'experience:99' => ['description' => 'An invented job.'],
            ],
        ])->assertSessionHasNoErrors();

        $tailored = $application->fresh()->currentTailoredApplication;

        $this->assertSame($this->withoutReframeableText($this->cvData()), $this->withoutReframeableText($tailored->cv_data));
        $this->assertSame(['experience:1'], array_keys($tailored->cv_data['experiences']));
        $this->assertSame(
            collect($this->coverLetterData())->except(['opening_paragraph', 'body_paragraphs', 'closing_paragraph'])->all(),
            collect($tailored->cover_letter_data)->except(['opening_paragraph', 'body_paragraphs', 'closing_paragraph'])->all(),
        );
    }

    public function test_saving_renders_both_pdfs_again_from_the_edited_snapshot(): void
    {
        $application = $this->makeApplication(ApplicationStatus::NeedsReview);
        $before = $application->currentTailoredApplication;

        $this->put(route('applications.tailored-content.update', $application), $this->edits());

        $tailored = $application->fresh()->currentTailoredApplication;
        $this->assertSame($before->id, $tailored->id);
        $this->assertNotSame($before->cv_pdf_path, $tailored->cv_pdf_path);
        $this->assertNotSame($before->cover_letter_pdf_path, $tailored->cover_letter_pdf_path);
        $this->assertSame([$tailored->cv_pdf_path, $tailored->cover_letter_pdf_path], array_keys($this->pdfRenderer->rendered));
        $this->assertStringContainsString('Edited first achievement', $this->pdfRenderer->rendered[$tailored->cv_pdf_path]);
        $this->assertStringContainsString('Ada Lovelace', $this->pdfRenderer->rendered[$tailored->cv_pdf_path]);
        $this->assertStringContainsString('Edited body two.', $this->pdfRenderer->rendered[$tailored->cover_letter_pdf_path]);
        $this->assertSame('%PDF-placeholder', $this->get(route('applications.cv', $application))->streamedContent());
    }

    public function test_saving_flags_the_application_as_edited_and_leaves_its_status_unchanged(): void
    {
        $application = $this->makeApplication(ApplicationStatus::NeedsReview);

        $this->put(route('applications.tailored-content.update', $application), $this->edits());

        $application->refresh();
        $this->assertTrue($application->edited_by_user);
        $this->assertSame(ApplicationStatus::NeedsReview, $application->status);
        Queue::assertNothingPushed();
    }

    #[DataProvider('uneditableStatuses')]
    public function test_saving_is_rejected_outside_needs_review(ApplicationStatus $status): void
    {
        $application = $this->makeApplication($status);
        $before = $application->fresh()->getAttributes();
        $tailoredBefore = $application->currentTailoredApplication->fresh()->getAttributes();

        $this->put(route('applications.tailored-content.update', $application), $this->edits())
            ->assertRedirect(route('applications.show', $application))
            ->assertSessionHas('error')
            ->assertSessionMissing('status');

        $this->assertSame($before, $application->fresh()->getAttributes());
        $this->assertSame($tailoredBefore, $application->currentTailoredApplication->fresh()->getAttributes());
        $this->assertSame([], $this->pdfRenderer->rendered);
    }

    /**
     * @return array<string, array{ApplicationStatus}>
     */
    public static function uneditableStatuses(): array
    {
        return [
            'pending_tailoring' => [ApplicationStatus::PendingTailoring],
            'tailoring_failed' => [ApplicationStatus::TailoringFailed],
            'applied' => [ApplicationStatus::Applied],
            'rejected' => [ApplicationStatus::Rejected],
        ];
    }

    public function test_an_invalid_edit_is_sent_back_with_errors_and_changes_nothing(): void
    {
        $application = $this->makeApplication(ApplicationStatus::NeedsReview);
        $tailoredBefore = $application->currentTailoredApplication->fresh()->getAttributes();

        $this->from(route('applications.show', $application))
            ->put(route('applications.tailored-content.update', $application), [...$this->edits(), 'opening_paragraph' => ''])
            ->assertRedirect(route('applications.show', $application).'#editor')
            ->assertSessionHasErrors('opening_paragraph');

        $this->assertSame($tailoredBefore, $application->currentTailoredApplication->fresh()->getAttributes());
        $this->assertFalse($application->fresh()->edited_by_user);
        $this->assertSame([], $this->pdfRenderer->rendered);
    }

    public function test_the_editor_shows_only_the_reframeable_text_prefilled_from_the_snapshot(): void
    {
        $application = $this->makeApplication(ApplicationStatus::NeedsReview);

        $response = $this->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('action="'.route('applications.tailored-content.update', $application).'"', false)
            ->assertSee('name="professional_summary"', false)
            ->assertSee('Original summary.')
            ->assertSee('name="entries[experience:1][description]"', false)
            ->assertSee('name="entries[experience:1][achievements]"', false)
            ->assertSee("Shipped the first thing\nShipped the second thing")
            ->assertSee('name="entries[education:1][description]"', false)
            ->assertDontSee('name="entries[education:1][achievements]"', false)
            ->assertSee('name="entries[project:1][achievements]"', false)
            ->assertSee('name="opening_paragraph"', false)
            ->assertSee('name="body_paragraphs"', false)
            ->assertSee("Original body one.\n\nOriginal body two.")
            ->assertSee('name="closing_paragraph"', false);

        foreach (['title', 'company', 'start_date', 'skills_used', 'full_name', 'email', 'subject', 'greeting', 'sign_off'] as $fact) {
            $response->assertDontSee("[{$fact}]\"", false)->assertDontSee("name=\"{$fact}\"", false);
        }
    }

    #[DataProvider('uneditableStatuses')]
    public function test_the_editor_is_not_offered_outside_needs_review(ApplicationStatus $status): void
    {
        $application = $this->makeApplication($status);

        $this->get(route('applications.show', $application))
            ->assertOk()
            ->assertDontSee('action="'.route('applications.tailored-content.update', $application).'"', false);
    }

    public function test_edited_applications_are_flagged_on_the_list_and_detail_pages(): void
    {
        $edited = $this->makeApplication(ApplicationStatus::NeedsReview);
        $untouched = $this->makeApplication(ApplicationStatus::NeedsReview);

        $this->get(route('applications.index'))->assertDontSeeText('Edited by you');
        $this->get(route('applications.show', $edited))->assertDontSeeText('Edited by you');

        $this->put(route('applications.tailored-content.update', $edited), $this->edits());

        $list = $this->get(route('applications.index'))->assertOk();
        $this->assertSame(1, substr_count($list->getContent(), 'Edited by you'));
        $this->get(route('applications.show', $edited))->assertSeeText('Edited by you');
        $this->get(route('applications.show', $untouched))->assertDontSeeText('Edited by you');
    }

    public function test_the_detail_page_confirms_a_save(): void
    {
        $application = $this->makeApplication(ApplicationStatus::NeedsReview);

        $this->followingRedirects()
            ->put(route('applications.tailored-content.update', $application), $this->edits())
            ->assertOk()
            ->assertSee('Documents saved.')
            ->assertSee('Edited summary.');
    }

    /**
     * The form as the editor submits it: textareas for every reframeable field.
     *
     * @return array<string, mixed>
     */
    private function edits(): array
    {
        return [
            'professional_summary' => 'Edited summary.',
            'entries' => [
                'experience:1' => [
                    'description' => 'Edited experience description.',
                    'achievements' => "Edited first achievement\n\n  Edited second achievement  \n",
                ],
                'education:1' => [
                    'description' => 'Edited education description.',
                    'achievements' => 'Education has none of these.',
                ],
                'project:1' => [
                    'description' => 'Edited project description.',
                    'achievements' => '',
                ],
            ],
            'opening_paragraph' => 'Edited opening.',
            'body_paragraphs' => "Edited body one.\r\n\r\nEdited body two.\n\n\n",
            'closing_paragraph' => 'Edited closing.',
        ];
    }

    /**
     * @param  array<string, mixed>  $cvData
     * @return array<string, mixed>
     */
    private function withoutReframeableText(array $cvData): array
    {
        unset($cvData['personal_info']['professional_summary']);

        foreach (['experiences', 'educations', 'projects'] as $section) {
            foreach ($cvData[$section] as $entryId => $entry) {
                unset($cvData[$section][$entryId]['description'], $cvData[$section][$entryId]['achievements']);
            }
        }

        return $cvData;
    }

    private function makeApplication(ApplicationStatus $status): Application
    {
        static $sequence = 0;
        $sequence++;

        $job = Job::create([
            'platform' => 'glints',
            'external_id' => "editor-job-{$sequence}",
            'title' => 'Software Engineer',
            'company_name' => 'Initech',
            'location' => 'Jakarta',
            'country_code' => 'ID',
            'url' => "https://glints.com/id/opportunities/jobs/{$sequence}",
            'description' => 'Write software.',
            'work_arrangement' => 'onsite',
            'job_type' => 'full_time',
            'status' => 'open',
            'posted_date' => '2026-09-01 00:00:00',
            'raw_payload' => [],
        ]);

        $application = $job->application()->create([
            'status' => $status,
            'previous_status' => in_array($status, [ApplicationStatus::Applied, ApplicationStatus::Rejected], true) ? ApplicationStatus::NeedsReview : null,
        ]);

        $directory = "tailored-applications/{$application->id}/original";
        Storage::disk(PdfRenderer::DISK)->put("{$directory}/cv.pdf", '%PDF-original-cv');
        Storage::disk(PdfRenderer::DISK)->put("{$directory}/cover_letter.pdf", '%PDF-original-cover-letter');

        $tailored = $application->tailoredApplications()->create([
            'cv_data' => $this->cvData(),
            'cover_letter_data' => $this->coverLetterData(),
            'cv_pdf_path' => "{$directory}/cv.pdf",
            'cover_letter_pdf_path' => "{$directory}/cover_letter.pdf",
        ]);

        $application->update(['current_tailored_application_id' => $tailored->id]);

        return $application->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function cvData(): array
    {
        return [
            'personal_info' => [
                ...$this->contactDetails(),
                'professional_summary' => 'Original summary.',
                'photo_path' => null,
                'show_photo' => false,
            ],
            'skills' => [['category' => 'Languages', 'skills' => ['PHP', 'SQL']]],
            'experiences' => [
                'experience:1' => [
                    'entry_id' => 'experience:1',
                    'title' => 'Backend Engineer',
                    'company' => 'Acme',
                    'location' => 'Jakarta',
                    'employment_type' => 'full_time',
                    'start_date' => '2022-01',
                    'end_date' => null,
                    'is_current' => true,
                    'skills_used' => ['PHP'],
                    'description' => 'Original experience description.',
                    'achievements' => ['Shipped the first thing', 'Shipped the second thing'],
                ],
            ],
            'educations' => [
                'education:1' => [
                    'entry_id' => 'education:1',
                    'institution' => 'Universitas Indonesia',
                    'degree' => 'Bachelor',
                    'field_of_study' => 'Computer Science',
                    'start_date' => '2016-08',
                    'end_date' => '2020-07',
                    'is_current' => false,
                    'description' => 'Original education description.',
                ],
            ],
            'projects' => [
                'project:1' => [
                    'entry_id' => 'project:1',
                    'name' => 'Applyr',
                    'start_date' => '2026-01',
                    'end_date' => null,
                    'is_current' => true,
                    'tech_stack' => ['Laravel'],
                    'link' => 'https://example.com/applyr',
                    'description' => 'Original project description.',
                    'achievements' => ['Automated job hunting'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function coverLetterData(): array
    {
        return [
            'letterhead' => $this->contactDetails(),
            'date' => '2026-09-10',
            'subject' => 'Re: Application for Software Engineer',
            'greeting' => 'Dear Initech Hiring Team',
            'opening_paragraph' => 'Original opening.',
            'body_paragraphs' => ['Original body one.', 'Original body two.'],
            'closing_paragraph' => 'Original closing.',
            'sign_off' => 'Sincerely, Ada Lovelace',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactDetails(): array
    {
        return [
            'full_name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+62 812 0000 0000',
            'location' => 'Jakarta, Indonesia',
            'links' => [['label' => 'GitHub', 'url' => 'https://github.com/ada']],
        ];
    }
}
