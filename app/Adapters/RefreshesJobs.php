<?php

namespace App\Adapters;

use App\Adapters\Exceptions\AdapterException;
use App\Models\Job;

/**
 * An Adapter whose search stops returning a posting once it closes, so a poll
 * fetches each open Job it didn't see by id to learn whether it closed.
 */
interface RefreshesJobs
{
    /**
     * Fetch a stored Job's posting by its external id, or null when the platform no longer has it.
     * The stored Job lets an Adapter whose fetch by id returns less than its search fill in the rest.
     *
     * @throws AdapterException
     */
    public function refresh(Job $job): ?JobData;
}
