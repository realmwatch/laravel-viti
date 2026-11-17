<?php

namespace Realmwatch\Viti;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Realmwatch\Viti\Contracts\Transport;
use Realmwatch\Viti\Contracts\Tripwire;
use Realmwatch\Viti\Reporting\Reporter;
use Throwable;

/**
 * The registry and the one way to raise an alarm. Everything that extends
 * Viti, built in or the app's own, goes through these methods:
 *
 *   Viti::trip('fake_export', Severity::Critical);            // from anywhere
 *   Viti::decoy('/api/v0/debug/users');                       // a path
 *   Viti::decoys(fn () => ['/old-admin', '/backup/*']);       // paths, worked out on first use
 *   Viti::extend('my_tripwire', MyTripwire::class, $options); // a whole tripwire
 */
final class Viti
{
    /** @var array<string, Decoy> lowercased exact paths */
    private array $exact = [];

    /** @var array<string, Decoy> lowercased "/prefix" of a "/prefix/*" decoy */
    private array $prefixes = [];

    /** @var list<array{Closure, string}> decoy providers not run yet, and their tripwire */
    private array $providers = [];

    /** @var array<string, string> tripwire name => what registered it */
    private array $tripwires = [];

    public function __construct(
        private readonly Application $app,
        private readonly Reporter $reporter,
    ) {}

    /**
     * Report that someone touched a tripwire. Never throws and never slows
     * the request beyond a cache write and a small file write.
     *
     * $token tells tripwires' traps apart (which decoy path, which bait);
     * null uses the tripwire's name. $context is the tripwire's own detail,
     * scalars only, and never personal data: see Reporting\Payload.
     *
     * @param  array<string, mixed>  $context
     */
    public function trip(string $tripwire, Severity $severity, ?string $token = null, array $context = []): void
    {
        // Artisan commands and queue workers have no real request.
        $request = $this->app->runningInConsole() && ! $this->app->runningUnitTests() ? null : $this->app['request'];
        $this->reporter->report($tripwire, $severity, $token ?? $tripwire, $context, $request);
    }

    public function decoy(string|Decoy $decoy): self
    {
        $decoy = is_string($decoy) ? new Decoy($decoy) : $decoy;
        $path = '/'.ltrim(strtolower($decoy->path), '/');
        if (str_ends_with($path, '/*')) {
            $this->prefixes[substr($path, 0, -2)] = $decoy;
        } else {
            $this->exact[$path] = $decoy;
        }

        return $this;
    }

    /**
     * Add decoys worked out later: $provider returns paths or Decoys, and
     * runs once, the first time the app answers 404. Use it for lists that
     * cost something to build (a query, a file).
     *
     * @param  Closure(): iterable<string|Decoy>  $provider
     */
    public function decoys(Closure $provider, string $tripwire = 'decoy_routes'): self
    {
        $this->providers[] = [$provider, $tripwire];

        return $this;
    }

    /**
     * Register a tripwire now: a Tripwire, its class name, or a closure
     * fn (Viti $viti, array $options) that wires itself up the same way.
     *
     * @param  array<string, mixed>  $options
     */
    public function extend(string $name, Tripwire|Closure|string $tripwire, array $options = []): self
    {
        try {
            $instance = is_string($tripwire) ? $this->app->make($tripwire) : $tripwire;
            $instance instanceof Closure ? $instance($this, $options) : $instance->register($this, $options);
            $this->tripwires[$name] = $instance instanceof Closure ? 'closure' : $instance::class;
        } catch (Throwable $e) {
            $this->reporter->warn("viti: tripwire {$name} could not register", ['error' => $e->getMessage()]);
        }

        return $this;
    }

    /**
     * Deliver reports your own way: a Transport, or fn (array $payload).
     */
    public function reportUsing(Transport|Closure $transport): self
    {
        $this->reporter->using($transport);

        return $this;
    }

    /** The decoy this request's path matches, if any. */
    public function decoyFor(Request $request): ?Decoy
    {
        $this->resolveProviders();
        $path = strtolower(rtrim($request->getPathInfo(), '/')) ?: '/';
        if (isset($this->exact[$path])) {
            return $this->exact[$path];
        }
        foreach ($this->prefixes as $prefix => $decoy) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $decoy;
            }
        }

        return null;
    }

    /** @return array<string, string> tripwire name => class, or "closure" */
    public function tripwires(): array
    {
        return $this->tripwires;
    }

    /** @return list<Decoy> */
    public function allDecoys(): array
    {
        $this->resolveProviders();

        return [...array_values($this->exact), ...array_values($this->prefixes)];
    }

    public function reporter(): Reporter
    {
        return $this->reporter;
    }

    private function resolveProviders(): void
    {
        while ($next = array_shift($this->providers)) {
            [$provider, $tripwire] = $next;
            try {
                foreach ($provider() as $decoy) {
                    $this->decoy(is_string($decoy) ? new Decoy($decoy, $tripwire) : $decoy);
                }
            } catch (Throwable $e) {
                $this->reporter->warn('viti: a decoy provider failed', ['tripwire' => $tripwire, 'error' => $e->getMessage()]);
            }
        }
    }
}
