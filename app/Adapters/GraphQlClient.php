<?php

namespace App\Adapters;

use App\Adapters\Exceptions\AntiBotBlockedException;
use App\Adapters\Exceptions\ApiErrorException;
use App\Adapters\Exceptions\ShapeDriftException;
use App\Adapters\Exceptions\TransportException;
use App\Enums\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * One platform's GraphQL endpoint, sending paced operations and turning every
 * failure into the AdapterException subclass it belongs to.
 */
class GraphQlClient
{
    use RetriesTransportFailures;

    private bool $hasSentRequest = false;

    /**
     * @param  array<string, string>  $headers  sent with every request, e.g. to pass as a browser
     * @param  array<string, mixed>  $options  Guzzle request options, e.g. curl TLS settings
     */
    public function __construct(
        private readonly Platform $platform,
        private readonly string $endpoint,
        private readonly array $headers = [],
        private readonly array $options = [],
    ) {}

    /**
     * Send one operation and return its data object.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(string $operationName, string $query, array $variables): array
    {
        return $this->data($this->send($operationName, $query, $variables));
    }

    /**
     * Send one operation and return the decoded body, classifying transport and HTTP failures.
     * GraphQL errors in the body are left for the caller, or data(), to judge.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function send(string $operationName, string $query, array $variables): array
    {
        $this->pace();

        try {
            $response = $this->retryingTransportFailures(Http::withHeaders($this->headers))
                ->withOptions($this->options)
                ->asJson()
                ->timeout(30)
                ->post($this->endpoint, [
                    'operationName' => $operationName,
                    'variables' => $variables,
                    'query' => $query,
                ]);
        } catch (ConnectionException $e) {
            throw new TransportException($this->platform, $e->getMessage(), previous: $e);
        }

        $this->guardHttpFailure($response);

        $body = $response->json();

        if (! is_array($body)) {
            throw new ShapeDriftException($this->platform, 'Response body is not JSON: '.$this->snippet($response->body()), $response->status());
        }

        return $body;
    }

    /**
     * The data object of a GraphQL body, or the API error it reports instead.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function data(array $body): array
    {
        if (! empty($body['errors'])) {
            $error = $body['errors'][0];
            $code = $error['extensions']['code'] ?? 'UNKNOWN';

            throw new ApiErrorException($this->platform, "GraphQL error {$code}: ".($error['message'] ?? ''), 200);
        }

        if (! is_array($body['data'] ?? null)) {
            throw new ShapeDriftException($this->platform, 'Response has no data object.', 200);
        }

        return $body['data'];
    }

    /**
     * A non-empty string field of a posting, or shape drift if it's gone.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $path  how to name the field in the error, when $data is nested
     */
    public function required(array $data, string $key, ?string $path = null): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new ShapeDriftException($this->platform, 'Posting field '.($path ?? $key).' is missing.', 200);
        }

        return $value;
    }

    private function guardHttpFailure(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $message = "HTTP {$status}: ".$this->snippet($response->body());

        if (($status === 403 && strtolower($response->header('Cf-Mitigated')) === 'challenge') || $status === 429) {
            throw new AntiBotBlockedException($this->platform, $message, $status);
        }

        if ($response->serverError()) {
            throw new TransportException($this->platform, $message, $status);
        }

        throw new ApiErrorException($this->platform, $message, $status);
    }

    /**
     * Space requests out so bursts don't raise Cloudflare's bot score.
     */
    private function pace(): void
    {
        if ($this->hasSentRequest) {
            Sleep::for(config('applyr.adapters.request_delay_ms'))->milliseconds();
        }

        $this->hasSentRequest = true;
    }

    private function snippet(string $body): string
    {
        return mb_substr(trim(strip_tags($body)), 0, 200);
    }
}
