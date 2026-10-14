<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantOffer;
use Illuminate\Http\Request;

class RestaurantOfferController extends Controller
{
    /**
     * Offers admin has set on this restaurant's food items.
     * Optional ?status=active|scheduled|expired|inactive
     */
    public function index(Request $request)
    {
        $restaurant = $request->user();

        $offers = RestaurantOffer::query()
            ->where('restaurant_id', $restaurant->id)
            ->with([
                'menuItem:id,category_id,name,price,image',
                'menuItem.category:id,name',
            ])
            ->latest('id')
            ->get()
            ->map(fn ($offer) => $this->formatOffer($offer));

        $counts = [
            'all' => $offers->count(),
            'active' => $offers->where('status', 'active')->count(),
            'scheduled' => $offers->where('status', 'scheduled')->count(),
            'expired' => $offers->where('status', 'expired')->count(),
            'inactive' => $offers->where('status', 'inactive')->count(),
        ];

        if ($request->filled('status') && $request->status !== 'all') {
            $offers = $offers->where('status', $request->status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Offers fetched successfully.',
            'data' => [
                'counts' => $counts,
                'offers' => $offers->values(),
            ],
        ]);
    }

    /**
     * Single offer
     */
    public function show(Request $request, $id)
    {
        $offer = RestaurantOffer::query()
            ->where('restaurant_id', $request->user()->id)
            ->with([
                'menuItem:id,category_id,name,price,image',
                'menuItem.category:id,name',
            ])
            ->find($id);

        if (!$offer) {
            return response()->json([
                'success' => false,
                'message' => 'Offer not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Offer fetched successfully.',
            'data' => $this->formatOffer($offer),
        ]);
    }

    private function formatOffer(RestaurantOffer $offer): array
    {
        $price = (float) ($offer->menuItem?->price ?? 0);
        $discount = $offer->discountFor($price);

        return [
            'id' => $offer->id,
            'menu_item_id' => $offer->menu_item_id,
            'food_item' => $offer->menuItem?->name,
            'category' => $offer->menuItem?->category?->name,
            'image' => $offer->menuItem?->image,
            'offer_label' => $offer->label(),
            'discount_type' => $offer->discount_type,
            'discount_value' => (float) $offer->discount_value,
            'original_price' => $price,
            'discount_amount' => $discount,
            'offer_price' => round($price - $discount, 2),
            'start_date' => $offer->start_date?->toDateString(),
            'end_date' => $offer->end_date?->toDateString(),
            'status' => $offer->status,
            'created_at' => $offer->created_at,
            'updated_at' => $offer->updated_at,
        ];
    }
}
