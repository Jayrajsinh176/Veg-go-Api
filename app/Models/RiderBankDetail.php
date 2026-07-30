<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderBankDetail extends Model
{
    protected $fillable = [
        'rider_id',
        'account_holder_name',
        'bank_name',
        'account_number',
        'ifsc_code',
        'branch_name',
        // 'upi_id',
        'is_verified',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    public function rider()
    {
        return $this->belongsTo(Rider::class);
    }
}