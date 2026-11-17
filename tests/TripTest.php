<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Realmwatch\Viti\Facades\Viti;
use Realmwatch\Viti\Reporting\Outcome;
use Realmwatch\Viti\Reporting\Reporter;
use Realmwatch\Viti\Severity;
use Realmwatch\Viti\Transports\SpoolTransport;

it('never reports cookies, credentials, query values, the body, or unvouched cf-* headers', function () {
    Route::post('/bait', fn () => Viti::trip('mass_assignment', Severity::High, context: ['keys' => ['is_admin']]));
    $send = fn () => $this->withHeaders([
        'Authorization' => 'Bearer secret-bearer',
        'Cookie' => 'session=secret-cookie',
        'Cf-Access-Jwt-Assertion' => 'secret-jwt',
        'Referer' => 'https://app.example.com/page?email=secret-referer',
        'CF-Ray' => '8e1f00aa',
    ])->post('/bait?token=secret-query', ['password' => 'secret-body']);

    $send();
    expect((array) trips()[0]['request']['headers'])->not->toHaveKey('cf-ray');

    config(['viti.cloudflare' => true]);
    Cache::flush();
    $send();
    [, $trip] = trips();
    $json = json_encode($trip);
    expect($json)->not->toContain('secret-')
        ->and($trip['request']['query_keys'])->toBe(['token'])
        ->and((array) $trip['request']['headers'])->toMatchArray(['referer' => 'https://app.example.com/page', 'cf-ray' => '8e1f00aa'])
        ->and((array) $trip['context'])->toBe(['keys' => ['is_admin']]);
});

it('escalates a signed-in user to critical, with their ID only', function () {
    $user = new User;
    $user->forceFill(['id' => 42, 'email' => 'someone@example.com']);
    $this->actingAs($user);

    Viti::trip('decoy_routes', Severity::Noise, '/.env');

    [$trip] = trips();
    expect($trip['severity'])->toBe('critical')
        ->and($trip['actor'])->toBe(['authenticated' => true, 'user_id' => '42'])
        ->and(json_encode($trip))->not->toContain('someone@');
});

it('reports a scanner once per window, however often it hits', function () {
    foreach (range(1, 100) as $_) {
        $this->get('/.env');
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/.env');

    expect(trips())->toHaveCount(2);
});

it('trips a decoy only when the app would 404, and never caches it', function () {
    Route::get('/server-status', fn () => 'real page');
    Viti::decoys(fn () => ['/old-admin/*']);

    $this->get('/server-status')->assertOk()->assertSee('real page');
    expect(trips())->toBeEmpty();

    $decoy = $this->get('/OLD-ADMIN/users')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    $plain = $this->get('/nothing-here')->assertNotFound();
    // A scanner comparing a decoy's 404 with any other must see no difference.
    expect($decoy->headers->all())->toEqualCanonicalizing($plain->headers->all())
        ->and($decoy->getContent())->toBe($plain->getContent());
    expect(trips())->toHaveCount(1)
        ->and(trips()[0]['token'])->toBe('/old-admin/*');
});

it('never throws when delivery fails, and logs the trip instead', function () {
    Log::spy();
    $dir = sys_get_temp_dir().'/viti-'.bin2hex(random_bytes(4));
    mkdir($dir);
    Viti::reportUsing(new SpoolTransport($dir));

    $this->get('/.git/config')->assertNotFound();
    expect(glob($dir.'/*.json'))->toHaveCount(1)
        ->and(json_decode(file_get_contents(glob($dir.'/*.json')[0]), true)['schema'])->toBe('viti.trip.v1');

    Viti::reportUsing(new SpoolTransport($dir.'/missing'));
    $outcome = app(Reporter::class)->report('test', Severity::Low, 'test', [], null, throttle: false);

    expect($outcome)->toBe(Outcome::Failed);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'viti: trip not delivered')->once();
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('writes each trip as one line the collector accepts, never over 64 KiB', function () {
    $dir = sys_get_temp_dir().'/viti-'.bin2hex(random_bytes(4));
    mkdir($dir);
    Viti::reportUsing(new SpoolTransport($dir));
    // Quotes escape to two bytes each: this context alone would be ~400 KB.
    $huge = array_fill_keys(array_map(fn ($i) => "key_{$i}", range(1, 20)), array_fill(0, 50, str_repeat('"', 200)));
    Route::get('/big', fn () => Viti::trip('big', Severity::High, context: $huge));

    $this->get('/big');

    [$file] = glob($dir.'/*.json');
    $json = file_get_contents($file);
    $trip = json_decode($json, true);
    expect(strlen($json))->toBeLessThanOrEqual(65536)
        ->and($json)->toStartWith('{"schema":"viti.trip.v1",')
        ->and($json)->not->toContain("\n")
        ->and(basename($file))->toMatch('/^[0-9A-Z]{26}\.json$/')
        ->and($trip['context'])->toBe(['context_dropped' => true]);
    unlink($file);
    rmdir($dir);
});
