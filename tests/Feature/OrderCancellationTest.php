<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\DeliveryOrder;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\RestaurantNotification;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderPayment;
use App\Models\Rider;
use App\Models\RiderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Order cancellation: customer cancel, restaurant reject, super admin
 * cancel and unpaid auto-expiry all go through OrderCancellationService.
 *
 * Runs against MySQL test database:
 * DB_CONNECTION=mysql DB_DATABASE=veggo_testing php artisan test --filter=OrderCancellationTest
 */
class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 9700000000;

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function createRestaurant(): Restaurant
    {
        $phone = (string) ($this->counter++);

        return Restaurant::create([
            'mobile' => $phone,
            'owner_name' => 'Chef Suresh',
            'restaurant_name' => 'Veggie Delight',
            'email' => 'veggie' . $phone . '@example.com',
            'status' => 'approved',
            'is_online' => true,
            'wallet_balance' => 0,
        ]);
    }

    private function createCustomer(): Customer
    {
        $phone = (string) ($this->counter++);

        return Customer::create([
            'phone' => $phone,
            'name' => 'Rahul Sharma',
            'email' => 'rahul' . $phone . '@example.com',
            'status' => 'active',
        ]);
    }

    private function createRider(): Rider
    {
        $phone = (string) ($this->counter++);

        return Rider::create([
            'name' => 'Amit Rider',
            'phone' => $phone,
            'email' => 'amit' . $phone . '@example.com',
            'status' => 'approved',
            'is_online' => true,
            'wallet_balance' => 0,
            'pending_cod' => 0,
        ]);
    }

    private function superAdmin(): Admin
    {
        return Admin::create([
            'name' => 'Owner',
            'email' => 'owner' . $this->counter++ . '@veggo.test',
            'password' => 'secret123',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    private function createOrder(Restaurant $restaurant, Customer $customer, array $attributes = []): RestaurantOrder
    {
        $order = RestaurantOrder::forceCreate(array_merge([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'order_number' => 'VG' . $this->counter++,
            'subtotal' => 250,
            'total_amount' => 275,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'earning_status' => 'pending',
            'placed_at' => now(),
        ], $attributes));

        RestaurantOrderPayment::create([
            'order_id' => $order->id,
            'payment_method' => $order->payment_method === 'cash' ? 'COD' : 'ONLINE',
            'payment_status' => strtoupper($order->payment_status),
            'order_amount' => 250,
            'total_amount' => 275,
        ]);

        return $order;
    }

    private function createDeliveryOrder(RestaurantOrder $order, ?Rider $rider, string $status): DeliveryOrder
    {
        return DeliveryOrder::create([
            'order_number' => $order->order_number,
            'restaurant_name' => 'Veggie Delight',
            'pickup_latitude' => 23.0225,
            'pickup_longitude' => 72.5714,
            'delivery_latitude' => 23.03,
            'delivery_longitude' => 72.58,
            'rider_id' => $rider?->id,
            'status' => $status,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Customer
    |--------------------------------------------------------------------------
    */

    public function test_customer_cancels_pending_order(): void
    {
        $restaurant = $this->createRestaurant();
        $customer = $this->createCustomer();
        $order = $this->createOrder($restaurant, $customer);

        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['reason' => 'Ordered by mistake'])
            ->assertOk()
            ->assertJsonPath('data.order_status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Ordered by mistake')
            ->assertJsonPath('data.cancelled_by', 'customer');

        $order->refresh();
        $this->assertSame('cancelled', $order->order_status);
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame('reversed', $order->earning_status);

        $this->assertDatabaseHas(RestaurantOrderPayment::class, ['order_id' => $order->id, 'payment_status' => 'FAILED']);
        $this->assertDatabaseHas(OrderStatusHistory::class, [
            'order_id' => $order->id,
            'status' => 'cancelled',
            'changed_by' => 'customer',
        ]);
        $this->assertDatabaseHas(RestaurantNotification::class, [
            'restaurant_id' => $restaurant->id,
            'reference_id' => $order->id,
        ]);
    }

    public function test_customer_cannot_cancel_after_restaurant_accepts(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($this->createRestaurant(), $customer, ['order_status' => 'accepted']);

        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Order has already been accepted and cannot be cancelled directly. Please contact support.');

        $this->assertSame('accepted', $order->fresh()->order_status);
    }

    public function test_customer_cannot_cancel_twice(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($this->createRestaurant(), $customer);

        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel")->assertOk();

        $this->postJson("/api/customer/orders/{$order->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order is already cancelled.');

        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->where('status', 'cancelled')->count());
    }

    public function test_customer_order_apis_show_who_cancelled(): void
    {
        $restaurant = $this->createRestaurant();
        $customer = $this->createCustomer();
        $order = $this->createOrder($restaurant, $customer);

        Sanctum::actingAs($restaurant);
        $this->postJson("/api/restaurant/orders/{$order->id}/reject", ['reason' => 'Out of stock'])->assertOk();

        Sanctum::actingAs($customer);

        $this->getJson("/api/customer/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.order.cancelled_by', 'restaurant')
            ->assertJsonPath('data.order.cancel_reason', 'Out of stock');

        $this->getJson('/api/customer/orders')
            ->assertOk()
            ->assertJsonPath('data.orders.0.cancelled_by', 'restaurant');
    }

    public function test_migration_backfills_cancelled_by_from_history(): void
    {
        $restaurant = $this->createRestaurant();
        $customer = $this->createCustomer();

        // Old customer cancel, saved as "system" before the customer value existed
        $byCustomer = $this->createOrder($restaurant, $customer, ['order_status' => 'cancelled']);
        OrderStatusHistory::create([
            'order_id' => $byCustomer->id,
            'status' => 'cancelled',
            'changed_by' => 'system',
            'remarks' => 'Order cancelled by customer. Reason: Changed my mind',
        ]);
        // A later payment note must not change the answer
        OrderStatusHistory::create([
            'order_id' => $byCustomer->id,
            'status' => 'cancelled',
            'changed_by' => 'system',
            'remarks' => 'Payment received after cancellation (PayU txn X) - refund needed.',
        ]);

        $byRestaurant = $this->createOrder($restaurant, $customer, ['order_status' => 'cancelled']);
        OrderStatusHistory::create([
            'order_id' => $byRestaurant->id,
            'status' => 'cancelled',
            'changed_by' => 'restaurant',
            'remarks' => 'Out of stock',
        ]);

        $expired = $this->createOrder($restaurant, $customer, ['order_status' => 'cancelled']);
        OrderStatusHistory::create([
            'order_id' => $expired->id,
            'status' => 'cancelled',
            'changed_by' => 'system',
            'remarks' => 'Auto-cancelled: online payment not received within 15 minutes.',
        ]);

        $migration = require database_path('migrations/2026_10_10_100100_add_cancelled_by_to_restaurant_orders_table.php');
        (new \ReflectionMethod($migration, 'backfill'))->invoke($migration);

        $this->assertSame('customer', $byCustomer->fresh()->cancelled_by);
        $this->assertSame('restaurant', $byRestaurant->fresh()->cancelled_by);
        $this->assertSame('system', $expired->fresh()->cancelled_by);
    }

    public function test_cancelling_paid_order_keeps_payment_paid(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($this->createRestaurant(), $customer, [
            'payment_method' => 'online',
            'payment_status' => 'paid',
        ]);

        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel")->assertOk();

        // Left for a manual refund
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseHas(RestaurantOrderPayment::class, ['order_id' => $order->id, 'payment_status' => 'PAID']);
    }

    /*
    |--------------------------------------------------------------------------
    | Restaurant
    |--------------------------------------------------------------------------
    */

    public function test_restaurant_reject_fails_payment_reverses_earning_and_notifies_customer(): void
    {
        $restaurant = $this->createRestaurant();
        $customer = $this->createCustomer();
        $order = $this->createOrder($restaurant, $customer);

        Sanctum::actingAs($restaurant);

        $this->postJson("/api/restaurant/orders/{$order->id}/reject", ['reason' => 'Out of stock'])
            ->assertOk()
            ->assertJsonPath('data.order_status', 'cancelled');

        $order->refresh();
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame('reversed', $order->earning_status);
        $this->assertSame('Out of stock', $order->cancel_reason);
        $this->assertSame('restaurant', $order->cancelled_by);

        $this->assertDatabaseHas(OrderStatusHistory::class, [
            'order_id' => $order->id,
            'status' => 'cancelled',
            'changed_by' => 'restaurant',
        ]);
        $this->assertDatabaseHas(CustomerNotification::class, [
            'customer_id' => $customer->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_restaurant_cannot_reject_accepted_order(): void
    {
        $restaurant = $this->createRestaurant();
        $order = $this->createOrder($restaurant, $this->createCustomer(), ['order_status' => 'accepted']);

        Sanctum::actingAs($restaurant);

        $this->postJson("/api/restaurant/orders/{$order->id}/reject", ['reason' => 'Busy'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only pending orders can be rejected.');

        $this->assertSame('accepted', $order->fresh()->order_status);
    }

    public function test_restaurant_cannot_accept_order_cancelled_by_customer(): void
    {
        $restaurant = $this->createRestaurant();
        $customer = $this->createCustomer();
        $order = $this->createOrder($restaurant, $customer);

        Sanctum::actingAs($customer);
        $this->postJson("/api/customer/orders/{$order->id}/cancel")->assertOk();

        Sanctum::actingAs($restaurant);
        $this->postJson("/api/restaurant/orders/{$order->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order has been cancelled by the customer.');

        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertDatabaseMissing(DeliveryOrder::class, ['order_number' => $order->order_number]);
    }

    /*
    |--------------------------------------------------------------------------
    | Rider accept / pickup
    |--------------------------------------------------------------------------
    */

    public function test_second_rider_cannot_take_an_order_already_accepted(): void
    {
        $order = $this->createOrder($this->createRestaurant(), $this->createCustomer(), ['order_status' => 'accepted']);
        $deliveryOrder = $this->createDeliveryOrder($order, null, 'available');

        $first = $this->createRider();
        $second = $this->createRider();

        Sanctum::actingAs($first);
        $this->postJson("/api/rider/orders/{$deliveryOrder->id}/accept")->assertOk();

        Sanctum::actingAs($second);
        $this->postJson("/api/rider/orders/{$deliveryOrder->id}/accept")
            ->assertStatus(400)
            ->assertJsonPath('message', 'Order is no longer available.');

        $this->assertSame($first->id, $deliveryOrder->fresh()->rider_id);
        $this->assertSame($first->id, $order->fresh()->rider_id);
    }

    public function test_locked_recheck_stops_accept_that_lost_the_race(): void
    {
        $order = $this->createOrder($this->createRestaurant(), $this->createCustomer(), ['order_status' => 'accepted']);
        $deliveryOrder = $this->createDeliveryOrder($order, null, 'available');

        // Both riders loaded the order while it was still available
        $staleForSecond = DeliveryOrder::find($deliveryOrder->id);
        $staleForCancel = DeliveryOrder::find($deliveryOrder->id);

        $first = $this->createRider();
        $service = app(\App\Services\Rider\RiderOrderService::class);

        $this->assertSame(200, $service->acceptOrder($first, DeliveryOrder::find($deliveryOrder->id))->getStatusCode());

        $response = $service->acceptOrder($this->createRider(), $staleForSecond);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame($first->id, $deliveryOrder->fresh()->rider_id);

        // Order cancelled by admin after a rider loaded it
        $other = $this->createOrder($this->createRestaurant(), $this->createCustomer(), ['order_status' => 'accepted']);
        $otherDelivery = $this->createDeliveryOrder($other, null, 'available');
        $stale = DeliveryOrder::find($otherDelivery->id);

        app(\App\Services\Order\OrderCancellationService::class)->cancel(
            $other,
            'admin',
            'Restaurant closed',
            \App\Services\Order\OrderCancellationService::ADMIN_CANCELLABLE
        );

        $response = $service->acceptOrder($this->createRider(), $stale);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('This order has been cancelled.', $response->getData()->message);
        $this->assertNull($otherDelivery->fresh()->rider_id);
        $this->assertSame('cancelled', $otherDelivery->fresh()->status);
    }

    public function test_locked_recheck_stops_pickup_after_admin_cancel(): void
    {
        $customer = $this->createCustomer();
        $rider = $this->createRider();
        $order = $this->createOrder($this->createRestaurant(), $customer, [
            'order_status' => 'ready_for_pickup',
            'rider_id' => $rider->id,
        ]);
        $deliveryOrder = $this->createDeliveryOrder($order, $rider, 'arrived');
        $deliveryOrder->forceFill(['pickup_otp' => '1234', 'restaurant_status' => 'ready_for_pickup'])->save();

        // Rider's request loaded the order before the admin cancelled it
        $stale = DeliveryOrder::find($deliveryOrder->id);

        app(\App\Services\Order\OrderCancellationService::class)->cancel(
            $order,
            'admin',
            'Customer unreachable',
            \App\Services\Order\OrderCancellationService::ADMIN_CANCELLABLE
        );

        $response = app(\App\Services\Rider\RiderOrderService::class)->verifyPickupOtp(
            $rider,
            $stale,
            new \Illuminate\Http\Request(['otp' => '1234'])
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('This order has been cancelled.', $response->getData()->message);
        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertSame('cancelled', $deliveryOrder->fresh()->status);
        $this->assertNull($order->fresh()->picked_up_at);
    }

    public function test_rider_with_active_order_cannot_accept_another(): void
    {
        $restaurant = $this->createRestaurant();
        $customer = $this->createCustomer();
        $rider = $this->createRider();

        $current = $this->createOrder($restaurant, $customer, ['order_status' => 'accepted', 'rider_id' => $rider->id]);
        $this->createDeliveryOrder($current, $rider, 'accepted');

        $next = $this->createOrder($restaurant, $customer, ['order_status' => 'accepted']);
        $nextDelivery = $this->createDeliveryOrder($next, null, 'available');

        Sanctum::actingAs($rider);
        $this->postJson("/api/rider/orders/{$nextDelivery->id}/accept")
            ->assertStatus(400)
            ->assertJsonPath('message', 'Complete your current order before accepting a new order.');

        $this->assertNull($nextDelivery->fresh()->rider_id);
    }

    public function test_rider_cannot_accept_order_cancelled_by_admin(): void
    {
        $order = $this->createOrder($this->createRestaurant(), $this->createCustomer(), ['order_status' => 'accepted']);
        $deliveryOrder = $this->createDeliveryOrder($order, null, 'available');

        $this->withToken($this->superAdmin()->createToken('test', ['super-admin'])->plainTextToken)
            ->postJson('/api/super-admin/orders/cancel', ['ids' => [$order->id], 'reason' => 'Restaurant closed'])
            ->assertOk();

        Sanctum::actingAs($this->createRider());
        $this->postJson("/api/rider/orders/{$deliveryOrder->id}/accept")
            ->assertStatus(400)
            ->assertJsonPath('message', 'This order has been cancelled.');

        $this->assertNull($deliveryOrder->fresh()->rider_id);
        $this->assertSame('cancelled', $deliveryOrder->fresh()->status);
    }

    public function test_rider_cannot_verify_pickup_on_order_cancelled_by_admin(): void
    {
        $customer = $this->createCustomer();
        $rider = $this->createRider();
        $order = $this->createOrder($this->createRestaurant(), $customer, [
            'order_status' => 'ready_for_pickup',
            'rider_id' => $rider->id,
        ]);
        $deliveryOrder = $this->createDeliveryOrder($order, $rider, 'arrived');
        $deliveryOrder->forceFill(['pickup_otp' => '1234', 'restaurant_status' => 'ready_for_pickup'])->save();

        $this->withToken($this->superAdmin()->createToken('test', ['super-admin'])->plainTextToken)
            ->postJson('/api/super-admin/orders/cancel', ['ids' => [$order->id], 'reason' => 'Customer unreachable'])
            ->assertOk();

        Sanctum::actingAs($rider);
        $this->postJson("/api/rider/orders/{$deliveryOrder->id}/verify-pickup-otp", ['otp' => '1234'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'This order has been cancelled.');

        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertSame('cancelled', $deliveryOrder->fresh()->status);
        $this->assertNull($deliveryOrder->fresh()->pickup_verified_at);
        // Customer only got the cancellation, never "picked up"
        $this->assertSame(1, CustomerNotification::where('order_id', $order->id)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Payment after cancellation
    |--------------------------------------------------------------------------
    */

    public function test_late_payu_success_on_cancelled_order_is_recorded_but_order_stays_cancelled(): void
    {
        config([
            'services.payu.key' => 'TEST_KEY',
            'services.payu.salt' => 'TEST_SALT',
            'services.payu.verify_url' => 'https://test.payu.in/merchant/postservice?form=2',
        ]);

        $restaurant = $this->createRestaurant();
        $customer = $this->createCustomer();

        // State left behind by the unpaid auto-expiry
        $order = $this->createOrder($restaurant, $customer, [
            'payment_method' => 'online',
            'payment_status' => 'failed',
            'order_status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
        RestaurantOrderPayment::where('order_id', $order->id)->update([
            'payment_status' => 'FAILED',
            'payment_gateway' => 'PAYU',
            'transaction_id' => 'TXN' . $order->id,
        ]);

        Http::fake([
            'test.payu.in/*' => Http::response([
                'status' => 1,
                'transaction_details' => [
                    'TXN' . $order->id => [
                        'status' => 'success',
                        'txnid' => 'TXN' . $order->id,
                        'amt' => '275.00',
                    ],
                ],
            ]),
        ]);

        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/payu/verify")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.order_status', 'cancelled')
            ->assertJsonPath('message', 'Payment received, but this order was already cancelled. Your amount will be refunded.');

        $order->refresh();
        $this->assertSame('cancelled', $order->order_status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertDatabaseHas(RestaurantOrderPayment::class, ['order_id' => $order->id, 'payment_status' => 'PAID']);

        // No "order placed" to the restaurant; customer told about the refund
        $this->assertDatabaseMissing(RestaurantNotification::class, ['reference_id' => $order->id]);
        $this->assertDatabaseHas(CustomerNotification::class, [
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'title' => 'Refund Pending 💳',
        ]);
        $this->assertTrue(
            OrderStatusHistory::where('order_id', $order->id)
                ->where('remarks', 'like', 'Payment received after cancellation%')
                ->exists()
        );
    }

    public function test_customer_cannot_start_payment_on_cancelled_order(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($this->createRestaurant(), $customer, [
            'payment_method' => 'online',
            'payment_status' => 'failed',
            'order_status' => 'cancelled',
        ]);

        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/payu/initiate")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order has been cancelled. Please place a new order.');
    }

    /*
    |--------------------------------------------------------------------------
    | Super admin
    |--------------------------------------------------------------------------
    */

    public function test_admin_cancels_accepted_order_and_notifies_everyone(): void
    {
        $restaurant = $this->createRestaurant();
        $customer = $this->createCustomer();
        $rider = $this->createRider();
        $order = $this->createOrder($restaurant, $customer, [
            'order_status' => 'ready_for_pickup',
            'rider_id' => $rider->id,
        ]);
        $deliveryOrder = $this->createDeliveryOrder($order, $rider, 'accepted');

        $this->withToken($this->superAdmin()->createToken('test', ['super-admin'])->plainTextToken)
            ->postJson('/api/super-admin/orders/cancel', ['ids' => [$order->id], 'reason' => 'Customer called'])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.skipped', 0);

        $order->refresh();
        $this->assertSame('cancelled', $order->order_status);
        $this->assertSame('reversed', $order->earning_status);
        $this->assertSame('admin', $order->cancelled_by);
        $this->assertSame('cancelled', $deliveryOrder->fresh()->status);

        $this->assertDatabaseHas(OrderStatusHistory::class, [
            'order_id' => $order->id,
            'status' => 'cancelled',
            'changed_by' => 'admin',
        ]);
        $this->assertDatabaseHas(CustomerNotification::class, ['customer_id' => $customer->id, 'order_id' => $order->id]);
        $this->assertDatabaseHas(RestaurantNotification::class, ['restaurant_id' => $restaurant->id, 'reference_id' => $order->id]);
        $this->assertDatabaseHas(RiderNotification::class, ['rider_id' => $rider->id]);
    }

    public function test_admin_cannot_cancel_picked_up_order(): void
    {
        $rider = $this->createRider();
        $order = $this->createOrder($this->createRestaurant(), $this->createCustomer(), [
            'order_status' => 'picked_up',
            'rider_id' => $rider->id,
        ]);
        $deliveryOrder = $this->createDeliveryOrder($order, $rider, 'picked_up');

        $this->withToken($this->superAdmin()->createToken('test', ['super-admin'])->plainTextToken)
            ->postJson('/api/super-admin/orders/cancel', ['ids' => [$order->id], 'reason' => 'Late'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Order has already been picked up by the rider and cannot be cancelled.');

        $this->assertSame('picked_up', $order->fresh()->order_status);
        $this->assertSame('picked_up', $deliveryOrder->fresh()->status);
    }

    public function test_cancelled_order_cannot_be_brought_back(): void
    {
        $order = $this->createOrder($this->createRestaurant(), $this->createCustomer(), [
            'order_status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $token = $this->superAdmin()->createToken('test', ['super-admin'])->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/super-admin/orders/{$order->id}/status", ['status' => 'CONFIRMED'])
            ->assertStatus(422);

        // Reactivate was removed: a cancelled order is final
        $this->withToken($token)
            ->postJson('/api/super-admin/orders/reactivate', ['ids' => [$order->id]])
            ->assertNotFound();

        $this->assertSame('cancelled', $order->fresh()->order_status);
    }

    public function test_admin_bulk_cancel_skips_picked_up_orders(): void
    {
        $restaurant = $this->createRestaurant();
        $customer = $this->createCustomer();
        $pending = $this->createOrder($restaurant, $customer);
        $pickedUp = $this->createOrder($restaurant, $customer, ['order_status' => 'picked_up']);

        $this->withToken($this->superAdmin()->createToken('test', ['super-admin'])->plainTextToken)
            ->postJson('/api/super-admin/orders/cancel', ['ids' => [$pending->id, $pickedUp->id]])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame('cancelled', $pending->fresh()->order_status);
        $this->assertSame('picked_up', $pickedUp->fresh()->order_status);
    }

    /*
    |--------------------------------------------------------------------------
    | Unpaid auto-expiry
    |--------------------------------------------------------------------------
    */

    public function test_unpaid_online_order_is_expired_and_customer_notified(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($this->createRestaurant(), $customer, [
            'payment_method' => 'online',
            'placed_at' => now()->subMinutes(6),
        ]);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame('cancelled', $order->order_status);
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame('system', $order->cancelled_by);
        $this->assertDatabaseHas(OrderStatusHistory::class, [
            'order_id' => $order->id,
            'status' => 'cancelled',
            'changed_by' => 'system',
        ]);
        $this->assertDatabaseHas(CustomerNotification::class, ['customer_id' => $customer->id, 'order_id' => $order->id]);
    }

    public function test_recent_or_paid_online_orders_are_not_expired(): void
    {
        $restaurant = $this->createRestaurant();
        $customer = $this->createCustomer();

        $recent = $this->createOrder($restaurant, $customer, [
            'payment_method' => 'online',
            'placed_at' => now()->subMinutes(2),
        ]);

        // Order row still says pending but the payment record was confirmed
        $paid = $this->createOrder($restaurant, $customer, [
            'payment_method' => 'online',
            'placed_at' => now()->subMinutes(6),
        ]);
        RestaurantOrderPayment::where('order_id', $paid->id)->update(['payment_status' => 'PAID']);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame('pending', $recent->fresh()->order_status);
        $this->assertSame('pending', $paid->fresh()->order_status);
    }
}
