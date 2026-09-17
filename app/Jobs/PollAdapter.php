<?php

namespace App\Jobs;

use App\Adapters\Adapter;
use App\Adapters\AdapterRegistry;
use App\Adapters\Exceptions\ApiErrorException;
use App\Adapters\Exceptions\ShapeDriftException;
use App\Adapters\JobData;
use App\Adapters\RefreshesJobs;
use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\Platform;
use App\Jobs\Middleware\TracksAdapterHealth;
use App\Models\Job;
use App\Models\SearchProfile;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One run of one Adapter across every active SearchProfile. Runs for the same
 * platform never overlap, so two runs can't race to create the same Job.
 */
class PollAdapter implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Pacing plus transport retry backoff can stretch a run to minutes; the queue's
    // retry_after must stay above this so a slow run isn't handed to a second worker.
    public int $timeout = 1800;

    public function __construct(public readonly Platform $platform) {}

    public function uniqueId(): string
    {
        return $this->platform->value;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [app(TracksAdapterHealth::class)];
    }

    public function handle(AdapterRegistry $adapters): void
    {
        $adapter = $adapters->for($this->platform);
        $seen = [];

        SearchProfile::query()->where('is_active', true)->each(function (SearchProfile $searchProfile) use ($adapter, &$seen) {
            foreach ($adapter->search($searchProfile) as $jobData) {
                $this->record($adapter, $searchProfile, $jobData);
                $seen[$jobData->externalId] = true;
            }
        });

        if ($adapter instanceof RefreshesJobs) {
            $this->refreshUnseenOpenJobs($adapter, $seen);
        }
    }

    /**
     * Fetch the open Jobs this run's searches didn't return, since the search may just have
     * stopped serving them after they closed. Only the longest-unrefreshed few are fetched per
     * run, so every one gets its turn over later runs without a run outgrowing its timeout.
     *
     * @param  array<string, true>  $seen  external ids the searches returned
     */
    private function refreshUnseenOpenJobs(RefreshesJobs $adapter, array $seen): void
    {
        $unseenOpenJobs = Job::query()
            ->where('platform', $this->platform)
            ->where('status', JobStatus::Open)
            ->whereNotIn('external_id', array_keys($seen))
            ->orderBy('refreshed_at')
            ->orderBy('id')
            ->limit(config('applyr.adapters.refresh_cap'))
            ->get();

        $lastFailure = null;
        $refreshedAny = false;

        foreach ($unseenOpenJobs as $job) {
            try {
                $jobData = $adapter->refresh($job->external_id);
            } catch (ApiErrorException|ShapeDriftException $e) {
                // One posting's bad answer mustn't fail the run, which would pause search too.
                // Blocks and transport failures still do: further requests won't fare better.
                $lastFailure = $e;
                Log::warning("{$this->platform->label()} Job {$job->external_id} was not refreshed.", ['error' => $e->getMessage()]);
                $job->update(['refreshed_at' => now()]);

                continue;
            }

            // A posting the platform no longer has can't be applied to any more.
            $attributes = $jobData?->jobAttributes() ?? ['status' => JobStatus::Closed];

            $job->update([...$attributes, 'refreshed_at' => now()]);
            $refreshedAny = true;
        }

        // When several postings all fail, refresh itself is broken rather than one posting, so the
        // run fails and counts toward pausing the Adapter instead of leaving open Jobs silently stale.
        if ($lastFailure !== null && ! $refreshedAny && $unseenOpenJobs->count() >= 2) {
            throw $lastFailure;
        }
    }

    /**
     * Upsert the Job, note the match, and open an Application only for a new Job.
     */
    private function record(Adapter $adapter, SearchProfile $searchProfile, JobData $jobData): void
    {
        $job = Job::query()
            ->where('platform', $this->platform)
            ->where('external_id', $jobData->externalId)
            ->first();

        // Fetched before the transaction so no row is locked while waiting on the platform.
        $description = $job === null ? $adapter->describe($jobData) : null;

        DB::transaction(function () use ($job, $description, $searchProfile, $jobData) {
            if ($job === null) {
                $job = Job::create([
                    ...$jobData->jobAttributes(),
                    'platform' => $this->platform,
                    'external_id' => $jobData->externalId,
                    'description' => $description,
                    'refreshed_at' => now(),
                ]);

                $application = $job->application()->create(['status' => ApplicationStatus::PendingTailoring]);

                TailorApplication::dispatch($application)->afterCommit();
            } else {
                $job->update([...$jobData->jobAttributes(), 'refreshed_at' => now()]);
            }

            if (! $job->searchProfiles()->whereKey($searchProfile->getKey())->exists()) {
                $job->searchProfiles()->attach($searchProfile, ['matched_at' => now()]);
            }
        });
    }
}
