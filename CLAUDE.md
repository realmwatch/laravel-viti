# Viti: working on this package

Laravel package (`realmwatch/laravel-viti`, namespace `Realmwatch\Viti`): tripwires that report trips to Heimdall. PHP 8.3+, Laravel 12 and 13. Read README.md and docs/heimdall-contract.md first.

## Architecture (keep it this small)

- `Viti` is the registry and the only entry point: `trip()`, `decoy()`, `decoys()`, `extend()`, `reportUsing()`, plus `honey()` and `isHoney()` for fake records.
- A tripwire implements `Contracts\Tripwire::register(Viti $viti, array $options)` and wires itself up with those same calls, plus Laravel events, listeners and middleware. Built-in tripwires get no private API: an app's own tripwire must be able to do anything theirs can.
- Decoys trip from the global `Http\DetectDecoys` middleware, and only on a response that's already a 404. That's why there's no route registration and no collision check.
- `Reporting\Reporter`: escalate (signed in → critical) → throttle (`Cache::add`) → `Reporting\Payload` (redaction by allowlist) → transport → the `TripwireTriggered` event. It never throws.
- Transports: `spool` (default, for the Heimdall collector) and `log`. There's no HTTP client, queue or HMAC: the collector already has the server's credentials. Don't add Saloon or Guzzle. If an app ever has no collector, add an HTTP transport with Laravel's `Http` client.
- No migrations, no UI, no Filament dependency. Filament logins and records use Laravel's auth and Eloquent events, so they're covered without one.

## Rules

- **Never break or slow the host app.** Every path from a request into Viti is wrapped, and every failure is logged, never thrown.
- **Never send personal data or secrets.** New payload fields are allowlisted in `Payload`, and the redaction test covers them.
- Keep the payload schema (`viti.trip.v1`) stable. A breaking change needs `v2` and a note in docs/heimdall-contract.md.

## Tests

Only high-value tests: ones that would catch a leak, a thrown exception in a request, a flood, or a tripwire that silently stops firing. No tests of getters, config defaults or framework behavior. Before adding a test, break the code it guards and check that it fails.

```sh
vendor/bin/pest
vendor/bin/pint
```

CI runs PHP 8.3 + Laravel 12 and PHP 8.4 + Laravel 13.
