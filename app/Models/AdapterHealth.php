<?php

namespace App\Models;

use App\Adapters\Exceptions\AdapterException;
use App\Enums\AdapterStatus;
use App\Enums\FailureCategory;
use App\Enums\Platform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One platform's Adapter health: consecutive failed runs on the broken and
 * transport tracks, and whether the Adapter is paused because of them.
 */
#[Fillable(['platform'])]
class AdapterHealth extends Model
{
    // Consecutive failed runs that pause the Adapter, per track.
    public const BROKEN_THRESHOLD = 3;

    public const TRANSPORT_THRESHOLD = 6;

    public const RECENT_FAILURE_LIMIT = 20;

    private const MESSAGE_LIMIT = 500;

    protected $table = 'adapter_health';

    protected $attributes = [
        'status' => 'healthy',
        'broken_failures' => 0,
        'transport_failures' => 0,
        'recent_failures' => '[]',
    ];

    public static function for(Platform $platform): self
    {
        return static::query()->firstOrCreate(['platform' => $platform]);
    }

    public function isPaused(): bool
    {
        return $this->status === AdapterStatus::Paused;
    }

    /**
     * Count one failed run on its track.
     *
     * @return bool whether this failure paused the Adapter
     */
    public function recordFailure(AdapterException $exception): bool
    {
        $category = FailureCategory::of($exception);
        $counter = $category->isTransport() ? 'transport_failures' : 'broken_failures';
        $wasPaused = $this->isPaused();

        $this->{$counter}++;
        $this->first_failure_at ??= now();
        $this->last_failure_at = now();
        $this->last_failure_category = $category;
        $this->last_failure_status_code = $exception->statusCode;
        $this->last_failure_message = mb_substr($exception->getMessage(), 0, self::MESSAGE_LIMIT);
        $this->recent_failures = array_slice(
            [...$this->recent_failures, ['category' => $category->value, 'at' => now()->toIso8601String()]],
            -self::RECENT_FAILURE_LIMIT,
        );

        $crossed = $this->{$counter} >= $this->thresholdFor($category);
        $this->status = $wasPaused || $crossed ? AdapterStatus::Paused : AdapterStatus::Failing;
        $this->save();

        return ! $wasPaused && $crossed;
    }

    /**
     * A run finished cleanly: end the failure streak on both tracks.
     */
    public function recordSuccess(): void
    {
        $this->forceFill([
            'status' => AdapterStatus::Healthy,
            'broken_failures' => 0,
            'transport_failures' => 0,
            'first_failure_at' => null,
            'last_failure_at' => null,
            'last_success_at' => now(),
        ])->save();
    }

    public function thresholdFor(FailureCategory $category): int
    {
        return $category->isTransport() ? self::TRANSPORT_THRESHOLD : self::BROKEN_THRESHOLD;
    }

    /**
     * Failed runs so far on the given category's track.
     */
    public function failuresFor(FailureCategory $category): int
    {
        return $category->isTransport() ? $this->transport_failures : $this->broken_failures;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => AdapterStatus::class,
            'broken_failures' => 'integer',
            'transport_failures' => 'integer',
            'first_failure_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_category' => FailureCategory::class,
            'last_failure_status_code' => 'integer',
            'recent_failures' => 'array',
        ];
    }
}
