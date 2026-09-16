<?php

namespace App\Adapters\Exceptions;

/**
 * A successful response no longer maps onto the canonical Job fields.
 */
class ShapeDriftException extends AdapterException {}
