<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantOffer;
use App\Services\Restaurant\RestaurantOfferService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminRestaurantOfferController extends Controller
{
    public function __construct(
        private RestaurantOfferService $offerService
    ) {
    }

    /**
     * List all offers
     */
    public function index()
    {
        $offers = RestaurantOffer::query()
            ->with([
                'restaurant:id,restaurant_name',
                'menuItem:id,restaurant_id,category_id,name,price',
                'menuItem.category:id,name',
            ])
            ->latest('id')
            ->get()
            ->map(fn ($offer) => $this->formatOffer($offer));

        return response()->json([
            'message' => 'Offers fetched successfully.',
            'data' => $offers,
        ]);
    }

    /**
     * Restaurants with their food items, for the offer form
     */
    public function options()
    {
        $restaurants = Restaurant::query()
            ->select(['id', 'restaurant_name'])
            ->whereNotNull('restaurant_name')
            ->where('restaurant_name', '!=', '')
            ->with([
                'menuItems' => function ($query) {
                    $query->select([
                        'id',
                        'restaurant_id',
                        'category_id',
                        'name',
                        'price',
                    ])
                        ->where('is_active', true)
                        ->with('category:id,name')
                        ->orderBy('name');
                },
            ])
            ->orderBy('restaurant_name')
            ->get()
            ->map(fn ($restaurant) => [
                'id' => $restaurant->id,
                'name' => $restaurant->restaurant_name,
                'items' => $restaurant->menuItems->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'price' => (float) $item->price,
                    'category' => $item->category?->name,
                ])->values(),
            ]);

        return response()->json([
            'message' => 'Offer options fetched successfully.',
            'data' => [
                'restaurants' => $restaurants,
            ],
        ]);
    }

    /**
     * Create offer
     */
    public function store(Request $request)
    {
        $validated = $this->validateOffer($request);

        $offer = RestaurantOffer::create($validated);

        $this->offerService->syncMenuItem($offer->menu_item_id);
        $this->offerService->notifyRestaurant($offer, 'created');

        return response()->json([
            'message' => 'Offer created successfully.',
            'data' => $this->formatOffer($offer->fresh($this->relations())),
        ], 201);
    }

    /**
     * Update offer
     */
    public function update(Request $request, $id)
    {
        $offer = RestaurantOffer::find($id);

        if (!$offer) {
            return response()->json([
                'message' => 'Offer not found.',
            ], 404);
        }

        $validated = $this->validateOffer($request, $offer);

        $previousMenuItemId = $offer->menu_item_id;

        $offer->update($validated);

        // Item may have changed: restore the old one, apply to the new one
        if ((int) $previousMenuItemId !== (int) $offer->menu_item_id) {
            $this->offerService->syncMenuItem($previousMenuItemId);
        }

        $this->offerService->syncMenuItem($offer->menu_item_id);
        $this->offerService->notifyRestaurant($offer, 'updated');

        return response()->json([
            'message' => 'Offer updated successfully.',
            'data' => $this->formatOffer($offer->fresh($this->relations())),
        ]);
    }

    /**
     * Activate / deactivate offer
     */
    public function toggleStatus($id)
    {
        $offer = RestaurantOffer::find($id);

        if (!$offer) {
            return response()->json([
                'message' => 'Offer not found.',
            ], 404);
        }

        if (!$offer->is_active) {
            $this->ensureNoOverlap(
                $offer->menu_item_id,
                $offer->start_date->toDateString(),
                $offer->end_date->toDateString(),
                $offer->id
            );
        }

        $offer->update([
            'is_active' => !$offer->is_active,
        ]);

        $this->offerService->syncMenuItem($offer->menu_item_id);
        $this->offerService->notifyRestaurant(
            $offer,
            $offer->is_active ? 'activated' : 'deactivated'
        );

        return response()->json([
            'message' => $offer->is_active
                ? 'Offer activated successfully.'
                : 'Offer deactivated successfully.',
            'data' => $this->formatOffer($offer->fresh($this->relations())),
        ]);
    }

    /**
     * Delete offer
     */
    public function destroy($id)
    {
        $offer = RestaurantOffer::find($id);

        if (!$offer) {
            return response()->json([
                'message' => 'Offer not found.',
            ], 404);
        }

        $this->offerService->notifyRestaurant($offer, 'deleted');
        $this->offerService->deleteOffer($offer);

        return response()->json([
            'message' => 'Offer deleted successfully.',
        ]);
    }


    private function relations(): array
    {
        return [
            'restaurant:id,restaurant_name',
            'menuItem:id,restaurant_id,category_id,name,price',
            'menuItem.category:id,name',
        ];
    }

    private function validateOffer(Request $request, ?RestaurantOffer $offer = null): array
    {
        $validated = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'menu_item_id' => ['required', 'integer', 'exists:restaurant_menu_items,id'],
            'discount_type' => ['required', 'in:percentage,fixed'],
            'discount_value' => ['required', 'numeric', 'gt:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validated['start_date'] = \Carbon\Carbon::parse($validated['start_date'])->toDateString();
        $validated['end_date'] = \Carbon\Carbon::parse($validated['end_date'])->toDateString();

        $menuItem = RestaurantMenuItem::find($validated['menu_item_id']);

        if ((int) $menuItem->restaurant_id !== (int) $validated['restaurant_id']) {
            throw ValidationException::withMessages([
                'menu_item_id' => 'This food item does not belong to the selected restaurant.',
            ]);
        }

        if ($validated['discount_type'] === 'percentage' && $validated['discount_value'] > 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'Percentage discount cannot be more than 100.',
            ]);
        }

        if ($validated['discount_type'] === 'fixed' && $validated['discount_value'] > (float) $menuItem->price) {
            throw ValidationException::withMessages([
                'discount_value' => 'Fixed discount cannot be more than the item price.',
            ]);
        }

        $isActive = $validated['is_active'] ?? $offer?->is_active ?? true;

        if ($isActive) {
            $this->ensureNoOverlap(
                $validated['menu_item_id'],
                $validated['start_date'],
                $validated['end_date'],
                $offer?->id
            );
        }

        return $validated;
    }

    /**
     * One food item can only have one switched-on offer for a given day.
     */
    private function ensureNoOverlap($menuItemId, string $startDate, string $endDate, $ignoreId = null): void
    {
        $overlaps = RestaurantOffer::query()
            ->where('menu_item_id', $menuItemId)
            ->where('is_active', true)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'start_date' => 'This food item already has an active offer in the selected date range.',
            ]);
        }
    }

    private function formatOffer(RestaurantOffer $offer): array
    {
        $price = (float) ($offer->menuItem?->price ?? 0);
        $discount = $offer->discountFor($price);

        return [
            'id' => $offer->id,
            'restaurant_id' => $offer->restaurant_id,
            'restaurant' => $offer->restaurant?->restaurant_name,
            'menu_item_id' => $offer->menu_item_id,
            'food_item' => $offer->menuItem?->name,
            'category' => $offer->menuItem?->category?->name,
            'original_price' => $price,
            'discount_type' => $offer->discount_type,
            'discount_value' => (float) $offer->discount_value,
            'discount_amount' => $discount,
            'final_price' => round($price - $discount, 2),
            'start_date' => $offer->start_date?->toDateString(),
            'end_date' => $offer->end_date?->toDateString(),
            'is_active' => (bool) $offer->is_active,
            'status' => $offer->status,
            'created_at' => $offer->created_at,
            'updated_at' => $offer->updated_at,
        ];
    }
}
