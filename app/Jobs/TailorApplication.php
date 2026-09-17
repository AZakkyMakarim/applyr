<?php

namespace App\Jobs;

use App\Ai\AiProvider;
use App\Ai\AiThrottle;
use App\Ai\Exceptions\MalformedResponseException;
use App\Ai\Exceptions\RateLimitedException;
use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\MasterProfile;
use App\Models\TailoredApplication;
use App\Notifiers\TelegramNotifier;
use App\Pdf\DocumentRenderer;
use App\Tailoring\DocumentSnapshots;
use App\Tailoring\TailoringPrompt;
use App\Tailoring\TailoringResponseValidator;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generates an Application's TailoredApplication with the AI provider, renders both PDFs,
 * and hands the Application to the user for review. An AI response that can't be decoded or fails fact
 * validation is regenerated, and never rendered or stored. A failed AI call fails the job.
 *
 * AI calls are throttled: tailoring that finds the rate limit spent, or that meets a 429, is released
 * back to the queue to run again later, and a release never counts as a failed validation attempt.
 * The limit is checked once per run, so a run may overshoot it by its remaining regenerations.
 */
class TailorApplication implements ShouldQueue
{
    use Queueable;

    /**
     * Any exception fails the job at once; releases aren't exceptions and don't count.
     */
    public int $maxExceptions = 1;

    public function __construct(public readonly Application $application) {}

    /**
     * Released jobs keep running for a day, whatever the worker's --tries.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(AiProvider $ai, AiThrottle $throttle, DocumentRenderer $documentRenderer, TelegramNotifier $notifier): void
    {
        $application = $this->application->refresh();

        // Rejected (or otherwise moved on) while this job waited in the queue.
        if ($application->status !== ApplicationStatus::PendingTailoring) {
            return;
        }

        // Nothing to tailor from until the user has saved a MasterProfile. Failing leaves the Application
        // on the dashboard to Retry once one is saved, rather than stuck in pending_tailoring.
        $masterProfile = MasterProfile::query()->with(['experiences', 'educations', 'projects'])->first();

        if ($masterProfile === null) {
            Log::warning("Application {$application->id} was not tailored: no MasterProfile has been saved.");
            $application->transitionTo(ApplicationStatus::TailoringFailed);

            return;
        }

        $job = $application->job;

        // Checked once per job rather than per call, so regenerations under way are never cut off.
        $wait = $throttle->secondsUntilAvailable();

        if ($wait > 0) {
            $this->release($wait);

            return;
        }

        try {
            $aiResponse = $this->generateValidAiResponse($ai, $throttle, new TailoringPrompt($job, $masterProfile), new TailoringResponseValidator($masterProfile));
        } catch (RateLimitedException) {
            $this->release($throttle->recordRateLimited());

            return;
        }

        // Every attempt was unreadable or failed validation; the user sees it on the dashboard, with no Telegram message.
        // A reject saved while tailoring ran stands.
        if ($aiResponse === null) {
            $application->transitionTo(ApplicationStatus::TailoringFailed);

            return;
        }

        // The snapshot keeps its own photo, so replacing the MasterProfile photo never changes these documents.
        $photoPath = $masterProfile->copyPhotoTo("tailored-applications/{$application->id}/photos/".Str::ulid());
        $snapshots = new DocumentSnapshots($job, $masterProfile, $aiResponse, $photoPath);
        $cvData = $snapshots->cvData();
        $coverLetterData = $snapshots->coverLetterData();
        ['cv_pdf_path' => $cvPdfPath, 'cover_letter_pdf_path' => $coverLetterPdfPath] = $documentRenderer->render($application, $cvData, $coverLetterData);

        $readyForReview = DB::transaction(function () use ($application, $cvData, $coverLetterData, $cvPdfPath, $coverLetterPdfPath) {
            $tailoredApplication = $application->tailoredApplications()->create([
                'cv_data' => $cvData,
                'cover_letter_data' => $coverLetterData,
                'cv_pdf_path' => $cvPdfPath,
                'cover_letter_pdf_path' => $coverLetterPdfPath,
            ]);

            // Fresh AI documents replace any the user edited, so they are no longer flagged as edited.
            $moved = $application->transitionTo(ApplicationStatus::NeedsReview, [
                'current_tailored_application_id' => $tailoredApplication->id,
                'edited_by_user' => false,
            ]);

            // Rejected while tailoring ran: the reject stands and these documents are dropped.
            if (! $moved) {
                $tailoredApplication->delete();
            }

            return $moved;
        });

        if (! $readyForReview) {
            return;
        }

        // The documents are ready even if the notification can't be delivered.
        rescue(fn () => $notifier->send(implode("\n", [
            'Applyr: Application ready for review',
            '',
            $job->title,
            "{$job->company_name} · {$job->platform->label()}",
            '',
            route('applications.show', $application),
        ])));
    }

    /**
     * Asks the AI until a response is readable and passes fact validation, discarding each one that
     * isn't, up to the regeneration limit.
     *
     * @return array<string, mixed>|null the first valid response, or null once every attempt failed
     *
     * @throws RateLimitedException on a 429, which ends the run without using up an attempt
     */
    private function generateValidAiResponse(AiProvider $ai, AiThrottle $throttle, TailoringPrompt $prompt, TailoringResponseValidator $validator): ?array
    {
        $limit = max(1, (int) config('applyr.tailoring.regeneration_limit'));

        for ($attempt = 1; $attempt <= $limit; $attempt++) {
            $throttle->recordCall();

            try {
                $aiResponse = $ai->generate($prompt->text(), TailoringPrompt::responseSchema());
            } catch (MalformedResponseException $e) {
                $throttle->recordAnswered();
                Log::warning("Application {$this->application->id}: tailoring attempt {$attempt} of {$limit} returned unreadable content.", [
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $throttle->recordAnswered();
            $failures = $validator->failures($aiResponse);

            if ($failures === []) {
                return $aiResponse;
            }

            Log::warning("Application {$this->application->id}: tailoring attempt {$attempt} of {$limit} failed validation.", [
                'failures' => $failures,
            ]);
        }

        return null;
    }
}
