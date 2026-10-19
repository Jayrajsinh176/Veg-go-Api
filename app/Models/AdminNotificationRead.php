<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotificationRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'admin_notification_id',
        'admin_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
