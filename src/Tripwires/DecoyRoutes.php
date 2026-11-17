<?php

namespace Realmwatch\Viti\Tripwires;

use Realmwatch\Viti\Contracts\Tripwire;
use Realmwatch\Viti\Decoy;
use Realmwatch\Viti\Severity;
use Realmwatch\Viti\Viti;

/**
 * Paths scanners probe. Noise on its own, but Heimdall correlates the
 * addresses across the fleet.
 *
 * Some never reach Laravel on a stock nginx: dotfiles are denied and a
 * missing .php file is answered by PHP-FPM. viti:install prints the nginx
 * lines that route them to the app.
 */
final class DecoyRoutes implements Tripwire
{
    public const DEFAULT_PATHS = [
        '/.env', '/.env.bak', '/.git/config', '/.git/HEAD', '/.aws/credentials',
        '/wp-login.php', '/wp-admin/*', '/xmlrpc.php',
        '/phpmyadmin/*', '/pma/*', '/server-status', '/actuator/env', '/config.json',
        '/_ignition/execute-solution', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php',
        // Safe even with Telescope or Horizon installed: they don't answer 404.
        '/telescope/*', '/horizon/*',
    ];

    public function register(Viti $viti, array $options): void
    {
        foreach ($options['paths'] ?? self::DEFAULT_PATHS as $path) {
            $viti->decoy(new Decoy($path, 'decoy_routes', Severity::Noise));
        }
    }
}
