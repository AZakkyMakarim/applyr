<?php

namespace App\Console\Commands;

use App\Adapters\AdapterRegistry;
use App\Enums\AdapterStatus;
use App\Jobs\PollAdapter;
use App\Models\AdapterHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('applyr:poll')]
#[Description('Queue one polling run per unpaused Adapter across all active SearchProfiles')]
class PollAdapters extends Command
{
    public function handle(AdapterRegistry $adapters): void
    {
        $paused = AdapterHealth::query()->where('status', AdapterStatus::Paused)->pluck('platform');

        foreach ($adapters->all() as $adapter) {
            if ($paused->contains($adapter->platform())) {
                continue;
            }

            PollAdapter::dispatch($adapter->platform());
        }
    }
}
