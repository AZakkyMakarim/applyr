<?php

namespace App\Adapters;

use App\Adapters\Exceptions\AdapterException;
use App\Enums\Platform;
use App\Models\SearchProfile;

/**
 * Fetches postings from one job board and normalizes them into canonical Job data.
 *
 * Failures surface only as the four AdapterException subclasses: anti-bot
 * blocked, transport, API error and shape drift.
 */
interface Adapter
{
    public function platform(): Platform;

    /**
     * Find the postings this platform returns for a SearchProfile, translating
     * whatever filters the platform can express and ignoring the rest.
     *
     * @return list<JobData>
     *
     * @throws AdapterException
     */
    public function search(SearchProfile $searchProfile): array;

    /**
     * Fetch a posting's full description as plain text. Called only for
     * postings not seen before, because search results may leave it out.
     *
     * @throws AdapterException
     */
    public function describe(JobData $jobData): string;
}
