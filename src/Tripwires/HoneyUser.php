<?php

namespace Realmwatch\Viti\Tripwires;

use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Login;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Realmwatch\Viti\Contracts\Tripwire;
use Realmwatch\Viti\Severity;
use Realmwatch\Viti\Viti;
use Throwable;

/**
 * A fake account (viti:seed creates it) that nobody legitimately signs in
 * as. Anyone trying its email has found it in a dump, an export or a
 * credential list: critical. It trips on Laravel's own auth events, so
 * every login form (the app's, Filament's, Fortify's) is covered, even
 * though the account is hidden from every query.
 *
 * The account is marked with Viti::honey(), and mail to its address is
 * cancelled and trips high, so it never gets a real email.
 */
final class HoneyUser implements Tripwire
{
    public function register(Viti $viti, array $options): void
    {
        $email = strtolower(trim((string) ($options['email'] ?? '')));
        if ($email === '') {
            return;
        }

        Event::listen(Attempting::class, function (Attempting $event) use ($viti, $email) {
            try {
                foreach ($event->credentials as $field => $value) {
                    if ($field !== 'password' && is_string($value) && strtolower(trim($value)) === $email) {
                        $viti->trip('honey_user', Severity::Critical, 'attempt', ['guard' => (string) $event->guard]);

                        return;
                    }
                }
            } catch (Throwable $e) {
                $viti->reporter()->warn('viti: honey user check failed', ['error' => $e->getMessage()]);
            }
        });

        // A sign-in that skipped Attempting: remember-me, a password reset,
        // social login, loginUsingId().
        Event::listen(Login::class, function (Login $event) use ($viti, $email) {
            try {
                if ($viti->isHoney($event->user) || strtolower((string) ($event->user->email ?? '')) === $email) {
                    $viti->trip('honey_user', Severity::Critical, 'login', ['guard' => (string) $event->guard]);
                }
            } catch (Throwable $e) {
                $viti->reporter()->warn('viti: honey user check failed', ['error' => $e->getMessage()]);
            }
        });

        Event::listen(MessageSending::class, function (MessageSending $event) use ($viti, $email) {
            try {
                $message = $event->message;
                foreach ([...$message->getTo(), ...$message->getCc(), ...$message->getBcc()] as $address) {
                    if (strtolower($address->getAddress()) === $email) {
                        $viti->trip('honey_contact', Severity::High, 'mail');

                        return false;
                    }
                }
            } catch (Throwable $e) {
                $viti->reporter()->warn('viti: honey mail check failed', ['error' => $e->getMessage()]);
            }

            return null;
        });

        $id = (string) ($options['id'] ?? '');
        $model = $options['model'] ?? config('auth.providers.users.model');
        if ($id !== '' && is_string($model) && class_exists($model)) {
            $viti->honey($model, [$id]);
        }
    }
}
