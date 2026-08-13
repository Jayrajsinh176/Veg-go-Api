<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RestaurantReview;
use App\Models\RestaurantReviewReply;

class RestaurantRatingController extends Controller
{
  public function summary(Request $request)
{
    $restaurant = auth()->user();

    $reviews = RestaurantReview::where('restaurant_id', $restaurant->id);

    $totalReviews = $reviews->count();

    $overallRating = $totalReviews > 0
        ? round($reviews->avg('rating'), 1)
        : 0;

    $fiveStar = RestaurantReview::where('restaurant_id', $restaurant->id)
        ->where('rating', 5)
        ->count();

    $fourStar = RestaurantReview::where('restaurant_id', $restaurant->id)
        ->where('rating', 4)
        ->count();

    $threeStar = RestaurantReview::where('restaurant_id', $restaurant->id)
        ->where('rating', 3)
        ->count();

    $twoStar = RestaurantReview::where('restaurant_id', $restaurant->id)
        ->where('rating', 2)
        ->count();

    $oneStar = RestaurantReview::where('restaurant_id', $restaurant->id)
        ->where('rating', 1)
        ->count();

    $data = [
        'overall_rating' => $overallRating,
        'total_reviews' => $totalReviews,

        'rating_breakdown' => [
            '5_star' => [
                'count' => $fiveStar,
                'percentage' => $totalReviews ? round(($fiveStar / $totalReviews) * 100) : 0,
            ],
            '4_star' => [
                'count' => $fourStar,
                'percentage' => $totalReviews ? round(($fourStar / $totalReviews) * 100) : 0,
            ],
            '3_star' => [
                'count' => $threeStar,
                'percentage' => $totalReviews ? round(($threeStar / $totalReviews) * 100) : 0,
            ],
            '2_star' => [
                'count' => $twoStar,
                'percentage' => $totalReviews ? round(($twoStar / $totalReviews) * 100) : 0,
            ],
            '1_star' => [
                'count' => $oneStar,
                'percentage' => $totalReviews ? round(($oneStar / $totalReviews) * 100) : 0,
            ],
        ],

        'sentiment' => [
            'positive' => $totalReviews ? round((($fiveStar + $fourStar) / $totalReviews) * 100) : 0,
            'neutral' => $totalReviews ? round(($threeStar / $totalReviews) * 100) : 0,
            'negative' => $totalReviews ? round((($twoStar + $oneStar) / $totalReviews) * 100) : 0,
        ],
    ];

    return response()->json([
        'success' => true,
        'message' => 'Rating summary fetched successfully.',
        'data' => $data,
    ]);
}

public function highlights(Request $request)
{
    $restaurant = auth()->user();

    $reviews = RestaurantReview::where('restaurant_id', $restaurant->id)
        ->pluck('review');

    $keywords = [
        'taste' => ['title' => 'Great Taste', 'emoji' => '🍛'],
        'tasty' => ['title' => 'Great Taste', 'emoji' => '🍛'],
        'fresh' => ['title' => 'Fresh Ingredients', 'emoji' => '🥗'],
        'quality' => ['title' => 'Fresh Ingredients', 'emoji' => '🥗'],
        'quick' => ['title' => 'Quick Service', 'emoji' => '🚀'],
        'fast' => ['title' => 'Quick Service', 'emoji' => '🚀'],
        'packaging' => ['title' => 'Good Packaging', 'emoji' => '📦'],
        'hot' => ['title' => 'Hot Food', 'emoji' => '🔥'],
    ];

    $found = [];

    foreach ($reviews as $review) {

        $review = strtolower($review);

        foreach ($keywords as $word => $item) {

            if (str_contains($review, $word)) {
                $found[$item['title']] = $item;
            }

        }
    }

    return response()->json([
        'success' => true,
        'message' => 'Customer highlights fetched successfully.',
        'data' => array_values($found),
    ]);
}
public function reviews(Request $request)
{
    $restaurant = auth()->user();

$reviews = RestaurantReview::with('reply')
    ->where('restaurant_id', $restaurant->id)
    ->orderByDesc('created_at')
    ->paginate(10);

    $data = [
        'reviews' => $reviews->map(function ($review) {
            return [
                'id' => $review->id,
                'customer_name' => 'Customer',
                'customer_initial' => 'C',
                'rating' => $review->rating,
                'review' => $review->review,
                'time' => $review->created_at->diffForHumans(),
                'helpful_count' => 0,
               'restaurant_reply' => $review->reply ? [
    'reply' => $review->reply->reply,
    'replied_at' => $review->reply->created_at->diffForHumans(),
] : null,
            ];
        }),

        'pagination' => [
            'current_page' => $reviews->currentPage(),
            'last_page' => $reviews->lastPage(),
            'per_page' => $reviews->perPage(),
            'total' => $reviews->total(),
        ],
    ];

    return response()->json([
        'success' => true,
        'message' => 'Customer reviews fetched successfully.',
        'data' => $data,
    ]);
}

public function pendingReplies(Request $request)
{
    $restaurant = auth()->user();

    $pendingCount = RestaurantReview::where('restaurant_id', $restaurant->id)
        ->whereDoesntHave('reply')
        ->count();

    return response()->json([
        'success' => true,
        'message' => 'Pending reviews fetched successfully.',
        'data' => [
            'pending_count' => $pendingCount,
            'button_enabled' => $pendingCount > 0,
        ],
    ]);
}

public function reply(Request $request)
{
    $restaurant = auth()->user();

    $validated = $request->validate([
        'review_id' => 'required|exists:restaurant_reviews,id',
        'reply' => 'required|string|max:1000',
    ]);

    $review = RestaurantReview::where('id', $validated['review_id'])
        ->where('restaurant_id', $restaurant->id)
        ->first();

    if (!$review) {
        return response()->json([
            'success' => false,
            'message' => 'Review not found.',
        ], 404);
    }

    $reply = RestaurantReviewReply::updateOrCreate(
        [
            'restaurant_review_id' => $review->id,
        ],
        [
            'restaurant_id' => $restaurant->id,
            'reply' => $validated['reply'],
        ]
    );

    return response()->json([
        'success' => true,
        'message' => 'Reply submitted successfully.',
        'data' => [
            'review_id' => $review->id,
            'reply' => $reply->reply,
        ],
    ]);
}

}