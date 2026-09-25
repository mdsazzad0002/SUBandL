<?php

namespace SUBandL\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string version()
 * @method static \SUBandL\Models\LicenseState state()
 * @method static bool isValid()
 * @method static string|null licenseKey()
 * @method static \SUBandL\Models\LicenseState verify(bool $force = false)
 * @method static array module(string $module)
 * @method static array checkUpdate()
 * @method static array update()
 * @method static array backup(bool $force = false)
 * @method static \SUBandL\License\LicenseClient client()
 *
 * @see \SUBandL\SUBandL
 */
class SUBandL extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \SUBandL\SUBandL::class;
    }
}
