<?php

namespace App\Services\Restaurant;

use App\Http\Controllers\Api\Customer\CustomerCartController;
use App\Models\CustomerCart;
use App\Models\CustomerCartItem;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantNotification;
use App\Models\RestaurantOffer;
use Illuminate\Support\Facades\DB;

/**
 * Applies admin offers to menu items.
 *
 * A running offer is written into restaurant_menu_items.discount_price,
 * so every customer screen, the cart and checkout (which all read
 * discount_price) use the offer price. The restaurant's own
 * discount_price is parked in regular_discount_price and restored when
 * the offer ends, is deactivated or deleted.
 */
class RestaurantOfferService
{
    /**
     * Bring one menu item in line with its running offer (if any).
     */
    public function syncMenuItem(int $menuItemId): ?RestaurantMenuItem
    {
        return DB::transaction(function () use ($menuItemId) {

            $item = RestaurantMenuItem::query()
                ->lockForUpdate()
                ->find($menuItemId);

            if (!$item) {
                return null;
            }

            $offer = RestaurantOffer::query()
                ->running()
                ->where('menu_item_id', $item->id)
                ->latest('id')
                ->first();

            if ($offer) {
                if ($item->active_offer_id === null) {
                    $item->regular_discount_price = $item->discount_price;
                }

                $price = (float) $item->price;

                $item->discount_price = round($price - $offer->discountFor($price), 2);
                $item->active_offer_id = $offer->id;
                $item->offer_label = $offer->label();

            } elseif ($item->active_offer_id !== null) {
                $item->discount_price = $item->regular_discount_price;
                $item->regular_discount_price = null;
                $item->active_offer_id = null;
                $item->offer_label = null;
            }

            $priceChanged = $item->isDirty('discount_price') || $item->isDirty('price');

            if ($item->isDirty()) {
                $item->save();
            }

            if ($priceChanged) {
                $this->refreshCarts($item);
            }

            return $item;
        });
    }

    /**
     * Sync every item that has a running offer or still carries an old one.
     * Run by the scheduler so offers start / end on their dates.
     */
    public function syncAll(): int
    {
        $ids = RestaurantOffer::query()
            ->running()
            ->pluck('menu_item_id')
            ->merge(
                RestaurantMenuItem::query()
                    ->whereNotNull('active_offer_id')
                    ->pluck('id')
            )
            ->unique();

        foreach ($ids as $id) {
            $this->syncMenuItem((int) $id);
        }

        return $ids->count();
    }

    /**
     * Delete an offer and restore the item's normal price first
     * (the FK would otherwise null active_offer_id before we can restore).
     */
    public function deleteOffer(RestaurantOffer $offer): void
    {
        $offer->update(['is_active' => false]);

        $this->syncMenuItem($offer->menu_item_id);

        $offer->delete();
    }

    /**
     * Re-price carts that already contain this item.
     */
    private function refreshCarts(RestaurantMenuItem $item): void
    {
        $unitPrice = $item->discount_price !== null
            ? (float) $item->discount_price
            : (float) $item->price;

        $cartItems = CustomerCartItem::query()
            ->where('menu_item_id', $item->id)
            ->get();

        if ($cartItems->isEmpty()) {
            return;
        }

        foreach ($cartItems as $cartItem) {
            $cartItem->unit_price = $unitPrice;
            $cartItem->total_price = $cartItem->quantity * $unitPrice;
            $cartItem->save();
        }

        $cartController = app(CustomerCartController::class);

        CustomerCart::query()
            ->whereIn('id', $cartItems->pluck('cart_id')->unique())
            ->get()
            ->each(fn ($cart) => $cartController->recalculateCartTotals($cart)->save());
    }

    /**
     * Tell the restaurant what admin did with its offer.
     */
    public function notifyRestaurant(RestaurantOffer $offer, string $action): void
    {
        $offer->loadMissing('menuItem:id,name,price');

        $itemName = $offer->menuItem?->name ?? 'a food item';
        $price = (float) ($offer->menuItem?->price ?? 0);
        $finalPrice = round($price - $offer->discountFor($price), 2);

        $dates = $offer->start_date->format('d M Y') . ' - ' . $offer->end_date->format('d M Y');

        $details = "{$offer->label()} on {$itemName} (₹" . $this->money($price)
            . " → ₹" . $this->money($finalPrice) . "), valid {$dates}.";

        [$title, $message] = match ($action) {
            'created' => ['New offer added', "Admin added an offer: {$details}"],
            'updated' => ['Offer updated', "Admin updated your offer: {$details}"],
            'activated' => ['Offer activated', "Admin activated your offer: {$details}"],
            'deactivated' => ['Offer deactivated', "Admin deactivated the {$offer->label()} offer on {$itemName}. Normal price applies."],
            'deleted' => ['Offer removed', "Admin removed the {$offer->label()} offer on {$itemName}. Normal price applies."],
            default => ['Offer changed', $details],
        };

        RestaurantNotification::create([
            'restaurant_id' => $offer->restaurant_id,
            'type' => 'promotion',
            'title' => $title,
            'message' => $message,
            'reference_type' => 'restaurant_offer',
            'reference_id' => $action === 'deleted' ? null : $offer->id,
            'is_read' => false,
        ]);
    }

    private function money(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
