<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Rider\RiderAuthController;
use App\Http\Controllers\Api\Rider\RiderOnboardingController;
use App\Http\Controllers\Api\Rider\RiderKycController;
use App\Http\Controllers\Api\Rider\RiderDashboardController;
use App\Http\Controllers\Api\Rider\RiderOrderController;
use App\Http\Controllers\Api\Rider\RiderBankController;
use App\Http\Controllers\Api\Rider\RiderUpiController;
use App\Http\Controllers\Api\Rider\RiderProfileController;
use App\Http\Controllers\Api\Rider\RiderSupportController;
use App\Http\Controllers\Api\Rider\RiderShiftController;
use App\Http\Controllers\Api\Rider\RiderCodController;
use App\Http\Controllers\Api\Rider\RiderSettlementController;

// Restaurant api controller
use App\Http\Controllers\Api\Restaurant\RestaurantAuthController;
use App\Http\Controllers\Api\Restaurant\PartnerContractController;
use App\Http\Controllers\Api\Restaurant\RestaurantOnboardingController;
use App\Http\Controllers\Api\Restaurant\RestaurantCategoryController;
use App\Http\Controllers\Api\Restaurant\RestaurantMenuItemController;
use App\Http\Controllers\Api\Restaurant\RestaurantDashboardController;
use App\Http\Controllers\Api\Restaurant\RestaurantRevenueController;
use App\Http\Controllers\Api\Restaurant\RestaurantOrderController;
use App\Http\Controllers\Api\Restaurant\RestaurantRatingController;
use App\Http\Controllers\Api\Restaurant\RestaurantProfileController;
use App\Http\Controllers\Api\Restaurant\RestaurantMenuController;
use App\Http\Controllers\Api\Restaurant\RestaurantNotificationController;
use App\Http\Controllers\Api\Restaurant\RestaurantOfferController;
use App\Http\Controllers\Api\Restaurant\RestaurantSettingsController;
use App\Http\Controllers\Api\Restaurant\RestaurantWalletController;
use App\Http\Controllers\Api\Restaurant\RestaurantSettlementController;
use App\Http\Controllers\Api\Restaurant\RestaurantAdvertisementController;
use App\Http\Controllers\Api\Restaurant\RestaurantSupportController;
use App\Http\Controllers\Api\Admin\AdminRestaurantEarningController;
use App\Http\Controllers\Api\Admin\AdminRiderEarningController;

// CUSTOMER APIS CONTOLLERS
use App\Http\Controllers\Api\Customer\CustomerAuthController;
use App\Http\Controllers\Api\Customer\CustomerHomeController;
use App\Http\Controllers\Api\Customer\CustomerAddressController;
use App\Http\Controllers\Api\Customer\CustomerCategoryController;
use App\Http\Controllers\Api\Customer\CustomerPopularController;
use App\Http\Controllers\Api\Customer\CustomerRestaurantController;
use App\Http\Controllers\Api\Customer\CustomerRestaurantDetailsController;
use App\Http\Controllers\Api\Customer\CustomerSearchController;
use App\Http\Controllers\Api\Customer\CustomerCartController;
use App\Http\Controllers\Api\Customer\CustomerOrderController;
use App\Http\Controllers\Api\Customer\CustomerProfileController;
use App\Http\Controllers\Api\Customer\CustomerFavoriteRestaurantController;
use App\Http\Controllers\Api\Customer\CustomerFavoriteFoodController;
use App\Http\Controllers\Api\Customer\CustomerNotificationController;
use App\Http\Controllers\Api\Customer\CustomerSettingsController;
use App\Http\Controllers\Api\Customer\CustomerSupportController;
use App\Http\Controllers\Api\Customer\CustomerPayUController;
use App\Http\Controllers\Api\Customer\CustomerReviewController;


// Admin Side controller
use App\Http\Controllers\Api\Admin\RiderBankVerificationController;
use App\Http\Controllers\Api\Admin\AdminAuthController;
use App\Http\Controllers\Api\Admin\AdminCustomerController;
use App\Http\Controllers\Api\Admin\AdminRestaurantController;
use App\Http\Controllers\Api\Admin\AdminRestaurantDocumentController;
use App\Http\Controllers\Api\Admin\AdminRestaurantMenuController;
use App\Http\Controllers\Api\Admin\AdminRestaurantOfferController;
use App\Http\Controllers\Api\Admin\AdminRestaurantTimingController;
use App\Http\Controllers\Api\Admin\AdminRiderController;
use App\Http\Controllers\Api\Admin\AdminRiderDocumentController;
use App\Http\Controllers\Api\Admin\AdminRiderShiftController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminReportController;
use App\Http\Controllers\Api\Admin\AdminPaymentController;
use App\Http\Controllers\Api\Admin\AdminActivityLogController;
use App\Http\Controllers\Api\Admin\AdminAdvertisementController;
use App\Http\Controllers\Api\Admin\AdminSupportController;


