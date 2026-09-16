<?php

namespace App\Ai;

use App\Ai\Exceptions\ProviderException;
use App\Ai\Exceptions\RateLimitedException;

/**
 * Generates structured data from a prompt. The tailoring pipeline knows only this interface.
 */
interface AiProvider
{
    /**
     * @param  array<string, mixed>  $responseSchema  the JSON schema the response must follow
     * @return array<string, mixed> the decoded response
     *
     * @throws RateLimitedException when the provider refuses the call for exceeding its rate limit
     * @throws ProviderException when the call fails or the response can't be decoded
     */
    public function generate(string $prompt, array $responseSchema): array;
}
