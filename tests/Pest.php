<?php

use Realmwatch\Viti\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/** The trips reported during the test, as payloads. */
function trips(): array
{
    return app('viti.test.trips')->all();
}
