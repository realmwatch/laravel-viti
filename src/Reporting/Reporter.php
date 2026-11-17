<?php

namespace Realmwatch\Viti\Reporting;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Realmwatch\Viti\Contracts\Transport;
use Realmwatch\Viti\Events\TripwireTriggered;
use Realmwatch\Viti\Severity;
use Realmwatch\Viti\Transports\LogTransport;
use Realmwatch\Viti\Transports\SpoolTransport;
use Throwable;

/**
 * Turns a trip into a report: escalates it if someone is signed in,
 * throttles repeats, redacts (Payload), delivers (Transport), then fires
 * TripwireTriggered. Never throws: anything that goes wrong is logged.
 */
final class Reporter
{
    private Transport|Closure|null $transport = null;

    public function __construct(private readonly Application $app) {}

    /**
     * Send reports through your own transport (a Transport, or a closure
     * taking the payload) instead of the configured one.
     */
    public function using(Transport|Closure $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function report(string $tripwire, Severity $severity, string $token, array $context, ?Request $request, bool $throttle = true): Outcome
    {
        try {
            if (! $this->app['config']->get('viti.enabled')) {
                return Outcome::Disabled;
            }
            // A signed-in user touching a tripwire is an insider or a stolen
            // session: the most valuable signal there is.
            $userId = $this->userId();
            if ($userId !== null) {
                $severity = Severity::Critical;
            }
            if ($throttle && ! $this->firstHit($tripwire, $token, $request?->ip())) {
                return Outcome::Throttled;
            }
            $payload = Payload::make($tripwire, $token, $severity, $context, $request, $userId,
                $this->site(), $this->app->basePath(), $this->version());
        } catch (Throwable $e) {
            $this->warn('viti: could not build a trip report', ['tripwire' => $tripwire, 'error' => $e->getMessage()]);

            return Outcome::Failed;
        }

        $outcome = Outcome::Sent;
        try {
            $transport = $this->transport ?? $this->configured();
            $transport instanceof Closure ? $transport($payload) : $transport->send($payload);
        } catch (Throwable $e) {
            // The trip still counts: the log keeps it for whoever looks.
            $this->warn('viti: trip not delivered', ['error' => $e->getMessage(), 'payload' => $payload]);
            $outcome = Outcome::Failed;
        }

        try {
            $this->app['events']->dispatch(new TripwireTriggered($payload));
        } catch (Throwable $e) {
            $this->warn('viti: a TripwireTriggered listener failed', ['error' => $e->getMessage()]);
        }

        return $outcome;
    }

    /** Logs at warning on the configured channel, and never throws. */
    public function warn(string $message, array $context = []): void
    {
        try {
            $channel = $this->app['config']->get('viti.log_channel');
            ($channel ? Log::channel($channel) : Log::getFacadeRoot())->warning($message, $context);
        } catch (Throwable) {
            // Nowhere left to say it.
        }
    }

    public function describe(): string
    {
        if ($this->transport !== null) {
            return 'custom transport';
        }

        return match ($this->app['config']->get('viti.transport')) {
            'spool' => 'spool: '.$this->app['config']->get('viti.spool_path'),
            'log' => 'log',
            default => 'unknown transport',
        };
    }

    private function configured(): Transport
    {
        $config = $this->app['config'];

        return match ($config->get('viti.transport')) {
            'spool' => new SpoolTransport((string) $config->get('viti.spool_path')),
            'log' => new LogTransport($config->get('viti.log_channel')),
            default => throw new InvalidArgumentException('viti.transport must be "spool" or "log"'),
        };
    }

    private function userId(): int|string|null
    {
        try {
            $guard = $this->app['auth']->guard();

            return $guard->check() ? $guard->id() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * True for the first hit in the window. If the cache is down, every
     * hit reports: better noisy than blind.
     */
    private function firstHit(string $tripwire, string $token, ?string $ip): bool
    {
        try {
            $key = 'viti:'.hash('sha256', $tripwire."\0".$token."\0".($ip ?? ''));

            return Cache::add($key, 1, (int) $this->app['config']->get('viti.throttle_seconds', 600));
        } catch (Throwable) {
            return true;
        }
    }

    private function site(): string
    {
        $config = $this->app['config'];

        return (string) ($config->get('viti.site') ?: parse_url((string) $config->get('app.url'), PHP_URL_HOST) ?: 'unknown');
    }

    private function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('realmwatch/laravel-viti') ?? 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
