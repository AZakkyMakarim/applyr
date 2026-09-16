<?php

namespace App\Adapters\Exceptions;

/**
 * The request got no usable HTTP response: connection failure, timeout or server error.
 */
class TransportException extends AdapterException {}
