<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Job;
use App\Models\SearchProfile;
use App\Models\TailoredApplication;
use App\Pdf\PdfRenderer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PdfRenderer::DISK);
    }

    public function test_the_applications_list_shows_title_company_platform_and_status(): void
    {
        $this->makeApplication(['title' => 'Backend Engineer', 'company_name' => 'Acme'], ApplicationStatus::NeedsReview);
        $this->makeApplication(['title' => 'Data Analyst', 'company_name' => 'Globex', 'platform' => 'jobstreet'], ApplicationStatus::PendingTailoring);

        $this->get('/applications')
            ->assertOk()
            ->assertSeeInOrder(['Data Analyst', 'Globex', 'JobStreet', 'Pending tailoring'])
            ->assertSeeInOrder(['Backend Engineer', 'Acme', 'Glints', 'Needs review']);
    }

    public function test_the_applications_list_opens_with_no_applications(): void
    {
        $this->get('/applications')
            ->assertOk()
            ->assertSee('No Applications');
    }

    public function test_the_dashboard_navigation_links_to_the_applications_list(): void
    {
        $this->get('/')->assertSee('href="'.route('applications.index').'"', false);
    }

    /**
     * @return array<string, array{ApplicationStatus}>
     */
    public static function statuses(): array
    {
        return collect(ApplicationStatus::cases())
            ->mapWithKeys(fn (ApplicationStatus $status) => [$status->value => [$status]])
            ->all();
    }

    #[DataProvider('statuses')]
    public function test_the_list_can_be_filtered_by_each_status(ApplicationStatus $status): void
    {
        foreach (ApplicationStatus::cases() as $case) {
            $this->makeApplication(['title' => "Job {$case->value}"], $case);
        }

        $response = $this->get('/applications?status='.$status->value)->assertOk();

        foreach (ApplicationStatus::cases() as $case) {
            $case === $status
                ? $response->assertSee("Job {$case->value}")
                : $response->assertDontSee("Job {$case->value}");
        }
    }

    public function test_an_unknown_status_filter_shows_every_application(): void
    {
        $this->makeApplication(['title' => 'Backend Engineer'], ApplicationStatus::NeedsReview);
        $this->makeApplication(['title' => 'Data Analyst'], ApplicationStatus::Applied);

        $this->get('/applications?status=bogus')
            ->assertOk()
            ->assertSee('Backend Engineer')
            ->assertSee('Data Analyst');
    }

    public function test_the_list_links_each_application_to_its_detail_page(): void
    {
        $application = $this->makeApplication([], ApplicationStatus::NeedsReview);

        $this->get('/applications')
            ->assertSee('href="'.route('applications.show', $application).'"', false);
    }

    public function test_the_detail_page_shows_the_job_matched_search_profiles_and_posting_link(): void
    {
        $application = $this->makeApplication([
            'title' => 'Backend Engineer',
            'company_name' => 'Acme',
            'location' => 'Jakarta Selatan',
            'url' => 'https://glints.com/id/opportunities/jobs/123',
            'description' => 'Build Laravel services.',
            'work_arrangement' => 'hybrid',
            'job_type' => 'full_time',
            'salary_min' => 10000000,
            'salary_max' => 15000000,
            'salary_currency' => 'IDR',
            'salary_period' => 'monthly',
        ], ApplicationStatus::NeedsReview);

        $laravel = $this->makeSearchProfile('Laravel Jakarta');
        $remote = $this->makeSearchProfile('Remote PHP');
        $application->job->searchProfiles()->attach($laravel, ['matched_at' => Carbon::parse('2026-09-10 08:30:00')]);
        $application->job->searchProfiles()->attach($remote, ['matched_at' => Carbon::parse('2026-09-12 14:00:00')]);

        $this->get(route('applications.show', $application))
            ->assertOk()
            ->assertSeeInOrder(['Backend Engineer', 'Acme', 'Glints', 'Needs review'])
            ->assertSee('Jakarta Selatan')
            ->assertSee('Hybrid')
            ->assertSee('Full-time')
            ->assertSee('IDR 10,000,000 – 15,000,000 / month')
            ->assertSee('Build Laravel services.')
            ->assertSee('href="https://glints.com/id/opportunities/jobs/123"', false)
            ->assertSeeInOrder(['Laravel Jakarta', '2026-09-10 08:30', 'Remote PHP', '2026-09-12 14:00']);
    }

    public function test_an_unknown_application_is_not_found(): void
    {
        $this->get('/applications/999')->assertNotFound();
    }

    public function test_the_detail_page_previews_and_links_both_pdfs_of_the_current_tailored_application(): void
    {
        $application = $this->makeApplication([], ApplicationStatus::NeedsReview);
        $this->makeTailoredApplication($application);

        $this->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('src="'.route('applications.cv', $application).'"', false)
            ->assertSee('src="'.route('applications.cover-letter', $application).'"', false)
            ->assertSee('href="'.route('applications.cv', [$application, 'download' => 1]).'"', false)
            ->assertSee('href="'.route('applications.cover-letter', [$application, 'download' => 1]).'"', false);
    }

    public function test_the_pdf_routes_serve_the_current_tailored_application_files_inline(): void
    {
        $application = $this->makeApplication([], ApplicationStatus::NeedsReview);
        $this->makeTailoredApplication($application, 'old');
        $this->makeTailoredApplication($application, 'current');

        $cv = $this->get(route('applications.cv', $application))->assertOk();
        $this->assertSame('%PDF-current-cv', $cv->streamedContent());
        $this->assertSame('application/pdf', $cv->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $cv->headers->get('Content-Disposition'));

        $coverLetter = $this->get(route('applications.cover-letter', $application))->assertOk();
        $this->assertSame('%PDF-current-cover-letter', $coverLetter->streamedContent());
        $this->assertStringStartsWith('inline', $coverLetter->headers->get('Content-Disposition'));
    }

    public function test_the_pdf_routes_download_as_attachments(): void
    {
        $application = $this->makeApplication(['title' => 'Backend Engineer', 'company_name' => 'Acme'], ApplicationStatus::NeedsReview);
        $this->makeTailoredApplication($application);

        $cv = $this->get(route('applications.cv', [$application, 'download' => 1]))->assertOk();
        $this->assertSame('%PDF-current-cv', $cv->streamedContent());
        $cv->assertDownload('cv-backend-engineer-acme.pdf');

        $this->get(route('applications.cover-letter', [$application, 'download' => 1]))
            ->assertOk()
            ->assertDownload('cover-letter-backend-engineer-acme.pdf');
    }

    #[DataProvider('untailoredStatuses')]
    public function test_an_application_without_a_tailored_application_renders_without_pdf_sections(ApplicationStatus $status): void
    {
        $application = $this->makeApplication(['title' => 'Backend Engineer'], $status);

        $this->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('Backend Engineer')
            ->assertDontSee(route('applications.cv', $application))
            ->assertDontSee(route('applications.cover-letter', $application))
            ->assertDontSee('<iframe', false);

        $this->get(route('applications.cv', $application))->assertNotFound();
        $this->get(route('applications.cover-letter', $application))->assertNotFound();
    }

    /**
     * @return array<string, array{ApplicationStatus}>
     */
    public static function untailoredStatuses(): array
    {
        return [
            'pending_tailoring' => [ApplicationStatus::PendingTailoring],
            'tailoring_failed' => [ApplicationStatus::TailoringFailed],
        ];
    }

    public function test_a_pdf_missing_from_storage_is_not_found(): void
    {
        $application = $this->makeApplication([], ApplicationStatus::NeedsReview);
        $tailored = $this->makeTailoredApplication($application);
        Storage::disk(PdfRenderer::DISK)->delete($tailored->cv_pdf_path);

        $this->get(route('applications.cv', $application))->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $jobAttributes
     */
    private function makeApplication(array $jobAttributes, ApplicationStatus $status): Application
    {
        static $sequence = 0;
        $sequence++;

        $job = Job::create(array_merge([
            'platform' => 'glints',
            'external_id' => "job-{$sequence}",
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
        ], $jobAttributes));

        return $job->application()->create(['status' => $status]);
    }

    private function makeTailoredApplication(Application $application, string $label = 'current'): TailoredApplication
    {
        $directory = "tailored-applications/{$application->id}/{$label}";
        Storage::disk(PdfRenderer::DISK)->put("{$directory}/cv.pdf", "%PDF-{$label}-cv");
        Storage::disk(PdfRenderer::DISK)->put("{$directory}/cover_letter.pdf", "%PDF-{$label}-cover-letter");

        $tailored = $application->tailoredApplications()->create([
            'cv_data' => [],
            'cover_letter_data' => [],
            'cv_pdf_path' => "{$directory}/cv.pdf",
            'cover_letter_pdf_path' => "{$directory}/cover_letter.pdf",
        ]);

        $application->update(['current_tailored_application_id' => $tailored->id]);

        return $tailored;
    }

    private function makeSearchProfile(string $name): SearchProfile
    {
        return SearchProfile::factory()->create(['name' => $name]);
    }
}
