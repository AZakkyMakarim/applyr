<?php

namespace App\Ai;

use App\Ai\Exceptions\ProviderException;
use App\Ai\Exceptions\RateLimitedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Calls Gemini generateContent with a responseSchema, using the configured model and API key.
 */
class GeminiProvider implements AiProvider
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function generate(string $prompt, array $responseSchema): array
    {
        $apiKey = config('services.gemini.api_key');
        $model = config('services.gemini.model');

        if (blank($apiKey) || blank($model)) {
            throw new ProviderException('Gemini is not configured: set GEMINI_API_KEY and GEMINI_MODEL.');
        }

        try {
            $response = Http::timeout(120)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(self::BASE_URL."/{$model}:generateContent", [
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $responseSchema,
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderException("Gemini could not be reached: {$e->getMessage()}", previous: $e);
        }

        if ($response->status() === 429) {
            throw new RateLimitedException('Gemini rate limit exceeded.');
        }

        if ($response->failed()) {
            throw new ProviderException("Gemini returned HTTP {$response->status()}: ".str($response->body())->limit(300));
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text)) {
            throw new ProviderException('Gemini returned no content: '.str($response->body())->limit(300));
        }

        try {
            $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProviderException("Gemini returned content that isn't JSON: {$e->getMessage()}", previous: $e);
        }

        if (! is_array($data)) {
            throw new ProviderException('Gemini returned JSON that is not an object.');
        }

        return $data;
    }
}
