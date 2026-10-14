<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantCategory;
use App\Models\RestaurantMenuItem;
use App\Services\Restaurant\RestaurantOfferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminRestaurantMenuController extends Controller
{
    /**
     * Get restaurant menu
     */
    public function index($restaurantId)
    {
        $restaurant = Restaurant::find($restaurantId);

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $categories = RestaurantCategory::query()
            ->where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->select([
                'id',
                'restaurant_id',
                'name',
                'is_active',
                'created_at',
                'updated_at',
            ])
            ->orderBy('name')
            ->get();

        $menuItems = RestaurantMenuItem::query()
            ->where('restaurant_id', $restaurantId)
            ->with([
                'category:id,restaurant_id,name,is_active',
            ])
            ->select([
                'id',
                'restaurant_id',
                'category_id',
                'name',
                'description',
                'price',
                'discount_price',
                'regular_discount_price',
                'active_offer_id',
                'offer_label',
                'image',
                'is_available',
                'is_recommended',
                'is_active',
                'created_at',
                'updated_at',
            ])
            ->latest('id')
            ->get();

        $menuItems->transform(function ($item) {

            $image = null;

            if ($item->image) {
                $image = asset(
                    'storage/' . ltrim($item->image, '/')
                );
            }

            return [
                'id' => $item->id,

                'restaurant_id' =>
                    $item->restaurant_id,

                'category_id' =>
                    $item->category_id,

                'category' =>
                    $item->category?->name,

                'name' =>
                    $item->name,

                'description' =>
                    $item->description,

                'price' =>
                    $item->price,

                'discount_price' =>
                    $item->ownDiscountPrice(),

                'offer_price' =>
                    $item->active_offer_id ? $item->discount_price : null,

                'offer_label' =>
                    $item->offer_label,

                'image' =>
                    $image,

                'available' =>
                    (bool) $item->is_available,

                'is_recommended' =>
                    (bool) $item->is_recommended,

                'is_active' =>
                    (bool) $item->is_active,

                'created_at' =>
                    $item->created_at,

                'updated_at' =>
                    $item->updated_at,
            ];
        });

        return response()->json([
            'message' => 'Restaurant menu fetched successfully.',

            'data' => [
                'restaurant' => [
                    'id' => $restaurant->id,
                    'name' => $restaurant->restaurant_name,
                    'owner' => $restaurant->owner_name,
                ],

                'categories' => $categories,

                'items' => $menuItems,
            ],
        ]);
    }


    /**
     * Add food item
     */
    public function store(Request $request, $restaurantId)
    {
        $restaurant = Restaurant::find($restaurantId);

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                'exists:restaurant_categories,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'discount_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'is_available' => [
                'nullable',
                'boolean',
            ],

            'is_recommended' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        $category = RestaurantCategory::query()
            ->where('id', $validated['category_id'])
            ->where('restaurant_id', $restaurantId)
            ->first();

        if (!$category) {
            return response()->json([
                'message' =>
                    'Selected category does not belong to this restaurant.',
            ], 422);
        }

        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath =
                $request->file('image')
                    ->store(
                        'restaurant_menu',
                        'public'
                    );
        }

        $item = RestaurantMenuItem::create([
            'restaurant_id' =>
                $restaurantId,

            'category_id' =>
                $category->id,

            'name' =>
                $validated['name'],

            'description' =>
                $validated['description'] ?? null,

            'price' =>
                $validated['price'],

            'discount_price' =>
                $validated['discount_price'] ?? null,

            'image' =>
                $imagePath,

            'is_available' =>
                $validated['is_available'] ?? true,

            'is_recommended' =>
                $validated['is_recommended'] ?? false,

            'is_active' =>
                $validated['is_active'] ?? true,
        ]);

        $item->load(
            'category:id,restaurant_id,name,is_active'
        );

        return response()->json([
            'message' =>
                'Food item added successfully.',

            'data' => $this->formatMenuItem(
                $item
            ),
        ], 201);
    }


    /**
     * Update food item
     */
    public function update(
        Request $request,
        $restaurantId,
        $itemId
    ) {
        $item = RestaurantMenuItem::query()
            ->where('id', $itemId)
            ->where('restaurant_id', $restaurantId)
            ->first();

        if (!$item) {
            return response()->json([
                'message' =>
                    'Food item not found for this restaurant.',
            ], 404);
        }

        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                'exists:restaurant_categories,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'discount_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'is_available' => [
                'nullable',
                'boolean',
            ],

            'is_recommended' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        $category = RestaurantCategory::query()
            ->where('id', $validated['category_id'])
            ->where('restaurant_id', $restaurantId)
            ->first();

        if (!$category) {
            return response()->json([
                'message' =>
                    'Selected category does not belong to this restaurant.',
            ], 422);
        }

        if ($request->hasFile('image')) {

            if ($item->image) {
                Storage::disk('public')
                    ->delete($item->image);
            }

            $item->image =
                $request->file('image')
                    ->store(
                        'restaurant_menu',
                        'public'
                    );
        }

        $item->category_id =
            $category->id;

        $item->name =
            $validated['name'];

        $item->description =
            $validated['description'] ?? null;

        $item->price =
            $validated['price'];

        // Keeps a running admin offer price intact
        $item->setOwnDiscountPrice(
            $validated['discount_price'] ?? null
        );

        if (
            array_key_exists(
                'is_available',
                $validated
            )
        ) {
            $item->is_available =
                $validated['is_available'];
        }

        if (
            array_key_exists(
                'is_recommended',
                $validated
            )
        ) {
            $item->is_recommended =
                $validated['is_recommended'];
        }

        if (
            array_key_exists(
                'is_active',
                $validated
            )
        ) {
            $item->is_active =
                $validated['is_active'];
        }

        $item->save();

        // Re-apply offer on the (possibly new) price
        $item = app(RestaurantOfferService::class)
            ->syncMenuItem($item->id);

        $item->load(
            'category:id,restaurant_id,name,is_active'
        );

        return response()->json([
            'message' =>
                'Food item updated successfully.',

            'data' => $this->formatMenuItem(
                $item
            ),
        ]);
    }


    /**
     * Delete food item
     */
    public function destroy(
        $restaurantId,
        $itemId
    ) {
        $item = RestaurantMenuItem::query()
            ->where('id', $itemId)
            ->where('restaurant_id', $restaurantId)
            ->first();

        if (!$item) {
            return response()->json([
                'message' =>
                    'Food item not found for this restaurant.',
            ], 404);
        }

        if ($item->image) {
            Storage::disk('public')
                ->delete($item->image);
        }

        $item->delete();

        return response()->json([
            'message' =>
                'Food item deleted successfully.',
        ]);
    }


    /**
     * Toggle food item availability
     */
    public function toggleAvailability(
        $restaurantId,
        $itemId
    ) {
        $item = RestaurantMenuItem::query()
            ->where('id', $itemId)
            ->where('restaurant_id', $restaurantId)
            ->first();

        if (!$item) {
            return response()->json([
                'message' =>
                    'Food item not found for this restaurant.',
            ], 404);
        }

        $item->is_available =
            !$item->is_available;

        $item->save();

        $item->load(
            'category:id,restaurant_id,name,is_active'
        );

        return response()->json([
            'message' =>
                'Food item availability updated successfully.',

            'data' => $this->formatMenuItem(
                $item
            ),
        ]);
    }


    /**
     * Add category
     */
    public function storeCategory(
        Request $request,
        $restaurantId
    ) {
        $restaurant = Restaurant::find(
            $restaurantId
        );

        if (!$restaurant) {
            return response()->json([
                'message' =>
                    'Restaurant not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $exists = RestaurantCategory::query()
            ->where(
                'restaurant_id',
                $restaurantId
            )
            ->whereRaw(
                'LOWER(name) = ?',
                [
                    strtolower(
                        trim(
                            $validated['name']
                        )
                    ),
                ]
            )
            ->exists();

        if ($exists) {
            return response()->json([
                'message' =>
                    'This category already exists.',
            ], 422);
        }

        $category =
            RestaurantCategory::create([
                'restaurant_id' =>
                    $restaurantId,

                'name' =>
                    trim(
                        $validated['name']
                    ),

                'is_active' =>
                    true,
            ]);

        return response()->json([
            'message' =>
                'Category added successfully.',

            'data' => $category,
        ], 201);
    }


    /**
     * Format menu item response
     */
    private function formatMenuItem(
        RestaurantMenuItem $item
    ) {
        return [
            'id' =>
                $item->id,

            'restaurant_id' =>
                $item->restaurant_id,

            'category_id' =>
                $item->category_id,

            'category' =>
                $item->category?->name,

            'name' =>
                $item->name,

            'description' =>
                $item->description,

            'price' =>
                $item->price,

            'discount_price' =>
                $item->ownDiscountPrice(),

            'offer_price' =>
                $item->active_offer_id ? $item->discount_price : null,

            'offer_label' =>
                $item->offer_label,

            'image' =>
                $item->image
                    ? asset(
                        'storage/' .
                        ltrim(
                            $item->image,
                            '/'
                        )
                    )
                    : null,

            'available' =>
                (bool) $item->is_available,

            'is_recommended' =>
                (bool) $item->is_recommended,

            'is_active' =>
                (bool) $item->is_active,

            'created_at' =>
                $item->created_at,

            'updated_at' =>
                $item->updated_at,
        ];
    }
}