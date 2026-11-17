<?php

namespace Realmwatch\Viti\Tripwires;

use Illuminate\Support\Facades\Blade;
use Realmwatch\Viti\Contracts\Tripwire;
use Realmwatch\Viti\Decoy;
use Realmwatch\Viti\Severity;
use Realmwatch\Viti\Support\BaitGenerator;
use Realmwatch\Viti\Viti;

/**
 * A path only someone reading this site's HTML source or robots.txt would
 * find: @viti renders an HTML comment pointing at it, and viti:install
 * prints the robots.txt lines. Anything under the path trips high and gets
 * the app's own 404.
 *
 * The path (VITI_BREADCRUMB_PATH) and the comment's wording (from
 * VITI_BAIT_SEED) are random per site. @viti is compiled into the view, so
 * run view:clear after changing either (deploys usually do).
 */
final class Breadcrumbs implements Tripwire
{
    public function register(Viti $viti, array $options): void
    {
        $path = '/'.trim((string) ($options['path'] ?? ''), '/');
        if ($path === '/') {
            return;
        }
        // It lands in an HTML comment: nothing that could close it or break out.
        if (! preg_match('#^/[A-Za-z0-9_~/-]+$#', $path) || str_contains($path, '--')) {
            $viti->reporter()->warn('viti: breadcrumbs path must be letters, digits, "-", "_", "~" and "/"', ['path' => $path]);

            return;
        }

        $viti->decoy(new Decoy($path.'/*', 'breadcrumbs', Severity::High, token: $path));

        $seed = (string) ($options['seed'] ?? '');
        if ($seed !== '' && app()->bound('blade.compiler')) {
            $comment = (new BaitGenerator($seed))->comment($path);
            Blade::directive('viti', fn () => $comment);
        }
    }

    /** A path for viti:install: random, but like one a person picked. */
    public static function randomPath(): string
    {
        $pick = fn (array $a) => $a[random_int(0, count($a) - 1)];
        $area = $pick(['admin', 'staff', 'internal', 'ops', 'backoffice', 'manage', 'portal', 'console', 'support', 'reports']);
        $tag = $pick(['legacy', 'old', 'v1', 'beta', 'staging', 'tools', 'panel', 'temp', 'dev']);
        $suffix = '';
        for ($i = random_int(4, 6); $i > 0; $i--) {
            $suffix .= 'abcdefghijkmnpqrstuvwxyz23456789'[random_int(0, 31)];
        }

        return $pick(["/{$area}-{$tag}-{$suffix}", "/{$area}/{$tag}-{$suffix}", "/_{$area}-{$suffix}", "/{$tag}-{$area}/{$suffix}"]);
    }
}
