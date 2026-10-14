<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Customer;
use App\Models\CustomerCart;
use App\Models\Restaurant;
use App\Models\RestaurantAdPackage;
use App\Models\RestaurantAdPackageDuration;
use App\Models\RestaurantAdvertisement;
use App\Models\RestaurantCategory;
use App\Models\RestaurantMenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Banners page (super admin panel). Needs MySQL like RestaurantAdvertisementTest:
 *   DB_CONNECTION=mysql DB_DATABASE=veggo_testing php artisan test --filter=SuperAdminAdvertisementTest
 */
class SuperAdminAdvertisementTest extends TestCase
{
    use RefreshDatabase;

    private int $mobile = 9100000000;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // Same as the restaurant side for now: online payment off (pay later)
        config(['advertisement.payment_driver' => 'fake', 'advertisement.payment_enabled' => false]);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function superAdmin(): Admin
    {
        return Admin::create([
            'name' => 'Owner',
            'email' => 'owner' . $this->mobile++ . '@veggo.test',
            'password' => 'secret123',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    private function roleAdmin(array $actions): Admin
    {
        $role = AdminRole::create([
            'name' => 'Marketing ' . $this->mobile++,
            'permissions' => ['marketing' => $actions],
        ]);

        return Admin::create([
            'name' => 'Marketer',
            'email' => 'marketer' . $this->mobile++ . '@veggo.test',
            'password' => 'secret123',
            'role' => Admin::ROLE_ADMIN,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    private function as(Admin $admin): static
    {
        return $this->withToken($admin->createToken('test', ['super-admin'])->plainTextToken);
    }

    private function asCustomer(): static
    {
        Sanctum::actingAs(Customer::create(['phone' => (string) $this->mobile++, 'name' => 'Customer', 'status' => 'active']));

        return $this->withoutToken();
    }

    private function restaurant(array $attributes = []): Restaurant
    {
        return Restaurant::create(array_merge([
            'mobile' => (string) $this->mobile++,
            'owner_name' => 'Owner',
            'restaurant_name' => 'Kathiyawadi Village',
            'email' => 'r' . $this->mobile . '@example.com',
            'status' => 'approved',
            'is_online' => true,
        ], $attributes));
    }

    private function foodItem(Restaurant $restaurant, array $attributes = []): RestaurantMenuItem
    {
        $category = RestaurantCategory::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizza', 'is_active' => true]);

        return RestaurantMenuItem::create(array_merge([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Paneer Pizza',
            'price' => 199,
            'is_available' => true,
            'is_active' => true,
        ], $attributes));
    }

    private function package(string $code): RestaurantAdPackage
    {
        return RestaurantAdPackage::where('code', $code)->firstOrFail();
    }

    private function duration(string $code, int $days = 7): RestaurantAdPackageDuration
    {
        return $this->package($code)->durations()->where('duration_days', $days)->firstOrFail();
    }

    private function create(Admin $admin, Restaurant $restaurant, string $code, array $extra = [], bool $withImage = true)
    {
        $payload = array_merge([
            'restaurant_id' => $restaurant->id,
            'package_id' => $this->package($code)->id,
            'package_duration_id' => $this->duration($code)->id,
            'payment_mode' => 'online',
        ], $extra);

        if ($withImage) {
            $payload['image'] = UploadedFile::fake()->image('ad.jpg', 1200, 600);
        }

        return $this->as($admin)->post('/api/super-admin/advertisements', $payload, ['Accept' => 'application/json']);
    }

    /*
    |--------------------------------------------------------------------------
    | Lookups
    |--------------------------------------------------------------------------
    */

    public function test_options_list_only_approved_restaurants_and_db_prices(): void
    {
        $approved = $this->restaurant(['restaurant_name' => 'Approved']);
        $this->restaurant(['restaurant_name' => 'Suspended', 'status' => 'suspended']);

        $response = $this->as($this->superAdmin())->getJson('/api/super-admin/advertisements/options')->assertOk();

        $this->assertSame([$approved->id], collect($response->json('data.restaurants'))->pluck('id')->all());
        $this->assertCount(4, $response->json('data.packages'));
        $this->assertFalse($response->json('data.payment.online_enabled'));

        $home = collect($response->json('data.packages'))->firstWhere('code', 'HOME_SCREEN_SHOWCASE');
        $seven = collect($home['durations'])->firstWhere('duration_days', 7);
        $this->assertEquals($this->duration('HOME_SCREEN_SHOWCASE')->price, $seven['price']);
    }

    public function test_food_items_lookup_only_returns_that_restaurants_items(): void
    {
        $a = $this->restaurant();
        $b = $this->restaurant();
        $mine = $this->foodItem($a);
        $this->foodItem($b, ['name' => 'Other Pizza']);

        $items = $this->as($this->superAdmin())
            ->getJson("/api/super-admin/advertisements/restaurants/{$a->id}/food-items")
            ->assertOk()
            ->json('data.categories.*.items.*.id');

        $this->assertSame([$mine->id], $items);
    }

    /*
    |--------------------------------------------------------------------------
    | 4 packages
    |--------------------------------------------------------------------------
    */

    public function test_package_1_created_by_super_admin_goes_live_as_pay_later_and_expires(): void
    {
        $admin = $this->superAdmin();
        $restaurant = $this->restaurant();

        $response = $this->create($admin, $restaurant, 'HOME_SCREEN_SHOWCASE', [
            // Protected fields are ignored
            'amount' => 1,
            'total_amount' => 1,
            'status' => 'active',
            'payment_status' => 'paid',
            'start_at' => now()->subYear()->toDateTimeString(),
        ])->assertCreated()
            ->assertJsonPath('data.advertisement.status', 'pending')
            ->assertJsonPath('data.advertisement.payment_status', 'pending')
            ->assertJsonPath('data.advertisement.created_by.type', 'super_admin');

        $ad = RestaurantAdvertisement::findOrFail($response->json('data.advertisement.id'));
        $this->assertEquals($this->duration('HOME_SCREEN_SHOWCASE')->price, $ad->amount);
        $this->assertSame('super_admin', $ad->created_by_type);
        $this->assertSame($admin->id, $ad->created_by_id);
        $this->assertSame($restaurant->id, $ad->restaurant_id);
        $this->assertNull($ad->start_at);

        $this->asCustomer()->getJson('/api/customer/home')->assertJsonCount(0, 'data.home_advertisements');

        $this->as($admin)->postJson("/api/super-admin/advertisements/{$ad->id}/payment")
            ->assertOk()
            ->assertJsonPath('data.payment_required', false)
            ->assertJsonPath('data.advertisement.status', 'active')
            ->assertJsonPath('data.advertisement.payment_status', 'pending');

        $this->asCustomer()->getJson('/api/customer/home')
            ->assertJsonCount(1, 'data.home_advertisements')
            ->assertJsonPath('data.home_advertisements.0.id', $ad->id);

        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $this->asCustomer()->getJson('/api/customer/home')->assertJsonCount(0, 'data.home_advertisements');

        $this->artisan('advertisements:expire')->assertSuccessful();
        $this->assertSame('expired', $ad->refresh()->status);
    }

    public function test_package_2_promotes_own_food_item_and_rejects_another_restaurants(): void
    {
        $admin = $this->superAdmin();
        $a = $this->restaurant();
        $b = $this->restaurant();
        $itemA = $this->foodItem($a);
        $itemB = $this->foodItem($b);

        $this->create($admin, $a, 'FOOD_ITEM_PROMOTION', ['food_item_id' => $itemB->id])->assertForbidden();
        $this->create($admin, $a, 'FOOD_ITEM_PROMOTION')->assertUnprocessable()->assertJsonValidationErrors('food_item_id');
        $this->create($admin, $a, 'FOOD_ITEM_PROMOTION', ['food_item_id' => [$itemA->id, $itemA->id]])->assertUnprocessable();
        $this->create($admin, $a, 'FOOD_ITEM_PROMOTION', ['food_item_id' => 999999])->assertUnprocessable();
        $this->assertSame(0, RestaurantAdvertisement::count());

        $response = $this->create($admin, $a, 'FOOD_ITEM_PROMOTION', ['food_item_id' => $itemA->id, 'payment_mode' => 'manual'])
            ->assertCreated()
            ->assertJsonPath('data.advertisement.status', 'active')
            ->assertJsonPath('data.advertisement.payment_status', 'paid')
            ->assertJsonPath('data.advertisement.target.type', 'food_item')
            ->assertJsonPath('data.advertisement.target.id', $itemA->id);

        $ad = RestaurantAdvertisement::findOrFail($response->json('data.advertisement.id'));
        $this->assertSame('food_item', $ad->target_type);
        $this->assertSame($itemA->id, $ad->target_id);
    }

    public function test_package_3_checkout_only_for_selected_restaurant(): void
    {
        $admin = $this->superAdmin();
        $a = $this->restaurant(['restaurant_name' => 'A']);
        $b = $this->restaurant(['restaurant_name' => 'B']);

        $id = $this->create($admin, $a, 'CHECKOUT_PAGE_OFFER', ['payment_mode' => 'manual'])
            ->assertCreated()
            ->assertJsonPath('data.advertisement.target.type', 'checkout')
            ->json('data.advertisement.id');

        $customer = Customer::create(['phone' => (string) $this->mobile++, 'name' => 'C', 'status' => 'active']);
        Sanctum::actingAs($customer);
        $this->withoutToken();

        $cart = CustomerCart::create(['customer_id' => $customer->id, 'restaurant_id' => $a->id]);
        $this->getJson('/api/customer/cart')->assertJsonPath('data.checkout_advertisement.id', $id);

        $cart->update(['restaurant_id' => $b->id]);
        $this->getJson('/api/customer/cart')->assertJsonPath('data.checkout_advertisement', null);
    }

    public function test_package_4_featured_without_image_then_back_to_normal(): void
    {
        $admin = $this->superAdmin();
        $featured = $this->restaurant(['restaurant_name' => 'Featured']);
        $this->travel(1)->minutes();
        $newer = $this->restaurant(['restaurant_name' => 'Newer']);

        $this->create($admin, $featured, 'FEATURED_RESTAURANT')->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->create($admin, $featured, 'FEATURED_RESTAURANT', ['payment_mode' => 'manual'], false)
            ->assertCreated()
            ->assertJsonPath('data.advertisement.target.type', 'restaurant')
            ->assertJsonPath('data.advertisement.target.id', $featured->id);

        $this->asCustomer()->getJson('/api/customer/restaurants')
            ->assertJsonPath('data.restaurants.0.id', $featured->id)
            ->assertJsonPath('data.restaurants.0.is_featured', true);

        $this->travel(8)->days();

        $this->asCustomer()->getJson('/api/customer/restaurants')
            ->assertJsonPath('data.restaurants.0.id', $newer->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    public function test_invalid_inputs_are_rejected(): void
    {
        $admin = $this->superAdmin();
        $restaurant = $this->restaurant();

        // Wrong restaurant id
        $this->as($admin)->post('/api/super-admin/advertisements', [
            'restaurant_id' => 999999,
            'package_id' => $this->package('HOME_SCREEN_SHOWCASE')->id,
            'package_duration_id' => $this->duration('HOME_SCREEN_SHOWCASE')->id,
            'payment_mode' => 'online',
            'image' => UploadedFile::fake()->image('ad.jpg', 1200, 600),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('restaurant_id');

        // Inactive restaurant
        $this->create($admin, $this->restaurant(['status' => 'suspended']), 'HOME_SCREEN_SHOWCASE')->assertForbidden();

        // Duration of another package
        $this->create($admin, $restaurant, 'HOME_SCREEN_SHOWCASE', [
            'package_duration_id' => $this->duration('CHECKOUT_PAGE_OFFER')->id,
        ])->assertUnprocessable();

        // Disabled duration / package
        $duration = $this->duration('HOME_SCREEN_SHOWCASE');
        $duration->update(['is_active' => false]);
        $this->create($admin, $restaurant, 'HOME_SCREEN_SHOWCASE')->assertUnprocessable();
        $duration->update(['is_active' => true]);

        $this->package('CHECKOUT_PAGE_OFFER')->update(['is_active' => false]);
        $this->create($admin, $restaurant, 'CHECKOUT_PAGE_OFFER')->assertUnprocessable();

        // Bad image
        $this->create($admin, $restaurant, 'HOME_SCREEN_SHOWCASE', [
            'image' => UploadedFile::fake()->create('ad.pdf', 100, 'application/pdf'),
        ], false)->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->create($admin, $restaurant, 'HOME_SCREEN_SHOWCASE', [
            'image' => UploadedFile::fake()->image('ad.jpg', 100, 50),
        ], false)->assertUnprocessable()->assertJsonValidationErrors('image');

        // Food item on a non-food package (wrong target)
        $this->create($admin, $restaurant, 'HOME_SCREEN_SHOWCASE', ['food_item_id' => $this->foodItem($restaurant)->id])
            ->assertUnprocessable()->assertJsonValidationErrors('food_item_id');

        $this->assertSame(0, RestaurantAdvertisement::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    public function test_role_admin_permissions_are_enforced(): void
    {
        $restaurant = $this->restaurant();
        $viewer = $this->roleAdmin(['View']);
        $creator = $this->roleAdmin(['View', 'Create']);

        $this->as($this->roleAdmin([]))->getJson('/api/super-admin/advertisements')->assertForbidden();
        $this->as($viewer)->getJson('/api/super-admin/advertisements')->assertOk();
        $this->create($viewer, $restaurant, 'HOME_SCREEN_SHOWCASE')->assertForbidden();

        // Create without Approve cannot record a manual payment
        $this->create($creator, $restaurant, 'HOME_SCREEN_SHOWCASE', ['payment_mode' => 'manual'])->assertForbidden();
        $this->assertSame(0, RestaurantAdvertisement::count());

        $id = $this->create($creator, $restaurant, 'HOME_SCREEN_SHOWCASE')
            ->assertCreated()
            ->assertJsonPath('data.advertisement.created_by.type', 'admin')
            ->json('data.advertisement.id');

        $this->as($creator)->postJson("/api/super-admin/advertisements/{$id}/mark-paid")->assertForbidden();
        $this->as($creator)->postJson("/api/super-admin/advertisements/{$id}/cancel")->assertForbidden();
        $this->as($creator)->putJson('/api/super-admin/advertisement/package-durations/' . $this->duration('HOME_SCREEN_SHOWCASE')->id, ['price' => 1])->assertForbidden();

        $this->as($this->roleAdmin(['View', 'Approve']))->postJson("/api/super-admin/advertisements/{$id}/mark-paid", ['payment_reference' => 'UTR-1'])
            ->assertOk()
            ->assertJsonPath('data.advertisement.payment_status', 'paid')
            ->assertJsonPath('data.advertisement.status', 'active');
    }

    /*
    |--------------------------------------------------------------------------
    | Manual payment / pause / resume / cancel
    |--------------------------------------------------------------------------
    */

    public function test_manual_payment_is_recorded_and_audited(): void
    {
        $admin = $this->superAdmin();
        $restaurant = $this->restaurant();

        $id = $this->create($admin, $restaurant, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');

        $this->as($admin)->postJson("/api/super-admin/advertisements/{$id}/mark-paid", [
            'payment_reference' => 'UTR-999',
            'payment_note' => 'Bank transfer',
        ])->assertOk();

        $ad = RestaurantAdvertisement::findOrFail($id);
        $this->assertSame('paid', $ad->payment_status);
        $this->assertSame('manual', $ad->payment_method);
        $this->assertSame('UTR-999', $ad->payment_reference);
        $this->assertSame($admin->id, $ad->paid_by_admin_id);
        $this->assertNotNull($ad->paid_at);
        $this->assertSame('active', $ad->status);
        $this->assertTrue($ad->histories()->where('actor_id', $admin->id)->exists());

        // Paying twice and reusing a reference are refused
        $this->as($admin)->postJson("/api/super-admin/advertisements/{$id}/mark-paid")->assertStatus(409);

        $this->create($admin, $this->restaurant(), 'HOME_SCREEN_SHOWCASE', [
            'payment_mode' => 'manual',
            'payment_reference' => 'UTR-999',
        ])->assertUnprocessable();
        $this->assertSame(1, RestaurantAdvertisement::count());

        $this->as($admin)->getJson("/api/super-admin/advertisements/{$id}")
            ->assertOk()
            ->assertJsonPath('data.advertisement.paid_by.id', $admin->id)
            ->assertJsonPath('data.advertisement.actions.mark_paid', false);
    }

    public function test_pause_resume_cancel_control_customer_visibility(): void
    {
        $admin = $this->superAdmin();
        $restaurant = $this->restaurant();

        $id = $this->create($admin, $restaurant, 'HOME_SCREEN_SHOWCASE', ['payment_mode' => 'manual'])->json('data.advertisement.id');
        $endAt = RestaurantAdvertisement::findOrFail($id)->end_at;

        $this->as($admin)->postJson("/api/super-admin/advertisements/{$id}/pause")->assertOk()->assertJsonPath('data.advertisement.status', 'paused');
        $this->asCustomer()->getJson('/api/customer/home')->assertJsonCount(0, 'data.home_advertisements');

        $this->as($admin)->postJson("/api/super-admin/advertisements/{$id}/resume")->assertOk()->assertJsonPath('data.advertisement.status', 'active');
        $this->assertEquals($endAt, RestaurantAdvertisement::findOrFail($id)->end_at);
        $this->asCustomer()->getJson('/api/customer/home')->assertJsonCount(1, 'data.home_advertisements');

        $this->as($admin)->postJson("/api/super-admin/advertisements/{$id}/cancel", ['reason' => 'Asked by owner'])
            ->assertOk()
            ->assertJsonPath('data.advertisement.status', 'cancelled')
            // Cancelling never refunds by itself
            ->assertJsonPath('data.advertisement.payment_status', 'paid');
        $this->asCustomer()->getJson('/api/customer/home')->assertJsonCount(0, 'data.home_advertisements');

        $this->as($admin)->postJson("/api/super-admin/advertisements/{$id}/resume")->assertStatus(422);
        $this->as($admin)->postJson("/api/super-admin/advertisements/{$id}/mark-refunded")
            ->assertOk()
            ->assertJsonPath('data.advertisement.payment_status', 'refunded');
    }

    public function test_edit_pending_ad_is_repriced_and_paid_ad_is_locked(): void
    {
        $admin = $this->superAdmin();
        $restaurant = $this->restaurant();

        $id = $this->create($admin, $restaurant, 'HOME_SCREEN_SHOWCASE')->json('data.advertisement.id');
        $longer = $this->package('HOME_SCREEN_SHOWCASE')->durations()->where('duration_days', '>', 7)->orderBy('duration_days')->firstOrFail();

        $this->as($admin)->putJson("/api/super-admin/advertisements/{$id}", [
            'package_duration_id' => $longer->id,
            'restaurant_id' => 999,
            'amount' => 1,
        ])->assertOk()
            ->assertJsonPath('data.advertisement.duration_days', $longer->duration_days)
            ->assertJsonPath('data.advertisement.restaurant.id', $restaurant->id);

        $this->assertEquals($longer->price, RestaurantAdvertisement::findOrFail($id)->amount);

        $this->as($admin)->postJson("/api/super-admin/advertisements/{$id}/mark-paid")->assertOk();
        $this->as($admin)->putJson("/api/super-admin/advertisements/{$id}", ['package_duration_id' => $this->duration('HOME_SCREEN_SHOWCASE')->id])
            ->assertStatus(422);
    }

    public function test_list_filters_and_shows_creator(): void
    {
        $admin = $this->superAdmin();
        $restaurant = $this->restaurant(['restaurant_name' => 'Searchable Dhaba']);

        $this->create($admin, $restaurant, 'HOME_SCREEN_SHOWCASE', ['payment_mode' => 'manual']);
        $this->create($admin, $this->restaurant(['restaurant_name' => 'Other']), 'FEATURED_RESTAURANT', [], false);

        $this->as($admin)->getJson('/api/super-admin/advertisements?status=active')
            ->assertOk()
            ->assertJsonPath('data.counts.all', 2)
            ->assertJsonPath('data.counts.active', 1)
            ->assertJsonPath('data.counts.pending', 1)
            ->assertJsonCount(1, 'data.advertisements')
            ->assertJsonPath('data.advertisements.0.created_by.id', $admin->id)
            ->assertJsonPath('data.advertisements.0.restaurant.name', 'Searchable Dhaba');

        $this->as($admin)->getJson('/api/super-admin/advertisements?search=Searchable')
            ->assertJsonCount(1, 'data.advertisements');
        $this->as($admin)->getJson('/api/super-admin/advertisements?payment_status=paid')
            ->assertJsonCount(1, 'data.advertisements');
    }

    public function test_super_admin_manages_pricing(): void
    {
        $admin = $this->superAdmin();
        $package = $this->package('CHECKOUT_PAGE_OFFER');
        $duration = $this->duration('CHECKOUT_PAGE_OFFER');

        $this->as($admin)->putJson("/api/super-admin/advertisement/package-durations/{$duration->id}", ['price' => 1234])->assertOk();
        $this->assertEquals(1234, $duration->refresh()->price);

        $this->as($admin)->postJson("/api/super-admin/advertisement/packages/{$package->id}/durations", ['duration_days' => 45, 'price' => 9000])
            ->assertCreated();
        $this->assertTrue($package->durations()->where('duration_days', 45)->exists());

        $this->as($admin)->putJson("/api/super-admin/advertisement/packages/{$package->id}", ['is_active' => false])->assertOk();
        $this->as($admin)->getJson('/api/super-admin/advertisements/options')->assertJsonCount(3, 'data.packages');
    }
}
