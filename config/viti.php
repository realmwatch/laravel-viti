<?php

use Realmwatch\Viti\Tripwires\BaitFiles;
use Realmwatch\Viti\Tripwires\Breadcrumbs;
use Realmwatch\Viti\Tripwires\DecoyRoutes;

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

    ],

];