use App\Services\Msg91OtpService;


Route::post('/test-msg91-verification', function (
    Request $request,
    Msg91OtpService $msg91
) {
    $validated = $request->validate([
        'access_token' => ['required', 'string', 'max:10000'],
    ]);

    $token = trim($validated['access_token']);

    Illuminate\Support\Facades\Log::info('Received MSG91 test verification request', [
        'token_length' => strlen($token),
        'token_preview' => substr($token, 0, 30),
    ]);

    $result = $msg91->verifyWithDetails($token);

    if (!$result['success']) {
        return response()->json([
            'success' => false,
            'message' => $result['message'],
            'code' => $result['code'] ?? null,
            'details' => $result['details'] ?? null,
        ], 422);
    }

    return response()->json([
        'success' => true,
        'message' => 'MSG91 access token verified successfully!',
        'verified_phone' => $result['data']['message'] ?? null,
        'data' => $result['data'],
    ]);
});


Route::middleware('auth:sanctum')->get('/rider/debug', function (Request $request) {
    return response()->json([
        'authenticated' => auth()->check(),
        'user' => $request->user(),
    ]);
    
});


Route::prefix('rider/auth')->group(function () {
    Route::post('/send-otp', [RiderAuthController::class, 'sendOtp']);
    Route::post('/verify-otp', [RiderAuthController::class, 'verifyOtp']);
    Route::post('/resend-otp', [RiderAuthController::class, 'resendOtp']);
    Route::post('/msg91-login', [RiderAuthController::class, 'msg91Login']);
});

/*
|--------------------------------------------------------------------------
| Restaurant Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('restaurant/auth')->group(function () {

    Route::post('/send-otp', [RestaurantAuthController::class, 'sendOtp']);
    Route::post('/verify-otp', [RestaurantAuthController::class, 'verifyOtp']);
    Route::post('/resend-otp', [RestaurantAuthController::class, 'resendOtp']);
    Route::post('/msg91-login', [RestaurantAuthController::class, 'msg91Login']);

});

/*
|--------------------------------------------------------------------------
| Customer Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('customer/auth')->group(function () {

    Route::post('/send-otp', [CustomerAuthController::class, 'sendOtp']);

    Route::post('/verify-otp', [CustomerAuthController::class, 'verifyOtp']);

    Route::post('/resend-otp', [CustomerAuthController::class, 'resendOtp']);

    Route::post('/msg91-login', [CustomerAuthController::class, 'msg91Login']);

});

// Public Application Configuration (Platform Settings, Maintenance, Fees)
Route::get('/app-config', [CustomerSettingsController::class, 'appConfig']);
Route::get('/customer/app-config', [CustomerSettingsController::class, 'appConfig']);


/*
|--------------------------------------------------------------------------
| Super Admin Panel (see routes/super_admin.php)
|--------------------------------------------------------------------------
*/

require __DIR__ . '/super_admin.php';


