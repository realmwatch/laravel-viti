<?php

namespace Realmwatch\Viti\Console;

use Illuminate\Console\Command;
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
     * Adds each key that's missing or empty, and keeps any value already
     * there. Returns the values now in effect.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function writeEnv(array $values): array
    {
        $file = $this->laravel->environmentFilePath();
        if (! is_file($file)) {
            $this->components->warn("No {$file}. Add these yourself:");
            foreach ($values as $key => $value) {
                $this->line("{$key}={$value}");
            }

            return $values;
        }

        $env = (string) file_get_contents($file);
        $original = $env;
        foreach ($values as $key => $value) {
            if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m)) {
                $current = trim($m[1], " \t\r\"'");
                if ($current !== '') {
                    $values[$key] = $current;
                    $this->components->twoColumnDetail($key, 'already set, kept');

                    continue;
                }
                $env = str_replace($m[0], "{$key}={$value}", $env);
            } else {
                $env = rtrim($env, "\n")."\n{$key}={$value}\n";
            }
            $this->components->twoColumnDetail($key, 'written to .env');
        }
        if ($env !== $original) {
            file_put_contents($file, $env);
        }

        return $values;
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
