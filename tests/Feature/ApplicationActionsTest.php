<?php

namespace Tests\Feature;

use App\Enums\ApplicationAction;
use App\Enums\ApplicationStatus;
use App\Jobs\TailorApplication;
use App\Models\Application;
use App\Models\Job;
use App\Pdf\PdfRenderer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake(PdfRenderer::DISK);
        $this->freezeSecond();
    }

    /**
     * @return array<string, array{ApplicationStatus, ?ApplicationStatus, ApplicationAction, ApplicationStatus}>
     */
    public static function allowedActions(): array
    {
        return [
            'reject pending_tailoring' => [ApplicationStatus::PendingTailoring, null, ApplicationAction::Reject, ApplicationStatus::Rejected],
            'reject tailoring_failed' => [ApplicationStatus::TailoringFailed, null, ApplicationAction::Reject, ApplicationStatus::Rejected],
            'reject needs_review' => [ApplicationStatus::NeedsReview, null, ApplicationAction::Reject, ApplicationStatus::Rejected],
            'retry tailoring_failed' => [ApplicationStatus::TailoringFailed, null, ApplicationAction::Retry, ApplicationStatus::PendingTailoring],
            'regenerate needs_review' => [ApplicationStatus::NeedsReview, null, ApplicationAction::Regenerate, ApplicationStatus::PendingTailoring],
            'mark needs_review applied' => [ApplicationStatus::NeedsReview, null, ApplicationAction::MarkApplied, ApplicationStatus::Applied],
            'undo applied' => [ApplicationStatus::Applied, ApplicationStatus::NeedsReview, ApplicationAction::Undo, ApplicationStatus::NeedsReview],
            'undo rejected from pending_tailoring' => [ApplicationStatus::Rejected, ApplicationStatus::PendingTailoring, ApplicationAction::Undo, ApplicationStatus::PendingTailoring],
            'undo rejected from tailoring_failed' => [ApplicationStatus::Rejected, ApplicationStatus::TailoringFailed, ApplicationAction::Undo, ApplicationStatus::TailoringFailed],
            'undo rejected from needs_review' => [ApplicationStatus::Rejected, ApplicationStatus::NeedsReview, ApplicationAction::Undo, ApplicationStatus::NeedsReview],
        ];
    }

    #[DataProvider('allowedActions')]
    public function test_an_allowed_action_moves_the_application_to_its_next_status(ApplicationStatus $from, ?ApplicationStatus $previousStatus, ApplicationAction $action, ApplicationStatus $to): void
    {
        $application = $this->makeApplication($from, $previousStatus);

        $this->patch(route('applications.transition', [$application, $action]))
            ->assertRedirect(route('applications.show', $application))
            ->assertSessionHas('status');

        $this->assertSame($to, $application->fresh()->status);
    }

    #[DataProvider('disallowedActions')]
    public function test_a_disallowed_action_is_rejected_and_leaves_the_application_unchanged(ApplicationStatus $from, ?ApplicationStatus $previousStatus, ApplicationAction $action): void
    {
        $application = $this->makeApplication($from, $previousStatus);
        $before = $application->fresh()->getAttributes();
        $this->travel(1)->minute();

        $this->patch(route('applications.transition', [$application, $action]))
            ->assertRedirect(route('applications.show', $application))
            ->assertSessionHas('error')
            ->assertSessionMissing('status');

        $this->assertSame($before, $application->fresh()->getAttributes());
        Queue::assertNothingPushed();
    }

    /**
     * Every status and action pair not in the allowed list.
     *
     * @return array<string, array{ApplicationStatus, ?ApplicationStatus, ApplicationAction}>
     */
    public static function disallowedActions(): array
    {
        $allowed = collect(self::allowedActions())->map(fn (array $case) => [$case[0], $case[2]]);
        $cases = [];

        foreach (ApplicationStatus::cases() as $status) {
            $previousStatus = match ($status) {
                ApplicationStatus::Applied, ApplicationStatus::Rejected => ApplicationStatus::NeedsReview,
                default => null,
            };

            foreach (ApplicationAction::cases() as $action) {
                if (! $allowed->contains([$status, $action])) {
                    $cases["{$action->value} {$status->value}"] = [$status, $previousStatus, $action];
                }
            }
        }

        return $cases;
    }

    public function test_an_unknown_action_is_not_found(): void
    {
        $application = $this->makeApplication(ApplicationStatus::NeedsReview);

        $this->patch("/applications/{$application->id}/withdraw")->assertNotFound();
        $this->assertSame(ApplicationStatus::NeedsReview, $application->fresh()->status);
    }

    #[DataProvider('rejectableStatuses')]
    public function test_rejecting_records_rejected_at_and_the_previous_status(ApplicationStatus $from): void
    {
        $application = $this->makeApplication($from);

        $this->patch(route('applications.transition', [$application, ApplicationAction::Reject]));

        $application->refresh();
        $this->assertSame(ApplicationStatus::Rejected, $application->status);
        $this->assertSame($from, $application->previous_status);
        $this->assertTrue($application->rejected_at->eq(now()));
        $this->assertNull($application->applied_at);
    }

    #[DataProvider('rejectableStatuses')]
    public function test_undoing_a_reject_restores_the_exact_previous_status_and_clears_rejected_at(ApplicationStatus $from): void
    {
        $application = $this->makeApplication($from);

        $this->patch(route('applications.transition', [$application, ApplicationAction::Reject]));
        $this->patch(route('applications.transition', [$application, ApplicationAction::Undo]));

        $application->refresh();
        $this->assertSame($from, $application->status);
        $this->assertNull($application->previous_status);
        $this->assertNull($application->rejected_at);
    }

    /**
     * @return array<string, array{ApplicationStatus}>
     */
    public static function rejectableStatuses(): array
    {
        return [
            'pending_tailoring' => [ApplicationStatus::PendingTailoring],
            'tailoring_failed' => [ApplicationStatus::TailoringFailed],
            'needs_review' => [ApplicationStatus::NeedsReview],
        ];
    }

    public function test_marking_applied_records_applied_at_and_undo_clears_it(): void
    {
        $application = $this->makeApplication(ApplicationStatus::NeedsReview);

        $this->patch(route('applications.transition', [$application, ApplicationAction::MarkApplied]));

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applied, $application->status);
        $this->assertSame(ApplicationStatus::NeedsReview, $application->previous_status);
        $this->assertTrue($application->applied_at->eq(now()));
        $this->assertNull($application->rejected_at);

        $this->patch(route('applications.transition', [$application, ApplicationAction::Undo]));

        $application->refresh();
        $this->assertSame(ApplicationStatus::NeedsReview, $application->status);
        $this->assertNull($application->previous_status);
        $this->assertNull($application->applied_at);
    }

    #[DataProvider('allowedActions')]
    public function test_only_actions_landing_in_pending_tailoring_queue_tailoring(ApplicationStatus $from, ?ApplicationStatus $previousStatus, ApplicationAction $action, ApplicationStatus $to): void
    {
        $application = $this->makeApplication($from, $previousStatus);

        $this->patch(route('applications.transition', [$application, $action]));

        if ($to === ApplicationStatus::PendingTailoring) {
            Queue::assertPushed(TailorApplication::class, fn (TailorApplication $tailoring) => $tailoring->application->is($application));
        } else {
            Queue::assertNothingPushed();
        }
    }

    public function test_regenerating_keeps_the_old_tailored_application_current_and_viewable(): void
    {
        $application = $this->makeApplication(ApplicationStatus::NeedsReview);
        $directory = "tailored-applications/{$application->id}/old";
        Storage::disk(PdfRenderer::DISK)->put("{$directory}/cv.pdf", '%PDF-old-cv');
        Storage::disk(PdfRenderer::DISK)->put("{$directory}/cover_letter.pdf", '%PDF-old-cover-letter');
        $tailored = $application->tailoredApplications()->create([
            'cv_data' => [],
            'cover_letter_data' => [],
            'cv_pdf_path' => "{$directory}/cv.pdf",
            'cover_letter_pdf_path' => "{$directory}/cover_letter.pdf",
        ]);
        $application->update(['current_tailored_application_id' => $tailored->id]);

        $this->patch(route('applications.transition', [$application, ApplicationAction::Regenerate]));

        $this->assertSame(ApplicationStatus::PendingTailoring, $application->fresh()->status);
        $this->assertSame($tailored->id, $application->fresh()->current_tailored_application_id);
        $this->get(route('applications.show', $application))
            ->assertSee('src="'.route('applications.cv', $application).'"', false);
        $this->assertSame('%PDF-old-cv', $this->get(route('applications.cv', $application))->streamedContent());
    }

    #[DataProvider('statusesWithActions')]
    public function test_the_detail_page_offers_only_the_actions_valid_for_the_status(ApplicationStatus $status, ?ApplicationStatus $previousStatus, array $expected): void
    {
        $application = $this->makeApplication($status, $previousStatus);

        $response = $this->get(route('applications.show', $application))->assertOk();

        foreach (ApplicationAction::cases() as $action) {
            $form = 'action="'.route('applications.transition', [$application, $action]).'"';

            in_array($action, $expected, true)
                ? $response->assertSee($form, false)->assertSee($action->label())
                : $response->assertDontSee($form, false);
        }
    }

    /**
     * @return array<string, array{ApplicationStatus, ?ApplicationStatus, list<ApplicationAction>}>
     */
    public static function statusesWithActions(): array
    {
        return [
            'pending_tailoring' => [ApplicationStatus::PendingTailoring, null, [ApplicationAction::Reject]],
            'tailoring_failed' => [ApplicationStatus::TailoringFailed, null, [ApplicationAction::Retry, ApplicationAction::Reject]],
            'needs_review' => [ApplicationStatus::NeedsReview, null, [ApplicationAction::MarkApplied, ApplicationAction::Regenerate, ApplicationAction::Reject]],
            'applied' => [ApplicationStatus::Applied, ApplicationStatus::NeedsReview, [ApplicationAction::Undo]],
            'rejected' => [ApplicationStatus::Rejected, ApplicationStatus::TailoringFailed, [ApplicationAction::Undo]],
        ];
    }

    public function test_the_detail_page_shows_the_outcome_of_an_action(): void
    {
        $application = $this->makeApplication(ApplicationStatus::NeedsReview);

        $this->followingRedirects()
            ->patch(route('applications.transition', [$application, ApplicationAction::MarkApplied]))
            ->assertOk()
            ->assertSee('Application marked applied.');

        $this->followingRedirects()
            ->patch(route('applications.transition', [$application, ApplicationAction::MarkApplied]))
            ->assertOk()
            ->assertSee('cannot be marked applied', false);
    }

    private function makeApplication(ApplicationStatus $status, ?ApplicationStatus $previousStatus = null): Application
    {
        static $sequence = 0;
        $sequence++;

        $job = Job::create([
            'platform' => 'glints',
            'external_id' => "action-job-{$sequence}",
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

        return $job->application()->create([
            'status' => $status,
            'previous_status' => $previousStatus,
            'applied_at' => $status === ApplicationStatus::Applied ? now()->subDay() : null,
            'rejected_at' => $status === ApplicationStatus::Rejected ? now()->subDay() : null,
        ]);
    }
}
