<?php

namespace App\Http\Middleware;

use App\Models\Restaurant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantNotSuspended
{
    /**
     * Block every restaurant API call while admin has the restaurant
     * suspended. The app should log out and show the message when it
     * gets is_suspended = true.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $restaurant = $request->user();

        if ($restaurant instanceof Restaurant && $restaurant->status === 'suspended') {
            return response()->json([
                'success' => false,
                'is_suspended' => true,
                'message' => 'Your restaurant account has been suspended by admin. Please contact support.',
            ], 403);
        }

        return $next($request);
    }
}
