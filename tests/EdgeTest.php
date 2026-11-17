<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Realmwatch\Viti\Facades\Viti;
use Realmwatch\Viti\Support\BaitGenerator;
use Realmwatch\Viti\Tripwires\BaitFiles;
use Realmwatch\Viti\Tripwires\Breadcrumbs;

it('serves the same per-site bait on /.env and /backup.sql, and reports only its fingerprint', function () {
    Viti::extend('bait_files', BaitFiles::class, ['seed' => 'site-seed']);

    $env = $this->get('/.env')->assertOk()->getContent();
    expect($this->get('/.env')->getContent())->toBe($env);
    preg_match('/^DB_PASSWORD=(.+)$/m', $env, $password);
    preg_match('/^APP_KEY=base64:(.+)$/m', $env, $key);
    expect($env)->toContain('STRIPE_SECRET=sk_live_', 'MAIL_PASSWORD=')
        ->and($this->get('/backup.sql')->assertOk()->getContent())->toContain('INSERT INTO `users`');

    [$trip] = trips();
    $json = json_encode(trips());
    expect($trip['tripwire'])->toBe('bait_files')
        ->and($trip['severity'])->toBe('high')
        ->and($trip['context']->bait_fingerprint)->toBe(hash('sha256', $password[1]))
        ->and($json)->not->toContain(substr($password[1], 0, 12))
        ->and($json)->not->toContain(substr($key[1], 0, 12));
});

it('gives each site differently shaped bait, with nothing that names Viti', function () {
    $shapes = [];
    foreach (range(1, 8) as $i) {
        $bait = new BaitGenerator("seed-{$i}", 'Acme', 'https://app.example.com');
        $env = $bait->env();
        preg_match_all('/^([A-Z_]+)=/m', $env, $keys);
        $shapes[] = implode(',', $keys[1]);
        expect($env.$bait->sql().$bait->comment('/x'))->not->toMatch('/viti|heimdall|realmwatch|bait/i');
    }

    expect(array_unique($shapes))->toHaveCount(8);
});

it('plants a breadcrumb comment worded per site, and trips high on its path with a plain 404', function () {
    expect(Blade::render('@viti', deleteCachedView: true))->toBe('');

    $comments = [];
    foreach (range(1, 6) as $i) {
        Viti::extend('breadcrumbs', Breadcrumbs::class, ['path' => '/staff-old-k3m9', 'seed' => "seed-{$i}"]);
        // A different template each time: compiled views are cached per template.
        $comments[] = trim(Blade::render('<p>@viti</p>'.str_repeat(' ', $i), deleteCachedView: true));
    }
    expect($comments[0])->toStartWith('<p><!-- ')->toContain('/staff-old-k3m9')
        ->and(array_unique($comments))->not->toHaveCount(1);

    $crumb = $this->get('/staff-old-k3m9/login')->assertNotFound();
    $plain = $this->get('/nothing-here')->assertNotFound();
    expect($crumb->getContent())->toBe($plain->getContent())
        ->and(trips())->toHaveCount(1)
        ->and(trips()[0])->toMatchArray(['tripwire' => 'breadcrumbs', 'token' => '/staff-old-k3m9', 'severity' => 'high']);
});

it('installs without overwriting values, and routes the decoys nginx would keep from Laravel', function () {
    $dir = sys_get_temp_dir().'/viti-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/.env", "APP_NAME=Acme\nVITI_BAIT_SEED=keep-me\nVITI_BREADCRUMB_PATH=\n");
    $this->app->useEnvironmentPath($dir);
    $published = file_exists(config_path('viti.php'));

    try {
        Artisan::call('viti:install');
        $once = file_get_contents("{$dir}/.env");
        $output = Artisan::output();
        Artisan::call('viti:install');

        expect(file_get_contents("{$dir}/.env"))->toBe($once)
            ->and($once)->toContain("VITI_BAIT_SEED=keep-me\n")
            ->and($once)->toMatch('#^VITI_BREADCRUMB_PATH=/[a-z0-9_/-]+$#m')
            ->and($output)->toContain('rewrite ^ /index.php last;', '/\.env', '/wp\-login\.php', '/xmlrpc\.php', 'eval\-stdin\.php', '/wp\-admin/.*\.php');
    } finally {
        if (! $published) {
            @unlink(config_path('viti.php'));
        }
        unlink("{$dir}/.env");
        rmdir($dir);
    }
});
