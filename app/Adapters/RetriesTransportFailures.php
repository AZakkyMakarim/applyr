<?php

namespace App\Adapters;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Retries a request that got no usable response, backing off between attempts,
 * so a brief outage costs the run nothing and a lasting one fails it only once.
 */
trait RetriesTransportFailures
{
    // Pause before each retry, in milliseconds: three attempts in all.
    private const TRANSPORT_RETRY_BACKOFF_MS = [30_000, 120_000];

    private function retryingTransportFailures(PendingRequest $request): PendingRequest
    {
        return $request->retry(
            self::TRANSPORT_RETRY_BACKOFF_MS,
            when: fn (Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()),
            // The last failed response is returned for the Adapter to classify.
            throw: false,
        );
    }
}
