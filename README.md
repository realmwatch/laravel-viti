# Viti

Tripwires for Laravel apps. Viti plants decoys that legitimate users never touch: scanner paths, breadcrumbs only a human reading your source would find, honey credentials. Whoever touches one gets reported to [Heimdall](https://github.com/realmwatch/heimdall).

Viti only detects and reports. It never blocks, bans or slows the app, and every failure is caught and logged.

## Install

```sh
composer require realmwatch/laravel-viti
php artisan viti:install   # config, this site's bait values, robots.txt and nginx lines
php artisan viti:seed      # the honey user and honey API token (needs the database)
php artisan viti:test      # sends a test trip
php artisan viti:list      # what's armed
```

`viti:install` publishes `config/viti.php`, adds `VITI_BAIT_SEED` and `VITI_BREADCRUMB_PATH` to `.env` (random per site, and never overwritten once set), and prints:

- the robots.txt lines for the breadcrumb path;
- an nginx block that goes above Forge's `location ~ \.php$` block and its dotfile deny rule. Without it nginx denies `/.env` and `/.git/*`, and PHP-FPM answers `/wp-login.php` with "File not found", so those decoys never reach Laravel.

If you published the config before a new tripwire was added, add its entry from the package's `config/viti.php`: a published `tripwires` list replaces the package's.

## Heimdall

Trips go to a spool directory that the Heimdall collector on the server ships to the hub within seconds (see [docs/heimdall-contract.md](docs/heimdall-contract.md)). Tripwires are opt-in per server:

```sh
sudo heimdall-collect upgrade --tripwires    # creates /var/lib/heimdall-viti
php artisan viti:test                        # a low "Tripwire test tripped" anomaly, within seconds
sudo -u heimdall heimdall-collect --trips    # what's waiting to ship
```

Without a collector, set `VITI_TRANSPORT=log`.

## Built-in tripwires

| Name | What trips it | Severity |
|---|---|---|
| `decoy_routes` | Paths scanners probe that the app doesn't have (`/.git/config`, `/wp-login.php`, ...). The app's own 404. | noise |
| `bait_files` | `/.env` and `/backup.sql`. They serve a fake production `.env` and mysqldump: an `APP_KEY`, database, mail and Stripe-shaped credentials, users with bcrypt hashes. | high |
| `breadcrumbs` | Anything under `VITI_BREADCRUMB_PATH`, which only robots.txt and the `@viti` comment mention. The app's own 404. | high |
| `honey_user` | A sign-in attempt with the honey user's email, through any login form that uses Laravel's auth (the app's, Filament's, Fortify's), or a sign-in as that user by any other route. | critical |
| `honey_token` | A request presenting the honey API token as its bearer token. It trips before auth runs. | critical |
| `mass_assignment` | Input fields only a mass-assignment probe sends (`is_admin`, `role`, ...), at any depth, on routes that opt in. Only the field names are reported. | high |
| `honey_contact` | A notification or mail to a honey record, which Viti cancels. Something reached data nobody should touch. | high |

The bait is derived from `VITI_BAIT_SEED`, so a site always serves the same files, while every site's differ in values and in shape (key order, optional keys and tables, versions). The trip carries only `bait_fingerprint`, a hash. Set `VITI_BAIT_DB_HOST` to put a decoy database host in the bait. Without a seed there's no bait, and `/.env` stays a `decoy_routes` decoy.

Put `@viti` in a layout to plant an HTML comment pointing at the breadcrumb path, worded per site. It renders nothing when breadcrumbs are off. It's compiled into the view, so run `php artisan view:clear` after changing the path or seed.

`viti:seed` creates the honey user with a plausible staff name and email at your domain and a random password nobody knows. It saves the user without model events, so no observer sends it a welcome mail. With Sanctum installed, it also gives the user a real API token; without Sanctum, it makes a token in Sanctum's format. Either way it prints the token once and keeps only its hash. Plant the token where a thief would look: a config file, a deploy script, a note. Running `viti:seed` again changes nothing.

Mass-assignment bait is opt-in per route group, because an admin form may legitimately send `role`:

```php
Route::middleware('viti.bait')->group(function () {
    Route::post('/profile', ...);
    Route::post('/admin/users', ...)->withoutViti();
});
```

The package's source is public, so none of this is a fixed string: values, the path and the comment's wording are picked per site, and a decoy that serves no bait answers with the app's unchanged 404.

## Honey records

`Viti::honey(Model::class, [$id, ...])` marks fake records so they never reach a real workflow:

- a global scope leaves them out of every query on the model, including `withTrashed()`;
- `viti:seed` soft-deletes the honey user when the model uses `SoftDeletes`, so queries that skip Eloquent's scopes but check `deleted_at` miss it too;
- notifications to them and mail to the honey user's address are cancelled, and trip `honey_contact`;
- `Viti::isHoney($model)` is for your own side effects: guard shipping, charging and anything else that acts on a record.

```php
if (Viti::isHoney($order)) {
    return; // never ship a fake order
}
```

Raw `DB::table()` queries see everything, so guard them yourself.

## Cloudflare

Set `VITI_CLOUDFLARE=true` to add Cloudflare's `cf-connecting-ip`, `cf-ray`, `cf-ipcountry` and `cf-visitor` headers to reports. Turn it on only if the server accepts traffic from Cloudflare alone, or anyone can forge them. The reported IP is always Laravel's `$request->ip()`, so set up trusted proxies for it.

## Known gaps

- A decoy's 404 is answered before the session middleware runs, so a signed-in user hitting a decoy isn't seen as signed in, and their trip isn't escalated to critical.

## Extending it

Everything built in uses the same calls, so your app's own tripwires can do anything the built-in ones do.

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
