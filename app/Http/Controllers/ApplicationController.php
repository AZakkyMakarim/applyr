<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationController extends Controller
{
    public function index(Request $request): View
    {
        // An unrecognised status is treated as no filter.
        $status = ApplicationStatus::tryFrom((string) $request->query('status'));

        return view('applications.index', [
            'status' => $status,
            'applications' => Application::query()
                ->with('job')
                ->when($status, fn ($query) => $query->where('status', $status))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function show(Application $application): View
    {
        $application->load(['job.searchProfiles' => fn ($query) => $query->orderByPivot('matched_at'), 'currentTailoredApplication']);

        return view('applications.show', [
            'application' => $application,
        ]);
    }

    public function cv(Request $request, Application $application): StreamedResponse
    {
        return $this->pdf($request, $application, 'cv_pdf_path', 'cv');
    }

    public function coverLetter(Request $request, Application $application): StreamedResponse
    {
        return $this->pdf($request, $application, 'cover_letter_pdf_path', 'cover-letter');
    }

    /**
     * Serve one PDF of the Application's current TailoredApplication, inline for the preview
     * or as an attachment when ?download is set.
     */
    private function pdf(Request $request, Application $application, string $pathAttribute, string $document): StreamedResponse
    {
        $path = $application->currentTailoredApplication?->{$pathAttribute};
        $disk = Storage::disk(PdfRenderer::DISK);

        abort_unless($path !== null && $disk->exists($path), 404);

        $job = $application->job;
        $filename = Str::slug("{$document} {$job->title} {$job->company_name}").'.pdf';

        return $request->boolean('download')
            ? $disk->download($path, $filename)
            : $disk->response($path, $filename, ['Content-Type' => 'application/pdf']);
    }
}
