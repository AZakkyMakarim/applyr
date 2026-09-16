<?php

namespace App\Jobs;

use App\Adapters\Adapter;
use App\Adapters\AdapterRegistry;
use App\Adapters\JobData;
use App\Enums\ApplicationStatus;
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

        SearchProfile::query()->where('is_active', true)->each(function (SearchProfile $searchProfile) use ($adapter) {
            foreach ($adapter->search($searchProfile) as $jobData) {
                $this->record($adapter, $searchProfile, $jobData);
            }
        });
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
