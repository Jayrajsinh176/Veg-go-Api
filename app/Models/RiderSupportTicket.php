<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderSupportTicket extends Model
{
    protected $fillable = [
        'rider_id',
        'ticket_number',
        'category',
        'subject',
        'description',
        'status',
        'admin_reply',
    ];

    public function rider()
    {
        return $this->belongsTo(Rider::class);
    }
}