<?php

namespace App\Http\Middleware;

use App\Models\Restaurant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $restaurant = $request->user();

        if (!$restaurant instanceof Restaurant || $restaurant->status !== 'approved') {
            return response()->json([
                'success' => false,
                'is_approved' => false,
                'status' => $restaurant instanceof Restaurant ? $restaurant->status : null,
                'message' => 'Your restaurant must be approved by admin to access this feature.',
            ], 403);
        }

        return $next($request);
    }
}
