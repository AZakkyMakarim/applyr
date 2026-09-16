<?php

namespace App\Adapters\Exceptions;

/**
 * Bot mitigation blocked the request, e.g. a Cloudflare challenge.
 */
class AntiBotBlockedException extends AdapterException {}
