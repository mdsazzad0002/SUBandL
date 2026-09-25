<?php

namespace SUBandL\Models;

use Illuminate\Database\Eloquent\Model;

class BackupHistory extends Model
{
    protected $fillable = ['ok', 'backup_id', 'message'];

    protected $casts = [
        'ok' => 'boolean',
    ];

    public function getTable()
    {
        return config('subandl.tables.backup_histories', 'backup_histories');
    }
}
