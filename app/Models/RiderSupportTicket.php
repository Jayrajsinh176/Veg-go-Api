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

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')
            ->where('ticket_type', 'rider')
            ->orderBy('created_at');
    }
}