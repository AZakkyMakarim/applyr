<?php

namespace App\Console\Commands;

use App\Adapters\AdapterRegistry;
use App\Jobs\PollAdapter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('applyr:poll')]
#[Description('Queue one polling run per Adapter across all active SearchProfiles')]
class PollAdapters extends Command
{
    public function handle(AdapterRegistry $adapters): void
    {
        foreach ($adapters->all() as $adapter) {
            PollAdapter::dispatch($adapter->platform());
        }
    }
}
