<?php

namespace Realmwatch\Viti\Console;

use Illuminate\Console\Command;
use Realmwatch\Viti\Decoy;
use Realmwatch\Viti\Viti;

final class ListCommand extends Command
{
    protected $signature = 'viti:list';

    protected $description = 'List the active tripwires and decoy paths';

    public function handle(Viti $viti): int
    {
        if (! config('viti.enabled')) {
            $this->components->warn('Viti is disabled (VITI_ENABLED).');

            return self::SUCCESS;
        }
        $this->components->twoColumnDetail('Reports go to', $viti->reporter()->describe());

        $this->table(['Tripwire', 'Registered by'], collect($viti->tripwires())->map(fn ($by, $name) => [$name, $by])->values());
        $this->table(['Decoy path', 'Tripwire', 'Severity'], collect($viti->allDecoys())
            ->map(fn (Decoy $d) => [$d->path, $d->tripwire, $d->severity->value]));

        return self::SUCCESS;
    }
}
