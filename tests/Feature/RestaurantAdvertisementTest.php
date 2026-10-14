<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerCart;
use App\Models\Restaurant;
use App\Models\RestaurantAdPackage;
use App\Models\RestaurantAdPackageDuration;
use App\Models\RestaurantAdPayment;
use App\Models\RestaurantAdvertisement;
use App\Models\RestaurantCategory;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantNotification;
use App\Services\Payment\FakePaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Needs MySQL (some existing migrations use ALTER ... MODIFY ENUM):
 *   DB_CONNECTION=mysql DB_DATABASE=veggo_testing php artisan test --filter=RestaurantAdvertisementTest
 */
class RestaurantAdvertisementTest extends TestCase
{
    use RefreshDatabase;

    private int $mobile = 9000000000;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['advertisement.payment_driver' => 'fake', 'advertisement.payment_enabled' => true]);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function restaurant(array $attributes = []): Restaurant
    {
        return Restaurant::create(array_merge([
            'mobile' => (string) $this->mobile++,
            'owner_name' => 'Owner',
            'restaurant_name' => 'Kathiyawadi Village',
            'email' => 'owner' . $this->mobile . '@example.com',
            'status' => 'approved',
            'is_online' => true,
        ], $attributes));
    }

    private function foodItem(Restaurant $restaurant, array $attributes = []): RestaurantMenuItem
    {
        $category = RestaurantCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Pizza',
            'is_active' => true,
        ]);

        return RestaurantMenuItem::create(array_merge([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Paneer Pizza',
            'price' => 199,
            'is_available' => true,
            'is_active' => true,
        ], $attributes));
    }

    private function customer(): Customer
    {
        return Customer::create(['phone' => (string) $this->mobile++, 'name' => 'Customer', 'status' => 'active']);
    }

    private function package(string $code): RestaurantAdPackage
    {
        return RestaurantAdPackage::where('code', $code)->firstOrFail();
    }

    private function duration(string $code, int $days = 7): RestaurantAdPackageDuration
    {
        return $this->package($code)->durations()->where('duration_days', $days)->firstOrFail();
    }

    private function image(int $width = 1200, int $height = 600): UploadedFile
    {
        return UploadedFile::fake()->image('ad.jpg', $width, $height);
    }

    private function createAd(Restaurant $restaurant, string $code, array $extra = [], bool $withImage = true)
    {
        Sanctum::actingAs($restaurant);

        $payload = array_merge([
            'package_id' => $this->package($code)->id,
            'package_duration_id' => $this->duration($code)->id,
        ], $extra);

        if ($withImage) {
            $payload['image'] = $this->image();
        }

        return $this->post('/api/restaurant/advertisements', $payload, ['Accept' => 'application/json']);
    }

    /**
     * Draft -> payment order -> verified payment. Returns the ad.
     */
    private function buyAd(Restaurant $restaurant, string $code, array $extra = [], bool $withImage = true): RestaurantAdvertisement
    {
        $id = $this->createAd($restaurant, $code, $extra, $withImage)->assertCreated()->json('data.advertisement.id');

        $this->pay($restaurant, $id)->assertOk()->assertJsonPath('data.advertisement.payment_status', 'paid');

        $advertisement = RestaurantAdvertisement::findOrFail($id);
        $this->assertSame('active', $advertisement->status);

        return $advertisement;
    }

    private function pay(Restaurant $restaurant, int $adId, ?string $paymentId = null)
    {
        Sanctum::actingAs($restaurant);

        $orderId = $this->postJson("/api/restaurant/advertisements/{$adId}/payment")
            ->assertOk()
            ->json('data.checkout.order_id');

        $paymentId ??= 'fake_pay_' . $adId . '_' . uniqid();

        return $this->postJson('/api/restaurant/advertisements/payment/verify', [
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'signature' => FakePaymentGateway::sign($orderId, $paymentId),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Packages
    |--------------------------------------------------------------------------
    */

    public function test_packages_list_comes_from_database_with_gst(): void
    {
        Sanctum::actingAs($this->restaurant());

        $response = $this->getJson('/api/restaurant/advertisement/packages')->assertOk();

        $this->assertCount(4, $response->json('data.packages'));

        $food = collect($response->json('data.packages'))->firstWhere('code', 'FOOD_ITEM_PROMOTION');
        $sevenDays = collect($food['durations'])->firstWhere('duration_days', 7);

        $this->assertEquals(1500, $sevenDays['price']);
        $this->assertEquals(270, $sevenDays['tax_amount']);
        $this->assertEquals(1770, $sevenDays['total_amount']);
        $this->assertTrue($food['requires_food_item']);

        $featured = collect($response->json('data.packages'))->firstWhere('code', 'FEATURED_RESTAURANT');
        $this->assertFalse($featured['requires_image']);
    }

    public function test_disabled_package_and_duration_are_hidden_and_rejected(): void
    {
        $restaurant = $this->restaurant();
        $this->package('CHECKOUT_PAGE_OFFER')->update(['is_active' => false]);
        $this->duration('HOME_SCREEN_SHOWCASE', 3)->update(['is_active' => false]);

        Sanctum::actingAs($restaurant);
        $packages = collect($this->getJson('/api/restaurant/advertisement/packages')->json('data.packages'));

        $this->assertNull($packages->firstWhere('code', 'CHECKOUT_PAGE_OFFER'));
        $this->assertNull(collect($packages->firstWhere('code', 'HOME_SCREEN_SHOWCASE')['durations'])->firstWhere('duration_days', 3));

        $this->createAd($restaurant, 'CHECKOUT_PAGE_OFFER')->assertStatus(422)->assertJsonValidationErrors('package_id');

        $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE', [
            'package_duration_id' => $this->duration('HOME_SCREEN_SHOWCASE', 3)->id,
        ])->assertStatus(422)->assertJsonValidationErrors('package_duration_id');
    }

    public function test_duration_from_another_package_is_rejected(): void
    {
        $this->createAd($this->restaurant(), 'HOME_SCREEN_SHOWCASE', [
            'package_duration_id' => $this->duration('FEATURED_RESTAURANT')->id,
        ])->assertStatus(422)->assertJsonValidationErrors('package_duration_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Package 1 - Home Screen Showcase
    |--------------------------------------------------------------------------
    */

    public function test_package_1_full_flow_shows_on_home_and_disappears_after_expiry(): void
    {
        $restaurant = $this->restaurant();

        $response = $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE')->assertCreated();
        $response->assertJsonPath('data.advertisement.status', 'draft')
            ->assertJsonPath('data.advertisement.payment_status', 'pending')
            ->assertJsonPath('data.advertisement.target.type', 'home')
            ->assertJsonPath('data.advertisement.target.id', null);

        $ad = RestaurantAdvertisement::findOrFail($response->json('data.advertisement.id'));
        $this->assertEquals(2500, $ad->amount);
        $this->assertEquals(450, $ad->tax_amount);
        $this->assertEquals(2950, $ad->total_amount);
        $this->assertSame('restaurant', $ad->created_by_type);
        $this->assertSame($restaurant->id, $ad->created_by_id);
        $this->assertStringStartsWith('restaurant_ads/', $ad->image);
        Storage::disk('public')->assertExists($ad->image);

        // Customer cannot see an unpaid draft
        Sanctum::actingAs($this->customer());
        $this->getJson('/api/customer/home')->assertOk()->assertJsonCount(0, 'data.home_advertisements');

        $this->pay($restaurant, $ad->id)->assertOk();
        $ad->refresh();

        $this->assertSame('active', $ad->status);
        $this->assertSame('paid', $ad->payment_status);
        $this->assertEquals(7, $ad->start_at->diffInDays($ad->end_at));

        Sanctum::actingAs($this->customer());
        $this->getJson('/api/customer/home')
            ->assertOk()
            ->assertJsonCount(1, 'data.home_advertisements')
            ->assertJsonPath('data.home_advertisements.0.id', $ad->id)
            ->assertJsonPath('data.home_advertisements.0.restaurant.id', $restaurant->id);

        // 7 days later it is gone, even before the scheduler runs
        $this->travel(7)->days();
        $this->travel(1)->minutes();

        $this->getJson('/api/customer/home')->assertOk()->assertJsonCount(0, 'data.home_advertisements');

        $this->artisan('advertisements:expire')->assertSuccessful();
        $this->assertSame('expired', $ad->refresh()->status);
    }

    public function test_package_1_requires_image(): void
    {
        $this->createAd($this->restaurant(), 'HOME_SCREEN_SHOWCASE', [], false)
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->assertSame(0, RestaurantAdvertisement::count());
    }

    public function test_invalid_images_are_rejected(): void
    {
        $restaurant = $this->restaurant();

        // Too small
        $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE', ['image' => $this->image(200, 100)], false)
            ->assertStatus(422)->assertJsonValidationErrors('image');

        // Not an image
        $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE', [
            'image' => UploadedFile::fake()->create('ad.pdf', 100, 'application/pdf'),
        ], false)->assertStatus(422)->assertJsonValidationErrors('image');

        // Too large
        $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE', [
            'image' => UploadedFile::fake()->image('big.jpg', 1200, 600)->size(4096),
        ], false)->assertStatus(422)->assertJsonValidationErrors('image');

        // Wrong aspect ratio when one is configured
        config(['advertisement.image.aspect_ratio.home' => 2.0]);
        $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE', ['image' => $this->image(1000, 1000)], false)
            ->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertSame(0, RestaurantAdvertisement::count());
        $this->assertEmpty(Storage::disk('public')->allFiles('restaurant_ads'));
    }

    /*
    |--------------------------------------------------------------------------
    | Package 2 - Food Item Promotion
    |--------------------------------------------------------------------------
    */

    public function test_package_2_full_flow_shows_on_exact_food_item_only(): void
    {
        $restaurant = $this->restaurant();
        $pizza = $this->foodItem($restaurant);
        $other = $this->foodItem($restaurant, ['name' => 'Margherita']);

        // Picker returns only this restaurant's items
        $otherRestaurant = $this->restaurant();
        $this->foodItem($otherRestaurant, ['name' => 'Not mine']);

        Sanctum::actingAs($restaurant);
        $names = collect($this->getJson('/api/restaurant/advertisement/food-items')->json('data.categories'))
            ->flatMap(fn ($c) => collect($c['items'])->pluck('name'));
        $this->assertTrue($names->contains('Paneer Pizza'));
        $this->assertFalse($names->contains('Not mine'));

        $ad = $this->buyAd($restaurant, 'FOOD_ITEM_PROMOTION', ['food_item_id' => $pizza->id]);

        $this->assertSame('food_item', $ad->target_type);
        $this->assertSame($pizza->id, $ad->target_id);
        $this->assertEquals(1770, $ad->total_amount);

        // Food item image untouched
        $this->assertNull($pizza->refresh()->image);

        Sanctum::actingAs($this->customer());
        $this->getJson("/api/customer/restaurants/{$restaurant->id}/menu-items/{$pizza->id}")
            ->assertOk()
            ->assertJsonPath('data.advertisement.id', $ad->id)
            ->assertJsonPath('data.advertisement.food_item_id', $pizza->id);

        $this->getJson("/api/customer/restaurants/{$restaurant->id}/menu-items/{$other->id}")
            ->assertOk()
            ->assertJsonPath('data.advertisement', null);

        $this->getJson('/api/customer/home')
            ->assertJsonCount(1, 'data.promoted_food_items')
            ->assertJsonPath('data.promoted_food_items.0.food_item.id', $pizza->id);

        // Deactivated food item stops showing
        $pizza->update(['is_active' => false]);
        $this->getJson('/api/customer/home')->assertJsonCount(0, 'data.promoted_food_items');
    }

    public function test_package_2_another_restaurants_food_item_must_fail(): void
    {
        $restaurant = $this->restaurant();
        $foreignItem = $this->foodItem($this->restaurant(['restaurant_name' => 'Other']));

        $this->createAd($restaurant, 'FOOD_ITEM_PROMOTION', ['food_item_id' => $foreignItem->id])
            ->assertForbidden();

        $this->assertSame(0, RestaurantAdvertisement::count());
    }

    public function test_package_2_requires_one_valid_active_food_item(): void
    {
        $restaurant = $this->restaurant();
        $inactive = $this->foodItem($restaurant, ['is_active' => false]);
        $item = $this->foodItem($restaurant);

        $this->createAd($restaurant, 'FOOD_ITEM_PROMOTION')->assertStatus(422)->assertJsonValidationErrors('food_item_id');
        $this->createAd($restaurant, 'FOOD_ITEM_PROMOTION', ['food_item_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('food_item_id');
        $this->createAd($restaurant, 'FOOD_ITEM_PROMOTION', ['food_item_id' => $inactive->id])->assertStatus(422)->assertJsonValidationErrors('food_item_id');
        $this->createAd($restaurant, 'FOOD_ITEM_PROMOTION', ['food_item_id' => [$item->id, $inactive->id]])->assertStatus(422)->assertJsonValidationErrors('food_item_id');

        // Food item is not allowed on other packages
        $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE', ['food_item_id' => $item->id])->assertStatus(422)->assertJsonValidationErrors('food_item_id');

        $this->assertSame(0, RestaurantAdvertisement::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Package 3 - Checkout Page Offer
    |--------------------------------------------------------------------------
    */

    public function test_package_3_shows_only_when_checking_out_from_same_restaurant(): void
    {
        $restaurantA = $this->restaurant(['restaurant_name' => 'A']);
        $restaurantB = $this->restaurant(['restaurant_name' => 'B']);

        $ad = $this->buyAd($restaurantA, 'CHECKOUT_PAGE_OFFER');
        $this->assertSame('checkout', $ad->target_type);
        $this->assertNull($ad->target_id);

        $customer = $this->customer();
        Sanctum::actingAs($customer);

        $cart = CustomerCart::create(['customer_id' => $customer->id, 'restaurant_id' => $restaurantA->id]);
        $this->getJson('/api/customer/cart')->assertOk()->assertJsonPath('data.checkout_advertisement.id', $ad->id);

        $cart->update(['restaurant_id' => $restaurantB->id]);
        $this->getJson('/api/customer/cart')->assertOk()->assertJsonPath('data.checkout_advertisement', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Package 4 - Featured Restaurant
    |--------------------------------------------------------------------------
    */

    public function test_package_4_featured_first_then_back_to_normal_after_expiry(): void
    {
        $featured = $this->restaurant(['restaurant_name' => 'Featured']);
        $this->travel(1)->minutes();
        $newer = $this->restaurant(['restaurant_name' => 'Newer']); // latest() would list it first

        $ad = $this->buyAd($featured, 'FEATURED_RESTAURANT', [], false);
        $this->assertSame('restaurant', $ad->target_type);
        $this->assertSame($featured->id, $ad->target_id);
        $this->assertNull($ad->image);

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/customer/restaurants')
            ->assertJsonPath('data.restaurants.0.id', $featured->id)
            ->assertJsonPath('data.restaurants.0.is_featured', true)
            ->assertJsonPath('data.restaurants.1.is_featured', false);

        $this->getJson('/api/customer/home')
            ->assertJsonCount(1, 'data.featured_restaurants')
            ->assertJsonPath('data.featured_restaurants.0.id', $featured->id);

        $this->travel(8)->days();

        $this->getJson('/api/customer/restaurants')
            ->assertJsonPath('data.restaurants.0.id', $newer->id)
            ->assertJsonPath('data.restaurants.1.is_featured', false);
        $this->getJson('/api/customer/home')->assertJsonCount(0, 'data.featured_restaurants');
    }

    public function test_package_4_rejects_image(): void
    {
        $this->createAd($this->restaurant(), 'FEATURED_RESTAURANT')
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

    public function test_restaurant_cannot_set_protected_fields(): void
    {
        $restaurant = $this->restaurant();
        $other = $this->restaurant();

        $id = $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE', [
            'restaurant_id' => $other->id,
            'amount' => 1,
            'tax_amount' => 0,
            'total_amount' => 1,
            'status' => 'active',
            'payment_status' => 'paid',
            'start_at' => now()->toDateTimeString(),
            'end_at' => now()->addYear()->toDateTimeString(),
            'target_type' => 'checkout',
        ])->assertCreated()->json('data.advertisement.id');

        $ad = RestaurantAdvertisement::findOrFail($id);
        $this->assertSame($restaurant->id, $ad->restaurant_id);
        $this->assertEquals(2950, $ad->total_amount);
        $this->assertSame('draft', $ad->status);
        $this->assertSame('pending', $ad->payment_status);
        $this->assertSame('home', $ad->target_type);
        $this->assertNull($ad->start_at);
    }

    public function test_restaurant_cannot_access_another_restaurants_advertisement(): void
    {
        $owner = $this->restaurant();
        $intruder = $this->restaurant();

        $id = $this->createAd($owner, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');

        Sanctum::actingAs($owner);
        $orderId = $this->postJson("/api/restaurant/advertisements/{$id}/payment")->json('data.checkout.order_id');

        Sanctum::actingAs($intruder);
        $this->getJson("/api/restaurant/advertisements/{$id}")->assertForbidden();
        $this->postJson("/api/restaurant/advertisements/{$id}/payment")->assertForbidden();
        $this->postJson("/api/restaurant/advertisements/{$id}/cancel")->assertForbidden();
        $this->postJson('/api/restaurant/advertisements/payment/verify', [
            'order_id' => $orderId,
            'payment_id' => 'fake_pay_x',
            'signature' => FakePaymentGateway::sign($orderId, 'fake_pay_x'),
        ])->assertForbidden();
        $this->assertCount(0, $this->getJson('/api/restaurant/advertisements')->json('data.advertisements'));

        $this->assertSame('pending', RestaurantAdvertisement::find($id)->payment_status);
    }

    public function test_customer_token_cannot_use_restaurant_advertisement_api(): void
    {
        Sanctum::actingAs($this->customer());

        $this->getJson('/api/restaurant/advertisement/packages')->assertForbidden();
    }

    public function test_unapproved_restaurant_cannot_advertise(): void
    {
        Sanctum::actingAs($this->restaurant(['status' => 'pending_approval']));

        $this->getJson('/api/restaurant/advertisement/packages')->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Payment
    |--------------------------------------------------------------------------
    */

    public function test_failed_payment_does_not_activate_and_can_be_retried(): void
    {
        $restaurant = $this->restaurant();
        $id = $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');

        $this->pay($restaurant, $id, 'fake_fail_1')->assertStatus(402);

        $ad = RestaurantAdvertisement::findOrFail($id);
        $this->assertSame('failed', $ad->payment_status);
        $this->assertSame('pending', $ad->status);
        $this->assertNull($ad->start_at);

        Sanctum::actingAs($this->customer());
        $this->getJson('/api/customer/home')->assertJsonCount(0, 'data.home_advertisements');

        $this->pay($restaurant, $id)->assertOk();
        $this->assertSame('active', $ad->refresh()->status);
    }

    public function test_forged_signature_is_rejected(): void
    {
        $restaurant = $this->restaurant();
        $id = $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');

        Sanctum::actingAs($restaurant);
        $orderId = $this->postJson("/api/restaurant/advertisements/{$id}/payment")->json('data.checkout.order_id');

        $this->postJson('/api/restaurant/advertisements/payment/verify', [
            'order_id' => $orderId,
            'payment_id' => 'fake_pay_1',
            'signature' => 'not-a-real-signature',
        ])->assertStatus(422);

        $this->assertSame('pending', RestaurantAdvertisement::find($id)->payment_status);
    }

    public function test_duplicate_verification_and_webhook_activate_only_once(): void
    {
        $restaurant = $this->restaurant();
        $id = $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');

        Sanctum::actingAs($restaurant);
        $orderId = $this->postJson("/api/restaurant/advertisements/{$id}/payment")->json('data.checkout.order_id');
        $verify = [
            'order_id' => $orderId,
            'payment_id' => 'fake_pay_dup',
            'signature' => FakePaymentGateway::sign($orderId, 'fake_pay_dup'),
        ];

        $this->postJson('/api/restaurant/advertisements/payment/verify', $verify)->assertOk();
        $first = RestaurantAdvertisement::findOrFail($id);

        $this->travel(5)->minutes();

        $this->postJson('/api/restaurant/advertisements/payment/verify', $verify)->assertOk();

        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'fake_pay_dup',
                'order_id' => $orderId,
                'amount' => 295000,
                'currency' => 'INR',
                'status' => 'captured',
            ]]],
        ]);

        $this->call('POST', '/api/restaurant/advertisements/payment/webhook', [], [], [], [
            'HTTP_X_PAYU_SIGNATURE' => hash_hmac('sha256', $body, FakePaymentGateway::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $ad = $first->fresh();
        $this->assertEquals($first->start_at, $ad->start_at);
        $this->assertEquals($first->end_at, $ad->end_at);
        $this->assertSame(1, RestaurantAdPayment::where('status', 'paid')->count());
        $this->assertSame(1, RestaurantNotification::where('restaurant_id', $restaurant->id)->where('title', 'Advertisement active')->count());

        // Paying an already paid ad is refused
        $this->postJson("/api/restaurant/advertisements/{$id}/payment")->assertStatus(409);
    }

    public function test_webhook_with_bad_signature_is_rejected(): void
    {
        $this->call('POST', '/api/restaurant/advertisements/payment/webhook', [], [], [], [
            'HTTP_X_PAYU_SIGNATURE' => 'bad',
            'CONTENT_TYPE' => 'application/json',
        ], '{"event":"payment.captured"}')->assertStatus(401);
    }

    public function test_webhook_activates_when_app_never_calls_verify(): void
    {
        $restaurant = $this->restaurant();
        $id = $this->createAd($restaurant, 'CHECKOUT_PAGE_OFFER')->json('data.advertisement.id');

        Sanctum::actingAs($restaurant);
        $orderId = $this->postJson("/api/restaurant/advertisements/{$id}/payment")->json('data.checkout.order_id');

        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_webhook_only', 'order_id' => $orderId, 'amount' => 212400, 'currency' => 'INR',
            ]]],
        ]);

        $this->call('POST', '/api/restaurant/advertisements/payment/webhook', [], [], [], [
            'HTTP_X_PAYU_SIGNATURE' => hash_hmac('sha256', $body, FakePaymentGateway::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $ad = RestaurantAdvertisement::find($id);
        $this->assertSame('active', $ad->status);
        $this->assertSame('pay_webhook_only', $ad->payment_reference);
    }

    public function test_payu_amount_mismatch_does_not_activate(): void
    {
        config([
            'advertisement.payment_driver' => 'payu',
            'services.payu.key' => 'UPFEAR',
            'services.payu.salt' => 'test_salt',
            'services.payu.verify_url' => 'https://test.payu.in/merchant/postservice?form=2',
        ]);

        $restaurant = $this->restaurant();
        $id = $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');

        Sanctum::actingAs($restaurant);
        $res = $this->postJson("/api/restaurant/advertisements/{$id}/payment")
            ->assertOk()
            ->assertJsonPath('data.checkout.gateway', 'payu');

        $orderId = $res->json('data.checkout.order_id');

        Http::fake([
            'test.payu.in/*' => Http::response([
                'status' => 1,
                'transaction_details' => [
                    $orderId => [
                        'status' => 'success',
                        'amount' => '1.00',
                        'mihpayid' => 'pay_payu1',
                        'txnid' => $orderId,
                    ],
                ],
            ]),
        ]);

        $this->postJson('/api/restaurant/advertisements/payment/verify', [
            'order_id' => $orderId,
            'payment_id' => 'pay_payu1',
            'signature' => 'valid',
        ])->assertStatus(422);

        $ad = RestaurantAdvertisement::find($id);
        $this->assertNotSame('active', $ad->status);
        $this->assertNotSame('paid', $ad->payment_status);
    }

    public function test_payu_verified_payment_activates(): void
    {
        config([
            'advertisement.payment_driver' => 'payu',
            'services.payu.key' => 'UPFEAR',
            'services.payu.salt' => 'test_salt',
            'services.payu.verify_url' => 'https://test.payu.in/merchant/postservice?form=2',
        ]);

        $restaurant = $this->restaurant();
        $id = $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');

        Sanctum::actingAs($restaurant);
        $res = $this->postJson("/api/restaurant/advertisements/{$id}/payment")->assertOk();
        $orderId = $res->json('data.checkout.order_id');

        Http::fake([
            'test.payu.in/*' => Http::response([
                'status' => 1,
                'transaction_details' => [
                    $orderId => [
                        'status' => 'success',
                        'amount' => '2950.00',
                        'mihpayid' => 'pay_payu2',
                        'txnid' => $orderId,
                        'mode' => 'upi',
                    ],
                ],
            ]),
        ]);

        $this->postJson('/api/restaurant/advertisements/payment/verify', [
            'order_id' => $orderId,
            'payment_id' => 'pay_payu2',
            'signature' => 'valid',
        ])->assertOk()->assertJsonPath('data.advertisement.status', 'active');

        $this->assertSame('pay_payu2', RestaurantAdvertisement::find($id)->payment_reference);
    }

    public function test_disabled_package_after_draft_cannot_be_paid(): void
    {
        $restaurant = $this->restaurant();
        $id = $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');

        $this->duration('HOME_SCREEN_SHOWCASE')->update(['is_active' => false]);

        Sanctum::actingAs($restaurant);
        $this->postJson("/api/restaurant/advertisements/{$id}/payment")->assertStatus(422);
    }

    public function test_price_change_after_draft_is_charged_from_database(): void
    {
        $restaurant = $this->restaurant();
        $id = $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');

        $this->duration('HOME_SCREEN_SHOWCASE')->update(['price' => 3000]);

        Sanctum::actingAs($restaurant);
        $this->postJson("/api/restaurant/advertisements/{$id}/payment")
            ->assertOk()
            ->assertJsonPath('data.checkout.amount', 354000);
    }

    public function test_second_ad_for_same_target_starts_after_the_running_one(): void
    {
        $restaurant = $this->restaurant();

        $first = $this->buyAd($restaurant, 'FEATURED_RESTAURANT', [], false);
        $second = $this->buyAd($restaurant, 'FEATURED_RESTAURANT', [], false);

        $this->assertEquals($first->end_at, $second->start_at);

        Sanctum::actingAs($restaurant);
        $this->getJson("/api/restaurant/advertisements/{$second->id}")->assertJsonPath('data.advertisement.status', 'scheduled');
    }

    public function test_pay_later_mode_activates_without_gateway_and_admin_settles_later(): void
    {
        config(['advertisement.payment_enabled' => false]);

        $restaurant = $this->restaurant();
        $id = $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');

        Sanctum::actingAs($restaurant);
        $this->postJson("/api/restaurant/advertisements/{$id}/payment")
            ->assertOk()
            ->assertJsonPath('data.payment_required', false)
            ->assertJsonPath('data.checkout', null)
            ->assertJsonPath('data.advertisement.status', 'active')
            ->assertJsonPath('data.advertisement.payment_status', 'pending');

        $ad = RestaurantAdvertisement::findOrFail($id);
        $this->assertSame('pay_later', $ad->payment_method);
        $this->assertNull($ad->paid_at);
        $this->assertSame(0, RestaurantAdPayment::count());

        Sanctum::actingAs($this->customer());
        $this->getJson('/api/customer/home')->assertJsonCount(1, 'data.home_advertisements');

        // Admin records the payment later; dates do not change
        $service = app(\App\Services\Restaurant\RestaurantAdvertisementService::class);
        $service->markPaidByAdmin($ad, 1, 'CASH-001');

        $paid = $ad->fresh();
        $this->assertSame('paid', $paid->payment_status);
        $this->assertEquals($ad->start_at, $paid->start_at);
        $this->assertEquals($ad->end_at, $paid->end_at);
    }

    public function test_unpaid_pay_later_ads_hide_once_online_payment_is_switched_on(): void
    {
        config(['advertisement.payment_enabled' => false]);

        $restaurant = $this->restaurant();
        $id = $this->createAd($restaurant, 'FEATURED_RESTAURANT', [], false)->json('data.advertisement.id');

        Sanctum::actingAs($restaurant);
        $this->postJson("/api/restaurant/advertisements/{$id}/payment")->assertOk();

        config(['advertisement.payment_enabled' => true]);

        Sanctum::actingAs($this->customer());
        $this->getJson('/api/customer/home')->assertJsonCount(0, 'data.featured_restaurants');
    }

    /*
    |--------------------------------------------------------------------------
    | Paused / cancelled / history
    |--------------------------------------------------------------------------
    */

    public function test_paused_and_cancelled_ads_are_not_shown(): void
    {
        $restaurant = $this->restaurant();
        $ad = $this->buyAd($restaurant, 'HOME_SCREEN_SHOWCASE');
        $service = app(\App\Services\Restaurant\RestaurantAdvertisementService::class);

        Sanctum::actingAs($this->customer());
        $this->getJson('/api/customer/home')->assertJsonCount(1, 'data.home_advertisements');

        $service->pause($ad);
        $this->getJson('/api/customer/home')->assertJsonCount(0, 'data.home_advertisements');

        $service->resume($ad->refresh());
        $this->getJson('/api/customer/home')->assertJsonCount(1, 'data.home_advertisements');

        $service->cancelByAdmin($ad->refresh(), 'admin', 1, 'Policy');
        $this->getJson('/api/customer/home')->assertJsonCount(0, 'data.home_advertisements');
    }

    public function test_suspended_restaurant_ads_are_not_shown(): void
    {
        $restaurant = $this->restaurant();
        $this->buyAd($restaurant, 'HOME_SCREEN_SHOWCASE');

        $restaurant->update(['status' => 'suspended']);

        Sanctum::actingAs($this->customer());
        $this->getJson('/api/customer/home')->assertJsonCount(0, 'data.home_advertisements');
    }

    public function test_restaurant_cancel_rules(): void
    {
        $restaurant = $this->restaurant();

        // Unpaid: cancelled immediately
        $draftId = $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');
        Sanctum::actingAs($restaurant);
        $this->postJson("/api/restaurant/advertisements/{$draftId}/cancel")->assertOk()
            ->assertJsonPath('data.advertisement.status', 'cancelled');
        $this->postJson("/api/restaurant/advertisements/{$draftId}/payment")->assertStatus(422);

        // Paid: only a request, stays active until admin decides
        $paid = $this->buyAd($restaurant, 'CHECKOUT_PAGE_OFFER');
        Sanctum::actingAs($restaurant);
        $this->postJson("/api/restaurant/advertisements/{$paid->id}/cancel", ['reason' => 'Changed plan'])
            ->assertOk()
            ->assertJsonPath('data.advertisement.status', 'active');
        $this->assertNotNull($paid->refresh()->cancellation_requested_at);
        $this->postJson("/api/restaurant/advertisements/{$paid->id}/cancel")->assertStatus(409);
    }

    public function test_history_lists_and_filters_by_status(): void
    {
        $restaurant = $this->restaurant();
        $item = $this->foodItem($restaurant);

        $this->buyAd($restaurant, 'FOOD_ITEM_PROMOTION', ['food_item_id' => $item->id]);
        $this->createAd($restaurant, 'HOME_SCREEN_SHOWCASE');

        Sanctum::actingAs($restaurant);
        $response = $this->getJson('/api/restaurant/advertisements')->assertOk();

        $response->assertJsonPath('data.counts.all', 2)
            ->assertJsonPath('data.counts.active', 1)
            ->assertJsonPath('data.counts.draft', 1);

        $active = $this->getJson('/api/restaurant/advertisements?status=active')->assertOk()->json('data.advertisements');
        $this->assertCount(1, $active);
        $this->assertSame('Food Item Promotion', $active[0]['package']['name']);
        $this->assertSame('Paneer Pizza', $active[0]['target']['name']);
        $this->assertSame(7, $active[0]['duration_days']);
        $this->assertSame('paid', $active[0]['payment_status']);
        $this->assertNotNull($active[0]['start_at']);
        $this->assertNotNull($active[0]['end_at']);
        $this->assertNotNull($active[0]['image_url']);
    }
}
