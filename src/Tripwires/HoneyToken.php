<?php

namespace Realmwatch\Viti\Tripwires;

use Illuminate\Contracts\Http\Kernel;
use Realmwatch\Viti\Contracts\Tripwire;
use Realmwatch\Viti\Http\DetectHoneyToken;
use Realmwatch\Viti\Viti;

/**
 * A fake API token (viti:seed prints it once and keeps only its hash).
 * Plant it where a thief would look: a config file, a script, a note.
 * Anyone presenting it as a bearer token trips critical, before auth runs.
 * It's hashed the way Sanctum hashes tokens, but Sanctum isn't required.
 */
final class HoneyToken implements Tripwire
{
    public function register(Viti $viti, array $options): void
    {
        $hash = strtolower(trim((string) ($options['hash'] ?? '')));
        if (! preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return;
        }
        app()->instance(DetectHoneyToken::class, new DetectHoneyToken($viti, $hash));
        $kernel = app(Kernel::class);
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(DetectHoneyToken::class);
        }
    }

    /** sha256 of the part after "|", as Sanctum stores it. */
    public static function hash(string $token): string
    {
        return hash('sha256', str_contains($token, '|') ? explode('|', $token, 2)[1] : $token);
    }
}
