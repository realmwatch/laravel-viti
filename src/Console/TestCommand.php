<?php

namespace Realmwatch\Viti\Console;

use Illuminate\Console\Command;
use Realmwatch\Viti\Reporting\Outcome;
use Realmwatch\Viti\Reporting\Reporter;
use Realmwatch\Viti\Severity;

final class TestCommand extends Command
{
    protected $signature = 'viti:test';

    protected $description = 'Send a test trip (tripwire "test", severity low) to check the wiring to Heimdall';

    public function handle(Reporter $reporter): int
    {
        $outcome = $reporter->report('test', Severity::Low, 'test', ['note' => 'viti:test'], null, throttle: false);

        if ($outcome === Outcome::Sent) {
            $this->components->info('Test trip sent ('.$reporter->describe().'). It shows in Heimdall after the collector\'s next push.');

            return self::SUCCESS;
        }
        $this->components->error("Test trip {$outcome->value} (".$reporter->describe().'). See the log for why.');

        return self::FAILURE;
    }
}
