<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The AI provider failed or answered with something that isn't decodable structured data.
 */
class ProviderException extends RuntimeException {}
