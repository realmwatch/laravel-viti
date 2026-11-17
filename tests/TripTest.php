<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Realmwatch\Viti\Facades\Viti;
use Realmwatch\Viti\Reporting\Outcome;
use Realmwatch\Viti\Reporting\Reporter;
use Realmwatch\Viti\Severity;
use Realmwatch\Viti\Transports\SpoolTransport;

it('never reports cookies, credentials, query values or the body', function () {
    Route::post('/bait', fn () => Viti::trip('mass_assignment', Severity::High, context: ['keys' => ['is_admin']]));

    $this->withHeaders([
        'Authorization' => 'Bearer secret-bearer',
        'Cookie' => 'session=secret-cookie',
        'Cf-Access-Jwt-Assertion' => 'secret-jwt',
        'Referer' => 'https://app.example.com/page?email=secret-referer',
        'CF-Ray' => '8e1f00aa',
    ])->post('/bait?token=secret-query', ['password' => 'secret-body']);

    [$trip] = trips();
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
