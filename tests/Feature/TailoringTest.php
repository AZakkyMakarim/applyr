<?php

namespace Tests\Feature;

use App\Ai\AiProvider;
use App\Ai\Exceptions\ProviderException;
use App\Ai\Exceptions\RateLimitedException;
use App\Enums\ApplicationStatus;
use App\Jobs\TailorApplication;
use App\Models\Application;
use App\Models\Education;
use App\Models\Experience;
use App\Models\Job;
use App\Models\MasterProfile;
use App\Models\Project;
use App\Models\SearchProfile;
use App\Models\TailoredApplication;
use App\Pdf\PdfRenderer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakePdfRenderer;
use Tests\TestCase;

class TailoringTest extends TestCase
{
    private const SOFTWARE_ENGINEER_ID = 'bc932c0b-3749-4e8c-8195-a11831888002';

    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-model:generateContent';

    private const TELEGRAM_URL = 'https://api.telegram.org/botbot-token/sendMessage';

    private FakePdfRenderer $pdfRenderer;

    private MasterProfile $masterProfile;

    private Experience $experience;

    private Education $education;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Storage::fake(PdfRenderer::DISK);
        $this->freezeTime();

        config([
            'services.gemini.api_key' => 'gemini-key',
            'services.gemini.model' => 'gemini-test-model',
            'services.telegram.bot_token' => 'bot-token',
            'services.telegram.chat_id' => '12345',
        ]);

        $this->pdfRenderer = new FakePdfRenderer;
        $this->app->instance(PdfRenderer::class, $this->pdfRenderer);

        $this->masterProfile = MasterProfile::create([
            'full_name' => 'Ahmad Zakky',
            'email' => 'zakky@example.com',
            'phone' => '+62 812 0000 0000',
            'location' => 'Jakarta, Indonesia',
            'professional_summary' => 'Backend engineer who ships.',
            'links' => [['label' => 'GitHub', 'url' => 'https://github.com/zakky']],
            'skills' => [['category' => 'Backend', 'skills' => ['PHP', 'Laravel']]],
            'photo_path' => 'master-profile/photo.jpg',
            'show_photo' => true,
        ]);

        $this->experience = $this->masterProfile->experiences()->create([
            'title' => 'Backend Engineer',
            'company' => 'Acme',
            'location' => 'Jakarta',
            'start_date' => '2021-03-01',
            'end_date' => null,
            'is_current' => true,
            'employment_type' => 'full_time',
            'description' => 'Built the payments platform.',
            'achievements' => ['Cut checkout latency by 40%', 'Led the Laravel upgrade'],
            'skills_used' => ['PHP', 'MySQL'],
        ]);

        $this->education = $this->masterProfile->educations()->create([
            'institution' => 'Universitas Indonesia',
            'degree' => 'Bachelor of Computer Science',
            'field_of_study' => 'Computer Science',
            'start_date' => '2015-08-01',
            'end_date' => '2019-07-01',
            'is_current' => false,
            'description' => 'Thesis on distributed queues.',
        ]);

