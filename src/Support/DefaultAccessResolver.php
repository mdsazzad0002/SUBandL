<?php

namespace SUBandL\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use SUBandL\Contracts\AccessResolver;

/**
 * Any authenticated user is allowed, unless the application defines a Gate
 * with the ability's name — then that Gate decides.
 */
class DefaultAccessResolver implements AccessResolver
{
    public function allows(string $ability, ?Authenticatable $user): bool
    {
        if (! $user) {
            return false;
        }

        if (Gate::has($ability)) {
            return Gate::forUser($user)->allows($ability);
        }

        return true;
    }
}
