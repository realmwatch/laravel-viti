<?php

namespace Realmwatch\Viti\Contracts;

/**
 * Delivers one trip report. It may throw: Viti catches it and logs the
 * payload instead, so the request carries on.
 */
interface Transport
{
    /**
     * @param  array<string, mixed>  $payload  already redacted (Reporting\Payload)
     */
    public function send(array $payload): void;
}
