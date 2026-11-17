<?php

namespace Realmwatch\Viti\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void trip(string $tripwire, \Realmwatch\Viti\Severity $severity, ?string $token = null, array $context = [])
 * @method static \Realmwatch\Viti\Viti decoy(string|\Realmwatch\Viti\Decoy $decoy)
 * @method static \Realmwatch\Viti\Viti decoys(\Closure $provider, string $tripwire = 'decoy_routes')
 * @method static \Realmwatch\Viti\Viti extend(string $name, \Realmwatch\Viti\Contracts\Tripwire|\Closure|string $tripwire, array $options = [])
 * @method static \Realmwatch\Viti\Viti reportUsing(\Realmwatch\Viti\Contracts\Transport|\Closure $transport)
 * @method static \Realmwatch\Viti\Viti honey(string $model, array $keys)
 * @method static bool isHoney(mixed $model)
 *
 * @see \Realmwatch\Viti\Viti
 */
final class Viti extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Realmwatch\Viti\Viti::class;
    }
}
