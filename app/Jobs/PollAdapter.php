<?php

namespace App\Jobs;

use App\Adapters\Adapter;
use App\Adapters\AdapterRegistry;
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
     * Fetch each open Job this run's searches didn't return, since the search may just have
     * stopped serving it after it closed.
     *
     * @param  array<string, true>  $seen  external ids the searches returned
     */
    private function refreshUnseenOpenJobs(RefreshesJobs $adapter, array $seen): void
    {
        $openJobs = Job::query()->where('platform', $this->platform)->where('status', JobStatus::Open);

        foreach ($openJobs->lazyById() as $job) {
            if (isset($seen[$job->external_id])) {
                continue;
            }

            // A posting the platform no longer has can't be applied to any more.
            $job->update($adapter->refresh($job->external_id)?->jobAttributes() ?? ['status' => JobStatus::Closed]);
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
                ]);

                $application = $job->application()->create(['status' => ApplicationStatus::PendingTailoring]);

                TailorApplication::dispatch($application)->afterCommit();
            } else {
                $job->update($jobData->jobAttributes());
            }

            if (! $job->searchProfiles()->whereKey($searchProfile->getKey())->exists()) {
                $job->searchProfiles()->attach($searchProfile, ['matched_at' => now()]);
            }
        });
    }
}
