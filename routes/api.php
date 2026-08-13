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
use App\Http\Controllers\Api\Rider\RiderWithdrawalController;
use App\Http\Controllers\Api\Rider\RiderProfileController;
use App\Http\Controllers\Api\Rider\RiderSupportController;
use App\Http\Controllers\Api\Rider\RiderShiftController;


// Admin Side controller
use App\Http\Controllers\Api\Admin\RiderBankVerificationController;

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
use App\Http\Controllers\Api\Restaurant\RestaurantSettingsController;
use App\Http\Controllers\Api\Restaurant\RestaurantWalletController;

// CUSTOMER APIS CONTOLLERS
use App\Http\Controllers\Api\Customer\CustomerAuthController;
use App\Http\Controllers\Api\Customer\CustomerHomeController;
use App\Http\Controllers\Api\Customer\CustomerAddressController;
use App\Http\Controllers\Api\Customer\CustomerCategoryController;
use App\Http\Controllers\Api\Customer\CustomerPopularController;
use App\Http\Controllers\Api\Customer\CustomerRestaurantController;
use App\Http\Controllers\Api\Customer\CustomerRestaurantDetailsController;
use App\Http\Controllers\Api\Customer\CustomerSearchController;

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

});


Route::middleware('auth:sanctum')
    ->prefix('rider')
    ->group(function () {

Route::get('/cities', [RiderOnboardingController::class,'cities']);

Route::post('/select-city', [RiderOnboardingController::class,'selectCity']);

Route::get('/onboarding-status', [RiderOnboardingController::class,'onboardingStatus']);

Route::get('/vehicle-types', [RiderOnboardingController::class, 'vehicleTypes']);

Route::post('/work-settings', [RiderOnboardingController::class,'saveWorkSettings']);

Route::post('/profile', [RiderOnboardingController::class,'saveProfile']);

Route::get('/work-zones', [RiderOnboardingController::class,'workZones']);

Route::post('/select-work-zone', [RiderOnboardingController::class,'selectWorkZone']);

Route::get('/order-types', [RiderOnboardingController::class,'orderTypes']);

Route::post('/select-order-type', [RiderOnboardingController::class,'selectOrderType']);

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

Route::get('/orders/active', [RiderOrderController::class,'activeOrder']);

Route::get('/orders', [RiderOrderController::class,'orders']);

Route::get('/orders/history', [RiderOrderController::class,'orderHistory']);

Route::post('/orders/{order}/accept', [RiderOrderController::class,'acceptOrder']);

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

Route::post('/withdrawals/request', [RiderWithdrawalController::class, 'requestWithdrawal']);

Route::get('/withdrawals', [RiderWithdrawalController::class, 'withdrawalHistory']);

Route::get('/profile', [RiderProfileController::class, 'profile']);

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


Route::middleware('auth:sanctum')
    ->prefix('restaurant')
    ->group(function () {

        Route::get('/onboarding-status', [RestaurantOnboardingController::class, 'onboardingStatus']);

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

Route::get('/settings/order-management', [RestaurantSettingsController::class, 'orderManagement']);

Route::put('/settings/order-management', [RestaurantSettingsController::class, 'updateOrderManagement']);

Route::get('/settings/online-status', [RestaurantSettingsController::class, 'onlineStatus']);

Route::put('/settings/online-status', [RestaurantSettingsController::class, 'toggleOnlineStatus']);

Route::delete('/settings/delete-account', [RestaurantSettingsController::class, 'deleteAccount']);

Route::get('/wallet', [RestaurantWalletController::class, 'index']);

Route::get('/wallet/earnings-trend', [RestaurantWalletController::class, 'earningsTrend']);

    });
Route::middleware('auth:sanctum')->prefix('customer')->group(function () {

    Route::get('/home', [CustomerHomeController::class, 'index']);

    Route::post('/addresses', [CustomerAddressController::class, 'store']);
    Route::get('/addresses', [CustomerAddressController::class, 'index']);
    Route::patch('/addresses/{id}/default', [CustomerAddressController::class, 'setDefault']);
    Route::put('/addresses/{id}', [CustomerAddressController::class, 'update']);
    Route::delete('/addresses/{id}', [CustomerAddressController::class, 'destroy']);

    Route::get('/categories', [CustomerCategoryController::class,'index']);
    Route::get('/popular', [CustomerPopularController::class,'index']);
    Route::get('/restaurants', [CustomerRestaurantController::class,'index']);
    Route::get('/restaurants/{id}', [CustomerRestaurantDetailsController::class,'show']);
    Route::get('/search', [CustomerSearchController::class,'index']);
    Route::get('/categories/{categoryId}/restaurants', [CustomerRestaurantController::class, 'byCategory']);


});
    
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
