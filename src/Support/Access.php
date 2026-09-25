<?php

namespace SUBandL\Support;

use SUBandL\Contracts\AccessResolver;

class Access
{
    /**
     * @param  string  $area  one of: license, update, backup
     */
    public static function allows(string $area): bool
    {
        $ability = config("subandl.access.abilities.{$area}", $area);

        return app(AccessResolver::class)->allows($ability, auth()->user());
    }
}
