<?php

namespace App\Jobs\Middleware;

use App\Adapters\Exceptions\AdapterException;
use App\Jobs\PollAdapter;
use App\Models\AdapterHealth;
use App\Notifiers\TelegramNotifier;
use Closure;

/**
 * Wraps an Adapter run: a clean run ends the failure streak, a failed one is
 * counted on its track, and crossing a threshold pauses the Adapter with one alert.
 */
class TracksAdapterHealth
{
    public function __construct(private readonly TelegramNotifier $notifier) {}

    public function handle(PollAdapter $job, Closure $next): void
    {
        $health = AdapterHealth::for($job->platform);

        // Queued before the Adapter was paused.
        if ($health->isPaused()) {
            return;
        }

        try {
            $next($job);
        } catch (AdapterException $e) {
            if ($health->recordFailure($e)) {
                // The pause stands even if the alert can't be delivered.
                rescue(fn () => $this->notifier->send($this->alert($health)));
            }

            return;
        }

        $health->recordSuccess();
    }

    private function alert(AdapterHealth $health): string
    {
        $category = $health->last_failure_category;

        return implode("\n", [
            "Applyr: {$health->platform->label()} Adapter paused",
            '',
            "Failure: {$category->label()} ({$health->failuresFor($category)}/{$health->thresholdFor($category)} consecutive failed runs)",
            'First failure: '.$health->first_failure_at->format('Y-m-d H:i:s T'),
            'Last failure: '.$health->last_failure_at->format('Y-m-d H:i:s T'),
            'Status code: '.($health->last_failure_status_code ?? 'none'),
            "Error: {$health->last_failure_message}",
            '',
            'The Adapter is paused and will not be polled until it is resumed.',
        ]);
    }
}