/*
|--------------------------------------------------------------------------
| Admin Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->group(function () {

    Route::post('/login', [AdminAuthController::class, 'login']);

    Route::middleware(['admin.auth', 'admin.activity', 'change.activity'])->group(function () {

        Route::get('/me', [AdminAuthController::class, 'me']);

        Route::post('/logout', [AdminAuthController::class, 'logout']);

        // Customer Management
        Route::get('/customers', [AdminCustomerController::class, 'index']);
           Route::get('/customers/{id}', [AdminCustomerController::class, 'show']);
           Route::get('/customers/{id}/orders', [AdminCustomerController::class, 'orders']);
           Route::get('/customers/{id}/orders/{orderId}', [AdminCustomerController::class, 'orderDetails']);
           Route::put('/customers/{id}', [AdminCustomerController::class, 'update']);

           // All Customer Orders
Route::get('/orders', [AdminOrderController::class, 'index']);


Route::get('/restaurants/timing',[AdminRestaurantTimingController::class, 'index']);
Route::get('/restaurants/{id}/timing',[AdminRestaurantTimingController::class, 'show']);
Route::put('/restaurants/{id}/timing',[AdminRestaurantTimingController::class, 'update']);

            // Restaurant Management
        Route::get('/restaurants', [AdminRestaurantController::class, 'index']);
        Route::get('/restaurants/{id}', [AdminRestaurantController::class, 'show']);
        Route::put('/restaurants/{id}', [AdminRestaurantController::class, 'update']);
        Route::put('/restaurants/{id}/commission', [AdminRestaurantController::class, 'updateCommission']);
        Route::post('/restaurants/{id}/suspend', [AdminRestaurantController::class, 'suspend']);
        Route::post('/restaurants/{id}/unsuspend', [AdminRestaurantController::class, 'unsuspend']);
        Route::get('/restaurants/{id}/performance', [AdminRestaurantController::class, 'performance']);
        Route::get('/restaurants/{id}/earnings', [AdminRestaurantController::class, 'earnings']);

        // Restaurant Earnings (Finance)
        Route::get('/restaurant-earnings', [AdminRestaurantEarningController::class, 'index']);
        Route::get('/restaurant-earnings/{id}/payouts', [AdminRestaurantEarningController::class, 'payouts']);

        // Rider Earnings (Finance)
        Route::get('/rider-earnings', [AdminRiderEarningController::class, 'index']);
        Route::get('/rider-earnings/{id}/payouts', [AdminRiderEarningController::class, 'payouts']);
        Route::post('/rider-earnings/{id}/adjust', [AdminRiderEarningController::class, 'adjust']);
        Route::put('/rider-earnings/{id}/status', [AdminRiderEarningController::class, 'updateStatus']);
        Route::post('/rider-earnings/{id}/contact', [AdminRiderEarningController::class, 'contact']);
        Route::get('/rider-earnings/{id}/settlements/{settlementId}', [AdminRiderEarningController::class, 'settlementDetail']);
        Route::get('/rider-earnings/{id}/cod-settlements', [AdminRiderEarningController::class, 'codSettlements']);
        Route::post('/rider-earnings/{id}/cod-settle', [AdminRiderEarningController::class, 'settleCod']);

        // Payments & Transactions
        Route::get('/payments', [AdminPaymentController::class, 'index']);
        Route::put('/payments/{id}', [AdminPaymentController::class, 'update']);

        // Restaurant Document Verification
Route::get('/restaurant-documents',[AdminRestaurantDocumentController::class, 'index']);
Route::get('/restaurant-documents/{restaurantId}',[AdminRestaurantDocumentController::class, 'show']);
Route::post('/restaurant-documents/{restaurantId}/under-review',[AdminRestaurantDocumentController::class, 'markUnderReview']);
Route::post('/restaurant-documents/{restaurantId}/approve',[AdminRestaurantDocumentController::class, 'approve']);
Route::post('/restaurant-documents/{restaurantId}/reject',[AdminRestaurantDocumentController::class, 'reject']);

Route::get('/restaurants/{restaurantId}/menu',[AdminRestaurantMenuController::class, 'index']);
Route::post('/restaurants/{restaurantId}/menu',[AdminRestaurantMenuController::class, 'store']);
Route::put('/restaurants/{restaurantId}/menu/{itemId}',[AdminRestaurantMenuController::class, 'update']);
Route::delete('/restaurants/{restaurantId}/menu/{itemId}',[AdminRestaurantMenuController::class, 'destroy']);
Route::patch('/restaurants/{restaurantId}/menu/{itemId}/availability',[AdminRestaurantMenuController::class, 'toggleAvailability']);
Route::post('/restaurants/{restaurantId}/menu/categories',[AdminRestaurantMenuController::class, 'storeCategory']);

// Restaurant Offers
Route::get('/restaurant-offers/options',[AdminRestaurantOfferController::class, 'options']);
Route::get('/restaurant-offers',[AdminRestaurantOfferController::class, 'index']);
Route::post('/restaurant-offers',[AdminRestaurantOfferController::class, 'store']);
Route::put('/restaurant-offers/{id}',[AdminRestaurantOfferController::class, 'update']);
Route::patch('/restaurant-offers/{id}/status',[AdminRestaurantOfferController::class, 'toggleStatus']);
Route::delete('/restaurant-offers/{id}',[AdminRestaurantOfferController::class, 'destroy']);


Route::get(
    '/riders',
    [AdminRiderController::class, 'index']
);

Route::post('/riders', [AdminRiderController::class, 'store']);

Route::get('/riders/vehicle-types', [AdminRiderController::class, 'vehicleTypes']);

Route::patch('/riders/{id}/status', [AdminRiderController::class, 'updateStatus']);

Route::patch('/riders/{id}/online', [AdminRiderController::class, 'updateOnline']);

Route::get('/riders/documents', [AdminRiderDocumentController::class, 'index']);

Route::get('/riders/{id}/documents', [AdminRiderDocumentController::class, 'show']);

Route::patch('/riders/{id}/documents/status', [AdminRiderDocumentController::class, 'updateStatus']);

Route::patch('/riders/{id}/documents/verify', [AdminRiderDocumentController::class, 'verifyDocument']);
Route::get(
    '/riders/shifts',
    [AdminRiderShiftController::class, 'index']
);

Route::get(
    '/riders/shifts/{id}',
    [AdminRiderShiftController::class, 'show']
);

Route::get(
    '/riders/{id}',
    [AdminRiderController::class, 'show']
);


Route::get(
    '/orders/{id}',
    [AdminOrderController::class, 'show']
);

Route::get(
    '/dashboard',
    [AdminDashboardController::class, 'index']
);

Route::get(
    '/reports',
    [AdminReportController::class, 'index']
);

// Activity Logs
Route::get('/activity-logs', [AdminActivityLogController::class, 'index']);
Route::put('/activity-logs/{id}', [AdminActivityLogController::class, 'update']);

// Promotions / Advertisements
Route::prefix('advertisements')->controller(AdminAdvertisementController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/options', 'options');
    Route::get('/restaurants/{restaurantId}/food-items', 'foodItems')->whereNumber('restaurantId');
    Route::post('/', 'store');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::put('/{id}', 'update')->whereNumber('id');
    Route::post('/{id}/activate', 'activate')->whereNumber('id');
    Route::post('/{id}/pause', 'pause')->whereNumber('id');
    Route::post('/{id}/resume', 'resume')->whereNumber('id');
    Route::post('/{id}/cancel', 'cancel')->whereNumber('id');
    Route::post('/{id}/mark-paid', 'markPaid')->whereNumber('id');
    Route::post('/{id}/mark-refunded', 'markRefunded')->whereNumber('id');
});

// Support Tickets
Route::get('/support/tickets', [AdminSupportController::class, 'tickets']);
Route::get('/support/tickets/{ticketNumber}', [AdminSupportController::class, 'getTicket']);
Route::post('/support/tickets/{ticketNumber}/messages', [AdminSupportController::class, 'replyToTicket']);
Route::patch('/support/tickets/{ticketNumber}', [AdminSupportController::class, 'updateTicket']);

    });

});


Route::middleware('auth:sanctum')
    ->prefix('rider')
    ->group(function () {

Route::get('/cities', [RiderOnboardingController::class,'cities']);

Route::post('/select-city', [RiderOnboardingController::class,'selectCity']);

Route::get('/city-areas', [RiderOnboardingController::class, 'cityAreas']);

Route::post('/select-city-area', [RiderOnboardingController::class, 'selectCityArea']);

Route::get('/onboarding-status', [RiderOnboardingController::class,'onboardingStatus']);

Route::get('/vehicle-types', [RiderOnboardingController::class, 'vehicleTypes']);

Route::post('/work-settings', [RiderOnboardingController::class,'saveWorkSettings']);

Route::post('/profile', [RiderOnboardingController::class,'saveProfile']);

// Route::get('/work-zones', [RiderOnboardingController::class,'workZones']);

// Route::post('/select-work-zone', [RiderOnboardingController::class,'selectWorkZone']);

// Route::get('/order-types', [RiderOnboardingController::class,'orderTypes']);

// Route::post('/select-order-type', [RiderOnboardingController::class,'selectOrderType']);

Route::post('/kyc/aadhaar', [RiderKycController::class,'saveAadhaar']);

Route::post('/kyc/pan', [RiderKycController::class,'savePan']);

Route::post('/kyc/driving-license', [RiderKycController::class,'saveDrivingLicense']);

Route::get('/kyc/required-documents', [RiderKycController::class,'requiredDocuments']);

Route::post('/kyc/pan/skip', [RiderKycController::class,'skipPan']);

Route::post('/kyc/selfie', [RiderKycController::class,'uploadSelfie']);

Route::post('/kyc/puc', [RiderKycController::class,'uploadPuc']);

Route::post('/kyc/rc-book', [RiderKycController::class,'uploadRcBook']);

Route::post('/kyc/insurance', [RiderKycController::class,'uploadInsurance']);

Route::post('/complete-profile', [RiderKycController::class,'completeProfile']);

Route::get('/delivery-kits', [RiderOnboardingController::class,'deliveryKits']);

Route::post('/select-delivery-kit', [RiderOnboardingController::class,'selectDeliveryKit']);

Route::get('/tshirt-sizes', [RiderOnboardingController::class,'tshirtSizes']);

Route::post('/select-tshirt-size', [RiderOnboardingController::class,'selectTshirtSize']);

Route::post('/delivery-address', [RiderOnboardingController::class,'saveDeliveryAddress']);

Route::post('/confirm-delivery-kit', [RiderOnboardingController::class,'confirmDeliveryKit']);

Route::get('/home', [RiderDashboardController::class,'home']);

Route::post('/availability', [RiderDashboardController::class,'updateAvailability']);

Route::post('/live-location', [RiderDashboardController::class,'updateLiveLocation']);

Route::get('/available-orders', [RiderDashboardController::class,'availableOrders']);

Route::get('/dashboard-stats', [RiderDashboardController::class,'dashboardStats']);

Route::get('/earnings', [RiderDashboardController::class,'earnings']);

Route::get('/earnings/analytics', [RiderDashboardController::class,'earningsAnalytics']);

Route::get('/wallet', [RiderDashboardController::class,'wallet']);

Route::get('/wallet/history', [RiderDashboardController::class,'walletHistory']);

Route::post('/cod/settle', [RiderCodController::class, 'settle']);

Route::get('/cod/settlements', [RiderCodController::class, 'settlementHistory']);

// Weekly (Wednesday to Wednesday) settlement history
Route::get('/settlements', [RiderSettlementController::class, 'index']);

Route::get('/settlements/{id}', [RiderSettlementController::class, 'show'])->whereNumber('id');

Route::get('/orders/active', [RiderOrderController::class,'activeOrder']);

Route::get('/orders', [RiderOrderController::class,'orders']);

Route::get('/orders/history', [RiderOrderController::class,'orderHistory']);

Route::post('/orders/{order}/accept', [RiderOrderController::class,'acceptOrder']);

Route::post('/orders/{order}/cancel', [RiderOrderController::class, 'cancelOrder']);

Route::get('/orders/{order}', [ RiderOrderController::class,'orderDetails']);

Route::post('/orders/{order}/arrived', [RiderOrderController::class,'arrivedAtRestaurant']);

Route::get('/orders/{order}/pickup-verification', [RiderOrderController::class,'pickupVerification']);

Route::post('/orders/{order}/verify-pickup-otp', [RiderOrderController::class,'verifyPickupOtp']);

// Route::post('/orders/{order}/picked-up', [
//     RiderOrderController::class,
//     'pickedUpOrder'
// ]);

Route::get('/orders/{order}/delivery-verification', [RiderOrderController::class,'deliveryVerification']);

Route::post('/orders/{order}/verify-delivery-otp', [ RiderOrderController::class,'verifyDeliveryOtp']);

Route::post('/orders/{order}/delivered', [RiderOrderController::class,'deliverOrder']);

Route::post('/bank-details', [RiderBankController::class,'saveBankDetails']);

Route::get('/bank-details', [RiderBankController::class,'getBankDetails']);

Route::post('/upi-details', [RiderUpiController::class, 'saveUpiDetails']);

Route::get('/upi-details', [RiderUpiController::class, 'getUpiDetails']);

Route::get('/profile-details', [RiderProfileController::class, 'profile']);

Route::get('/documents', [RiderProfileController::class, 'documents']);

Route::post('/logout', [RiderProfileController::class, 'logout']);

Route::get('/notifications', [RiderProfileController::class, 'notifications']);

Route::put('/notifications/read-all', [RiderProfileController::class, 'markAllNotificationsRead']);

Route::put('/notifications/{id}/read', [RiderProfileController::class, 'markNotificationRead']);

Route::post('/apply-referral', [RiderProfileController::class, 'applyReferral']);

Route::get('/refer-earn', [RiderProfileController::class, 'referEarn']);

Route::post('/support/tickets', [RiderSupportController::class, 'createTicket']);

Route::get('/support/tickets', [RiderSupportController::class, 'ticketList']);

Route::get('/support/tickets/{ticket}', [RiderSupportController::class, 'ticketDetails']);

Route::post('/support/tickets/{ticket}/messages', [RiderSupportController::class, 'replyToTicket']);

Route::get('/available-shifts', [RiderShiftController::class, 'availableShifts']);

Route::post('/start-shift', [RiderShiftController::class, 'startShift']);

Route::post('/complete-shift', [RiderShiftController::class, 'completeShift']);

// Route::patch(
//     '/rider-bank-details/{bankDetail}/verify',
//     [RiderBankVerificationController::class, 'verify']
// );

// Route::patch(
//     '/rider-bank-details/{bankDetail}/reject',
//     [RiderBankVerificationController::class, 'reject']
// );

    });

// Payment gateway callbacks for customer orders (no customer auth)
Route::match(['GET', 'POST'], '/payment/payu/success', [CustomerPayUController::class,'success',]);
Route::match(['GET', 'POST'], '/payment/payu/failure', [CustomerPayUController::class,'failure',]);
Route::post('/payment/payu/webhook', [CustomerPayUController::class, 'webhook']);

// Payment gateway callbacks & webhook for restaurant advertisements (no auth)
Route::match(['GET', 'POST'], '/restaurant/advertisements/payment/callback', [RestaurantAdvertisementController::class, 'callback']);
Route::post('/restaurant/advertisements/payment/webhook', [RestaurantAdvertisementController::class, 'webhook']);

Route::middleware(['auth:sanctum', 'restaurant.not_suspended'])
    ->prefix('restaurant')
    ->group(function () {

        Route::get('/onboarding-status', [RestaurantOnboardingController::class, 'onboardingStatus']);

        Route::post('/support/tickets', [RestaurantSupportController::class, 'createTicket']);
        Route::get('/support/tickets', [RestaurantSupportController::class, 'ticketList']);
        Route::get('/support/tickets/{ticket}', [RestaurantSupportController::class, 'ticketDetails'])->whereNumber('ticket');
        Route::post('/support/tickets/{ticket}/messages', [RestaurantSupportController::class, 'replyToTicket']);

        Route::post('/profile', [RestaurantOnboardingController::class, 'saveProfile']);

        // Route::get('/address', [RestaurantOnboardingController::class, 'getAddress']);

Route::post('/address', [RestaurantOnboardingController::class, 'saveAddress']);
Route::post('/working-hours', [RestaurantOnboardingController::class, 'saveWorkingHours']);
Route::post('/working-days', [RestaurantOnboardingController::class, 'saveWorkingDays']);

// Documents
Route::get('/outlet-types', [RestaurantOnboardingController::class, 'getOutletTypes']);

Route::get('/documents', [RestaurantOnboardingController::class, 'getDocuments']);

Route::post('/documents', [RestaurantOnboardingController::class, 'saveDocuments']);
// Restaurant Name
Route::get('/name', [RestaurantOnboardingController::class, 'getRestaurantName']);

Route::post('/name', [RestaurantOnboardingController::class, 'saveRestaurantName']);

// Restaurant Categories
Route::post('/categories', [RestaurantCategoryController::class, 'store']);
Route::get('/categories', [RestaurantCategoryController::class, 'index']);
Route::put('/categories/{category}', [RestaurantCategoryController::class, 'update']);
Route::patch('/categories/{category}/status', [RestaurantCategoryController::class, 'toggleStatus']);
Route::delete('/categories/{category}', [RestaurantCategoryController::class, 'destroy']);

Route::post('/menu-items', [RestaurantMenuItemController::class, 'store']);
Route::get('/menu-items', [RestaurantMenuItemController::class, 'index']);
Route::get('/menu-items/{menuItem}', [RestaurantMenuItemController::class, 'show']);
Route::put('/menu-items/{menuItem}', [RestaurantMenuItemController::class, 'update']);
Route::delete('/menu-items/{menuItem}', [RestaurantMenuItemController::class, 'destroy']);
Route::patch('/menu-items/{menuItem}/availability', [RestaurantMenuItemController::class, 'toggleAvailability']);
Route::post('/menu-items/{menuItem}/duplicate', [RestaurantMenuItemController::class, 'duplicate']);


Route::get('/categories/{category}/items', [RestaurantMenuItemController::class, 'categoryItems']);
Route::get('/menu/review', [RestaurantMenuItemController::class, 'reviewMenu']);
Route::get(
    '/partner-contract/terms',
    [PartnerContractController::class, 'terms']
);
Route::post(
    '/onboarding/submit',
    [RestaurantOnboardingController::class, 'submitOnboarding']
);

Route::middleware('restaurant.approved')->group(function () {
Route::get(
    '/dashboard',
    [RestaurantDashboardController::class, 'dashboard']
);

Route::post(
    '/availability',
    [RestaurantDashboardController::class, 'updateAvailability']
);

Route::get(
    '/dashboard/revenue',
    [RestaurantDashboardController::class, 'revenueOverview']
);

Route::get(
    '/dashboard/performance',
    [RestaurantDashboardController::class, 'performance']
);

Route::get(
    '/dashboard/ratings',
    [RestaurantDashboardController::class, 'ratings']
);

Route::get(
    '/dashboard/top-selling-items',
    [RestaurantDashboardController::class, 'topSellingItems']
);

   Route::prefix('revenue')->group(function () {

            Route::get('/summary', [RestaurantRevenueController::class, 'summary']);

            Route::get('/chart', [RestaurantRevenueController::class, 'chart']);

            Route::get('/breakdown', [RestaurantRevenueController::class, 'breakdown']);

            Route::get('/categories', [RestaurantRevenueController::class, 'categories']);

        });

        /*
|--------------------------------------------------------------------------
| Restaurant Orders
|--------------------------------------------------------------------------
*/

