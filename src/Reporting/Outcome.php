<?php

namespace Realmwatch\Viti\Reporting;

enum Outcome: string
{
    case Sent = 'sent';
    case Throttled = 'throttled';
    case Disabled = 'disabled';
    case Failed = 'failed';
}
