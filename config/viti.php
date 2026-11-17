<?php

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

    ],

];
