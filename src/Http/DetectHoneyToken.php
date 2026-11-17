<?php

namespace Realmwatch\Viti\Http;

use Closure;
use Illuminate\Http\Request;
use Realmwatch\Viti\Severity;
use Realmwatch\Viti\Tripwires\HoneyToken;
use Realmwatch\Viti\Viti;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Global middleware for the honey_token tripwire: trips when the bearer
 * token is the honey token, then lets the app answer as it would anyway.
 * Requests without a bearer token cost one header read.
 */
final class DetectHoneyToken
{
    public function __construct(private readonly Viti $viti, private readonly string $hash) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $token = $request->bearerToken();
            if ($token !== null && $token !== '' && hash_equals($this->hash, HoneyToken::hash($token))) {
                $this->viti->trip('honey_token', Severity::Critical, 'bearer');
            }
        } catch (Throwable $e) {
            $this->viti->reporter()->warn('viti: honey token check failed', ['error' => $e->getMessage()]);
        }

        return $next($request);
    }
}
