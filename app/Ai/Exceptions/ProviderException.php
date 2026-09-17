<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The AI provider call failed, or (as MalformedResponseException) answered with something that isn't
 * decodable structured data.
 */
class ProviderException extends RuntimeException {}
