<?php

namespace Realmwatch\Viti\Http;

use Closure;
use Illuminate\Http\Request;
use Realmwatch\Viti\Viti;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Global middleware: when the app answers 404 on a decoy's path, trip it.
 * It looks only at responses that are already 404, so a real route always
 * wins and every other request costs one status check.
 *
 * A decoy's default response is the app's own 404, untouched apart from the
 * header every 404 gets: the source being public must not make decoys
 * detectable.
 */
final class DetectDecoys
{
    public function __construct(private readonly Viti $viti) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response->getStatusCode() !== 404) {
            return $response;
        }

        // Every 404 gets this, not just a decoy's: Cloudflare must never cache
        // a decoy (that hides repeat hits), and a decoy's 404 must look exactly
        // like any other, or a scanner can tell which paths are watched.
        $response->headers->set('Cache-Control', 'no-store, private');

        try {
            $decoy = $this->viti->decoyFor($request);
            if ($decoy === null) {
                return $response;
            }
            $this->viti->trip($decoy->tripwire, $decoy->severity, $decoy->token(), $decoy->context);
            if ($decoy->respond !== null) {
                $response = ($decoy->respond)($request, $response);
                $response->headers->set('Cache-Control', 'no-store, private');
            }
        } catch (Throwable $e) {
            $this->viti->reporter()->warn('viti: decoy response failed', ['error' => $e->getMessage()]);
        }

        return $response;
    }
}
