<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerSupportTicket extends Model
{
    protected $fillable = [
        'customer_id',
        'order_id',
        'ticket_number',
        'category',
        'subject',
        'description',
        'status',
        'admin_reply',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrder::class, 'order_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')
            ->where('ticket_type', 'customer')
            ->orderBy('created_at');
    }
}

