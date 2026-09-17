<?php

namespace Tests\Feature;

use App\Ai\AiProvider;
use App\Ai\Exceptions\MalformedResponseException;
use App\Ai\Exceptions\ProviderException;
use App\Ai\Exceptions\RateLimitedException;
use App\Enums\ApplicationAction;
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
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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
        Storage::disk(MasterProfile::PHOTO_DISK)->put('master-profile/photo.jpg', 'photo-bytes');
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

        $application = $this->softwareEngineerApplication();
        $cv = $application->currentTailoredApplication->cv_data;
        $photoPath = $cv['personal_info']['photo_path'];

        // The snapshot keeps its own copy of the photo rather than pointing at the MasterProfile's,
        // so removing the MasterProfile photo leaves it in place.
        $this->assertStringStartsWith("tailored-applications/{$application->id}/photos/", $photoPath);
        $this->assertSame('photo-bytes', Storage::disk(MasterProfile::PHOTO_DISK)->get($photoPath));

        MasterProfile::sole()->removePhoto();
        $this->assertSame('photo-bytes', Storage::disk(MasterProfile::PHOTO_DISK)->get($photoPath));

        $this->assertSame([
            'full_name' => 'Ahmad Zakky',
            'email' => 'zakky@example.com',
            'phone' => '+62 812 0000 0000',
            'location' => 'Jakarta, Indonesia',
            'links' => [['label' => 'GitHub', 'url' => 'https://github.com/zakky']],
            'professional_summary' => 'Reframed summary for PT. BEONE OPTIMA SOLUSI.',
            'photo_path' => $photoPath,
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
        $application = Job::where('company_name', 'PT. BEONE OPTIMA SOLUSI')->sole()->application;
        $this->assertStringContainsString(route('applications.show', $application), $message);
    }

    public function test_tailoring_exits_without_calling_gemini_once_the_application_has_left_pending_tailoring(): void
    {
        Http::fake();
        $application = $this->applicationFor('already-rejected', ApplicationStatus::Rejected);

        TailorApplication::dispatch($application);

        Http::assertNothingSent();
        $this->assertSame(ApplicationStatus::Rejected, $application->fresh()->status);
        $this->assertSame(0, TailoredApplication::count());
        $this->assertSame([], $this->pdfRenderer->rendered);
    }

    public function test_tailoring_without_a_saved_master_profile_fails_so_it_can_be_retried(): void
    {
        Http::fake();
        $this->masterProfile->delete();
        $application = $this->applicationFor('no-master-profile');

        $this->runTailoring($application)->assertNotReleased();

        Http::assertNothingSent();
        $application->refresh();
        $this->assertSame(ApplicationStatus::TailoringFailed, $application->status);
        $this->assertTrue(ApplicationAction::Retry->isAvailableFor($application));
        $this->assertSame(0, TailoredApplication::count());
        $this->assertSame([], $this->pdfRenderer->rendered);
    }

    public function test_rejecting_a_pending_application_before_its_tailoring_runs_prevents_any_gemini_call(): void
    {
        Http::fake();
        $application = $this->applicationFor('rejected-while-queued');

        $this->patch(route('applications.transition', [$application, ApplicationAction::Reject]))->assertRedirect();
        TailorApplication::dispatch($application);

        Http::assertNothingSent();
        $this->assertSame(ApplicationStatus::Rejected, $application->fresh()->status);
        $this->assertSame(0, TailoredApplication::count());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function tailoringOutcomes(): array
    {
        return [
            'valid response' => [true],
            'every attempt invalid' => [false],
        ];
    }

    #[DataProvider('tailoringOutcomes')]
    public function test_a_reject_while_tailoring_is_in_flight_is_not_overwritten(bool $valid): void
    {
        $application = $this->applicationFor('rejected-in-flight');
        $content = $this->validContent();
        $content['professional_summary'] = $valid ? 'Valid summary.' : '';

        Http::fake([
            'generativelanguage.googleapis.com/*' => function () use ($application, $content) {
                $this->patch(route('applications.transition', [$application, ApplicationAction::Reject]));

                return Http::response($this->geminiEnvelope($content));
            },
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        TailorApplication::dispatch($application);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Rejected, $application->status);
        $this->assertSame(ApplicationStatus::PendingTailoring, $application->previous_status);
        $this->assertNotNull($application->rejected_at);
        $this->assertNull($application->current_tailored_application_id);
        $this->assertSame(0, TailoredApplication::count());
        Http::assertNotSent(fn (Request $request) => $request->url() === self::TELEGRAM_URL);
    }

    public function test_a_successful_regeneration_replaces_the_current_tailored_application(): void
    {
        $application = $this->applicationFor('regenerated');
        $old = $this->giveEarlierTailoredApplication($application);
        $application->update(['edited_by_user' => true]);
        $content = $this->validContent();
        $content['professional_summary'] = 'Regenerated summary.';
        $this->fakeGeminiSequence($content);

        $this->patch(route('applications.transition', [$application, ApplicationAction::Regenerate]))->assertRedirect();

        $application->refresh();
        $this->assertSame(ApplicationStatus::NeedsReview, $application->status);
        $this->assertNotSame($old->id, $application->current_tailored_application_id);
        $this->assertSame('Regenerated summary.', $application->currentTailoredApplication->cv_data['personal_info']['professional_summary']);
        $this->assertSame(2, $application->tailoredApplications()->count());
        $this->assertFalse($application->edited_by_user, 'Fresh AI documents are no longer edited by the user.');
    }

    public function test_a_failed_regeneration_leaves_the_old_documents_current_and_viewable(): void
    {
        $application = $this->applicationFor('regeneration-failed');
        $old = $this->giveEarlierTailoredApplication($application);
        $application->update(['edited_by_user' => true]);
        $invalid = $this->validContent();
        $invalid['professional_summary'] = '';
        $this->fakeGeminiSequence($invalid, $invalid, $invalid);

        $this->patch(route('applications.transition', [$application, ApplicationAction::Regenerate]))->assertRedirect();

        $application->refresh();
        $this->assertSame(ApplicationStatus::TailoringFailed, $application->status);
        $this->assertSame($old->id, $application->current_tailored_application_id);
        $this->assertTrue($application->edited_by_user, 'The edited documents are still the current ones.');
        $this->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('src="'.route('applications.cv', $application).'"', false);
        $this->assertSame('%PDF-old-cv', $this->get(route('applications.cv', $application))->streamedContent());
        $this->assertSame('%PDF-old-cover-letter', $this->get(route('applications.cover-letter', $application))->streamedContent());
    }

    public function test_a_valid_first_attempt_is_used_without_regenerating(): void
    {
        $application = $this->applicationFor('valid-first');
        $this->fakeGeminiSequence($this->validContent());

        TailorApplication::dispatch($application);

        $this->assertCount(1, $this->geminiRequests());
        $application->refresh();
        $this->assertSame(ApplicationStatus::NeedsReview, $application->status);
        $this->assertSame(1, TailoredApplication::count());
        $this->assertSame('Valid summary.', $application->currentTailoredApplication->cv_data['personal_info']['professional_summary']);
    }

    public function test_an_invalid_attempt_is_discarded_and_a_fresh_one_generated(): void
    {
        $application = $this->applicationFor('invalid-then-valid');
        $invalid = $this->validContent();
        $invalid['professional_summary'] = 'Discarded summary.';
        $invalid['entries'][0]['entry_id'] = 'experience:999999';
        $this->fakeGeminiSequence($invalid, $this->validContent());

        TailorApplication::dispatch($application);

        $this->assertCount(2, $this->geminiRequests());
        $application->refresh();
        $this->assertSame(ApplicationStatus::NeedsReview, $application->status);
        $this->assertSame(1, TailoredApplication::count());
        $this->assertSame('Valid summary.', $application->currentTailoredApplication->cv_data['personal_info']['professional_summary']);
        $this->assertCount(2, $this->pdfRenderer->rendered);
        foreach ($this->pdfRenderer->rendered as $html) {
            $this->assertStringNotContainsString('Discarded summary.', $html);
        }
        Http::assertSent(fn (Request $request) => $request->url() === self::TELEGRAM_URL);
    }

    public function test_tailoring_fails_once_every_attempt_is_invalid(): void
    {
        $application = $this->applicationFor('always-invalid');
        $invalid = $this->validContent();
        $invalid['professional_summary'] = '';
        $this->fakeGeminiSequence($invalid, $invalid, $invalid, $this->validContent());

        TailorApplication::dispatch($application);

        $this->assertCount(3, $this->geminiRequests());
        $application->refresh();
        $this->assertSame(ApplicationStatus::TailoringFailed, $application->status);
        $this->assertNull($application->current_tailored_application_id);
        $this->assertSame(0, TailoredApplication::count());
        $this->assertSame([], $this->pdfRenderer->rendered);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::TELEGRAM_URL);
    }

    public function test_unreadable_content_is_discarded_and_a_fresh_one_generated(): void
    {
        $application = $this->applicationFor('unreadable-then-valid');
        $this->fakeGeminiSequence('not json', '"a string"', $this->validContent());

        TailorApplication::dispatch($application);

        $this->assertCount(3, $this->geminiRequests());
        $application->refresh();
        $this->assertSame(ApplicationStatus::NeedsReview, $application->status);
        $this->assertSame('Valid summary.', $application->currentTailoredApplication->cv_data['personal_info']['professional_summary']);
    }

    public function test_tailoring_fails_once_every_attempt_is_unreadable_or_invalid(): void
    {
        $application = $this->applicationFor('always-unreadable');
        $invalid = $this->validContent();
        $invalid['professional_summary'] = '';
        $this->fakeGeminiSequence('not json', $invalid, 'still not json', $this->validContent());

        TailorApplication::dispatch($application);

        $this->assertCount(3, $this->geminiRequests());
        $application->refresh();
        $this->assertSame(ApplicationStatus::TailoringFailed, $application->status);
        $this->assertSame(0, TailoredApplication::count());
        $this->assertSame([], $this->pdfRenderer->rendered);
    }

    public function test_a_gemini_error_fails_tailoring_without_regenerating(): void
    {
        $application = $this->applicationFor('gemini-error');
        $this->fakeGeminiSequence(500, $this->validContent());

        try {
            $this->runTailoring($application);
            $this->fail('A Gemini HTTP error should fail the job.');
        } catch (ProviderException $e) {
            $this->assertNotInstanceOf(MalformedResponseException::class, $e);
        }

        $this->assertCount(1, $this->geminiRequests());
    }

    public function test_tailoring_that_fails_on_a_gemini_error_marks_the_application_tailoring_failed(): void
    {
        $application = $this->applicationFor('gemini-error-failed');
        $this->fakeGeminiSequence(500);

        try {
            TailorApplication::dispatch($application);
            $this->fail('A Gemini HTTP error should fail the job.');
        } catch (ProviderException) {
        }

        $this->assertSame(ApplicationStatus::TailoringFailed, $application->fresh()->status);
        $this->assertSame(0, TailoredApplication::count());
    }

    public function test_tailoring_that_fails_to_render_its_pdfs_marks_the_application_tailoring_failed(): void
    {
        $application = $this->applicationFor('render-error');
        $this->fakeGeminiSequence($this->validContent());
        $this->app->instance(PdfRenderer::class, new class implements PdfRenderer
        {
            public function render(string $html, string $path): string
            {
                throw new RuntimeException('Chrome crashed.');
            }
        });

        try {
            TailorApplication::dispatch($application);
            $this->fail('A PDF render error should fail the job.');
        } catch (RuntimeException) {
        }

        $this->assertSame(ApplicationStatus::TailoringFailed, $application->fresh()->status);
        $this->assertSame(0, TailoredApplication::count());
    }

    public function test_tailoring_that_fails_after_a_reject_leaves_the_application_rejected(): void
    {
        $application = $this->applicationFor('rejected-then-failed');
        $tailoring = new TailorApplication($application);
        $application->transitionTo(ApplicationStatus::Rejected);

        $tailoring->failed(new ProviderException('Gemini is down.'));

        $this->assertSame(ApplicationStatus::Rejected, $application->fresh()->status);
    }

    public function test_the_number_of_attempts_comes_from_the_regeneration_limit(): void
    {
        config(['applyr.tailoring.regeneration_limit' => 2]);
        $application = $this->applicationFor('limit-two');
        $invalid = $this->validContent();
        unset($invalid['cover_letter']);
        $this->fakeGeminiSequence($invalid, $invalid, $this->validContent());

        TailorApplication::dispatch($application);

        $this->assertCount(2, $this->geminiRequests());
        $this->assertSame(ApplicationStatus::TailoringFailed, $application->fresh()->status);
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $invalidate
     */
    #[DataProvider('invalidContent')]
    public function test_content_failing_fact_validation_never_becomes_a_tailored_application(Closure $invalidate): void
    {
        $application = $this->applicationFor('invalid');
        $this->fakeGeminiSequence(...array_fill(0, 3, $invalidate($this->validContent())));

        TailorApplication::dispatch($application);

        $this->assertSame(ApplicationStatus::TailoringFailed, $application->fresh()->status);
        $this->assertSame(0, TailoredApplication::count());
        $this->assertSame([], $this->pdfRenderer->rendered);
    }

    /**
     * Each case breaks one rule of an otherwise valid response, whose entries are the
     * experience, education and project in that order.
     *
     * @return array<string, array{Closure(array<string, mixed>): array<string, mixed>}>
     */
    public static function invalidContent(): array
    {
        $cases = [
            'unknown entry_id' => fn (array $c) => data_set($c, 'entries.2.entry_id', 'project:999999'),
            'entry_id of an unknown kind' => fn (array $c) => data_set($c, 'entries.1.entry_id', str_replace('education:', 'certificate:', $c['entries'][1]['entry_id'])),
            'duplicated entry_id' => fn (array $c) => data_set($c, 'entries.1.entry_id', $c['entries'][0]['entry_id']),
            'more achievements than the experience has' => fn (array $c) => data_set($c, 'entries.0.achievements', ['One', 'Two', 'Invented three']),
            'achievements on an education' => fn (array $c) => data_set($c, 'entries.1.achievements', ['Invented']),
            'empty achievement' => fn (array $c) => data_set($c, 'entries.0.achievements', ['', 'Reframed latency achievement']),
            'blank achievement' => fn (array $c) => data_set($c, 'entries.0.achievements', ['Reframed latency achievement', '   ']),
            'empty professional summary' => fn (array $c) => data_set($c, 'professional_summary', '   '),
            'empty opening paragraph' => fn (array $c) => data_set($c, 'cover_letter.opening_paragraph', ''),
            'empty body paragraph' => fn (array $c) => data_set($c, 'cover_letter.body_paragraphs.1', ''),
            'no body paragraphs' => fn (array $c) => data_set($c, 'cover_letter.body_paragraphs', []),
            'empty closing paragraph' => fn (array $c) => data_set($c, 'cover_letter.closing_paragraph', ' '),
            'missing professional summary' => fn (array $c) => Arr::except($c, 'professional_summary'),
            'missing entries' => fn (array $c) => Arr::except($c, 'entries'),
            'missing cover letter' => fn (array $c) => Arr::except($c, 'cover_letter'),
            'missing closing paragraph' => fn (array $c) => Arr::except($c, 'cover_letter.closing_paragraph'),
            'entry without an entry_id' => fn (array $c) => Arr::except($c, 'entries.0.entry_id'),
            'entry without achievements' => fn (array $c) => Arr::except($c, 'entries.2.achievements'),
            'summary that is not a string' => fn (array $c) => data_set($c, 'professional_summary', ['Not', 'a string']),
            'entries that are not a list' => fn (array $c) => data_set($c, 'entries', ['experience' => $c['entries'][0]]),
            'entry that is not an object' => fn (array $c) => data_set($c, 'entries.2', 'project'),
            'entry_id that is not a string' => fn (array $c) => data_set($c, 'entries.0.entry_id', 1),
            'null description' => fn (array $c) => data_set($c, 'entries.0.description', null),
            'description that is not a string' => fn (array $c) => data_set($c, 'entries.0.description', ['Built things.']),
            'achievements that are not a list' => fn (array $c) => data_set($c, 'entries.0.achievements', 'Cut checkout latency by 40%'),
            'achievement that is not a string' => fn (array $c) => data_set($c, 'entries.0.achievements', [['text' => 'Cut checkout latency by 40%']]),
            'body paragraphs that are not a list' => fn (array $c) => data_set($c, 'cover_letter.body_paragraphs', 'First body paragraph.'),
        ];

        return array_map(fn (Closure $case) => [$case], $cases);
    }

    public function test_entries_the_ai_leaves_out_keep_their_master_profile_text(): void
    {
        $application = $this->applicationFor('omitted-entries');
        $content = $this->validContent();
        $content['entries'] = [$content['entries'][0]];
        $this->fakeGeminiSequence($content);

        TailorApplication::dispatch($application);

        $cv = $application->fresh()->currentTailoredApplication->cv_data;
        $this->assertSame('Valid experience description.', $cv['experiences']["experience:{$this->experience->id}"]['description']);
        $this->assertSame('Thesis on distributed queues.', $cv['educations']["education:{$this->education->id}"]['description']);
        $this->assertArrayNotHasKey('achievements', $cv['educations']["education:{$this->education->id}"]);
        $this->assertSame('Job-application assistant.', $cv['projects']["project:{$this->project->id}"]['description']);
        $this->assertSame(['Polls two job boards'], $cv['projects']["project:{$this->project->id}"]['achievements']);
    }

    public function test_a_blank_reframed_description_keeps_the_master_profile_text_but_empty_achievements_stay_curated(): void
    {
        $application = $this->applicationFor('blank-entry-text');
        $content = $this->validContent();
        $content['entries'][0]['description'] = '';
        $content['entries'][0]['achievements'] = [];
        $content['entries'][2]['description'] = '   ';
        $this->fakeGeminiSequence($content);

        TailorApplication::dispatch($application);

        $this->assertSame(ApplicationStatus::NeedsReview, $application->fresh()->status);
        $cv = $application->fresh()->currentTailoredApplication->cv_data;
        $this->assertSame('Built the payments platform.', $cv['experiences']["experience:{$this->experience->id}"]['description']);
        $this->assertSame([], $cv['experiences']["experience:{$this->experience->id}"]['achievements']);
        $this->assertSame('Valid education description.', $cv['educations']["education:{$this->education->id}"]['description']);
        $this->assertSame('Job-application assistant.', $cv['projects']["project:{$this->project->id}"]['description']);
        $this->assertCount(1, $this->geminiRequests());
    }

    public function test_facts_in_the_snapshot_equal_the_master_profile_whatever_the_ai_returns(): void
    {
        $application = $this->applicationFor('rewritten-facts');
        $content = $this->validContent();
        $content['skills'] = [['category' => 'Invented', 'skills' => ['Rust']]];
        $content['full_name'] = 'Someone Else';
        $content['entries'][0] += [
            'title' => 'CTO',
            'company' => 'Google',
            'start_date' => '2010-01',
            'end_date' => '2011-01',
            'is_current' => false,
            'skills_used' => ['Rust'],
        ];
        $content['entries'][1] += ['institution' => 'MIT', 'degree' => 'PhD', 'start_date' => '2000-01'];
        $content['entries'][2] += ['name' => 'Kubernetes', 'tech_stack' => ['Go'], 'link' => 'https://example.com'];
        $this->fakeGeminiSequence($content);

        TailorApplication::dispatch($application);

        $cv = $application->fresh()->currentTailoredApplication->cv_data;
        $this->assertSame('Ahmad Zakky', $cv['personal_info']['full_name']);
        $this->assertSame([['category' => 'Backend', 'skills' => ['PHP', 'Laravel']]], $cv['skills']);

        foreach (['experiences' => $this->experience, 'educations' => $this->education, 'projects' => $this->project] as $section => $entry) {
            $facts = $entry->fresh()->tailoringFacts();
            $this->assertSame($facts, array_intersect_key($cv[$section][$entry->tailoringEntryId()], $facts));
        }
        $this->assertSame('Valid experience description.', $cv['experiences']["experience:{$this->experience->id}"]['description']);
    }

    public function test_tailoring_beyond_the_rate_limit_is_released_without_calling_gemini(): void
    {
        config(['services.gemini.rate_limit_per_minute' => 2]);
        $applications = [$this->applicationFor('burst-1'), $this->applicationFor('burst-2'), $this->applicationFor('burst-3')];
        $this->fakeGeminiSequence($this->validContent(), $this->validContent(), $this->validContent());

        $this->runTailoring($applications[0])->assertNotReleased();
        $this->runTailoring($applications[1])->assertNotReleased();
        $this->runTailoring($applications[2])->assertReleased(delay: 60);

        $this->assertCount(2, $this->geminiRequests());
        $this->assertSame(ApplicationStatus::PendingTailoring, $applications[2]->fresh()->status);

        $this->travel(59)->seconds();
        $this->runTailoring($applications[2])->assertReleased(delay: 1);
        $this->assertCount(2, $this->geminiRequests());

        $this->travel(1)->seconds();
        $this->runTailoring($applications[2])->assertNotReleased();
        $this->assertCount(3, $this->geminiRequests());
        $this->assertSame(ApplicationStatus::NeedsReview, $applications[2]->fresh()->status);
    }

    public function test_the_rate_limit_holds_over_any_sliding_minute(): void
    {
        config(['services.gemini.rate_limit_per_minute' => 2]);
        $this->fakeGeminiSequence($this->validContent(), $this->validContent(), $this->validContent());

        $this->runTailoring($this->applicationFor('at-0s'))->assertNotReleased();
        $this->travel(50)->seconds();
        $this->runTailoring($this->applicationFor('at-50s'))->assertNotReleased();
        $this->travel(10)->seconds();
        $this->runTailoring($this->applicationFor('at-60s'))->assertNotReleased();

        $this->runTailoring($this->applicationFor('also-at-60s'))->assertReleased(delay: 50);
        $this->assertCount(3, $this->geminiRequests());
    }

    public function test_every_regeneration_counts_against_the_rate_limit(): void
    {
        config(['services.gemini.rate_limit_per_minute' => 3]);
        $first = $this->applicationFor('regenerated');
        $second = $this->applicationFor('waits-for-the-regenerations');
        $invalid = $this->validContent();
        $invalid['professional_summary'] = '';
        $this->fakeGeminiSequence($invalid, $invalid, $this->validContent(), $this->validContent());

        $this->runTailoring($first)->assertNotReleased();
        $this->runTailoring($second)->assertReleased(delay: 60);

        $this->assertCount(3, $this->geminiRequests());
        $this->assertSame(ApplicationStatus::NeedsReview, $first->fresh()->status);
        $this->assertSame(ApplicationStatus::PendingTailoring, $second->fresh()->status);
    }

    public function test_tailoring_that_exits_before_calling_gemini_is_neither_released_nor_counted(): void
    {
        config(['services.gemini.rate_limit_per_minute' => 1]);
        $this->fakeGeminiSequence($this->validContent());
        $application = $this->applicationFor('tailored');

        $this->runTailoring($this->applicationFor('rejected', ApplicationStatus::Rejected))->assertNotReleased();
        $this->runTailoring($application)->assertNotReleased();

        $this->assertSame(ApplicationStatus::NeedsReview, $application->fresh()->status);
    }

    public function test_a_429_releases_tailoring_with_exponential_backoff(): void
    {
        $application = $this->applicationFor('rate-limited');
        $this->fakeGeminiSequence(429, 429, 429);

        $this->runTailoring($application)->assertReleased(delay: 60);

        $this->travel(60)->seconds();
        $this->runTailoring($application)->assertReleased(delay: 120);

        $this->travel(120)->seconds();
        $this->runTailoring($application)->assertReleased(delay: 240);

        $this->assertCount(3, $this->geminiRequests());
        $this->assertSame(ApplicationStatus::PendingTailoring, $application->fresh()->status);
        $this->assertSame(0, TailoredApplication::count());
        $this->assertSame([], $this->pdfRenderer->rendered);
    }

    public function test_the_429_backoff_is_capped_at_an_hour(): void
    {
        $application = $this->applicationFor('rate-limited-for-long');
        $this->fakeGeminiSequence(...array_fill(0, 8, 429));

        foreach ([60, 120, 240, 480, 960, 1920, 3600, 3600] as $expectedDelay) {
            $this->runTailoring($application)->assertReleased(delay: $expectedDelay);
            $this->travel($expectedDelay)->seconds();
        }
    }

    public function test_other_tailoring_waits_out_a_429_backoff_without_calling_gemini(): void
    {
        $rateLimited = $this->applicationFor('rate-limited');
        $waiting = $this->applicationFor('waiting');
        $this->fakeGeminiSequence(429, $this->validContent());

        $this->runTailoring($rateLimited)->assertReleased(delay: 60);

        $this->travel(30)->seconds();
        $this->runTailoring($waiting)->assertReleased(delay: 30);
        $this->assertCount(1, $this->geminiRequests());

        $this->travel(30)->seconds();
        $this->runTailoring($waiting)->assertNotReleased();
        $this->assertSame(ApplicationStatus::NeedsReview, $waiting->fresh()->status);
    }

    public function test_an_answered_call_resets_the_429_backoff(): void
    {
        $first = $this->applicationFor('first');
        $second = $this->applicationFor('second');
        $this->fakeGeminiSequence(429, 429, $this->validContent(), 429);

        $this->runTailoring($first)->assertReleased(delay: 60);
        $this->travel(60)->seconds();
        $this->runTailoring($first)->assertReleased(delay: 120);
        $this->travel(120)->seconds();
        $this->runTailoring($first)->assertNotReleased();

        $this->runTailoring($second)->assertReleased(delay: 60);
    }

    public function test_an_unreadable_answer_resets_the_429_backoff(): void
    {
        config(['applyr.tailoring.regeneration_limit' => 1]);
        $first = $this->applicationFor('first');
        $second = $this->applicationFor('second');
        $this->fakeGeminiSequence(429, 429, 'not json', 429);

        $this->runTailoring($first)->assertReleased(delay: 60);
        $this->travel(60)->seconds();
        $this->runTailoring($first)->assertReleased(delay: 120);
        $this->travel(120)->seconds();
        $this->runTailoring($first)->assertNotReleased();

        $this->runTailoring($second)->assertReleased(delay: 60);
    }

    public function test_the_429_backoff_starts_over_once_a_quiet_cooldown_has_passed(): void
    {
        $application = $this->applicationFor('rate-limited-again-later');
        $this->fakeGeminiSequence(429, 429, 429);

        $this->runTailoring($application)->assertReleased(delay: 60);
        $this->travel(60)->seconds();
        $this->runTailoring($application)->assertReleased(delay: 120);

        $this->travel(241)->seconds();
        $this->runTailoring($application)->assertReleased(delay: 60);
    }

    public function test_a_429_does_not_consume_a_regeneration_attempt(): void
    {
        config(['applyr.tailoring.regeneration_limit' => 3]);
        $application = $this->applicationFor('rate-limited-then-regenerated');
        $invalid = $this->validContent();
        $invalid['entries'][0]['entry_id'] = 'experience:999999';
        $this->fakeGeminiSequence(429, $invalid, $invalid, $this->validContent());

        $this->runTailoring($application)->assertReleased(delay: 60);
        $this->travel(60)->seconds();
        $this->runTailoring($application)->assertNotReleased();

        $this->assertCount(4, $this->geminiRequests());
        $application->refresh();
        $this->assertSame(ApplicationStatus::NeedsReview, $application->status);
        $this->assertSame('Valid summary.', $application->currentTailoredApplication->cv_data['personal_info']['professional_summary']);
    }

    public function test_tailoring_survives_its_releases_but_fails_on_its_first_error(): void
    {
        $tailoring = new TailorApplication($this->applicationFor('released'));

        $this->assertEquals(now()->addDay(), $tailoring->retryUntil());
        $this->assertSame(1, $tailoring->maxExceptions);
    }

    public function test_gemini_reports_a_429_as_rate_limited(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429]], 429)]);

        $this->expectException(RateLimitedException::class);

        app(AiProvider::class)->generate('prompt', ['type' => 'OBJECT']);
    }

    public function test_gemini_reports_other_failures_as_provider_errors(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 500]], 500)]);

        try {
            app(AiProvider::class)->generate('prompt', ['type' => 'OBJECT']);
            $this->fail('An HTTP error should have thrown.');
        } catch (ProviderException $e) {
            $this->assertNotInstanceOf(MalformedResponseException::class, $e);
        }
    }

    public function test_gemini_reports_unreadable_content_as_malformed(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->geminiEnvelope('not json'))
            ->push($this->geminiEnvelope('42'))
            ->push(['candidates' => [['finishReason' => 'SAFETY']]]),
        ]);

        foreach (['not JSON', 'not an object', 'no content'] as $case) {
            try {
                app(AiProvider::class)->generate('prompt', ['type' => 'OBJECT']);
                $this->fail("Content that is {$case} should have thrown.");
            } catch (MalformedResponseException) {
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

        return $this->geminiEnvelope($content);
    }

    private function applicationFor(string $externalId, ApplicationStatus $status = ApplicationStatus::PendingTailoring): Application
    {
        $job = Job::create([
            'platform' => 'glints',
            'external_id' => $externalId,
            'title' => 'Software Engineer',
            'company_name' => 'Acme',
            'country_code' => 'ID',
            'location' => 'Jakarta',
            'description' => 'Build things.',
            'url' => "https://glints.com/id/opportunities/jobs/{$externalId}",
            'work_arrangement' => 'onsite',
            'job_type' => 'full_time',
            'status' => 'open',
            'posted_date' => now(),
            'raw_payload' => [],
        ]);

        return $job->application()->create(['status' => $status]);
    }

    /**
     * Puts the Application in needs_review with an earlier TailoredApplication, and its PDFs, as its current one.
     */
    private function giveEarlierTailoredApplication(Application $application): TailoredApplication
    {
        $directory = "tailored-applications/{$application->id}/old";
        Storage::disk(PdfRenderer::DISK)->put("{$directory}/cv.pdf", '%PDF-old-cv');
        Storage::disk(PdfRenderer::DISK)->put("{$directory}/cover_letter.pdf", '%PDF-old-cover-letter');

        $tailored = $application->tailoredApplications()->create([
            'cv_data' => [],
            'cover_letter_data' => [],
            'cv_pdf_path' => "{$directory}/cv.pdf",
            'cover_letter_pdf_path' => "{$directory}/cover_letter.pdf",
        ]);

        $application->update([
            'status' => ApplicationStatus::NeedsReview,
            'current_tailored_application_id' => $tailored->id,
        ]);

        return $tailored;
    }

    /**
     * Gemini answers successive calls with each content in turn; a status code answers with that HTTP error,
     * and a string is answered as the raw response text.
     *
     * @param  array<string, mixed>|int|string  ...$contents
     */
    private function fakeGeminiSequence(array|int|string ...$contents): void
    {
        $sequence = Http::sequence();

        foreach ($contents as $content) {
            match (true) {
                is_int($content) => $sequence->push(['error' => ['code' => $content]], $content),
                is_string($content) => $sequence->push($this->geminiEnvelope($content)),
                default => $sequence->push($this->geminiEnvelope($content)),
            };
        }

        Http::fake([
            'generativelanguage.googleapis.com/*' => $sequence,
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
    }

    /**
     * A Gemini generateContent response whose text is the given content as JSON, or the given raw text.
     *
     * @param  array<string, mixed>|string  $content
     * @return array<string, mixed>
     */
    private function geminiEnvelope(array|string $content): array
    {
        $text = is_string($content) ? $content : json_encode($content);

        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]]]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function validContent(): array
    {
        return [
            'professional_summary' => 'Valid summary.',
            'entries' => [
                ['entry_id' => "experience:{$this->experience->id}", 'description' => 'Valid experience description.', 'achievements' => ['Led the Laravel upgrade', 'Cut checkout latency by 40%']],
                ['entry_id' => "education:{$this->education->id}", 'description' => 'Valid education description.', 'achievements' => []],
                ['entry_id' => "project:{$this->project->id}", 'description' => 'Valid project description.', 'achievements' => []],
            ],
            'cover_letter' => [
                'opening_paragraph' => 'Valid opening.',
                'body_paragraphs' => ['First body paragraph.', 'Second body paragraph.'],
                'closing_paragraph' => 'Valid closing.',
            ],
        ];
    }

    /**
     * Runs one queue attempt of TailorApplication, recording a release for assertions.
     */
    private function runTailoring(Application $application): TailorApplication
    {
        $tailoring = (new TailorApplication($application))->withFakeQueueInteractions();

        app()->call([$tailoring, 'handle']);

        return $tailoring;
    }

    /**
     * @return Collection<int, array{Request, mixed}>
     */
    private function geminiRequests(): Collection
    {
        return Http::recorded(fn (Request $request) => $request->url() === self::GEMINI_URL);
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
