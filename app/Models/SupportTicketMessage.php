<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    protected $fillable = [
        'ticket_type',
        'ticket_id',
        'sender_type',
        'message',
        'image_path',
    ];
}