Route::prefix('orders')->group(function () {

    Route::get('/', [RestaurantOrderController::class, 'index']);

    Route::get('/pending', [RestaurantOrderController::class, 'pending']);

    Route::get('/preparing', [RestaurantOrderController::class, 'preparing']);

    Route::get('/{order}', [RestaurantOrderController::class, 'show']);

    Route::post('/{order}/accept', [RestaurantOrderController::class, 'accept']);

    Route::post('/{order}/reject', [RestaurantOrderController::class, 'reject']);

    Route::post('/{order}/status', [RestaurantOrderController::class, 'updateStatus']);

});

Route::prefix('ratings')->group(function () {

    Route::get('/summary', [RestaurantRatingController::class, 'summary']);

    Route::get('/highlights', [RestaurantRatingController::class, 'highlights']);

    Route::get('/reviews', [RestaurantRatingController::class, 'reviews']);

    Route::get('/pending-replies', [RestaurantRatingController::class, 'pendingReplies']);

    Route::post('/reply', [RestaurantRatingController::class, 'reply']);

});

Route::prefix('menu')->group(function () {

    Route::get('/dashboard', [RestaurantMenuController::class, 'dashboard']);

});

Route::prefix('profile')->group(function () {

    Route::get('/', [RestaurantProfileController::class, 'index']);

});

