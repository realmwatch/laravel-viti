<?php

namespace Realmwatch\Viti\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a trip is reported (not for hits the throttle dropped). Listen
 * to it for your own handling, for example logging the user out on a
 * critical trip.
 */
final class TripwireTriggered
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload  what was sent to Heimdall
     */
    public function __construct(public readonly array $payload) {}
}
