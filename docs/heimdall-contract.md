# How trips reach Heimdall

Viti never talks to the network. It writes each trip to a local spool directory, and the Heimdall collector on the same server ships the files to the hub with the credentials it already has. So:

- the app holds no Heimdall secret, and there's no HMAC to sign or rotate;
- a trip costs the request one cache write and one small file write, with no queue;
- the hub knows which server a trip came from, because the collector authenticated it.

**The Heimdall side isn't built yet.** This file is the contract both sides build to.

## The spool directory

`/var/lib/heimdall-viti` (`VITI_SPOOL` overrides it). The Heimdall installer creates it:

```sh
install -d -m 1733 -o heimdall -g heimdall /var/lib/heimdall-viti
```

Mode `1733` lets any local user (each site's PHP-FPM user) create files without being able to list or read anyone else's. The collector's user owns the directory, so it can list, read and delete every file in it.

## Files

- One trip per file, named `<id>.json`, where `<id>` is the trip's ULID.
- Viti writes `.<id>.tmp` first, sets mode `0644`, then renames it. **The collector ignores names that start with a dot.**
- Each file is a single JSON object (below), under 8 KB in practice. The collector should skip any file over 64 KB, and anything that doesn't parse.

## Shipping (collector)

- Ship the files with each push, oldest first. Cap each push at about 200 trips or 256 KB, and leave the rest for the next push.
- Delete a file only after the hub accepts it (2xx). The hub dedupes on `id`, so a resend is harmless.
- If more than 5,000 files pile up (hub down for days), delete the oldest ones, so a scanner can't fill the disk.
- **Latency:** an hourly push is too slow for a critical trip. Suggested: a systemd path unit (`DirectoryNotEmpty=/var/lib/heimdall-viti`) that starts a light trips-only push, rate-limited to about one a minute. Without systemd, the hourly push.

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
| `tripwire` | The tripwire's name, built in (`decoy_routes`, ...) or the app's own. `test` comes from `viti:test`. |
| `token` | Which trap in that tripwire: a decoy path, a bait name. Group alerts by `tripwire` + `token`. |
| `severity` | `noise`, `low`, `high` or `critical`. Any trip by a signed-in user is `critical`. |
| `request` | `null` when the trip didn't come from a web request (a queue job, an Artisan command). |
| `request.ip` | Laravel's `$request->ip()`, so it's only as good as the app's trusted proxies. `cf-connecting-ip` is just a header: trust it only if the server accepts traffic from Cloudflare alone. |
| `request.headers` | Only `accept`, `accept-language`, `referer` (without its query string), `user-agent`, `cf-connecting-ip`, `cf-ray`, `cf-ipcountry` and `cf-visitor`. Never cookies or credentials. |
| `request.query_keys` | Names only, never values. |
| `actor.user_id` | A string. Never a name or email. |
| `context` | The tripwire's own detail: up to 20 snake_case keys, with scalars or lists of scalars as values. |

There's never a request body, a cookie, an `Authorization` header or a query value in a trip.

A repeat of the same `tripwire` + `token` from the same IP within 10 minutes isn't reported at all (the throttle drops it).

## Severity routing (hub)

- `noise`: a daily rollup per site, correlated across the fleet by IP.
- `low`, `high`: an alert.
- `critical`: page someone now.
