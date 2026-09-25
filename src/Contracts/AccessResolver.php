<?php

namespace SUBandL\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides whether the current user may use a SUBandL page. $ability is the
 * project-specific name mapped in config('subandl.access.abilities').
 */
interface AccessResolver
{
    public function allows(string $ability, ?Authenticatable $user): bool;
}
