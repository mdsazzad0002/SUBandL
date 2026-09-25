<?php

namespace SUBandL\Events;

use SUBandL\Models\LicenseState;

class LicenseVerified
{
    public function __construct(public LicenseState $state, public array $result)
    {
    }
}
