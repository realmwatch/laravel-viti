# How trips reach Heimdall

Viti never talks to the network. It writes each trip to a local spool directory, and the Heimdall collector on the same server ships the files to the hub with the credentials it already has. So:

- the app holds no Heimdall secret, and there's no HMAC to sign or rotate;
- a trip costs the request one cache write and one small file write, with no queue;
- the hub knows which server a trip came from, because the collector authenticated it.

This file is the contract from Viti's side. Heimdall's side (collector 0.5.34 and later) is in [Heimdall's tripwire docs](https://github.com/realmwatch/heimdall/blob/main/docs/tripwires.md).

## The spool directory

`/var/lib/heimdall-viti` (`VITI_SPOOL` overrides it). Tripwires are opt-in per server, and this creates it:

```sh
sudo heimdall-collect upgrade --tripwires
```

Mode `1733` lets any local user (each site's PHP-FPM user) create files without being able to list or read anyone else's. The collector's user owns the directory, so it can list, read and delete every file in it.

## Files

- One trip per file, named `<id>.json`, where `<id>` is the trip's 26-character ULID.
- Viti writes `.<id>.tmp` first, sets mode `0644`, then renames it. The collector skips dot files, and deletes any older than 60 minutes.
- Each file is a regular file (not a symlink) holding one line, with no trailing newline: a single JSON object (below) that starts with `{"schema":"viti.trip.v1",`.
- Each file is at most 64 KiB. A trip is usually under 2 KB. If one would be larger, Viti replaces its context with `{"context_dropped": true}`, and as a last resort empties the request headers and query keys.

The collector deletes any file that breaks these rules.

## Shipping (collector)

- A systemd path unit sees a new file. About five seconds later the collector sends every waiting trip, each in its own request. These sends don't count toward the 12-an-hour event-report cap. Anything left over goes with the next hourly report.
- A file is deleted only after the hub stores it (HTTP 200). An older hub answers 415, and the files wait.
- Past 5,000 waiting files (hub down for days), the collector deletes the oldest, so a scanner can't fill the disk.
- `sudo -u heimdall heimdall-collect --trips` shows what's waiting.

## What the hub accepts

- It validates `schema`, `id` (a ULID), `tripwire` (snake_case, at most 64 characters), `severity` and `occurred_at` (RFC 3339). It drops trips older than 30 days or more than 15 minutes in the future.
- It keeps only the allowlisted headers, query-key names, scalar context (strings clipped at 200 characters, lists capped at 50) and the user ID.
- Stored detail is capped at 32 KiB. Past that, the context is dropped.
- It dedupes on `id`, keeps trips for 90 days, and accepts up to 720 trip requests per host per hour.

## Payload: `viti.trip.v1`

```json
{
  "schema": "viti.trip.v1",
  "id": "01JD7Q3V9X8K2M4N6P0R2S4T6V",
  "site": "app.example.com",
  "root": "/home/web/app.example.com/current",
  "tripwire": "decoy_routes",
  "token": "/.env",
  "severity": "noise",
  "occurred_at": "2026-11-17T08:12:44Z",
  "request": {
    "ip": "203.0.113.9",
    "method": "GET",
    "path": "/.env",
    "query_keys": ["x"],
    "headers": {
      "user-agent": "Mozilla/5.0 ...",
      "cf-connecting-ip": "203.0.113.9",
      "cf-ray": "8e1f00aa",
      "cf-ipcountry": "DE"
    }
  },
  "actor": { "authenticated": false, "user_id": null },
  "context": {},
  "package_version": "v0.1.0"
}
```

| Field | Notes |
|---|---|
| `site` | `VITI_SITE`, or `APP_URL`'s host. A label, not proof: the hub trusts only the server the collector authenticated. |
| `root` | `base_path()`. The hub matches it to a site it inventories. With release directories it's the release path, so match on the site's root as a prefix. |
| `tripwire` | snake_case, at most 64 characters, or the hub rejects the trip. The tripwire's name, built in (`decoy_routes`, `bait_files`, `breadcrumbs`) or the app's own. `test` comes from `viti:test`. |
| `token` | Which trap in that tripwire: a decoy path, a bait name. Group alerts by `tripwire` + `token`. |
| `severity` | `noise`, `low`, `high` or `critical`. Any trip by a signed-in user is `critical`. |
| `request` | `null` when the trip didn't come from a web request (a queue job, an Artisan command). |
| `request.ip` | Laravel's `$request->ip()`, so it's only as good as the app's trusted proxies. `cf-connecting-ip` is just a header: trust it only if the server accepts traffic from Cloudflare alone. |
| `request.headers` | Only `accept`, `accept-language`, `referer` (without its query string), `user-agent`, `cf-connecting-ip`, `cf-ray`, `cf-ipcountry` and `cf-visitor`. Never cookies or credentials. |
| `request.query_keys` | Names only, never values. |
| `actor.user_id` | A string. Never a name or email. |
| `context` | The tripwire's own detail: up to 20 snake_case keys, with scalars or lists of scalars as values. |
| `context.bait_fingerprint` | On every `bait_files` trip: the sha256 (hex) of the bait `DB_PASSWORD`. It names the site's bait set without revealing it, and changes when `VITI_BAIT_SEED` rotates. A decoy database host can hash a password it's offered and match it back to the site that leaked it. |

There's never a request body, a cookie, an `Authorization` header or a query value in a trip.

A repeat of the same `tripwire` + `token` from the same IP within 10 minutes isn't reported at all (the throttle drops it).

## Severity routing (hub)

- `noise`: stored only, with no UI yet.
- `low`: a low anomaly, with no alert.
- `high` and `critical`: a high anomaly, alerted at once.

There's at most one open anomaly per `tripwire`, `token`, IP and day.
