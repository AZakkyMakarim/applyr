<?php

namespace App\Adapters;

use App\Adapters\Exceptions\AdapterException;

/**
 * An Adapter whose search stops returning a posting once it closes, so a poll
 * fetches each open Job it didn't see by id to learn whether it closed.
 */
interface RefreshesJobs
{
    /**
     * Fetch one posting by its external id, or null when the platform no longer has it.
     *
     * @throws AdapterException
     */
    public function refresh(string $externalId): ?JobData;
}
