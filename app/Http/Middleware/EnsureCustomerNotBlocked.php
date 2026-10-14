<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerNotBlocked
{
    /**
     * Block every customer API call while admin has the customer blocked.
     * The token is revoked, so the app should log out and show the message
     * when it gets is_blocked = true.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user();

        if ($customer instanceof Customer && $customer->status === 'blocked') {
            $customer->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'is_blocked' => true,
                'message' => 'Your account has been blocked. Please contact the administrator.',
            ], 403);
        }

        return $next($request);
    }
}
