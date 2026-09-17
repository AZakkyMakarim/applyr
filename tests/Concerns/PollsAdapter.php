<?php

namespace Tests\Concerns;

use App\Enums\FailureCategory;
use App\Enums\Platform;
use App\Jobs\TailorApplication;
use App\Models\AdapterHealth;
use App\Models\Job;
use App\Models\SearchProfile;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/**
 * Polls with one Adapter served from its fixtures while every other Adapter finds nothing.
 */
trait PollsAdapter
{
    /** @var list<array<string, mixed>> */
    private array $searchPages = [];

    private int $searchCalls = 0;

    /**
     * The platform whose Adapter is under test.
     */
    abstract protected function platform(): Platform;

    protected function setUpPollsAdapter(): void
    {
        Sleep::fake();

        // Polling is under test, not tailoring: new Applications stay in pending_tailoring.
        Queue::fake([TailorApplication::class]);

        // The poll runs every Adapter; the others find nothing so only this platform's Jobs are stored.
        $this->fakeAdaptersFindingNothing(except: $this->platform());
    }

    /**
     * Serve search responses in order, the last one repeating. Calling again swaps the responses
     * for later polls and starts them over.
     *
     * @param  list<array<string, mixed>>  $searchPages
     * @param  (Closure(Request, Closure(): PromiseInterface): PromiseInterface)|null  $respond  answers a request, given a way to take the next search response; every request is a search without it
     */
    private function serveSearchPages(string $url, array $searchPages, ?Closure $respond = null): void
    {
        $alreadyFaked = $this->searchPages !== [];
        $this->searchPages = $searchPages;
        $this->searchCalls = 0;

        // A registered fake can't be replaced, so later calls only swap what it serves.
        if ($alreadyFaked) {
            return;
        }

        $nextSearchPage = fn () => Http::response($this->searchPages[min($this->searchCalls++, count($this->searchPages) - 1)]);

        Http::fake([$url => fn (Request $request) => $respond ? $respond($request, $nextSearchPage) : $nextSearchPage()]);
    }

    /**
     * The run fails with the given category recorded against the platform, storing nothing.
     */
    private function assertPollFails(FailureCategory $category): void
    {
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $health = AdapterHealth::for($this->platform());
        $this->assertSame($category, $health->last_failure_category);
        $this->assertSame(1, $health->failuresFor($category));
        $this->assertSame(0, Job::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        // Each platform's fixtures live in a directory named after its Platform case.
        return json_decode(file_get_contents(base_path("tests/Fixtures/{$this->platform()->name}/{$name}")), true, flags: JSON_THROW_ON_ERROR);
    }
}
