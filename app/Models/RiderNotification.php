<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderNotification extends Model
{
    protected $fillable = [
        'rider_id',
        'title',
        'message',
        'type',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function rider()
    {
        return $this->belongsTo(Rider::class);
    }
}