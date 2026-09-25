<?php

namespace SUBandL\Events;

class BackupFinished
{
    public function __construct(public bool $ok, public array $result)
    {
    }
}
