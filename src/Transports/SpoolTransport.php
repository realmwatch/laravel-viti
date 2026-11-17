<?php

namespace Realmwatch\Viti\Transports;

use Realmwatch\Viti\Contracts\Transport;
use RuntimeException;

/**
 * Writes each trip to its own file in the spool directory, for the Heimdall
 * collector on this server to ship. The file is written under a dot name
 * and renamed into place, so the collector never reads half a report.
 * See docs/heimdall-contract.md.
 */
final class SpoolTransport implements Transport
{
    public function __construct(private readonly string $path) {}

    public function send(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $id = (string) $payload['id'];
        $tmp = $this->path.'/.'.$id.'.tmp';

        if (@file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            throw new RuntimeException("can't write to the spool directory {$this->path}");
        }
        // The collector runs as another user; a report holds nothing secret.
        @chmod($tmp, 0644);
        if (! @rename($tmp, $this->path.'/'.$id.'.json')) {
            @unlink($tmp);
            throw new RuntimeException("can't write to the spool directory {$this->path}");
        }
    }
}
