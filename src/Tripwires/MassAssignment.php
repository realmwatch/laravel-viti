<?php

namespace Realmwatch\Viti\Tripwires;

use Realmwatch\Viti\Contracts\Tripwire;
use Realmwatch\Viti\Http\DetectBaitKeys;
use Realmwatch\Viti\Viti;

/**
 * Fields that only someone probing for mass assignment would send. Arms
 * the "viti.bait" route middleware; it's opt-in per route group, because
 * an admin form may legitimately send "role".
 */
final class MassAssignment implements Tripwire
{
    public const DEFAULT_KEYS = ['is_admin', 'admin', 'is_superuser', 'is_staff', 'role', 'roles', 'role_id', 'permissions', 'user_type', 'email_verified_at'];

    public function register(Viti $viti, array $options): void
    {
        $keys = array_values(array_unique(array_map(fn ($k) => strtolower((string) $k), $options['keys'] ?? self::DEFAULT_KEYS)));
        app()->instance(DetectBaitKeys::class, new DetectBaitKeys($viti, $keys));
    }
}
