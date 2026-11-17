<?php

namespace Realmwatch\Viti\Contracts;

use Realmwatch\Viti\Viti;

/**
 * A tripwire wires itself up once, at boot, when it's enabled: decoys with
 * $viti->decoy(), event listeners, middleware. Whatever it watches calls
 * $viti->trip() when someone touches it.
 *
 * Built-in tripwires use this and nothing else, so an app's own tripwire
 * can do anything theirs can.
 */
interface Tripwire
{
    /**
     * @param  array<string, mixed>  $options  this tripwire's entry in config('viti.tripwires')
     */
    public function register(Viti $viti, array $options): void;
}
