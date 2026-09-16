<?php

namespace Tests\Feature;

use App\Jobs\TailorApplication;
use App\Models\Application;
use App\Models\Job;
use App\Models\MasterProfile;
use App\Models\MasterProfileEntry;
use App\Pdf\BrowsershotPdfRenderer;
use App\Pdf\DocumentRenderer;
use App\Pdf\PdfRenderer;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakePdfRenderer;
use Tests\TestCase;
use Throwable;

class DocumentTemplatesTest extends TestCase
{
    private FakePdfRenderer $pdfRenderer;

    private MasterProfile $masterProfile;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PdfRenderer::DISK);
        $this->travelTo('2026-09-16 10:00:00');

        config([
            'services.gemini.api_key' => 'gemini-key',
            'services.gemini.model' => 'gemini-test-model',
            'services.telegram.bot_token' => 'bot-token',
            'services.telegram.chat_id' => '12345',
        ]);

        $this->pdfRenderer = new FakePdfRenderer;
        $this->app->instance(PdfRenderer::class, $this->pdfRenderer);

        // Gemini hands back the MasterProfile's own text, which always passes fact validation.
        Http::fake([
            'generativelanguage.googleapis.com/*' => fn (Request $request) => Http::response($this->echoingGeminiResponse()),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->masterProfile = MasterProfile::create([
            'full_name' => 'Ahmad Zakky',
            'email' => 'zakky@example.com',
            'phone' => '+62 812 0000 0000',
            'location' => 'Jakarta, Indonesia',
            'professional_summary' => 'Backend engineer who ships.',
            'links' => [['label' => 'GitHub', 'url' => 'https://github.com/zakky']],
            'skills' => [
                ['category' => 'Backend', 'skills' => ['PHP', 'Laravel']],
                ['category' => 'Tools', 'skills' => ['Docker']],
            ],
            'show_photo' => false,
        ]);
    }

    public function test_cv_sections_follow_the_fixed_order_below_the_header(): void
    {
        $this->addEveryKindOfEntry();

        $cv = $this->document($this->tailor()['cv']);

        $this->assertSame('Ahmad Zakky', $this->text($cv->querySelector('header h1')));
        $this->assertSame(
            ['Professional Summary', 'Skills', 'Experience', 'Education', 'Projects'],
            $this->texts($cv->querySelectorAll('h2')),
        );
        $this->assertSame('header', $cv->body->firstElementChild->localName);
    }

    public function test_empty_cv_sections_are_left_out_entirely(): void
    {
        $this->masterProfile->update(['skills' => []]);
        $this->masterProfile->experiences()->create($this->experience());

        $cv = $this->tailor()['cv'];

        $this->assertSame(['Professional Summary', 'Experience'], $this->texts($this->document($cv)->querySelectorAll('h2')));
        $this->assertStringNotContainsString('Skills', $cv);
        $this->assertStringNotContainsString('Education', $cv);
        $this->assertStringNotContainsString('Projects', $cv);
    }

    public function test_cv_dates_show_month_and_year_or_present(): void
    {
        $this->masterProfile->experiences()->create($this->experience(['start_date' => '2022-01-01', 'end_date' => null, 'is_current' => true]));
        $this->masterProfile->educations()->create($this->education(['start_date' => '2018-08-01', 'end_date' => '2022-07-01', 'is_current' => false]));
        $this->masterProfile->projects()->create($this->project(['name' => 'Undated side project', 'start_date' => null, 'end_date' => null]));

        $cv = $this->document($this->tailor()['cv']);

        $this->assertSame('Jan 2022 – Present', $this->text($cv->querySelector('.experience .dates')));
        $this->assertSame('Aug 2018 – Jul 2022', $this->text($cv->querySelector('.education .dates')));
        $this->assertNull($cv->querySelector('.project .dates'));
    }

    public function test_a_finished_entry_shows_its_end_month(): void
    {
        $this->masterProfile->experiences()->create($this->experience(['start_date' => '2022-01-01', 'end_date' => '2024-03-01', 'is_current' => false]));

        $cv = $this->document($this->tailor()['cv']);

        $this->assertSame('Jan 2022 – Mar 2024', $this->text($cv->querySelector('.experience .dates')));
    }

    public function test_skills_take_one_line_per_category(): void
    {
        $cv = $this->document($this->tailor()['cv']);

        $this->assertSame(
            ['Backend: PHP, Laravel', 'Tools: Docker'],
            $this->texts($cv->querySelectorAll('.skills .skill-line')),
        );
    }

    public function test_the_photo_sits_in_the_cv_header_when_show_photo_is_on_and_never_on_the_cover_letter(): void
    {
        $this->masterProfile->replacePhoto(UploadedFile::fake()->image('me.jpg', 400, 400));
        $this->masterProfile->update(['show_photo' => true]);

        $documents = $this->tailor();
        $photo = $this->document($documents['cv'])->querySelector('header img.photo');

        $this->assertNotNull($photo);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $photo->getAttribute('src'));
        $this->assertStringNotContainsString('<img', $documents['coverLetter']);
    }

    public function test_the_photo_is_left_out_when_show_photo_is_off(): void
    {
        $this->masterProfile->replacePhoto(UploadedFile::fake()->image('me.jpg', 400, 400));

        $documents = $this->tailor();

        $this->assertStringNotContainsString('<img', $documents['cv']);
        $this->assertStringNotContainsString('<img', $documents['coverLetter']);
    }

    public function test_show_photo_without_an_uploaded_photo_renders_no_image(): void
    {
        $this->masterProfile->update(['show_photo' => true]);

        $this->assertStringNotContainsString('<img', $this->tailor()['cv']);
    }

    public function test_the_cover_letter_runs_letterhead_date_subject_greeting_paragraphs_and_sign_off(): void
    {
        $coverLetter = $this->document($this->tailor(['title' => 'Backend Engineer', 'company_name' => 'Globex'])['coverLetter']);

        $this->assertSame('Ahmad Zakky', $this->text($coverLetter->querySelector('header h1')));
        $this->assertSame([
            '16 September 2026',
            'Re: Application for Backend Engineer',
            'Dear Globex Hiring Team',
            'Opening paragraph.',
            'First body paragraph.',
            'Second body paragraph.',
            'Closing paragraph.',
            'Sincerely, Ahmad Zakky',
        ], $this->texts($coverLetter->querySelectorAll('main > *')));
    }

    public function test_re_rendering_uses_only_the_snapshot_whatever_changed_in_the_master_profile(): void
    {
        $this->addEveryKindOfEntry();
        $this->masterProfile->replacePhoto(UploadedFile::fake()->image('me.jpg', 400, 400));
        $this->masterProfile->update(['show_photo' => true]);

        $original = $this->tailor();
        $application = Application::sole();

        $this->masterProfile->update(['full_name' => 'Someone Else', 'skills' => [['category' => 'Other', 'skills' => ['COBOL']]]]);
        $this->masterProfile->replacePhoto(UploadedFile::fake()->image('new.png', 100, 100));
        $this->masterProfile->entries()->each(fn (MasterProfileEntry $entry) => $entry->delete());

        $tailored = $application->currentTailoredApplication;
        $paths = app(DocumentRenderer::class)->render($application, $tailored->cv_data, $tailored->cover_letter_data);

        $this->assertNotSame($tailored->cv_pdf_path, $paths['cv_pdf_path']);
        $this->assertSame($original['cv'], $this->pdfRenderer->rendered[$paths['cv_pdf_path']]);
        $this->assertSame($original['coverLetter'], $this->pdfRenderer->rendered[$paths['cover_letter_pdf_path']]);
    }

    public function test_both_documents_use_the_bundled_inter_font_and_load_nothing_external(): void
    {
        $this->addEveryKindOfEntry();

        $this->assertNotEmpty(glob(resource_path('fonts/inter/*.woff2')));

        foreach ($this->tailor() as $html) {
            $this->assertStringContainsString('@font-face', $html);
            $this->assertStringContainsString('src: url(data:font/woff2;base64,', $html);
            $this->assertMatchesRegularExpression("/font-family:\s*'Inter'/", $html);
            $this->assertDoesNotMatchRegularExpression('/<link\b|<script\b|@import|url\(\s*[\'"]?(https?:)?\/\//i', $html);
            $this->assertDoesNotMatchRegularExpression('/\bsrc="(https?:)?\/\//i', $html);
        }
    }

    public function test_a_real_pdf_renders_through_browsershot(): void
    {
        if (! $this->chromiumIsAvailable()) {
            $this->markTestSkipped('Chromium (Puppeteer) is not available for Browsershot.');
        }

        $this->addEveryKindOfEntry();
        $documents = $this->tailor();

        $path = (new BrowsershotPdfRenderer)->render($documents['cv'], 'smoke/cv.pdf');

        $this->assertStringStartsWith('%PDF-', Storage::disk(PdfRenderer::DISK)->get($path));
    }

    /**
     * Tailors a new Application for a Job and returns the HTML handed to the PdfRenderer.
     *
     * @param  array<string, mixed>  $job
     * @return array{cv: string, coverLetter: string}
     */
    private function tailor(array $job = []): array
    {
        $application = Job::create([
            'platform' => 'glints',
            'external_id' => 'job-'.(Job::count() + 1),
            'title' => 'Software Engineer',
            'company_name' => 'Acme',
            'country_code' => 'ID',
            'location' => 'Jakarta',
            'description' => 'Build things.',
            'url' => 'https://glints.com/id/opportunities/jobs/1',
            'work_arrangement' => 'onsite',
            'job_type' => 'full_time',
            'status' => 'open',
            'posted_date' => now(),
            'raw_payload' => [],
            ...$job,
        ])->application()->create(['status' => 'pending_tailoring']);

        TailorApplication::dispatchSync($application);

        $tailored = $application->refresh()->currentTailoredApplication;
        $this->assertNotNull($tailored, 'Tailoring did not produce a TailoredApplication.');

        return [
            'cv' => $this->pdfRenderer->rendered[$tailored->cv_pdf_path],
            'coverLetter' => $this->pdfRenderer->rendered[$tailored->cover_letter_pdf_path],
        ];
    }

    /**
     * A valid Gemini response built from the MasterProfile as it is now.
     *
     * @return array<string, mixed>
     */
    private function echoingGeminiResponse(): array
    {
        $masterProfile = MasterProfile::with(['experiences', 'educations', 'projects'])->sole();

        $content = [
            'professional_summary' => $masterProfile->professional_summary,
            'entries' => $masterProfile->entries()->map(fn (MasterProfileEntry $entry) => [
                'entry_id' => $entry->tailoringEntryId(),
                'description' => $entry->reframeableText()['description'] ?? '',
                'achievements' => $entry->reframeableText()['achievements'] ?? [],
            ])->all(),
            'cover_letter' => [
                'opening_paragraph' => 'Opening paragraph.',
                'body_paragraphs' => ['First body paragraph.', 'Second body paragraph.'],
                'closing_paragraph' => 'Closing paragraph.',
            ],
        ];

        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => json_encode($content)]]]]]];
    }

    private function addEveryKindOfEntry(): void
    {
        $this->masterProfile->experiences()->create($this->experience());
        $this->masterProfile->educations()->create($this->education());
        $this->masterProfile->projects()->create($this->project());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function experience(array $overrides = []): array
    {
        return [
            'title' => 'Backend Engineer',
            'company' => 'Acme',
            'location' => 'Jakarta',
            'start_date' => '2021-03-01',
            'end_date' => null,
            'is_current' => true,
            'employment_type' => 'full_time',
            'description' => 'Built the payments platform.',
            'achievements' => ['Cut checkout latency by 40%'],
            'skills_used' => ['PHP'],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function education(array $overrides = []): array
    {
        return [
            'institution' => 'Universitas Indonesia',
            'degree' => 'Bachelor of Computer Science',
            'field_of_study' => 'Computer Science',
            'start_date' => '2015-08-01',
            'end_date' => '2019-07-01',
            'is_current' => false,
            'description' => 'Thesis on distributed queues.',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function project(array $overrides = []): array
    {
        return [
            'name' => 'Applyr',
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-01',
            'is_current' => false,
            'tech_stack' => ['Laravel'],
            'link' => 'https://github.com/zakky/applyr',
            'description' => 'Job-application assistant.',
            'achievements' => ['Polls two job boards'],
            ...$overrides,
        ];
    }

    private function document(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    /**
     * An element's text with whitespace collapsed.
     */
    private function text(?Element $element): ?string
    {
        return $element === null ? null : trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }

    /**
     * @param  iterable<Element>  $elements
     * @return list<string>
     */
    private function texts(iterable $elements): array
    {
        return collect($elements)->map(fn (Element $element) => $this->text($element))->values()->all();
    }

    private function chromiumIsAvailable(): bool
    {
        try {
            // Puppeteer installed is not enough: its browser must have been downloaded too.
            return Process::path(base_path())
                ->run(['node', '-e', "require('fs').accessSync(require('puppeteer').executablePath())"])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
