<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiderUpiDetail extends Model
{
    protected $fillable = [
        'rider_id',
        'holder_name',
        'upi_id',
        'is_verified',
        'verified_at',
        'verified_by',
        'rejection_reason',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }
}