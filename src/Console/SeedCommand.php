<?php

namespace Realmwatch\Viti\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Realmwatch\Viti\Console\Concerns\WritesEnv;
use Realmwatch\Viti\Tripwires\HoneyToken;
use Throwable;

/**
 * Creates this site's honey user (and, with Sanctum, a honey API token) and
 * writes what Viti needs to .env. Safe to run again: it finds the user by
 * its email and never replaces a value that's set.
 *
 * The user is saved without model events, so no observer sends it a
 * welcome mail, and soft-deleted when the model supports it.
 */
final class SeedCommand extends Command
{
    use WritesEnv;

    protected $signature = 'viti:seed';

    protected $description = 'Create the honey user and honey API token, and write their details to .env';

    public function handle(): int
    {
        $options = (array) config('viti.tripwires.honey_user', []);
        $model = $options['model'] ?? config('auth.providers.users.model');
        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            $this->components->error('No user model: set viti.tripwires.honey_user.model.');

            return self::FAILURE;
        }

        [$name, $email] = $this->identity();
        $email = $this->writeEnv(['VITI_HONEY_EMAIL' => (string) (($options['email'] ?? null) ?: $email)])['VITI_HONEY_EMAIL'];
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);

        try {
            $query = $model::query()->withoutGlobalScope('viti_honey');
            $user = ($softDeletes ? $query->withTrashed() : $query)->where('email', $email)->first();
            if ($user === null) {
                $user = new $model;
                $user->forceFill(['name' => $name, 'email' => $email, 'password' => Hash::make(Str::random(64))]);
                if (Schema::hasColumn($user->getTable(), 'email_verified_at')) {
                    $user->forceFill(['email_verified_at' => now()->subDays(random_int(30, 400))]);
                }
                $user->saveQuietly();
                $this->components->twoColumnDetail('Honey user', 'created');
            } else {
                $this->components->twoColumnDetail('Honey user', 'already exists, kept');
            }
            if ($softDeletes && ! $user->trashed()) {
                $user->deleteQuietly();
            }
        } catch (Throwable $e) {
            $this->components->error('Could not create the honey user: '.$e->getMessage());
            $this->line("Create a user with the email {$email} yourself, with a random password, and set VITI_HONEY_USER_ID to its ID.");

            return self::FAILURE;
        }
        $this->writeEnv(['VITI_HONEY_USER_ID' => (string) $user->getKey()]);

        if (filled(config('viti.tripwires.honey_token.hash')) || $this->envHas('VITI_HONEY_TOKEN_HASH')) {
            $this->components->twoColumnDetail('Honey token', 'already exists, kept');
        } else {
            $token = $this->token($user);
            $this->writeEnv(['VITI_HONEY_TOKEN_HASH' => HoneyToken::hash($token)]);
            $this->newLine();
            $this->line('The honey API token. It is shown only this once, and only its hash is kept:');
            $this->newLine();
            $this->line($token);
            $this->newLine();
            $this->line('Plant it where a thief would look: a config file, a deploy script, a note.');
        }

        $this->newLine();
        $this->line('Then: php artisan config:cache (if you cache config).');

        return self::SUCCESS;
    }

    /**
     * A Sanctum token on the honey user when Sanctum is installed, so it's in
     * personal_access_tokens like a real one; otherwise one shaped like it.
     */
    private function token(Model $user): string
    {
        $name = $this->pick(['deploy', 'ci', 'backup', 'integration', 'zapier', 'reporting']);
        if (method_exists($user, 'createToken')) {
            return $user->createToken($name)->plainTextToken;
        }
        $random = (string) config('sanctum.token_prefix', '').Str::random(40);

        return random_int(10, 9999).'|'.$random.hash('crc32b', $random);
    }

    /** @return array{string, string} a plausible staff name and email at this site's domain */
    private function identity(): array
    {
        $first = $this->pick(['James', 'Maria', 'Robert', 'Linda', 'Michael', 'Sarah', 'David', 'Karen', 'Daniel', 'Laura', 'Kevin', 'Emily', 'Brian', 'Megan', 'Jason', 'Rachel']);
        $last = $this->pick(['Carter', 'Nguyen', 'Patel', 'Morales', 'Bennett', 'Hughes', 'Foster', 'Reyes', 'Coleman', 'Hayes', 'Price', 'Warren', 'Sullivan', 'Ortiz', 'Fisher', 'Graham']);
        $domain = preg_replace('/^www\./', '', (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
        $local = $this->pick([strtolower("{$first}.{$last}"), strtolower($first[0].$last), strtolower("{$first}{$last[0]}")]);

        return ["{$first} {$last}", "{$local}@{$domain}"];
    }

    /** @param  non-empty-list<string>  $options */
    private function pick(array $options): string
    {
        return $options[random_int(0, count($options) - 1)];
    }

    private function envHas(string $key): bool
    {
        $file = $this->laravel->environmentFilePath();

        return is_file($file) && preg_match('/^'.preg_quote($key, '/').'=["\']?[^\s"\']+/m', (string) file_get_contents($file)) === 1;
    }
}
