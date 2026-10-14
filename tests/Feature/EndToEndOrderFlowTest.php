<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerCart;
use App\Models\DeliveryOrder;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Models\RestaurantCategory;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantOrder;
use App\Models\RestaurantWorkingDay;
use App\Models\RestaurantWorkingHour;
use App\Models\Rider;
use App\Models\RiderShift;
use App\Models\RiderWalletTransaction;
use App\Models\RiderWeeklySettlement;
use App\Services\Payment\PayUOrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End to End Order Flow Test
 *
 * Runs against MySQL test database:
 * DB_CONNECTION=mysql DB_DATABASE=veggo_testing php artisan test --filter=EndToEndOrderFlowTest
 */
class EndToEndOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneCounter = 9800000000;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'restaurant.delivery_charge' => 25,
            'services.payu.key' => 'TEST_KEY',
            'services.payu.salt' => 'TEST_SALT',
            'services.payu.base_url' => 'https://test.payu.in/_payment',
        ]);

        \App\Models\RiderEarningSlab::create([
            'min_distance' => 0,
            'max_distance' => 50,
            'base_amount' => 40.00,
            'status' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers & Fixtures
    |--------------------------------------------------------------------------
    */

    private function createRestaurant(): Restaurant
    {
        $phone = (string) ($this->phoneCounter++);

        $restaurant = Restaurant::create([
            'mobile' => $phone,
            'owner_name' => 'Chef Suresh',
            'restaurant_name' => 'Veggie Delight Restaurant',
            'email' => 'veggie' . $phone . '@example.com',
            'status' => 'approved',
            'is_online' => true,
            'wallet_balance' => 0,
        ]);

        // Address: Ahmedabad center (23.0225, 72.5714)
        RestaurantAddress::create([
            'restaurant_id' => $restaurant->id,
            'full_address' => '101 Veggie Street, CG Road, Ahmedabad',
            'shop_plot_number' => '101',
            'floor' => '1',
            'building_name' => 'Green Square',
            'pincode' => '380009',
            'complete_address' => '101 Veggie Street, CG Road, Ahmedabad, 380009',
            'latitude' => 23.0225000,
            'longitude' => 72.5714000,
        ]);

        // Working day: today
        $today = now()->format('l');
        RestaurantWorkingDay::create([
            'restaurant_id' => $restaurant->id,
            'day' => $today,
        ]);

        // Working hours: 00:00 to 23:59 (all day)
        RestaurantWorkingHour::create([
            'restaurant_id' => $restaurant->id,
            'day' => $today,
            'slot' => 1,
            'open_time' => '00:00:00',
            'close_time' => '23:59:59',
        ]);

        return $restaurant;
    }

    private function createMenuItem(Restaurant $restaurant, array $attributes = []): RestaurantMenuItem
    {
        $category = RestaurantCategory::firstOrCreate([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main Course',
        ], [
            'is_active' => true,
        ]);

        return RestaurantMenuItem::create(array_merge([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Paneer Butter Masala',
            'price' => 250,
            'discount_price' => null,
            'is_available' => true,
            'is_active' => true,
        ], $attributes));
    }

    private function createCustomer(): Customer
    {
        $phone = (string) ($this->phoneCounter++);

        return Customer::create([
            'phone' => $phone,
            'name' => 'Rahul Sharma',
            'email' => 'rahul' . $phone . '@example.com',
            'status' => 'active',
        ]);
    }

    private function createCustomerAddress(Customer $customer): CustomerAddress
    {
        // 2 km from restaurant (23.0300, 72.5800)
        return CustomerAddress::create([
            'customer_id' => $customer->id,
            'label' => 'Home',
            'receiver_name' => 'Rahul Sharma',
            'receiver_phone' => $customer->phone,
            'address' => 'Flat 402, Shivalik Residency, Navrangpura, Ahmedabad',
            'pincode' => '380009',
            'latitude' => 23.0300000,
            'longitude' => 72.5800000,
            'is_default' => true,
        ]);
    }

    private function createRider(): Rider
    {
        $phone = (string) ($this->phoneCounter++);

        // Create 24h shift if not exists
        RiderShift::firstOrCreate([
            'shift_name' => 'All Day Shift',
        ], [
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'status' => true,
        ]);

        return Rider::create([
            'name' => 'Amit Rider',
            'first_name' => 'Amit',
            'last_name' => 'Kumar',
            'phone' => $phone,
            'email' => 'amit' . $phone . '@example.com',
            'status' => 'approved',
            'onboarding_completed' => true,
            'is_online' => true,
            'wallet_balance' => 0,
            'pending_cod' => 0,
            'latitude' => 23.0230000,
            'longitude' => 72.5720000,
            'last_location_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Tests
    |--------------------------------------------------------------------------
    */

    public function test_complete_cod_order_flow_from_add_to_cart_to_delivery_by_rider(): void
    {
        // 1. Setup entities
        $restaurant = $this->createRestaurant();
        $menuItem1 = $this->createMenuItem($restaurant, ['name' => 'Paneer Butter Masala', 'price' => 250]);
        $menuItem2 = $this->createMenuItem($restaurant, ['name' => 'Butter Naan', 'price' => 50]);

        $customer = $this->createCustomer();
        $customerAddress = $this->createCustomerAddress($customer);

        $rider = $this->createRider();

        // ---------------------------------------------------------------------
        // STEP 1: Customer Adds Items to Cart
        // ---------------------------------------------------------------------
        Sanctum::actingAs($customer);

        $addToCartResponse = $this->postJson('/api/customer/cart/items', [
            'restaurant_id' => $restaurant->id,
            'menu_item_id' => $menuItem1->id,
            'quantity' => 2,
            'special_instruction' => 'Less spicy please',
        ]);

        $addToCartResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cart.restaurant.id', $restaurant->id);

        // Add second item
        $this->postJson('/api/customer/cart/items', [
            'restaurant_id' => $restaurant->id,
            'menu_item_id' => $menuItem2->id,
            'quantity' => 3,
        ])->assertOk();

        // ---------------------------------------------------------------------
        // STEP 2: Customer Views Cart
        // ---------------------------------------------------------------------
        $cartResponse = $this->getJson('/api/customer/cart');
        $cartResponse->assertOk()
            ->assertJsonPath('success', true);

        $cartData = $cartResponse->json('data.cart');
        $expectedSubtotal = (250 * 2) + (50 * 3); // 500 + 150 = 650
        $this->assertEquals($expectedSubtotal, $cartData['subtotal']);
        $this->assertGreaterThan(0, $cartData['total_amount']);

        // ---------------------------------------------------------------------
        // STEP 3: Customer Places COD Order
        // ---------------------------------------------------------------------
        $placeOrderResponse = $this->postJson('/api/customer/orders', [
            'delivery_address_id' => $customerAddress->id,
            'payment_method' => 'cash',
        ]);

        $placeOrderResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order.order_status', 'pending')
            ->assertJsonPath('data.order.payment_method', 'cash')
            ->assertJsonPath('data.order.payment_status', 'pending');

        $orderId = $placeOrderResponse->json('data.order.id');
        $orderNumber = $placeOrderResponse->json('data.order.order_number');
        $this->assertNotEmpty($orderNumber);
        $this->assertStringStartsWith('VG-', $orderNumber);

        // Verify cart is cleared
        $this->assertDatabaseMissing('customer_carts', ['customer_id' => $customer->id]);

        // Verify Restaurant Notification
        $this->assertDatabaseHas('restaurant_notifications', [
            'restaurant_id' => $restaurant->id,
            'reference_id' => $orderId,
        ]);

        // ---------------------------------------------------------------------
        // STEP 4: Restaurant Views Pending Orders and Accepts
        // ---------------------------------------------------------------------
        Sanctum::actingAs($restaurant);

        $pendingResponse = $this->getJson('/api/restaurant/orders/pending');
        $pendingResponse->assertOk()
            ->assertJsonPath('success', true);

        // Accept order
        $acceptResponse = $this->postJson("/api/restaurant/orders/{$orderId}/accept");
        $acceptResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_status', 'accepted');

        // Verify DeliveryOrder is created for riders
        $deliveryOrder = DeliveryOrder::where('order_number', $orderNumber)->first();
        $this->assertNotNull($deliveryOrder);
        $this->assertEquals('available', $deliveryOrder->status);
        $this->assertEquals($restaurant->address->latitude, $deliveryOrder->pickup_latitude);
        $this->assertEquals($customerAddress->latitude, $deliveryOrder->delivery_latitude);

        // ---------------------------------------------------------------------
        // STEP 5: Restaurant Updates Status to Preparing & Ready for Pickup
        // ---------------------------------------------------------------------
        // Mark preparing
        $this->postJson("/api/restaurant/orders/{$orderId}/status", [
            'status' => 'preparing',
        ])->assertOk()->assertJsonPath('data.order_status', 'preparing');

        // Mark ready for pickup
        $readyResponse = $this->postJson("/api/restaurant/orders/{$orderId}/status", [
            'status' => 'ready_for_pickup',
        ]);

        $readyResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_status', 'ready_for_pickup');

        $pickupOtp = $readyResponse->json('data.pickup_otp');
        $this->assertNotNull($pickupOtp);
        $this->assertEquals(4, strlen($pickupOtp));

        // ---------------------------------------------------------------------
        // STEP 6: Rider Views Available Orders & Accepts Order
        // ---------------------------------------------------------------------
        Sanctum::actingAs($rider);

        $availableOrdersResponse = $this->getJson('/api/rider/available-orders');
        $availableOrdersResponse->assertOk()
            ->assertJsonPath('success', true);

        // Rider accepts the order
        $riderAcceptResponse = $this->postJson("/api/rider/orders/{$deliveryOrder->id}/accept");
        $riderAcceptResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order.status', 'accepted')
            ->assertJsonPath('data.order.rider_id', $rider->id);

        // Verify pickup OTP is synced on delivery order
        $deliveryOrder->refresh();
        $this->assertEquals($pickupOtp, $deliveryOrder->pickup_otp);

        // ---------------------------------------------------------------------
        // STEP 7: Rider Arrives at Restaurant
        // ---------------------------------------------------------------------
        $arrivedResponse = $this->postJson("/api/rider/orders/{$deliveryOrder->id}/arrived");
        $arrivedResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order.status', 'arrived');

        // ---------------------------------------------------------------------
        // STEP 8: Rider Verifies Pickup OTP
        // ---------------------------------------------------------------------
        // Rider checks pickup verification screen
        $this->getJson("/api/rider/orders/{$deliveryOrder->id}/pickup-verification")
            ->assertOk()
            ->assertJsonPath('data.otp_required', true);

        // Wrong OTP fails
        $this->postJson("/api/rider/orders/{$deliveryOrder->id}/verify-pickup-otp", [
            'otp' => '0000',
        ])->assertStatus(400)
            ->assertJsonPath('success', false);

        // Correct OTP succeeds
        $verifyPickupResponse = $this->postJson("/api/rider/orders/{$deliveryOrder->id}/verify-pickup-otp", [
            'otp' => $pickupOtp,
        ]);

        $verifyPickupResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status_after_save', 'picked_up');

        // Delivery order is picked up
        $deliveryOrder->refresh();
        $this->assertEquals('picked_up', $deliveryOrder->status);
        $this->assertNotNull($deliveryOrder->picked_up_at);

        // Customer's restaurant order now has delivery OTP generated
        $restaurantOrder = RestaurantOrder::find($orderId);
        $this->assertEquals('picked_up', $restaurantOrder->order_status);
        $deliveryOtp = $restaurantOrder->delivery_otp;
        $this->assertNotNull($deliveryOtp);
        $this->assertEquals(4, strlen($deliveryOtp));

        // ---------------------------------------------------------------------
        // STEP 9: Rider Arrives at Customer & Verifies Delivery OTP
        // ---------------------------------------------------------------------
        // Rider checks delivery verification
        $this->getJson("/api/rider/orders/{$deliveryOrder->id}/delivery-verification")
            ->assertOk()
            ->assertJsonPath('data.otp_required', true);

        // Wrong delivery OTP fails
        $this->postJson("/api/rider/orders/{$deliveryOrder->id}/verify-delivery-otp", [
            'otp' => '9999',
        ])->assertStatus(400);

        // Correct delivery OTP succeeds
        $verifyDeliveryResponse = $this->postJson("/api/rider/orders/{$deliveryOrder->id}/verify-delivery-otp", [
            'otp' => $deliveryOtp,
        ]);

        $verifyDeliveryResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.otp_verified', true);

        // ---------------------------------------------------------------------
        // STEP 10: Rider Marks Order as Delivered (Collects Cash)
        // ---------------------------------------------------------------------
        $deliverResponse = $this->postJson("/api/rider/orders/{$deliveryOrder->id}/delivered", [
            'payment_method' => 'cash',
        ]);

        $deliverResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'delivered');

        // ---------------------------------------------------------------------
        // STEP 11: Verify Database State, Earnings, and Wallets
        // ---------------------------------------------------------------------
        $deliveryOrder->refresh();
        $this->assertEquals('delivered', $deliveryOrder->status);
        $this->assertNotNull($deliveryOrder->delivered_at);

        $restaurantOrder->refresh();
        $this->assertEquals('delivered', $restaurantOrder->order_status);
        $this->assertNotNull($restaurantOrder->delivered_at);

        // Rider earnings & pending COD
        $rider->refresh();
        $this->assertGreaterThan(0, $rider->wallet_balance);
        $this->assertGreaterThan(0, $rider->pending_cod); // COD collected

        // Restaurant wallet credited
        $restaurant->refresh();
        $this->assertGreaterThan(0, $restaurant->wallet_balance);

        // Rider & Customer notifications created
        $this->assertDatabaseHas('rider_notifications', [
            'rider_id' => $rider->id,
            'type' => 'order',
        ]);

        $this->assertDatabaseHas('customer_notifications', [
            'customer_id' => $customer->id,
            'order_id' => $orderId,
        ]);

        // ---------------------------------------------------------------------
        // STEP 12: Customer Leaves Review & Restaurant Replies
        // ---------------------------------------------------------------------
        Sanctum::actingAs($customer);

        $reviewResponse = $this->postJson("/api/customer/orders/{$orderId}/review", [
            'rating' => 5,
            'review' => 'Food was hot, fresh and delicious! Rider was very quick.',
        ]);

        $reviewResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.rating', 5);

        // Restaurant replies
        Sanctum::actingAs($restaurant);
        $reviewId = $reviewResponse->json('data.id');

        $replyResponse = $this->postJson('/api/restaurant/ratings/reply', [
            'review_id' => $reviewId,
            'reply' => 'Thank you Rahul! We are delighted to serve you.',
        ]);

        $replyResponse->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_order_creation_rejects_closed_restaurant(): void
    {
        $restaurant = $this->createRestaurant();
        $menuItem = $this->createMenuItem($restaurant);
        $customer = $this->createCustomer();
        $address = $this->createCustomerAddress($customer);

        // Set restaurant offline
        $restaurant->update(['is_online' => false]);

        Sanctum::actingAs($customer);
        $this->postJson('/api/customer/cart/items', [
            'restaurant_id' => $restaurant->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
        ])->assertOk();

        $response = $this->postJson('/api/customer/orders', [
            'delivery_address_id' => $address->id,
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This restaurant is currently offline and not accepting orders.');
    }

    public function test_order_creation_rejects_address_exceeding_15km_radius(): void
    {
        $restaurant = $this->createRestaurant();
        $menuItem = $this->createMenuItem($restaurant);
        $customer = $this->createCustomer();

        // Customer far away (Mumbai coordinates ~ 500 km away)
        $farAddress = CustomerAddress::create([
            'customer_id' => $customer->id,
            'label' => 'Far Away',
            'receiver_name' => 'Rahul Sharma',
            'receiver_phone' => $customer->phone,
            'address' => 'Marine Drive, Mumbai',
            'pincode' => '400020',
            'latitude' => 18.9438000,
            'longitude' => 72.8231000,
            'is_default' => true,
        ]);

        Sanctum::actingAs($customer);
        $this->postJson('/api/customer/cart/items', [
            'restaurant_id' => $restaurant->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
        ])->assertOk();

        $response = $this->postJson('/api/customer/orders', [
            'delivery_address_id' => $farAddress->id,
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('outside this restaurant\'s maximum delivery radius', $response->json('message'));
    }

    public function test_rider_cannot_accept_order_if_pending_cod_limit_reached(): void
    {
        $restaurant = $this->createRestaurant();
        $menuItem = $this->createMenuItem($restaurant);
        $customer = $this->createCustomer();
        $address = $this->createCustomerAddress($customer);
        $rider = $this->createRider();

        // Put rider at maximum ₹2500 pending COD
        $rider->update(['pending_cod' => 2500]);

        Sanctum::actingAs($customer);
        $this->postJson('/api/customer/cart/items', [
            'restaurant_id' => $restaurant->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
        ]);
        $order = $this->postJson('/api/customer/orders', [
            'delivery_address_id' => $address->id,
            'payment_method' => 'cash',
        ])->json('data.order');

        Sanctum::actingAs($restaurant);
        $this->postJson("/api/restaurant/orders/{$order['id']}/accept");

        $deliveryOrder = DeliveryOrder::where('order_number', $order['order_number'])->first();

        // Rider attempts to accept order
        Sanctum::actingAs($rider);
        $response = $this->postJson("/api/rider/orders/{$deliveryOrder->id}/accept");

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('cod_limit', 2500);

        $this->assertStringContainsString('limit', $response->json('message'));
    }

    public function test_online_order_payu_initiate_and_verification_flow(): void
    {
        $restaurant = $this->createRestaurant();
        $menuItem = $this->createMenuItem($restaurant);
        $customer = $this->createCustomer();
        $address = $this->createCustomerAddress($customer);

        Sanctum::actingAs($customer);
        $this->postJson('/api/customer/cart/items', [
            'restaurant_id' => $restaurant->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
        ]);

        // Place online order
        $orderResponse = $this->postJson('/api/customer/orders', [
            'delivery_address_id' => $address->id,
            'payment_method' => 'online',
        ]);

        $orderResponse->assertCreated()
            ->assertJsonPath('data.order.payment_method', 'online')
            ->assertJsonPath('data.order.payment_status', 'pending');

        $orderId = $orderResponse->json('data.order.id');

        // Customer initiates PayU payment
        $initiateResponse = $this->postJson("/api/customer/orders/{$orderId}/payu/initiate");
        $initiateResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.params.key', 'TEST_KEY');

        $txnid = $initiateResponse->json('data.params.txnid');
        $this->assertNotEmpty($txnid);

        // Verify payment record in DB
        $paymentRecord = \App\Models\RestaurantOrderPayment::where('order_id', $orderId)->first();
        $this->assertEquals('PAYU', $paymentRecord->payment_gateway);
        $this->assertEquals($txnid, $paymentRecord->transaction_id);
    }
}
