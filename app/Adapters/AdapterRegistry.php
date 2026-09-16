<?php

namespace App\Adapters;

use App\Enums\Platform;
use InvalidArgumentException;

/**
 * The Adapters the app polls, one per platform.
 */
class AdapterRegistry
{
    /**
     * @param  iterable<Adapter>  $adapters
     */
    public function __construct(private readonly iterable $adapters) {}

    /**
     * @return list<Adapter>
     */
    public function all(): array
    {
        return [...$this->adapters];
    }

    public function for(Platform $platform): Adapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->platform() === $platform) {
                return $adapter;
            }
        }

        throw new InvalidArgumentException("No Adapter is registered for {$platform->value}.");
    }
}
