<?php

namespace SUBandL\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseInstallation extends Model
{
    protected $fillable = ['installation_uuid', 'signing_secret', 'domain'];

    public function getTable()
    {
        return config('subandl.tables.installations', 'license_installations');
    }
}
