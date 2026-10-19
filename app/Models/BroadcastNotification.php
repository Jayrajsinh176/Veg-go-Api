<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BroadcastNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'message',
        'audience',
        'channels',
        'total_recipients',
        'delivered_count',
        'failed_count',
        'status',
        'sent_at',
        'scheduled_at',
        'created_by',
    ];

    protected $casts = [
        'channels' => 'array',
        'sent_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'total_recipients' => 'integer',
        'delivered_count' => 'integer',
        'failed_count' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}

