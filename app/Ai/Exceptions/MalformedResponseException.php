<?php

namespace App\Ai\Exceptions;

/**
 * The AI provider answered, but with content that isn't decodable structured data. Unlike a failed
 * call, this is one bad answer: tailoring discards it as a failed attempt and regenerates. It stays a
 * ProviderException so callers that don't regenerate can treat every provider failure alike.
 */
class MalformedResponseException extends ProviderException {}
