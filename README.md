# Viti

Tripwires for Laravel apps. Viti plants decoys that legitimate users never touch: scanner paths, breadcrumbs only a human reading your source would find, honey credentials. Whoever touches one gets reported to [Heimdall](https://github.com/realmwatch/heimdall).

Viti only detects and reports. It never blocks, bans or slows the app, and every failure is caught and logged.

## Install

```sh
composer require realmwatch/laravel-viti
php artisan vendor:publish --tag=viti-config   # optional
php artisan viti:test                          # sends a test trip
php artisan viti:list                          # what's armed
```

Trips go to a spool directory that the Heimdall collector on the server ships (see [docs/heimdall-contract.md](docs/heimdall-contract.md)). Without a collector, set `VITI_TRANSPORT=log`.

## Extending it

Everything built in uses the same four calls, so your app's own tripwires can do anything the built-in ones do.

```php
use Realmwatch\Viti\Decoy;
use Realmwatch\Viti\Facades\Viti;
use Realmwatch\Viti\Severity;

// Raise the alarm from anywhere: a policy, a job, an export.
Viti::trip('fake_export', Severity::Critical, token: 'patients-csv');

// A decoy path. It trips only when your app answers it with 404, so a real
// route is never shadowed. "/*" matches everything under it.
Viti::decoy('/api/v0/debug/users');
Viti::decoy(new Decoy('/backup/*', 'breadcrumbs', Severity::High,
    respond: fn ($request, $notFound) => response('', 403)));

// Decoys worked out on first use (a query, a file).
Viti::decoys(fn () => Something::decoyPaths());

// A whole tripwire: a class implementing Contracts\Tripwire, or a closure.
Viti::extend('honey_invoice', function ($viti, array $options) {
    Invoice::retrieved(function ($invoice) use ($viti) {
        if ($invoice->getKey() === 999_999) {
            $viti->trip('honey_invoice', Severity::Critical);
        }
    });
});
```

Tripwire classes can also go in `config/viti.php` under `tripwires`, with their options, like the built-in ones.

Listen for `Realmwatch\Viti\Events\TripwireTriggered` to act on a trip yourself.

## What a report contains

Only an allowlist: the tripwire, the path, the IP, a few headers, query *names*, and a user ID if someone's signed in. Never a body, cookie, credential, query value, name or email. Details in [docs/heimdall-contract.md](docs/heimdall-contract.md).

## License

MIT
