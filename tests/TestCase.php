<?php

namespace Realmwatch\Viti\Tests;

use ArrayObject;
use Orchestra\Testbench\TestCase as Orchestra;
use Realmwatch\Viti\Viti;
use Realmwatch\Viti\VitiServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [VitiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.url', 'https://app.example.com');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Capture reports in memory; the spool transport has its own test.
        $trips = new ArrayObject;
        $this->app->instance('viti.test.trips', new class($trips)
        {
            public function __construct(public ArrayObject $t) {}

            public function all(): array
            {
                return $this->t->getArrayCopy();
            }
        });
        $this->app->make(Viti::class)->reportUsing(fn (array $payload) => $trips->append($payload));
    }
}
