<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChangeActivityEvent extends Model
{
    protected $fillable = [
        'admin_id',
        'admin_name',
        'admin_email',
        'action',
        'module',
        'target',
        'method',
        'path',
        'details',
        'ip_address',
        'user_agent',
    ];
}
