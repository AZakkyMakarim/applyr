<?php

namespace App\Jobs;

use App\Ai\AiProvider;
use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\MasterProfile;
use App\Models\TailoredApplication;
use App\Notifiers\TelegramNotifier;
use App\Pdf\PdfRenderer;
use App\Tailoring\DocumentSnapshots;
use App\Tailoring\TailoringPrompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generates an Application's TailoredApplication with the AI provider, renders both PDFs,
 * and hands the Application to the user for review.
 */
class TailorApplication implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Application $application) {}

    public function handle(AiProvider $ai, PdfRenderer $pdfRenderer, TelegramNotifier $notifier): void
    {
        $application = $this->application->refresh();

        // Rejected (or otherwise moved on) while this job waited in the queue.
        if ($application->status !== ApplicationStatus::PendingTailoring) {
            return;
        }

        // Nothing to tailor from until the user has saved a MasterProfile.
        $masterProfile = MasterProfile::query()->with(['experiences', 'educations', 'projects'])->first();

        if ($masterProfile === null) {
            Log::warning("Application {$application->id} was not tailored: no MasterProfile has been saved.");

            return;
        }

        $job = $application->job;

        $output = $ai->generate((new TailoringPrompt($job, $masterProfile))->text(), TailoringPrompt::responseSchema());

        $snapshots = new DocumentSnapshots($job, $masterProfile, $output);
        $cvData = $snapshots->cvData();
        $coverLetterData = $snapshots->coverLetterData();

        $directory = "tailored-applications/{$application->id}/".Str::ulid();
        $cvPdfPath = $pdfRenderer->render(view('documents.cv', ['cv' => $cvData])->render(), "{$directory}/cv.pdf");
        $coverLetterPdfPath = $pdfRenderer->render(
            view('documents.cover-letter', ['coverLetter' => $coverLetterData])->render(),
            "{$directory}/cover_letter.pdf",
        );

        DB::transaction(function () use ($application, $cvData, $coverLetterData, $cvPdfPath, $coverLetterPdfPath) {
            $tailoredApplication = $application->tailoredApplications()->create([
                'cv_data' => $cvData,
                'cover_letter_data' => $coverLetterData,
                'cv_pdf_path' => $cvPdfPath,
                'cover_letter_pdf_path' => $coverLetterPdfPath,
            ]);

            $application->update([
                'current_tailored_application_id' => $tailoredApplication->id,
                'status' => ApplicationStatus::NeedsReview,
            ]);
        });

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
}
