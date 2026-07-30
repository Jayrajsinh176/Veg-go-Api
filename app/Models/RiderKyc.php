<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderKyc extends Model
{
    protected $fillable = [
    'rider_id',

    'aadhaar_number',
    'aadhaar_last_four',
    'aadhaar_verified',
    'aadhaar_document',

    'pan_number',
    'has_pan',
    'pan_verified',
    'pan_document',

    'driving_license_number',
    'driving_license_document',
    'driving_license_verified',

    'puc_document',
    'puc_verified',

    'rc_book_document',
    'rc_book_verified',

    'insurance_document',
    'insurance_verified',

    'selfie',

    'status',
    'rejection_reason',
];

    protected $casts = [
    'aadhaar_number' => 'encrypted',
    'pan_number' => 'encrypted',
    'driving_license_number' => 'encrypted',

    'aadhaar_verified' => 'boolean',
    'has_pan' => 'boolean',
    'pan_verified' => 'boolean',
    'driving_license_verified' => 'boolean',
    'puc_verified' => 'boolean',
    'rc_book_verified' => 'boolean',
    'insurance_verified' => 'boolean',
];

    protected $hidden = [
        'aadhaar_number',
        'pan_number',
    ];
}