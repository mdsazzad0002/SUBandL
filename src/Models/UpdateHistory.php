<?php

namespace SUBandL\Models;

use Illuminate\Database\Eloquent\Model;

class UpdateHistory extends Model
{
    protected $fillable = ['ok', 'from_version', 'to_version', 'message'];

    protected $casts = [
        'ok' => 'boolean',
    ];

    public function getTable()
    {
        return config('subandl.tables.update_histories', 'update_histories');
    }
}
