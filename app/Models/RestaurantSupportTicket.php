<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantSupportTicket extends Model
{
    protected $fillable = [
        'restaurant_id',
        'ticket_number',
        'category',
        'subject',
        'description',
        'status',
        'admin_reply',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')
            ->where('ticket_type', 'restaurant')
            ->orderBy('created_at');
    }
}
