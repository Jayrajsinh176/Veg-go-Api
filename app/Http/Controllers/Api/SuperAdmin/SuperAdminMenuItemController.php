<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantCategory;
use App\Models\RestaurantMenuItem;
use App\Services\Restaurant\RestaurantOfferService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Super Admin > Menu & Food Items (all restaurants)
 *
 * Deleting only hides an item (is_active = false), the same as the restaurant
 * app does: order items cascade on a real delete and would lose history.
 * Status Active / Inactive is is_available (can customers order it).
 */
class SuperAdminMenuItemController extends Controller
{
    private const FILTER_RULES = [
        'search' => ['nullable', 'string', 'max:100'],
        'restaurant_id' => ['nullable', 'integer'],
        'category' => ['nullable', 'string', 'max:255'],
        'status' => ['nullable', 'in:active,inactive'],
        'sort' => ['nullable', 'in:name,price-low,price-high,latest'],
    ];

    /**
     * Item list (search + filters + sort + pagination), category counts and restaurants
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            ...self::FILTER_RULES,
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $priceSql = 'COALESCE(restaurant_menu_items.discount_price, restaurant_menu_items.price)';

        $query = $this->filteredQuery($validated);

        match ($validated['sort'] ?? 'name') {
            'price-low' => $query->orderByRaw("{$priceSql} ASC"),
            'price-high' => $query->orderByRaw("{$priceSql} DESC"),
            'latest' => $query->latest('restaurant_menu_items.updated_at'),
            default => $query->orderBy('restaurant_menu_items.name'),
        };

        $items = $query
            ->orderBy('restaurant_menu_items.id')
            ->paginate($validated['per_page'] ?? 10);

        $items->getCollection()->transform(fn ($item) => $this->row($item));

        // Category sidebar: counts for the chosen restaurant / status / search,
        // categories grouped by name across restaurants
        $categories = $this->filteredQuery([...$validated, 'category' => null])
            ->join('restaurant_categories as rc', 'rc.id', '=', 'restaurant_menu_items.category_id')
            ->groupBy('rc.name')
            ->orderBy('rc.name')
            ->select(DB::raw('rc.name, COUNT(*) as count'))
            ->toBase()
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->count])
            ->values();

        return response()->json([
            'message' => 'Menu items fetched successfully.',
            'data' => $items,
            'filters' => [
                'restaurants' => Restaurant::query()
                    ->whereNotNull('restaurant_name')
                    ->orderBy('restaurant_name')
                    ->get(['id', 'restaurant_name'])
                    ->map(fn ($restaurant) => [
                        'id' => $restaurant->id,
                        'name' => $restaurant->restaurant_name,
                    ])
                    ->values(),
                'categories' => $categories,
                'total' => $categories->sum('count'),
            ],
        ]);
    }

    /**
     * Active categories of one restaurant (for the create / edit form)
     */
    public function categories(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
        ]);

        return response()->json([
            'message' => 'Categories fetched successfully.',
            'data' => RestaurantCategory::query()
                ->where('restaurant_id', $validated['restaurant_id'])
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->values(),
        ]);
    }

    /**
     * Create an item (multipart: optional image)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            ...$this->itemRules(),
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if ($error = $this->checkDiscount($validated)) {
            return $error;
        }

        $item = DB::transaction(function () use ($request, $validated) {
            $category = $this->resolveCategory((int) $validated['restaurant_id'], $validated['category']);

            $item = RestaurantMenuItem::create([
                'restaurant_id' => $validated['restaurant_id'],
                'category_id' => $category->id,
                'name' => trim($validated['name']),
                'description' => $this->nullableText($validated['description'] ?? null),
                'price' => $validated['price'],
                'discount_price' => $validated['discount_price'] ?? null,
                'image' => $request->hasFile('image')
                    ? $request->file('image')->store('restaurant_menu', 'public')
                    : null,
                'is_available' => true,
                'is_recommended' => $request->boolean('is_recommended'),
                'is_active' => true,
            ]);

            Restaurant::query()
                ->whereKey($validated['restaurant_id'])
                ->where('status', 'draft')
                ->where('onboarding_step', '<', 8)
                ->update(['onboarding_step' => 8]);

            return $item;
        });

        // Picks up an offer already running on this item
        app(RestaurantOfferService::class)->syncMenuItem($item->id);

        return response()->json([
            'message' => 'Food item created successfully.',
            'data' => $this->freshRow($item->id),
        ], 201);
    }

    /**
     * Update an item (POST + multipart so the image can change)
     */
    public function update(Request $request, $id)
    {
        $item = $this->findItem($id);

        if (!$item) {
            return $this->notFound();
        }

        $validated = $request->validate([
            ...$this->itemRules(),
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        if ($error = $this->checkDiscount($validated)) {
            return $error;
        }

        DB::transaction(function () use ($request, $item, $validated) {
            $category = $this->resolveCategory($item->restaurant_id, $validated['category']);

            if ($request->hasFile('image') || $request->boolean('remove_image')) {
                if ($item->image) {
                    Storage::disk('public')->delete($item->image);
                }

                $item->image = $request->hasFile('image')
                    ? $request->file('image')->store('restaurant_menu', 'public')
                    : null;
            }

            $item->category_id = $category->id;
            $item->name = trim($validated['name']);
            $item->description = $this->nullableText($validated['description'] ?? null);
            $item->price = $validated['price'];

            // Keeps a running admin offer price intact
            $item->setOwnDiscountPrice($validated['discount_price'] ?? null);

            if ($request->has('is_recommended')) {
                $item->is_recommended = $request->boolean('is_recommended');
            }

            $item->save();
        });

        // Re-apply offer on the (possibly new) price
        app(RestaurantOfferService::class)->syncMenuItem($item->id);

        return response()->json([
            'message' => 'Food item updated successfully.',
            'data' => $this->freshRow($item->id),
        ]);
    }

    /**
     * Active (customers can order) <-> Inactive
     */
    public function toggleStatus($id)
    {
        $item = $this->findItem($id);

        if (!$item) {
            return $this->notFound();
        }

        $item->update(['is_available' => !$item->is_available]);

        $row = $this->freshRow($item->id);

        return response()->json([
            'message' => $row['name'] . ' is now ' . ($row['is_available'] ? 'active' : 'inactive') . '.',
            'data' => $row,
        ]);
    }

    /**
     * Remove from the menu (kept in the database for order history)
     */
    public function destroy($id)
    {
        $item = $this->findItem($id);

        if (!$item) {
            return $this->notFound();
        }

        $item->update([
            'is_active' => false,
            'is_available' => false,
        ]);

        return response()->json([
            'message' => 'Food item deleted successfully.',
        ]);
    }

    // =====================================================
    // HELPERS
    // =====================================================

    private function filteredQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return RestaurantMenuItem::query()
            ->select('restaurant_menu_items.*')
            ->where('restaurant_menu_items.is_active', true)
            ->with([
                'category:id,name',
                'restaurant:id,restaurant_name',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . $search . '%';

                $query->where(function ($query) use ($like) {
                    $query->where('restaurant_menu_items.name', 'like', $like)
                        ->orWhere('restaurant_menu_items.description', 'like', $like)
                        ->orWhereHas('category', fn ($category) => $category->where('name', 'like', $like))
                        ->orWhereHas('restaurant', fn ($restaurant) => $restaurant->where('restaurant_name', 'like', $like));
                });
            })
            ->when($filters['restaurant_id'] ?? null, fn ($query, $restaurantId) => $query->where('restaurant_menu_items.restaurant_id', $restaurantId))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->whereHas(
                'category',
                fn ($query) => $query->where('name', $category)
            ))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where(
                'restaurant_menu_items.is_available',
                $status === 'active'
            ));
    }

    private function itemRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999'],
            'discount_price' => ['nullable', 'numeric', 'min:0'],
            'is_recommended' => ['nullable', 'boolean'],
        ];
    }

    private function checkDiscount(array $validated)
    {
        $discount = $validated['discount_price'] ?? null;

        if ($discount !== null && (float) $discount >= (float) $validated['price']) {
            return response()->json([
                'message' => 'Discount price must be lower than the price.',
                'errors' => ['discount_price' => ['Discount price must be lower than the price.']],
            ], 422);
        }

        return null;
    }

    /**
     * Restaurant's category with this name, created if it does not exist yet
     */
    private function resolveCategory(int $restaurantId, string $name): RestaurantCategory
    {
        $name = trim($name);

        $category = RestaurantCategory::query()
            ->where('restaurant_id', $restaurantId)
            ->where('name', $name)
            ->first();

        if ($category) {
            if (!$category->is_active) {
                $category->update(['is_active' => true]);
            }

            return $category;
        }

        return RestaurantCategory::create([
            'restaurant_id' => $restaurantId,
            'name' => $name,
            'display_order' => (int) RestaurantCategory::where('restaurant_id', $restaurantId)->max('display_order') + 1,
            'is_active' => true,
        ]);
    }

    private function findItem($id): ?RestaurantMenuItem
    {
        return RestaurantMenuItem::query()
            ->where('is_active', true)
            ->find($id);
    }

    private function freshRow(int $id): array
    {
        return $this->row(
            RestaurantMenuItem::with(['category:id,name', 'restaurant:id,restaurant_name'])->findOrFail($id)
        );
    }

    private function row(RestaurantMenuItem $item): array
    {
        $price = (float) $item->price;
        $sellingPrice = $item->sellingPrice();
        $ownDiscount = $item->ownDiscountPrice();

        // Tags come from real item data
        $tags = array_values(array_filter([
            $item->is_recommended ? 'Recommended' : null,
            $item->offer_label ?: null,
            !$item->offer_label && $sellingPrice < $price && $price > 0
                ? round((($price - $sellingPrice) / $price) * 100) . '% Off'
                : null,
        ]));

        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'restaurant' => $item->restaurant ? [
                'id' => $item->restaurant->id,
                'name' => $item->restaurant->restaurant_name,
            ] : null,
            'category' => $item->category?->name,
            'price' => $price,
            'discount_price' => $ownDiscount !== null ? (float) $ownDiscount : null,
            'selling_price' => $sellingPrice,
            'offer_label' => $item->offer_label,
            'image' => $item->image ? asset('storage/' . ltrim($item->image, '/')) : null,
            'is_available' => (bool) $item->is_available,
            'is_recommended' => (bool) $item->is_recommended,
            'tags' => $tags,
            'updated_at' => $item->updated_at ? Carbon::parse($item->updated_at)->toIso8601String() : null,
        ];
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function notFound()
    {
        return response()->json([
            'message' => 'Food item not found.',
        ], 404);
    }
}
