<?php

namespace Realmwatch\Viti\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Realmwatch\Viti\Severity;
use Realmwatch\Viti\Viti;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Route middleware "viti.bait" for the mass_assignment tripwire: trips high
 * when the input carries a field no form of yours sends (is_admin, role),
 * at any depth. Only the field names are reported, never their values.
 *
 * Opt routes in with ->middleware('viti.bait') on a group, and out with
 * ->withoutViti(). With the tripwire off, it does nothing.
 */
final class DetectBaitKeys
{
    /** @param  list<string>  $keys  lowercased */
    public function __construct(private readonly Viti $viti, private readonly array $keys = []) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->keys !== []) {
            try {
                $found = [];
                foreach (array_keys(Arr::dot($request->all())) as $path) {
                    foreach (explode('.', (string) $path) as $segment) {
                        if (in_array(strtolower($segment), $this->keys, true)) {
                            $found[strtolower($segment)] = true;
                        }
                    }
                }
                if ($found !== []) {
                    $this->viti->trip('mass_assignment', Severity::High, $request->route()?->uri() ?? $request->getPathInfo(), [
                        'keys' => array_keys($found),
                    ]);
                }
            } catch (Throwable $e) {
                $this->viti->reporter()->warn('viti: bait key check failed', ['error' => $e->getMessage()]);
            }
        }

        return $next($request);
    }
}
