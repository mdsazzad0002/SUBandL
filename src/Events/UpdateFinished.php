<?php

namespace SUBandL\Events;

class UpdateFinished
{
    public function __construct(public bool $ok, public ?string $fromVersion, public ?string $toVersion, public array $result)
    {
    }
}
