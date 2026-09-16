<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The AI provider refused the call for exceeding its rate limit (HTTP 429). Deliberately not
 * a ProviderException: a rate limit is retried later, not counted as a failed attempt.
 */
class RateLimitedException extends RuntimeException {}
