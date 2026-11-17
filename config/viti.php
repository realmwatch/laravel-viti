<?php

use Realmwatch\Viti\Tripwires\BaitFiles;
use Realmwatch\Viti\Tripwires\Breadcrumbs;
use Realmwatch\Viti\Tripwires\DecoyRoutes;
use Realmwatch\Viti\Tripwires\HoneyToken;
use Realmwatch\Viti\Tripwires\HoneyUser;
use Realmwatch\Viti\Tripwires\MassAssignment;

return [

    'enabled' => env('VITI_ENABLED', true),

    // How Heimdall names this app in alerts. Null: APP_URL's host. Heimdall
    // also gets base_path(), which it matches to the site it inventories.
    'site' => env('VITI_SITE'),

    // Where trips go:
    //   spool: one JSON file per trip in `spool_path`; the Heimdall collector
    //          on the server ships them with its own credentials, so this
    //          app holds no secret (docs/heimdall-contract.md).
    //   log:   the log channel only (local development, or no collector).
    // Viti::reportUsing() swaps in your own transport.
    'transport' => env('VITI_TRANSPORT', 'spool'),

    'spool_path' => env('VITI_SPOOL', '/var/lib/heimdall-viti'),

    // Report Cloudflare's cf-connecting-ip, cf-ray, cf-ipcountry and
    // cf-visitor headers. Turn it on only if the server accepts traffic
    // from Cloudflare alone: otherwise anyone can forge them. The reported
    // IP is always Laravel's $request->ip() (set up trusted proxies for it).
    'cloudflare' => env('VITI_CLOUDFLARE', false),

    // Trips that can't be delivered, and Viti's own errors, are logged here
    // at warning. Null: the default channel. Viti never throws into a request.
    'log_channel' => env('VITI_LOG_CHANNEL'),

    // One report per tripwire, token and IP per window; repeat hits inside
    // the window are dropped, so a scanner can't flood Heimdall.
    'throttle_seconds' => 600,

    // Every tripwire, built in or yours: name => options. 'class' implements
    // Realmwatch\Viti\Contracts\Tripwire and gets the rest of its entry as
    // options. Add your own here, or call Viti::extend() from a provider.
    'tripwires' => [

        // Paths scanners probe that this app doesn't have. Tripped only when
        // the app answers 404, so a real route is never shadowed.
        'decoy_routes' => [
            'class' => DecoyRoutes::class,
            'enabled' => true,
            'paths' => DecoyRoutes::DEFAULT_PATHS,
        ],

        // /.env and /backup.sql serve a fake .env and mysqldump, unique to
        // this site and derived from `seed` (viti:install writes it). This
        // entry comes after decoy_routes, so its /.env decoy wins. Without a
        // seed it does nothing. `db_host` is the bait's DB_HOST: point it at
        // a decoy database host to catch anyone who tries the credentials.
        // Null: a plausible local address.
        'bait_files' => [
            'class' => BaitFiles::class,
            'enabled' => true,
            'seed' => env('VITI_BAIT_SEED'),
            'db_host' => env('VITI_BAIT_DB_HOST'),
        ],

        // A per-site path (viti:install writes it) that only someone reading
        // your HTML source or robots.txt would find. Put @viti in a layout to
        // plant an HTML comment that points at it, worded per site from the
        // seed; viti:install prints the robots.txt lines.
        'breadcrumbs' => [
            'class' => Breadcrumbs::class,
            'enabled' => true,
            'path' => env('VITI_BREADCRUMB_PATH'),
            'seed' => env('VITI_BAIT_SEED'),
        ],

        // A fake account (viti:seed creates it). Any login attempt with its
        // email is critical. It's hidden from every query on `model` (null:
        // the auth provider's), and mail or notifications to it are
        // cancelled and trip high. Guard your own side effects with
        // Viti::isHoney($user).
        'honey_user' => [
            'class' => HoneyUser::class,
            'enabled' => true,
            'email' => env('VITI_HONEY_EMAIL'),
            'id' => env('VITI_HONEY_USER_ID'),
            'model' => null,
        ],

        // A fake API token (viti:seed prints it once, and keeps only its
        // sha256 here). Presenting it as a bearer token is critical.
        'honey_token' => [
            'class' => HoneyToken::class,
            'enabled' => true,
            'hash' => env('VITI_HONEY_TOKEN_HASH'),
        ],

        // Input fields only a mass-assignment probe sends. Opt-in: add the
        // "viti.bait" middleware to a route group, and ->withoutViti() to a
        // route that legitimately takes one (an admin form setting "role").
        'mass_assignment' => [
            'class' => MassAssignment::class,
            'enabled' => true,
            'keys' => MassAssignment::DEFAULT_KEYS,
        ],

    ],

];
