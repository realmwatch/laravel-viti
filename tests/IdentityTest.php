<?php

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Realmwatch\Viti\Facades\Viti;
use Realmwatch\Viti\Tripwires\HoneyToken;
use Realmwatch\Viti\Tripwires\HoneyUser;

class IdentityTestUser extends User
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $table = 'users';
}

class IdentityTestNotice extends Notification
{
    public static array $sentTo = [];

    public function via(): array
    {
        return [IdentityTestChannel::class];
    }
}

class IdentityTestChannel
{
    public function send($notifiable): void
    {
        IdentityTestNotice::$sentTo[] = $notifiable->id;
    }
}

beforeEach(function () {
    config(['auth.providers.users.model' => IdentityTestUser::class, 'mail.default' => 'array']);
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('personal_access_tokens', function (Blueprint $table) {
        $table->id();
        $table->morphs('tokenable');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
});

it('trips critical on any sign-in attempt as the honey user, without reporting its email', function () {
    Viti::extend('honey_user', HoneyUser::class, ['email' => 'j.carter@example.com']);

    // Laravel's guard, as the app's own form, Filament and Fortify all use.
    expect(Auth::attempt(['email' => ' J.Carter@Example.com', 'password' => 'guess']))->toBeFalse();
    $honey = IdentityTestUser::forceCreate(['name' => 'J', 'email' => 'j.carter@example.com', 'password' => 'x']);
    Auth::login($honey);

    expect(trips())->toHaveCount(2)
        ->and(array_column(trips(), 'token'))->toBe(['attempt', 'login'])
        ->and(array_unique(array_column(trips(), 'severity')))->toBe(['critical'])
        ->and(json_encode(trips()))->not->toContain('carter');
});

it('seeds the honey user and token once, hides the user everywhere, and trips on the token before auth', function () {
    $dir = sys_get_temp_dir().'/viti-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/.env", "APP_NAME=Acme\n");
    $this->app->useEnvironmentPath($dir);
    $real = IdentityTestUser::forceCreate(['name' => 'Real', 'email' => 'real@example.com', 'password' => 'x']);

    try {
        expect(Artisan::call('viti:seed'))->toBe(0);
        preg_match('/^\d+\|\S+$/m', Artisan::output(), $token);
        $env = file_get_contents("{$dir}/.env");
        expect(Artisan::call('viti:seed'))->toBe(0)
            ->and(file_get_contents("{$dir}/.env"))->toBe($env)
            ->and(IdentityTestUser::withoutGlobalScopes()->count())->toBe(2);
    } finally {
        unlink("{$dir}/.env");
        rmdir($dir);
    }
    preg_match_all('/^(VITI_\w+)=(.*)$/m', $env, $m);
    $values = array_combine($m[1], $m[2]);
    Viti::extend('honey_user', HoneyUser::class, ['email' => $values['VITI_HONEY_EMAIL'], 'id' => $values['VITI_HONEY_USER_ID']]);
    Viti::extend('honey_token', HoneyToken::class, ['hash' => $values['VITI_HONEY_TOKEN_HASH']]);

    // Out of every query, even one that includes soft-deleted rows.
    expect(IdentityTestUser::withTrashed()->pluck('id')->all())->toBe([$real->id]);
    // And soft-deleted, for queries that skip Eloquent's scopes but not deleted_at.
    expect(IdentityTestUser::withoutGlobalScope('viti_honey')->pluck('id')->all())->toBe([$real->id]);

    // Stored as Sanctum stores it, so the token is real to the app too.
    expect(DB::table('personal_access_tokens')->value('token'))->toBe($values['VITI_HONEY_TOKEN_HASH']);

    // Mail and notifications to it are cancelled, and trip.
    $honey = IdentityTestUser::withoutGlobalScopes()->find($values['VITI_HONEY_USER_ID']);
    expect(Viti::isHoney($honey))->toBeTrue()->and(Viti::isHoney($real))->toBeFalse();
    $honey->notify(new IdentityTestNotice);
    $real->notify(new IdentityTestNotice);
    Mail::raw('Your invoice', fn ($message) => $message->to(strtoupper($values['VITI_HONEY_EMAIL'])));
    Mail::raw('Your invoice', fn ($message) => $message->to('real@example.com'));
    expect(IdentityTestNotice::$sentTo)->toBe([$real->id])
        ->and(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(array_column(trips(), 'token'))->toBe(['notification', 'mail']);

    // The printed token trips before the app's auth turns it away.
    Route::get('/api/me', fn () => abort(401));
    $this->withToken($token[0])->getJson('/api/me')->assertUnauthorized();
    expect(trips()[2])->toMatchArray(['tripwire' => 'honey_token', 'severity' => 'critical'])
        ->and(json_encode(trips()))->not->toContain(explode('|', $token[0])[1]);
});

it('trips on bait fields in an opted-in group, sending names only', function () {
    Route::middleware('viti.bait')->group(function () {
        Route::post('/profile', fn () => 'ok');
        Route::post('/admin/users', fn () => 'ok')->withoutViti();
    });

    $this->post('/admin/users', ['role' => 'editor'])->assertOk();
    $this->post('/profile', ['name' => 'x', 'user' => ['Is_Admin' => 'secret-value']])->assertOk();

    expect(trips())->toHaveCount(1)
        ->and(trips()[0])->toMatchArray(['tripwire' => 'mass_assignment', 'token' => 'profile', 'severity' => 'high'])
        ->and((array) trips()[0]['context'])->toBe(['keys' => ['is_admin']])
        ->and(json_encode(trips()))->not->toContain('secret-value');
});