Route::get('/notifications', [RestaurantNotificationController::class, 'index']);

Route::patch(
    '/notifications/{notification}/read',
    [RestaurantNotificationController::class, 'markAsRead']
);

Route::patch(
    '/notifications/read-all',
    [RestaurantNotificationController::class, 'markAllAsRead']
);

// Offers set by admin on this restaurant's items
Route::get('/offers', [RestaurantOfferController::class, 'index']);
Route::get('/offers/{id}', [RestaurantOfferController::class, 'show']);

Route::get('/settings/order-management', [RestaurantSettingsController::class, 'orderManagement']);

Route::put('/settings/order-management', [RestaurantSettingsController::class, 'updateOrderManagement']);

Route::get('/settings/online-status', [RestaurantSettingsController::class, 'onlineStatus']);

Route::put('/settings/online-status', [RestaurantSettingsController::class, 'toggleOnlineStatus']);

Route::delete('/settings/delete-account', [RestaurantSettingsController::class, 'deleteAccount']);

Route::get('/wallet', [RestaurantWalletController::class, 'index']);

Route::get('/wallet/earnings-trend', [RestaurantWalletController::class, 'earningsTrend']);

// Credit / debit history and weekly (Monday to Monday) settlements
Route::get('/wallet/history', [RestaurantSettlementController::class, 'walletHistory']);

