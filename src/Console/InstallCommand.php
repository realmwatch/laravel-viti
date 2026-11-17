<?php

namespace Realmwatch\Viti\Console;

use Illuminate\Console\Command;
use Realmwatch\Viti\Console\Concerns\WritesEnv;
use Realmwatch\Viti\Decoy;
use Realmwatch\Viti\Tripwires\Breadcrumbs;
use Realmwatch\Viti\Viti;

/**
 * Sets a site up: publishes the config, writes this site's random bait seed
 * and breadcrumb path to .env, and prints the robots.txt and nginx lines.
 * Safe to run again: it never changes a value that's already set.
 */
final class InstallCommand extends Command
{
    use WritesEnv;

    protected $signature = 'viti:install';

    protected $description = 'Publish the Viti config, write this site\'s bait values to .env, and print the robots.txt and nginx lines';

    public function handle(Viti $viti): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'viti-config']);
        $this->components->info('Config: config/viti.php (an existing one is left alone).');

        $values = $this->writeEnv([
            'VITI_BAIT_SEED' => bin2hex(random_bytes(32)),
            'VITI_BREADCRUMB_PATH' => Breadcrumbs::randomPath(),
        ]);
        $path = '/'.trim($values['VITI_BREADCRUMB_PATH'], '/');

        $this->newLine();
        $this->line('Add to public/robots.txt:');
        $this->newLine();
        $this->line("User-agent: *\nDisallow: {$path}");
        $this->newLine();
        $this->line('Put @viti in a layout to plant the HTML comment that points there.');

        $this->newLine();
        $this->line('Add to the site\'s nginx config, above the "location ~ \.php$" block and the');
        $this->line('dotfile deny rule (location ~ /\.(?!well-known).*). Without it nginx denies the');
        $this->line('dotfile decoys and PHP-FPM answers the .php ones, so they never reach Laravel:');
        $this->newLine();
        $this->line($this->nginx($viti));
        $this->newLine();
        $this->line('Then: php artisan config:cache (if you cache config) and php artisan viti:test.');

        return self::SUCCESS;
    }

    /**
     * One location block for every decoy a stock nginx keeps from Laravel:
     * dotfiles (denied) and .php files (answered by PHP-FPM).
     */
    private function nginx(Viti $viti): string
    {
        $patterns = [];
        foreach ($viti->allDecoys() as $decoy) {
            /** @var Decoy $decoy */
            $path = '/'.ltrim(strtolower($decoy->path), '/');
            if (str_ends_with($path, '/*')) {
                $prefix = substr($path, 0, -2);
                $patterns[] = str_contains($prefix, '/.') ? preg_quote($prefix).'(/.*)?' : preg_quote($prefix).'/.*\.php';
            } elseif (str_contains($path, '/.') || str_ends_with($path, '.php')) {
                $patterns[] = preg_quote($path);
            }
        }
        $patterns = array_values(array_unique($patterns));
        if ($patterns === []) {
            return '# (no decoy needs it)';
        }

        return 'location ~* "^(?:'.implode('|', $patterns).")$\" {\n    rewrite ^ /index.php last;\n}";
    }
}
