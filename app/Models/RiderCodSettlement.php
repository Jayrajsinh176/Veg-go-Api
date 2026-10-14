<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiderCodSettlement extends Model
{
    protected $fillable = [
        'rider_id',
        'amount',
        'method',
        'source',
        'pending_cod_after',
        'settlement_id',
        'wallet_transaction_id',
        'settled_by_type',
        'settled_by_id',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'pending_cod_after' => 'decimal:2',
    ];

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(RiderWeeklySettlement::class, 'settlement_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(RiderWalletTransaction::class, 'wallet_transaction_id');
    }
}
