<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PartnerContractTerm;

class PartnerContractController extends Controller
{
   public function terms()
{
    $terms = PartnerContractTerm::where('is_active', true)
        ->orderBy('display_order')
        ->get([
            'id',
            'title',
            'content',
            'display_order',
        ]);

    return response()->json([
        'success' => true,
        'message' => 'Partner contract terms fetched successfully.',
        'data' => $terms,
    ]);
}
}
