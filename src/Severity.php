<?php

namespace Realmwatch\Viti;

/**
 * How much a trip matters. Heimdall routes on it: noise goes to a daily
 * rollup, high alerts, critical pages.
 */
enum Severity: string
{
    case Noise = 'noise';
    case Low = 'low';
    case High = 'high';
    case Critical = 'critical';
}
