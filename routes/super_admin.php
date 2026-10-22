<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\Admin\AdminNotificationController;
use App\Http\Controllers\Api\Admin\AdminRestaurantOfferController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminAdminController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminAdvertisementController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminAuthController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminCustomerController;
use App\Http\Controllers\Api\Admin\AdminRiderEarningController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminDashboardController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminFinanceController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminMenuItemController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminOrderController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminPaymentController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminRefundController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminReportController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminRestaurantApplicationController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminRestaurantController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminRiderApplicationController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminRiderController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminRoleController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminSettlementController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminRestaurantSettlementController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminSupportController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminActivityLogController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminSettingController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminCouponController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminMarketingController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminNotificationController;

/*
|--------------------------------------------------------------------------
| Super Admin Panel Routes
|--------------------------------------------------------------------------
|
| Loaded from routes/api.php, so every route here is prefixed with
| /api/super-admin and uses the api middleware group.
|
| Role-based admins (created on the Roles & Permissions page) use the same
| routes. "super_admin.can:<module>[,<action>]" checks their permissions;
| without an action it follows the HTTP method (GET = View, POST = Create,
| PUT/PATCH = Edit, DELETE = Delete). "super_admin.can:owner" is for real
| super admins only. Super admins pass every check.
|
*/

Route::prefix('super-admin')->group(function () {

    Route::post('/login', [SuperAdminAuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware(['super_admin.auth', 'admin.activity'])->group(function () {

        Route::get('/me', [SuperAdminAuthController::class, 'me']);
        Route::put('/profile', [SuperAdminAuthController::class, 'updateProfile']);

        Route::post('/logout', [SuperAdminAuthController::class, 'logout']);

        Route::get('/activity-logs', [SuperAdminActivityLogController::class, 'index'])
            ->middleware('super_admin.can:activityLogs');

        // Dashboard (platform-wide figures)
        Route::get('/dashboard', [SuperAdminDashboardController::class, 'index'])->middleware('super_admin.can:owner');

        // Platform Settings
        Route::prefix('settings')->controller(SuperAdminSettingController::class)->group(function () {
            Route::get('/', 'show');
            Route::put('/', 'update')->middleware('super_admin.can:owner');
            Route::post('/logo', 'uploadLogo')->middleware('super_admin.can:owner');
            Route::delete('/logo', 'removeLogo')->middleware('super_admin.can:owner');
        });

        // Admin Management page: /admin panel logins
        Route::prefix('admins')->controller(SuperAdminAdminController::class)->middleware('super_admin.can:owner')->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::put('/{id}', 'update')->whereNumber('id');
            Route::put('/{id}/password', 'resetPassword')->whereNumber('id');
            Route::delete('/{id}', 'destroy')->whereNumber('id');
        });

        // Roles & Permissions page
        Route::prefix('roles')->controller(SuperAdminRoleController::class)->middleware('super_admin.can:owner')->group(function () {
            Route::get('/', 'index');
            Route::get('/audit', 'auditHistory');
            Route::post('/', 'store');
            Route::put('/{id}', 'update')->whereNumber('id');
            Route::delete('/{id}', 'destroy')->whereNumber('id');
            Route::post('/{id}/admins', 'storeAdmin')->whereNumber('id');
            Route::delete('/{id}/admins/{adminId}', 'destroyAdmin')->whereNumber(['id', 'adminId']);
        });

        // Reports & Analytics page
        Route::get('/reports', [SuperAdminReportController::class, 'index'])->middleware('super_admin.can:reports');

        // Customers (Users page)
        Route::middleware('super_admin.can:users')->group(function () {
            Route::get('/customers', [SuperAdminCustomerController::class, 'index']);
            Route::get('/customers/export', [SuperAdminCustomerController::class, 'export'])->middleware('super_admin.can:users,Export');
            Route::post('/customers', [SuperAdminCustomerController::class, 'store']);
            Route::patch('/customers/status', [SuperAdminCustomerController::class, 'updateStatus'])->middleware('super_admin.can:users,Suspend');
            Route::get('/customers/{id}', [SuperAdminCustomerController::class, 'show'])->whereNumber('id');
            Route::put('/customers/{id}', [SuperAdminCustomerController::class, 'update'])->whereNumber('id');
        });

        // Restaurants page (Restaurant Applications also creates restaurants)
        Route::post('/restaurants', [SuperAdminRestaurantController::class, 'store'])->middleware('super_admin.can:restaurants|restaurantApplications,Create');

        Route::middleware('super_admin.can:restaurants')->group(function () {
            Route::get('/restaurants', [SuperAdminRestaurantController::class, 'index']);
            Route::get('/restaurants/export', [SuperAdminRestaurantController::class, 'export'])->middleware('super_admin.can:restaurants,Export');
            Route::get('/restaurants/{id}', [SuperAdminRestaurantController::class, 'show'])->whereNumber('id');
            Route::put('/restaurants/{id}', [SuperAdminRestaurantController::class, 'update'])->whereNumber('id');
            Route::put('/restaurants/{id}/location', [SuperAdminRestaurantController::class, 'updateLocation'])->whereNumber('id');
            Route::get('/restaurants/reverse-geocode', [SuperAdminRestaurantController::class, 'reverseGeocode']);
            Route::patch('/restaurants/{id}/status', [SuperAdminRestaurantController::class, 'updateStatus'])->whereNumber('id')->middleware('super_admin.can:restaurants,Suspend');
            Route::get('/restaurants/{id}/menu', [SuperAdminRestaurantController::class, 'menu'])->whereNumber('id');
            Route::patch('/restaurants/{id}/menu/{itemId}/availability', [SuperAdminRestaurantController::class, 'toggleMenuItem'])->whereNumber(['id', 'itemId']);
            Route::get('/restaurants/{id}/orders', [SuperAdminRestaurantController::class, 'orders'])->whereNumber('id');
        });

        // Restaurant offers page
        Route::middleware('super_admin.can:restaurantOffers')->group(function () {
            Route::get('/restaurant-offers', [AdminRestaurantOfferController::class, 'index']);
            Route::get('/restaurant-offers/options', [AdminRestaurantOfferController::class, 'options']);
            Route::post('/restaurant-offers', [AdminRestaurantOfferController::class, 'store']);
            Route::put('/restaurant-offers/{id}', [AdminRestaurantOfferController::class, 'update'])->whereNumber('id');
            Route::patch('/restaurant-offers/{id}/status', [AdminRestaurantOfferController::class, 'toggleStatus'])->whereNumber('id');
            Route::delete('/restaurant-offers/{id}', [AdminRestaurantOfferController::class, 'destroy'])->whereNumber('id');
        });

        // Orders page
        Route::prefix('orders')->controller(SuperAdminOrderController::class)->middleware('super_admin.can:orders')->group(function () {
            Route::get('/', 'index');
            Route::post('/cancel', 'cancel')->middleware('super_admin.can:orders,Edit');
            Route::patch('/{id}/status', 'updateStatus')->whereNumber('id');
        });

        // Payments & Transactions page
        Route::prefix('payments')->controller(SuperAdminPaymentController::class)->middleware('super_admin.can:payments')->group(function () {
            Route::get('/', 'index');
            Route::patch('/{id}/settle', 'settle')->whereNumber('id')->middleware('super_admin.can:payments,Approve');
        });

        // Support & Escalations page
        Route::get('/support/tickets', [SuperAdminSupportController::class, 'tickets'])
            ->middleware('super_admin.can:support');
        Route::get('/support/tickets/{ticketNumber}', [SuperAdminSupportController::class, 'getTicket'])
            ->middleware('super_admin.can:support');
        Route::post('/support/tickets/{ticketNumber}/messages', [SuperAdminSupportController::class, 'replyToTicket'])
            ->middleware('super_admin.can:support,Edit');
        Route::patch('/support/tickets/{ticketNumber}', [SuperAdminSupportController::class, 'updateTicket'])
            ->middleware('super_admin.can:support,Edit');

        // Finance Overview page
        Route::prefix('finance')->controller(SuperAdminFinanceController::class)->middleware('super_admin.can:finance')->group(function () {
            Route::get('/', 'index');
            Route::put('/restaurants/{id}/commission', 'updateCommission')->whereNumber('id');
            Route::post('/restaurants/{id}/clear-payout', 'clearRestaurantPayout')->whereNumber('id')->middleware('super_admin.can:finance,Approve');
        });
        Route::put('/finance/riders/{id}/status', [AdminRiderEarningController::class, 'updateStatus'])->whereNumber('id')->middleware('super_admin.can:finance,Approve');
        Route::post('/finance/riders/{id}/cod-settle', [AdminRiderEarningController::class, 'settleCod'])->whereNumber('id')->middleware('super_admin.can:finance,Approve');

        // Refunds Command Center page
        Route::prefix('refunds')->controller(SuperAdminRefundController::class)->middleware('super_admin.can:finance')->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}', 'show')->whereNumber('id');
            Route::post('/{id}/approve', 'approve')->whereNumber('id')->middleware('super_admin.can:finance,Approve');
            Route::post('/{id}/reject', 'reject')->whereNumber('id')->middleware('super_admin.can:finance,Reject');
        });

        // Settlements page: restaurant weekly settlements (Monday to Monday, paid Tuesday)
        Route::prefix('settlements/restaurants')->controller(SuperAdminRestaurantSettlementController::class)->middleware('super_admin.can:finance')->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}', 'show')->whereNumber('id');
            Route::post('/run-batch', 'runBatch')->middleware('super_admin.can:finance,Approve');
            Route::post('/{id}/mark-paid', 'markPaid')->whereNumber('id')->middleware('super_admin.can:finance,Approve');
            Route::post('/{id}/retry', 'retry')->whereNumber('id')->middleware('super_admin.can:finance,Approve');
            Route::patch('/{id}/hold', 'updateHold')->whereNumber('id')->middleware('super_admin.can:finance,Approve');
            Route::post('/{id}/contact', 'contact')->whereNumber('id')->middleware('super_admin.can:finance,Edit');
        });

        // Settlements page: rider weekly settlements (Wednesday to Wednesday, paid Thursday)
        Route::prefix('settlements')->controller(SuperAdminSettlementController::class)->middleware('super_admin.can:finance')->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}', 'show')->whereNumber('id');
            Route::post('/run-batch', 'runBatch')->middleware('super_admin.can:finance,Approve');
            Route::post('/{id}/mark-paid', 'markPaid')->whereNumber('id')->middleware('super_admin.can:finance,Approve');
            Route::post('/{id}/retry', 'retry')->whereNumber('id')->middleware('super_admin.can:finance,Approve');
            Route::patch('/{id}/hold', 'updateHold')->whereNumber('id')->middleware('super_admin.can:finance,Approve');
            Route::post('/{id}/cod-settle', 'settleCod')->whereNumber('id')->middleware('super_admin.can:finance,Approve');
            Route::post('/{id}/contact', 'contact')->whereNumber('id')->middleware('super_admin.can:finance,Edit');
        });

        // Delivery partners page (rider list is also used by Rider Location and Orders)
        Route::get('/rider-onboarding/options', [SuperAdminRiderController::class, 'riderOnboardingOptions'])->middleware('super_admin.can:deliveryPartners,Create');
        Route::get('/riders', [SuperAdminRiderController::class, 'index'])->middleware('super_admin.can:deliveryPartners|riderLocation|orders,View');
        Route::post('/riders', [SuperAdminRiderController::class, 'storeForOnboarding'])->middleware('super_admin.can:deliveryPartners,Create');
        Route::patch('/riders/{id}/online', [SuperAdminRiderController::class, 'updateOnline'])->whereNumber('id')->middleware('super_admin.can:deliveryPartners|riderLocation,Edit');
        Route::get('/riders/{id}/orders', [SuperAdminRiderController::class, 'orders'])->whereNumber('id')->middleware('super_admin.can:deliveryPartners');

        // Rider applications / delivery partner applications
        Route::prefix('rider-applications')->controller(SuperAdminRiderApplicationController::class)->middleware('super_admin.can:deliveryApplications')->group(function () {
            Route::get('/', 'index');
            Route::post('/{id}/documents/{key}/verify', 'verifyDocument')->whereNumber('id')->whereIn('key', ['aadhaar', 'pan', 'driving_license', 'rc_book', 'insurance', 'puc'])->middleware('super_admin.can:deliveryApplications,Edit');
            Route::post('/{id}/approve', 'approve')->whereNumber('id')->middleware('super_admin.can:deliveryApplications,Approve');
            Route::post('/{id}/reject', 'reject')->whereNumber('id')->middleware('super_admin.can:deliveryApplications,Reject');
        });

        // Menu & Food Items page
        Route::prefix('menu-items')->controller(SuperAdminMenuItemController::class)->middleware('super_admin.can:menu')->group(function () {
            Route::get('/', 'index');
            Route::get('/categories', 'categories');
            Route::post('/', 'store');
            Route::post('/{id}', 'update')->whereNumber('id')->middleware('super_admin.can:menu,Edit');
            Route::patch('/{id}/status', 'toggleStatus')->whereNumber('id');
            Route::delete('/{id}', 'destroy')->whereNumber('id');
        });

        // Banners page: paid restaurant advertisements (rules in RestaurantAdvertisementService)
        Route::prefix('advertisements')->controller(SuperAdminAdvertisementController::class)->middleware('super_admin.can:marketing')->group(function () {
            Route::get('/', 'index');
            Route::get('/options', 'options');
            Route::get('/restaurants/{restaurantId}/food-items', 'foodItems')->whereNumber('restaurantId');
            Route::post('/', 'store')->middleware('super_admin.can:marketing,Create');
            Route::get('/{id}', 'show')->whereNumber('id');
            Route::put('/{id}', 'update')->whereNumber('id')->middleware('super_admin.can:marketing,Edit');
            Route::post('/{id}/payment', 'payment')->whereNumber('id')->middleware('super_admin.can:marketing,Edit');
            Route::post('/{id}/payment/verify', 'verifyPayment')->whereNumber('id')->middleware('super_admin.can:marketing,Edit');
            Route::post('/{id}/activate', 'activate')->whereNumber('id')->middleware('super_admin.can:marketing,Approve');
            Route::post('/{id}/pause', 'pause')->whereNumber('id')->middleware('super_admin.can:marketing,Suspend');
            Route::post('/{id}/resume', 'resume')->whereNumber('id')->middleware('super_admin.can:marketing,Suspend');
            Route::post('/{id}/cancel', 'cancel')->whereNumber('id')->middleware('super_admin.can:marketing,Delete');
            Route::post('/{id}/mark-paid', 'markPaid')->whereNumber('id')->middleware('super_admin.can:marketing,Approve');
            Route::post('/{id}/mark-refunded', 'markRefunded')->whereNumber('id')->middleware('super_admin.can:marketing,Approve');
        });

        // Advertisement packages & pricing (changing prices needs Approve)
        Route::prefix('advertisement')->controller(SuperAdminAdvertisementController::class)->middleware('super_admin.can:marketing')->group(function () {
            Route::get('/packages', 'packages');
            Route::get('/packages/{id}', 'package')->whereNumber('id');
            Route::put('/packages/{id}', 'updatePackage')->whereNumber('id')->middleware('super_admin.can:marketing,Approve');
            Route::post('/packages/{id}/durations', 'storeDuration')->whereNumber('id')->middleware('super_admin.can:marketing,Approve');
            Route::put('/package-durations/{id}', 'updateDuration')->whereNumber('id')->middleware('super_admin.can:marketing,Approve');
        });

        // Advertisements page (Marketing Overview): summary cards + impressions/clicks chart
        Route::prefix('marketing')->controller(SuperAdminMarketingController::class)->middleware('super_admin.can:marketing')->group(function () {
            Route::get('/overview', 'overview');
            Route::get('/performance', 'performance');
        });

        // Coupons & Promotions page
        Route::prefix('coupons')->controller(SuperAdminCouponController::class)->middleware('super_admin.can:marketing')->group(function () {
            Route::get('/', 'index');
            Route::get('/options', 'options');
            Route::get('/analytics', 'analyticsReport');
            Route::post('/', 'store')->middleware('super_admin.can:marketing,Create');
            Route::patch('/{id}/status', 'toggleStatus')->whereNumber('id')->middleware('super_admin.can:marketing,Edit');
            Route::delete('/{id}', 'destroy')->whereNumber('id')->middleware('super_admin.can:marketing,Delete');
        });

        // Restaurant Applications page
        Route::prefix('restaurant-applications')->controller(SuperAdminRestaurantApplicationController::class)->middleware('super_admin.can:restaurantApplications')->group(function () {
            Route::get('/', 'index');
            Route::get('/export', 'export')->middleware('super_admin.can:restaurantApplications,Export');            Route::get('/{id}', 'show')->whereNumber('id');
            Route::post('/{id}/documents/{key}/verify', 'verifyDocument')->whereNumber('id')->whereIn('key', ['fssai', 'gst', 'pan', 'bank'])->middleware('super_admin.can:restaurantApplications,Edit');
            Route::post('/{id}/approve', 'approve')->whereNumber('id')->middleware('super_admin.can:restaurantApplications,Approve');
            Route::post('/{id}/reject', 'reject')->whereNumber('id')->middleware('super_admin.can:restaurantApplications,Reject');
            Route::post('/{id}/request-info', 'requestInfo')->whereNumber('id')->middleware('super_admin.can:restaurantApplications,Edit');
            Route::post('/{id}/notes', 'storeNote')->whereNumber('id')->middleware('super_admin.can:restaurantApplications,Edit');
            Route::put('/{id}/commission', 'updateCommission')->whereNumber('id');
            Route::post('/{id}/setup/{step}', 'verifySetup')->whereNumber('id')->whereIn('step', ['restaurant_setup', 'menu_setup', 'live'])->middleware('super_admin.can:restaurantApplications,Approve');
        });

        // Header bell inbox (same data as the Admin panel; filtered by role permissions)
        Route::prefix('admin-notifications')->controller(AdminNotificationController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/unread-count', 'unreadCount');
            Route::post('/read-all', 'markAllRead');
            Route::get('/settings', 'settings');
            Route::put('/settings', 'updateSettings');
            Route::post('/{id}/read', 'markRead')->whereNumber('id');
            Route::post('/{id}/unread', 'markUnread')->whereNumber('id');
        });

        // Communication & Notifications (Command Center)
        Route::prefix('notifications')->controller(SuperAdminNotificationController::class)->middleware('super_admin.can:notifications')->group(function () {
            Route::get('/', 'index');
            Route::post('/broadcast', 'store')->middleware('super_admin.can:notifications,Create');
            Route::get('/export', 'export')->middleware('super_admin.can:notifications,Export');
        });

    });

});