        $this->project = $this->masterProfile->projects()->create([
            'name' => 'Applyr',
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-01',
            'is_current' => false,
            'tech_stack' => ['Laravel'],
            'link' => 'https://github.com/zakky/applyr',
            'description' => 'Job-application assistant.',
            'achievements' => ['Polls two job boards'],
        ]);
    }

    public function test_a_poll_that_creates_an_application_tailors_it_into_needs_review(): void
    {
        $this->fakePlatforms();

        $this->artisan('applyr:poll')->assertSuccessful();

        $application = $this->softwareEngineerApplication();
        $tailored = $application->currentTailoredApplication;

        $this->assertSame(ApplicationStatus::NeedsReview, $application->status);
        $this->assertNotNull($tailored);
        $this->assertTrue($tailored->application->is($application));
        Storage::disk(PdfRenderer::DISK)->assertExists($tailored->cv_pdf_path);
        Storage::disk(PdfRenderer::DISK)->assertExists($tailored->cover_letter_pdf_path);
        $this->assertNotSame($tailored->cv_pdf_path, $tailored->cover_letter_pdf_path);
        $this->assertArrayHasKey($tailored->cv_pdf_path, $this->pdfRenderer->rendered);
        $this->assertArrayHasKey($tailored->cover_letter_pdf_path, $this->pdfRenderer->rendered);
    }

    public function test_the_gemini_request_carries_the_response_schema_and_every_entry_with_its_id_and_facts(): void
    {
        $this->fakePlatforms();

        $this->artisan('applyr:poll')->assertSuccessful();

        $requests = Http::recorded(fn (Request $request) => $request->url() === self::GEMINI_URL);
        $this->assertCount(3, $requests);

        [$request] = $requests->first(fn (array $pair) => str_contains($this->promptOf($pair[0]), 'PT. BEONE OPTIMA SOLUSI'));
        $this->assertSame('gemini-key', $request->header('x-goog-api-key')[0]);

        $generationConfig = $request['generationConfig'];
        $this->assertSame('application/json', $generationConfig['responseMimeType']);
        $schema = $generationConfig['responseSchema'];
        $this->assertEqualsCanonicalizing(['professional_summary', 'entries', 'cover_letter'], array_keys($schema['properties']));
        $this->assertEqualsCanonicalizing(
            ['entry_id', 'description', 'achievements'],
            array_keys($schema['properties']['entries']['items']['properties']),
        );
        $this->assertEqualsCanonicalizing(
            ['opening_paragraph', 'body_paragraphs', 'closing_paragraph'],
            array_keys($schema['properties']['cover_letter']['properties']),
        );

        $prompt = $this->promptOf($request);
        $this->assertStringContainsString('software engineer', $prompt);
        $this->assertStringContainsString('Mengembangkan, memelihara', $prompt);

        $entries = collect($this->promptContext($prompt)['entries'])->keyBy('entry_id');
        $this->assertEqualsCanonicalizing(
            ["experience:{$this->experience->id}", "education:{$this->education->id}", "project:{$this->project->id}"],
            $entries->keys()->all(),
        );

        $experience = $entries["experience:{$this->experience->id}"];
        $this->assertSame('Backend Engineer', $experience['facts']['title']);
        $this->assertSame('Acme', $experience['facts']['company']);
        $this->assertSame('2021-03', $experience['facts']['start_date']);
        $this->assertTrue($experience['facts']['is_current']);
        $this->assertSame('Built the payments platform.', $experience['reframeable']['description']);
        $this->assertSame(['Cut checkout latency by 40%', 'Led the Laravel upgrade'], $experience['reframeable']['achievements']);

        $this->assertSame('Universitas Indonesia', $entries["education:{$this->education->id}"]['facts']['institution']);
        $this->assertSame('Applyr', $entries["project:{$this->project->id}"]['facts']['name']);
    }

    public function test_cv_data_copies_facts_and_takes_only_the_reframed_text_from_gemini(): void
    {
        $this->fakePlatforms();

        $this->artisan('applyr:poll')->assertSuccessful();

        $cv = $this->softwareEngineerApplication()->currentTailoredApplication->cv_data;

        $this->assertSame([
            'full_name' => 'Ahmad Zakky',
            'email' => 'zakky@example.com',
            'phone' => '+62 812 0000 0000',
            'location' => 'Jakarta, Indonesia',
            'links' => [['label' => 'GitHub', 'url' => 'https://github.com/zakky']],
            'professional_summary' => 'Reframed summary for PT. BEONE OPTIMA SOLUSI.',
            'photo_path' => 'master-profile/photo.jpg',
            'show_photo' => true,
        ], $cv['personal_info']);

        $this->assertSame([['category' => 'Backend', 'skills' => ['PHP', 'Laravel']]], $cv['skills']);

        $this->assertSame([
            'entry_id' => "experience:{$this->experience->id}",
            'title' => 'Backend Engineer',
            'company' => 'Acme',
            'location' => 'Jakarta',
            'employment_type' => 'full_time',
            'start_date' => '2021-03',
            'end_date' => null,
            'is_current' => true,
            'skills_used' => ['PHP', 'MySQL'],
            'description' => 'Reframed experience description.',
            'achievements' => ['Reframed latency achievement'],
        ], $cv['experiences']["experience:{$this->experience->id}"]);

        $this->assertSame([
            'entry_id' => "education:{$this->education->id}",
            'institution' => 'Universitas Indonesia',
            'degree' => 'Bachelor of Computer Science',
            'field_of_study' => 'Computer Science',
            'start_date' => '2015-08',
            'end_date' => '2019-07',
            'is_current' => false,
            'description' => 'Reframed education description.',
        ], $cv['educations']["education:{$this->education->id}"]);

        $this->assertSame([
            'entry_id' => "project:{$this->project->id}",
            'name' => 'Applyr',
            'start_date' => '2026-01',
            'end_date' => '2026-06',
            'is_current' => false,
            'tech_stack' => ['Laravel'],
            'link' => 'https://github.com/zakky/applyr',
            'description' => 'Reframed project description.',
            'achievements' => ['Reframed job boards achievement'],
        ], $cv['projects']["project:{$this->project->id}"]);
    }

    public function test_cover_letter_data_fills_the_fact_lines_from_the_job_and_master_profile(): void
    {
        $this->fakePlatforms();

        $this->artisan('applyr:poll')->assertSuccessful();

        $coverLetter = $this->softwareEngineerApplication()->currentTailoredApplication->cover_letter_data;

        $this->assertSame([
            'full_name' => 'Ahmad Zakky',
            'email' => 'zakky@example.com',
            'phone' => '+62 812 0000 0000',
            'location' => 'Jakarta, Indonesia',
            'links' => [['label' => 'GitHub', 'url' => 'https://github.com/zakky']],
        ], $coverLetter['letterhead']);
        $this->assertSame(now()->toDateString(), $coverLetter['date']);
        $this->assertSame('Re: Application for software engineer', $coverLetter['subject']);
        $this->assertSame('Dear PT. BEONE OPTIMA SOLUSI Hiring Team', $coverLetter['greeting']);
        $this->assertSame('Sincerely, Ahmad Zakky', $coverLetter['sign_off']);
        $this->assertSame('Opening for PT. BEONE OPTIMA SOLUSI.', $coverLetter['opening_paragraph']);
        $this->assertSame(['First body paragraph.', 'Second body paragraph.'], $coverLetter['body_paragraphs']);
        $this->assertSame('Closing paragraph.', $coverLetter['closing_paragraph']);
    }

    public function test_the_rendered_documents_come_from_the_snapshot(): void
    {
        $this->fakePlatforms();

        $this->artisan('applyr:poll')->assertSuccessful();

        $tailored = $this->softwareEngineerApplication()->currentTailoredApplication;
        $cvHtml = $this->pdfRenderer->rendered[$tailored->cv_pdf_path];
        $coverLetterHtml = $this->pdfRenderer->rendered[$tailored->cover_letter_pdf_path];

        $this->assertStringContainsString('Reframed summary for PT. BEONE OPTIMA SOLUSI.', $cvHtml);
        $this->assertStringContainsString('Backend Engineer', $cvHtml);
        $this->assertStringContainsString('Reframed latency achievement', $cvHtml);
        $this->assertStringNotContainsString('Led the Laravel upgrade', $cvHtml);

        $this->assertStringContainsString('Re: Application for software engineer', $coverLetterHtml);
        $this->assertStringContainsString('Dear PT. BEONE OPTIMA SOLUSI Hiring Team', $coverLetterHtml);
        $this->assertStringContainsString('Second body paragraph.', $coverLetterHtml);
        $this->assertStringContainsString('Sincerely, Ahmad Zakky', $coverLetterHtml);
    }

    public function test_a_review_notification_is_sent_for_each_tailored_application(): void
    {
        $this->fakePlatforms();

        $this->artisan('applyr:poll')->assertSuccessful();

        $messages = Http::recorded(fn (Request $request) => $request->url() === self::TELEGRAM_URL)
            ->map(fn (array $pair) => $pair[0]['text']);

        $this->assertCount(3, $messages);
        $message = $messages->first(fn (string $text) => str_contains($text, 'PT. BEONE OPTIMA SOLUSI'));
        $this->assertStringContainsString('software engineer', $message);
        $this->assertStringContainsString('Glints', $message);
        $this->assertStringContainsString(route('dashboard'), $message);
    }

    public function test_tailoring_exits_without_calling_gemini_once_the_application_has_left_pending_tailoring(): void
    {
        Http::fake();
        $job = Job::create([
            'platform' => 'glints',
            'external_id' => 'already-rejected',
            'title' => 'Software Engineer',
            'company_name' => 'Acme',
            'country_code' => 'ID',
            'location' => 'Jakarta',
            'description' => 'Build things.',
            'url' => 'https://glints.com/id/opportunities/jobs/already-rejected',
            'work_arrangement' => 'onsite',
            'job_type' => 'full_time',
            'status' => 'open',
            'posted_date' => now(),
            'raw_payload' => [],
        ]);
        $application = $job->application()->create(['status' => ApplicationStatus::Rejected]);

        TailorApplication::dispatch($application);

        Http::assertNothingSent();
        $this->assertSame(ApplicationStatus::Rejected, $application->fresh()->status);
        $this->assertSame(0, TailoredApplication::count());
        $this->assertSame([], $this->pdfRenderer->rendered);
    }

    public function test_gemini_reports_a_429_as_rate_limited(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429]], 429)]);

        $this->expectException(RateLimitedException::class);

        app(AiProvider::class)->generate('prompt', ['type' => 'OBJECT']);
    }

    public function test_gemini_reports_other_failures_as_provider_errors(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['code' => 500]], 500)
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'not json']]]]]]),
        ]);

        foreach ([1, 2] as $attempt) {
            try {
                app(AiProvider::class)->generate('prompt', ['type' => 'OBJECT']);
                $this->fail("Attempt {$attempt} should have thrown.");
            } catch (ProviderException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function fakePlatforms(): void
    {
        Http::fake([
            'id.jobstreet.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/JobStreet/search-jobs-no-results.json'))),
            'glints.com/api/v2/graphql' => fn (Request $request) => match ($request['operationName']) {
                'searchJobs' => Http::response($this->glintsFixture('search-jobs.json')),
                'searchHierarchicalLocations' => Http::response($this->glintsFixture('search-locations.json')),
                'getJobById' => Http::response($this->glintsFixture("job-detail-{$request['variables']['id']}.json")),
            },
            'generativelanguage.googleapis.com/*' => fn (Request $request) => Http::response($this->geminiResponse($request)),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);
    }

    /**
     * A valid Gemini response whose summary and opening name the Job's company, so tests can tell Jobs apart.
     *
     * @return array<string, mixed>
     */
    private function geminiResponse(Request $request): array
    {
        $company = $this->promptContext($this->promptOf($request))['job']['company_name'];

        $content = [
            'professional_summary' => "Reframed summary for {$company}.",
            'entries' => [
                ['entry_id' => "experience:{$this->experience->id}", 'description' => 'Reframed experience description.', 'achievements' => ['Reframed latency achievement']],
                ['entry_id' => "education:{$this->education->id}", 'description' => 'Reframed education description.', 'achievements' => []],
                ['entry_id' => "project:{$this->project->id}", 'description' => 'Reframed project description.', 'achievements' => ['Reframed job boards achievement']],
            ],
            'cover_letter' => [
                'opening_paragraph' => "Opening for {$company}.",
                'body_paragraphs' => ['First body paragraph.', 'Second body paragraph.'],
                'closing_paragraph' => 'Closing paragraph.',
            ],
        ];

        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => json_encode($content)]]]]]];
    }

    private function promptOf(Request $request): string
    {
        return $request['contents'][0]['parts'][0]['text'];
    }

    /**
     * The JSON context block the prompt ends with.
     *
     * @return array<string, mixed>
     */
    private function promptContext(string $prompt): array
    {
        return json_decode(substr($prompt, strpos($prompt, '{')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function softwareEngineerApplication(): Application
    {
        return Job::where('external_id', self::SOFTWARE_ENGINEER_ID)->sole()->application;
    }

    /**
     * @return array<string, mixed>
     */
    private function glintsFixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/Glints/{$name}")), true);
    }
}