Route::get('/settlements', [RestaurantSettlementController::class, 'index']);

Route::get('/settlements/{id}', [RestaurantSettlementController::class, 'show'])->whereNumber('id');

// Advertisements / Promote Restaurant (all rules in RestaurantAdvertisementService)
Route::get('/advertisement/packages', [RestaurantAdvertisementController::class, 'packages']);
Route::get('/advertisement/packages/{id}', [RestaurantAdvertisementController::class, 'package'])->whereNumber('id');
Route::get('/advertisement/food-items', [RestaurantAdvertisementController::class, 'foodItems']);
Route::post('/advertisements/payment/verify', [RestaurantAdvertisementController::class, 'verifyPayment']);
Route::get('/advertisements', [RestaurantAdvertisementController::class, 'index']);
Route::post('/advertisements', [RestaurantAdvertisementController::class, 'store']);
Route::get('/advertisements/{id}', [RestaurantAdvertisementController::class, 'show'])->whereNumber('id');
Route::post('/advertisements/{id}/payment', [RestaurantAdvertisementController::class, 'payment'])->whereNumber('id');
Route::post('/advertisements/{id}/cancel', [RestaurantAdvertisementController::class, 'cancel'])->whereNumber('id');

});

    });


Route::middleware(['auth:sanctum', 'customer.not_blocked'])->prefix('customer')->group(function () {
     Route::post('/auth/logout', [CustomerAuthController::class, 'logout']);

    Route::get('/home', [CustomerHomeController::class, 'index']);

    Route::post('/addresses', [CustomerAddressController::class, 'store']);
    Route::get('/addresses', [CustomerAddressController::class, 'index']);
    Route::patch('/addresses/{id}/default', [CustomerAddressController::class, 'setDefault']);
    Route::put('/addresses/{id}', [CustomerAddressController::class, 'update']);
    Route::delete('/addresses/{id}', [CustomerAddressController::class, 'destroy']);
    Route::get('/addresses/{id}', [CustomerAddressController::class, 'show']);

    Route::get('/categories', [CustomerCategoryController::class,'index']);
    Route::get('/popular', [CustomerPopularController::class,'index']);
    Route::get('/popular/all', [CustomerPopularController::class,'all']);
    Route::get('/restaurants', [CustomerRestaurantController::class,'index']);
    Route::get('/restaurants/{id}', [CustomerRestaurantDetailsController::class,'show']);
    Route::get('/search', [CustomerSearchController::class,'index']);
    Route::get('/categories/{categoryId}/restaurants', [CustomerRestaurantController::class, 'byCategory']);
    Route::get('/restaurants/{id}/recommended', [CustomerRestaurantDetailsController::class,'recommended']);
    Route::get('/restaurants/{id}/best-sellers', [CustomerRestaurantDetailsController::class,'bestSellers']);
    Route::get('/restaurants/{id}/popular-categories', [CustomerRestaurantDetailsController::class,'popularCategories']);
    Route::get('/restaurants/{id}/menu', [CustomerRestaurantDetailsController::class,'menu']);
    Route::get('/restaurants/{id}/menu/search', [CustomerRestaurantDetailsController::class,'menuSearch']);
    Route::get('/restaurants/{id}/categories/{categoryId}/menu', [CustomerRestaurantDetailsController::class,'categoryMenu']);
    Route::get('/restaurants/{id}/menu-items/{itemId}', [CustomerRestaurantDetailsController::class,'menuItem']);
    Route::get('/cart', [CustomerCartController::class,'index']);
    Route::post('/cart/items', [CustomerCartController::class,'addItem']);
    Route::put('/cart/items/{itemId}', [CustomerCartController::class,'updateItem']);
    Route::delete('/cart/items/{itemId}', [CustomerCartController::class,'removeItem']);
    Route::delete('/cart', [CustomerCartController::class,'clear']);
    Route::post('/cart/apply-coupon', [CustomerCartController::class, 'applyCoupon']);
    Route::post('/orders', [CustomerOrderController::class, 'store']);
    Route::get('/orders/{id}', [CustomerOrderController::class,'show']);
    Route::get('/orders', [CustomerOrderController::class,'index']);
    Route::post('/orders/{id}/reorder', [CustomerOrderController::class, 'reorder']);
    Route::post('/orders/{id}/cancel', [CustomerOrderController::class, 'cancel']);
    Route::post('/orders/{id}/review', [CustomerReviewController::class, 'store']);
    Route::get('/orders/{id}/review', [CustomerReviewController::class, 'show']);
    Route::get('/reviews', [CustomerReviewController::class, 'index']);
     Route::get('/profile', [CustomerProfileController::class, 'show']);
    Route::put('/profile', [CustomerProfileController::class, 'update']);
    Route::post('/profile/change-phone/send-otp', [CustomerProfileController::class,'sendPhoneChangeOtp']);
Route::post('/profile/change-phone/verify-otp', [CustomerProfileController::class,'verifyPhoneChangeOtp']);
Route::get('/favorites/restaurants',[CustomerFavoriteRestaurantController::class, 'index']);
Route::post('/favorites/restaurants/{restaurantId}',[CustomerFavoriteRestaurantController::class, 'store']);
Route::delete('/favorites/restaurants/{restaurantId}',[CustomerFavoriteRestaurantController::class, 'destroy']);

Route::get('/favorites/foods',[CustomerFavoriteFoodController::class, 'index']);
Route::post('/favorites/foods/{menuItemId}',[CustomerFavoriteFoodController::class, 'store']);
Route::delete('/favorites/foods/{menuItemId}', [CustomerFavoriteFoodController::class, 'destroy']);

Route::get('/notifications',[CustomerNotificationController::class, 'index']);
Route::patch('/notifications/{id}/read',[CustomerNotificationController::class, 'markAsRead']);
Route::patch( '/notifications/read-all',[CustomerNotificationController::class, 'markAllAsRead']);
Route::put('/settings/notifications', [CustomerSettingsController::class, 'updateNotifications']);
Route::get('/settings', [CustomerSettingsController::class, 'show']);

Route::post('/support/tickets', [CustomerSupportController::class, 'createTicket']);
Route::get('/support/tickets', [CustomerSupportController::class, 'ticketList']);
Route::get('/support/tickets/{ticket}', [CustomerSupportController::class, 'ticketDetails']);
Route::post('/support/tickets/{ticket}/messages', [CustomerSupportController::class, 'replyToTicket']);

Route::post('/orders/{id}/payu/initiate',[CustomerPayUController::class, 'initiate']);
Route::post('/orders/{id}/payu/verify', [CustomerPayUController::class, 'verify']);

});

// Alias for PayU order payment routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/orders/{id}/payu/initiate', [CustomerPayUController::class, 'initiate']);
    Route::post('/orders/{id}/payu/verify', [CustomerPayUController::class, 'verify']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
