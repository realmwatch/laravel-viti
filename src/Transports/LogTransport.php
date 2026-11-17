<?php

namespace Realmwatch\Viti\Transports;

use Illuminate\Support\Facades\Log;
use Realmwatch\Viti\Contracts\Transport;

/** Writes each trip to a log channel (null: the default one). */
final class LogTransport implements Transport
{
    public function __construct(private readonly ?string $channel = null) {}

    public function send(array $payload): void
    {
        ($this->channel ? Log::channel($this->channel) : Log::getFacadeRoot())->warning('viti: tripwire triggered', $payload);
    }
}
