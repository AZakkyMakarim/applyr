<?php

namespace Tests;

use App\Enums\Platform;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every outbound call must be faked explicitly; an unfaked request fails the test.
        Http::preventStrayRequests();
    }

    /**
     * Fake every Adapter's platform as finding no postings, except the platforms given.
     * The first fake matching a request answers it, so fakes made before this call win.
     */
    protected function fakeAdaptersFindingNothing(Platform ...$except): void
    {
        foreach (Platform::cases() as $platform) {
            if (in_array($platform, $except, true)) {
                continue;
            }

            Http::fake(match ($platform) {
                Platform::Glints => ['glints.com/*' => $this->noPostingsFound($platform)],
                Platform::JobStreet => ['id.jobstreet.com/*' => $this->noPostingsFound($platform)],
            });
        }
    }

    /**
     * A search response from the platform that finds no postings.
     */
    protected function noPostingsFound(Platform $platform): PromiseInterface
    {
        return match ($platform) {
            Platform::Glints => Http::response(['data' => ['searchJobsV3' => ['hasMore' => false, 'jobsInPage' => []]]]),
            Platform::JobStreet => Http::response(file_get_contents(base_path('tests/Fixtures/JobStreet/search-jobs-no-results.json'))),
        };
    }

    /**
     * The Cloudflare challenge page the platform answers with once it takes the client for a bot.
     */
    protected function cloudflareChallenge(Platform $platform): PromiseInterface
    {
        return Http::response(
            file_get_contents(base_path("tests/Fixtures/{$platform->name}/cloudflare-challenge.html")),
            403,
            ['Cf-Mitigated' => 'challenge', 'Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
