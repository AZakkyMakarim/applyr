<?php

namespace App\Enums;

use App\Adapters\Exceptions\AdapterException;
use App\Adapters\Exceptions\AntiBotBlockedException;
use App\Adapters\Exceptions\ApiErrorException;
use App\Adapters\Exceptions\ShapeDriftException;
use App\Adapters\Exceptions\TransportException;

/**
 * Why an Adapter run failed. Transport failures may clear up on their own; the
 * other three mean the Adapter is broken and share one failure track.
 */
enum FailureCategory: string
{
    case AntiBot = 'anti_bot';
    case ApiError = 'api_error';
    case ShapeDrift = 'shape_drift';
    case Transport = 'transport';

    public static function of(AdapterException $exception): self
    {
        return match (true) {
            $exception instanceof AntiBotBlockedException => self::AntiBot,
            $exception instanceof ApiErrorException => self::ApiError,
            $exception instanceof ShapeDriftException => self::ShapeDrift,
            $exception instanceof TransportException => self::Transport,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AntiBot => 'anti-bot blocked',
            self::ApiError => 'API error',
            self::ShapeDrift => 'shape drift',
            self::Transport => 'transport',
        };
    }

    public function isTransport(): bool
    {
        return $this === self::Transport;
    }
}
