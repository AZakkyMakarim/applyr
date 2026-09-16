<?php

namespace App\Ai;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Keeps AI calls inside Gemini's free-tier quota, shared by every queue worker through the cache:
 * calls are limited to services.gemini.rate_limit_per_minute in any sliding minute, and each
 * consecutive 429 doubles a cooldown during which no call should be made.
 */
class AiThrottle
{
    private const CALLS_KEY = 'ai-throttle:call-timestamps';

    private const STREAK_KEY = 'ai-throttle:429-streak';

    private const COOLDOWN_KEY = 'ai-throttle:cooldown-until';

    private const WINDOW_SECONDS = 60;

    private const BASE_BACKOFF_SECONDS = 60;

    private const MAX_BACKOFF_SECONDS = 3600;

    public function __construct(private readonly Cache $cache) {}

    /**
     * Seconds until a call may be made; 0 when one may be made now.
     */
    public function secondsUntilAvailable(): int
    {
        $now = now()->getTimestamp();
        $cooldown = max(0, (int) $this->cache->get(self::COOLDOWN_KEY, 0) - $now);

        $perMinute = max(1, (int) config('services.gemini.rate_limit_per_minute'));
        $calls = $this->recentCalls();
        $limited = count($calls) >= $perMinute
            ? $calls[count($calls) - $perMinute] + self::WINDOW_SECONDS - $now
            : 0;

        return max($cooldown, $limited);
    }

    /**
     * Counts a call about to be made against the per-minute limit.
     */
    public function recordCall(): void
    {
        $this->cache->put(self::CALLS_KEY, [...$this->recentCalls(), now()->getTimestamp()], self::WINDOW_SECONDS);
    }

    /**
     * The provider answered without a 429, so the backoff starts over.
     */
    public function recordAnswered(): void
    {
        $this->cache->forget(self::STREAK_KEY);
    }

    /**
     * The provider answered 429: starts a cooldown twice as long as the previous one, up to an hour.
     * The streak is forgotten if no further 429 comes within one more cooldown of this one ending.
     *
     * @return int the cooldown in seconds
     */
    public function recordRateLimited(): int
    {
        $streak = (int) $this->cache->get(self::STREAK_KEY, 0) + 1;
        $backoff = min(self::MAX_BACKOFF_SECONDS, self::BASE_BACKOFF_SECONDS * 2 ** min($streak - 1, 16));

        $this->cache->put(self::STREAK_KEY, $streak, $backoff * 2);
        $this->cache->put(self::COOLDOWN_KEY, now()->getTimestamp() + $backoff, $backoff);

        return $backoff;
    }

    /**
     * @return list<int> timestamps of the calls made in the last minute, oldest first
     */
    private function recentCalls(): array
    {
        $since = now()->getTimestamp() - self::WINDOW_SECONDS;

        return array_values(array_filter(
            $this->cache->get(self::CALLS_KEY, []),
            fn (int $calledAt) => $calledAt > $since,
        ));
    }
}
