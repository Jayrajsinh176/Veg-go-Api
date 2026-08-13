<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\Models\RestaurantOrder;

class RestaurantProfileController extends Controller
{
    
public function index(Request $request)
{
    $restaurant = Restaurant::with([
        'address',
        'workingHours',
    ])->findOrFail(auth()->id());

    $workingHour = $restaurant->workingHours->first();

    $workingHours = null;

    if ($workingHour) {
        $workingHours =
            date('h:i A', strtotime($workingHour->open_time))
            . ' - ' .
            date('h:i A', strtotime($workingHour->close_time));
    }

    $reviews = RestaurantReview::where('restaurant_id', $restaurant->id);

$averageRating = round($reviews->avg('rating') ?? 0, 1);

$totalReviews = $reviews->count();

$totalOrders = RestaurantOrder::where('restaurant_id', $restaurant->id)->count();

$totalRevenue = RestaurantOrder::where('restaurant_id', $restaurant->id)
    ->where('payment_status', 'paid')
    ->sum('total_amount');
    
    return response()->json([
        'success' => true,
        'message' => 'Profile fetched successfully.',
        'data' => [
    'restaurant_name' => $restaurant->restaurant_name,
    'phone' => $restaurant->mobile,
    'verified' => $restaurant->status === 'approved',
    'member_since' => $restaurant->created_at->format('M Y'),

    'address' => $restaurant->address
        ? $restaurant->address->full_address . ', ' . $restaurant->address->complete_address
        : null,

    'landmark' => $restaurant->address?->landmark,

    'profile_image' => $restaurant->address?->restaurant_front_image,

    'working_hours' => $workingHours,

    'average_rating' => $averageRating,

    'total_reviews' => $totalReviews,

    'total_orders' => $totalOrders,

    'total_revenue' => (float) $totalRevenue,
],
    ]);
}

}
