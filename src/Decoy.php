<?php

namespace Realmwatch\Viti;

use Closure;

/**
 * A path that trips when this app would answer it with 404, so a decoy
 * never shadows a real route.
 *
 * A path ending in "/*" matches itself and everything under it
 * ("/wp-admin/*" matches "/wp-admin" and "/wp-admin/install.php").
 * Matching ignores case.
 *
 * $token is what Heimdall groups hits by (null: the path). $respond, when
 * set, is called as fn (Request $request, Response $notFound): Response
 * and its response is sent instead of the app's own 404, for bait content.
 * $context goes into the trip (scalars only, never personal data).
 */
final readonly class Decoy
{
    public function __construct(
        public string $path,
        public string $tripwire = 'decoy_routes',
        public Severity $severity = Severity::Noise,
        public ?string $token = null,
        public ?Closure $respond = null,
        /** @var array<string, mixed> */
        public array $context = [],
    ) {}

    public function token(): string
    {
        return $this->token ?? $this->path;
    }
}
