<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// PayU sends the customer's browser here after payment; the app closes its WebView on this URL.
Route::get('/payment/result', function (\Illuminate\Http\Request $request) {
    $status = in_array($request->query('status'), ['paid', 'failed', 'pending'], true)
        ? $request->query('status')
        : 'pending';

    return view('payment-result', [
        'status' => $status,
        'orderId' => $request->query('order_id'),
    ]);
})->name('payment.result');

// Hosted PayU Payment Link for restaurant advertisement campaigns
Route::get('/pay/advertisement/{id}', [\App\Http\Controllers\Web\AdvertisementPaymentLinkController::class, 'show'])
    ->whereNumber('id')
    ->name('advertisement.pay');

// TEST ROUTE: 1-Click PayU Food Order Checkout
Route::get('/test-payu-order', function () {
    $restaurant = \App\Models\Restaurant::first();
    $customer = \App\Models\User::first() ?? \App\Models\Customer::first();
    if (!$restaurant) {
        return 'No restaurant found.';
    }

    $amount = 10.00;
    $orderNumber = 'VG' . strtoupper(substr(uniqid(), -8));
    $order = \App\Models\RestaurantOrder::create([
        'restaurant_id' => $restaurant->id,
        'customer_id' => $customer ? $customer->id : 1,
        'order_number' => $orderNumber,
        'subtotal' => $amount,
        'total_amount' => $amount,
        'payment_method' => 'online',
        'payment_status' => 'pending',
        'order_status' => 'pending',
        'placed_at' => now(),
    ]);

    $payment = \App\Models\RestaurantOrderPayment::create([
        'order_id' => $order->id,
        'payment_method' => 'online',
        'payment_status' => 'PENDING',
        'order_amount' => $amount,
        'total_amount' => $amount,
        'payment_gateway' => 'PAYU',
    ]);

    $key = config('services.payu.key');
    $salt = config('services.payu.salt');
    $txnid = 'VG' . strtoupper(\Illuminate\Support\Str::uuid());
    $amountFormatted = number_format($amount, 2, '.', '');
    $productinfo = 'VegGo Order ' . $order->id;
    $firstname = $customer && !empty($customer->name) ? $customer->name : 'VegGo Customer';
    $email = $customer && !empty($customer->email) ? $customer->email : 'test@veggo.com';
    $phone = $customer && !empty($customer->phone) ? $customer->phone : '9876543210';

    $hashString = implode('|', [
        $key, $txnid, $amountFormatted, $productinfo, $firstname, $email,
        '', '', '', '', '', '', '', '', '', '',
        $salt,
    ]);
    $hash = strtolower(hash('sha512', $hashString));

    $payment->update([
        'transaction_id' => $txnid,
    ]);

    $baseUrl = rtrim((string) config('app.url'), '/');
    $surl = $baseUrl . '/api/payment/payu/success';
    $furl = $baseUrl . '/api/payment/payu/failure';
    $action = config('services.payu.base_url', 'https://test.payu.in/_payment');

    return view('payu-checkout-form', [
        'title' => 'Test Food Order Payment',
        'subtitle' => 'Order #' . $order->id . ' (' . $orderNumber . ') - Amount: ₹' . $amountFormatted,
        'action' => $action,
        'params' => [
            'key' => $key,
            'txnid' => $txnid,
            'amount' => $amountFormatted,
            'productinfo' => $productinfo,
            'firstname' => $firstname,
            'email' => $email,
            'phone' => $phone,
            'surl' => $surl,
            'furl' => $furl,
            'hash' => $hash,
        ],
    ]);
});

// TEST ROUTE: 1-Click PayU Advertisement Campaign Checkout
Route::get('/test-payu-ad', function () {
    $restaurant = \App\Models\Restaurant::first();
    if (!$restaurant) {
        return 'No restaurant found.';
    }

    $package = \App\Models\RestaurantAdPackage::where('code', 'FEATURED_RESTAURANT')->first()
        ?? \App\Models\RestaurantAdPackage::first();
    $duration = $package->durations->first();

    $service = app(\App\Services\Restaurant\RestaurantAdvertisementService::class);
    $ad = $service->create($restaurant, [
        'package_id' => $package->id,
        'package_duration_id' => $duration->id,
    ], null, 'restaurant', $restaurant->id);

    $init = $service->initiatePayment($restaurant, $ad->id);
    $checkout = $init['checkout'];
    $params = $checkout['params'];

    return view('payu-checkout-form', [
        'title' => 'Test Restaurant Advertisement Payment',
        'subtitle' => 'Ad #' . $ad->id . ' (' . $package->name . ') - Total (with GST): ₹' . number_format($ad->total_amount, 2),
        'action' => $params['action'] ?? config('services.payu.base_url'),
        'params' => [
            'key' => $params['key'],
            'txnid' => $params['txnid'],
            'amount' => $params['amount'],
            'productinfo' => $params['productinfo'],
            'firstname' => $params['firstname'],
            'email' => $params['email'],
            'phone' => $params['phone'],
            'surl' => $params['surl'],
            'furl' => $params['furl'],
            'hash' => $params['hash'],
        ],
    ]);
});

