<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderPayment;
use App\Services\Payment\PayUOrderPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerPayUController extends Controller
{
    public function __construct(private PayUOrderPaymentService $payU)
    {
    }

public function initiate(Request $request, $id)
{
    $key = config('services.payu.key');
    $salt = config('services.payu.salt');

    if (empty($key) || empty($salt)) {
        return response()->json([
            'success' => false,
            'message' => 'PayU credentials are not configured.',
        ], 500);
    }

    $order = RestaurantOrder::with('payment')->find($id);

    if (!$order) {
        return response()->json([
            'success' => false,
            'message' => 'Order not found.',
        ], 404);
    }

    // Ensure the authenticated customer owns this order.
    if ((int) $order->customer_id !== (int) $request->user()->id) {
        return response()->json([
            'success' => false,
            'message' => 'You are not authorized to pay for this order.',
        ], 403);
    }

    $payment = $order->payment;

    if (!$payment) {
        return response()->json([
            'success' => false,
            'message' => 'Payment record not found.',
        ], 422);
    }

    if (strtoupper((string) $payment->payment_method) !== 'ONLINE') {
        return response()->json([
            'success' => false,
            'message' => 'This order is not configured for online payment.',
        ], 422);
    }

    // A previous attempt may have succeeded without its callback reaching us.
    if (strtoupper((string) $payment->payment_status) !== 'PAID') {
        $this->payU->reconcile($payment);
        $payment->refresh();
        $order->refresh();
    }

    // A late payment found above is already recorded for refund
    if ($order->order_status === 'cancelled') {
        return response()->json([
            'success' => false,
            'message' => 'This order has been cancelled. Please place a new order.',
        ], 422);
    }

    if (
        strtoupper((string) $payment->payment_status) === 'PAID' ||
        strtolower((string) $order->payment_status) === 'paid'
    ) {
        return response()->json([
            'success' => false,
            'message' => 'This order has already been paid.',
        ], 422);
    }

    $amount = number_format((float) $payment->total_amount, 2, '.', '');

    if ((float) $amount <= 0) {
        return response()->json([
            'success' => false,
            'message' => 'Payment amount must be greater than zero.',
        ], 422);
    }

    $customer = $request->user();

    $firstname = trim((string) ($customer->name ?? 'Customer'));
    $email = trim((string) ($customer->email ?? ''));
    if ($email === '') {
        $email = trim((string) $request->input('email', ''));
    }
    if ($email === '') {
        $email = 'customer' . $customer->id . '@veggo.in';
    }
    $phone = trim((string) ($customer->phone ?? ''));

    $txnid = 'VG' . strtoupper((string) Str::uuid());
    $productinfo = 'VegGo Order ' . $order->id;

    // PayU hosted checkout request hash.
    $hashString = implode('|', [
        $key,
        $txnid,
        $amount,
        $productinfo,
        $firstname,
        $email,
        '', '', '', '', '',
        '', '', '', '', '',
        $salt,
    ]);

    $hash = strtolower(hash('sha512', $hashString));

    $payment->update([
        'transaction_id' => $txnid,
        'payment_gateway' => 'PAYU',
        'payment_status' => 'PENDING',
    ]);

    // A retry after a failed attempt is pending again.
    if (strtolower((string) $order->payment_status) === 'failed') {
        $order->update(['payment_status' => 'pending']);
    }

    $baseUrl = rtrim((string) config('app.url'), '/');
    $surl = (string) (config('services.payu.surl') ?: ($baseUrl . '/api/payment/payu/success'));
    $furl = (string) (config('services.payu.furl') ?: ($baseUrl . '/api/payment/payu/failure'));

    return response()->json([
        'success' => true,
        'message' => 'PayU payment initiated.',
        'data' => [
            'payment_url' => config('services.payu.base_url'),
            'result_url' => $this->resultUrl(),
            'params' => [
                'key' => $key,
                'txnid' => $txnid,
                'amount' => $amount,
                'productinfo' => $productinfo,
                'firstname' => $firstname,
                'email' => $email,
                'phone' => $phone,
                'surl' => $surl,
                'furl' => $furl,
                'hash' => $hash,
            ],
        ],
    ]);
}

/**
 * Customer asks the backend to re-check the payment with PayU,
 * e.g. when the app reopens or the result was "pending".
 */
public function verify(Request $request, $id)
{
    $order = RestaurantOrder::with('payment')->find($id);

    if (!$order || (int) $order->customer_id !== (int) $request->user()->id) {
        return response()->json([
            'success' => false,
            'message' => 'Order not found.',
        ], 404);
    }

    $payment = $order->payment;

    if (!$payment || strtoupper((string) $payment->payment_method) !== 'ONLINE') {
        return response()->json([
            'success' => false,
            'message' => 'This order is not configured for online payment.',
        ], 422);
    }

    // A cancelled order's payment may have been failed by us (auto-expiry),
    // not by PayU, so still ask PayU whether the money came through
    if (
        strtoupper((string) $payment->payment_status) === 'FAILED' &&
        strtolower((string) $order->payment_status) === 'failed' &&
        $order->order_status !== 'cancelled'
    ) {
        $status = PayUOrderPaymentService::FAILED;
    } else {
        $status = $this->payU->reconcile($payment);
    }

    $order->refresh();

    $messages = [
        PayUOrderPaymentService::PAID => 'Payment verified successfully.',
        PayUOrderPaymentService::FAILED => 'Payment failed.',
        PayUOrderPaymentService::PENDING => 'Payment is pending or could not be confirmed yet.',
    ];

    return response()->json([
        'success' => $status === PayUOrderPaymentService::PAID,
        'message' => $this->resultMessage($status, $order, $messages[$status]),
        'data' => [
            'order_id' => $order->id,
            'payment_status' => $status,
            'order_status' => $order->order_status,
        ],
    ]);
}

/**
 * PayU success callback (customer's browser is redirected here).
 */
public function success(Request $request)
{
    Log::info('PayU success callback reached', $this->logContext($request));

    return $this->respondToBrowser($request, $this->processCallback($request));
}

/**
 * PayU failure callback (customer's browser is redirected here).
 */
public function failure(Request $request)
{
    Log::info('PayU failure callback reached', $this->logContext($request));

    return $this->respondToBrowser($request, $this->processCallback($request));
}

/**
 * PayU server-to-server webhook (configured in the PayU dashboard).
 */
public function webhook(Request $request)
{
    Log::info('PayU webhook reached', $this->logContext($request));

    $result = $this->processCallback($request);

    // Always 200 so PayU does not keep retrying requests we have handled.
    return response()->json([
        'success' => $result['status'] === PayUOrderPaymentService::PAID,
        'message' => $result['message'],
        'data' => [
            'order_id' => $result['order_id'],
            'payment_status' => $result['status'],
        ],
    ]);
}

/**
 * Validate a PayU callback/webhook and confirm it server-side.
 *
 * @return array{status: string, order_id: int|null, message: string}
 */
private function processCallback(Request $request): array
{
    $key = (string) config('services.payu.key');
    $salt = (string) config('services.payu.salt');

    $txnid = (string) $request->input('txnid', '');
    $responseKey = (string) $request->input('key', '');
    $status = strtolower((string) $request->input('status', ''));
    $responseHash = strtolower((string) $request->input('hash', ''));

    $invalid = fn (string $message, $orderId = null) => [
        'status' => PayUOrderPaymentService::PENDING,
        'order_id' => $orderId,
        'message' => $message,
    ];

    if (
        $key === '' ||
        $salt === '' ||
        $txnid === '' ||
        $responseKey !== $key ||
        $responseHash === ''
    ) {
        return $invalid('Invalid PayU callback.');
    }

    /*
     * Standard PayU reverse hash for a response without
     * additional charges:
     * salt|status||||||udf5|udf4|udf3|udf2|udf1|
     * email|firstname|productinfo|amount|txnid|key
     */
    $reverseHashString = implode('|', [
        $salt,
        $status,
        '', '', '', '', '',
        (string) $request->input('udf5', ''),
        (string) $request->input('udf4', ''),
        (string) $request->input('udf3', ''),
        (string) $request->input('udf2', ''),
        (string) $request->input('udf1', ''),
        (string) $request->input('email', ''),
        (string) $request->input('firstname', ''),
        (string) $request->input('productinfo', ''),
        (string) $request->input('amount', ''),
        $txnid,
        $key,
    ]);

    $additionalCharges = (string) $request->input(
        'additionalCharges',
        $request->input('additional_charges', '')
    );

    if ($additionalCharges !== '') {
        $reverseHashString = $additionalCharges . '|' . $reverseHashString;
    }

    $calculatedHash = strtolower(hash('sha512', $reverseHashString));

    if (!hash_equals($calculatedHash, $responseHash)) {
        Log::warning('PayU callback hash validation failed', ['txnid' => $txnid]);

        return $invalid('PayU callback hash validation failed.');
    }

    $rawProductInfo = (string) $request->input('productinfo', '');
    $cleanCallbackProductInfo = trim(preg_replace('/\s+/', ' ', str_replace('#', ' ', $rawProductInfo)));

    $payment = RestaurantOrderPayment::where('transaction_id', $txnid)
        ->where('payment_gateway', 'PAYU')
        ->first();

    // An older attempt of an order the customer has since retried:
    // find the order from the (hash-verified) product info.
    if (!$payment && preg_match('/^VegGo Order (\d+)$/', $cleanCallbackProductInfo, $matches)) {
        $payment = RestaurantOrderPayment::where('order_id', (int) $matches[1])
            ->where('payment_gateway', 'PAYU')
            ->first();
    }

    if (!$payment) {
        return $invalid('Payment record not found.');
    }

    $order = RestaurantOrder::find($payment->order_id);

    if (!$order) {
        return $invalid('Order not found.');
    }

    $callbackAmount = number_format(
        (float) $request->input('amount', 0),
        2,
        '.',
        ''
    );

    $expectedAmount = number_format(
        (float) $payment->total_amount,
        2,
        '.',
        ''
    );

    $cleanExpectedProductInfo = 'VegGo Order ' . $order->id;

    $matchesProductInfo = (
        $cleanCallbackProductInfo === $cleanExpectedProductInfo ||
        $rawProductInfo === 'VegGo Order #' . $order->id ||
        $rawProductInfo === $cleanExpectedProductInfo ||
        str_contains($cleanCallbackProductInfo, 'VegGo Order ' . $order->id) ||
        ($order->order_number && str_contains($cleanCallbackProductInfo, (string) $order->order_number))
    );

    if (
        $callbackAmount !== $expectedAmount ||
        !$matchesProductInfo
    ) {
        Log::warning('PayU callback validation mismatch', [
            'txnid' => $txnid,
            'callback_amount' => $callbackAmount,
            'expected_amount' => $expectedAmount,
            'callback_productinfo' => $rawProductInfo,
            'expected_productinfo' => $cleanExpectedProductInfo,
        ]);

        return $invalid('PayU callback does not match the order.', $order->id);
    }

    // Never downgrade a payment already confirmed as paid.
    if (
        strtoupper((string) $payment->payment_status) === 'PAID' &&
        strtolower((string) $order->payment_status) === 'paid'
    ) {
        return [
            'status' => PayUOrderPaymentService::PAID,
            'order_id' => $order->id,
            'order_status' => $order->order_status,
            'message' => $this->resultMessage(PayUOrderPaymentService::PAID, $order, 'Payment already confirmed.'),
        ];
    }

    try {
        $verified = $this->payU->verifyTransaction($txnid);
    } catch (\Throwable $e) {
        Log::error('PayU server-to-server verification error', [
            'txnid' => $txnid,
            'error' => $e->getMessage(),
        ]);
        report($e);

        // A verification service error does not prove failure.
        return $invalid('Payment verification is pending. Please check again.', $order->id);
    }

    $result = $this->payU->applyVerification($payment, $order, $txnid, $verified, $status);

    $order->refresh();

    $messages = [
        PayUOrderPaymentService::PAID => 'Payment verified successfully.',
        PayUOrderPaymentService::FAILED => 'PayU reports that the payment failed.',
        PayUOrderPaymentService::PENDING => 'Payment status is pending or could not be confirmed.',
    ];

    return [
        'status' => $result,
        'order_id' => $order->id,
        'order_status' => $order->order_status,
        'message' => $this->resultMessage($result, $order, $messages[$result]),
    ];
}

/**
 * A payment that succeeded for an already-cancelled order is refunded,
 * so the app must not show it as placed.
 */
private function resultMessage(string $status, RestaurantOrder $order, string $default): string
{
    if ($status === PayUOrderPaymentService::PAID && $order->order_status === 'cancelled') {
        return 'Payment received, but this order was already cancelled. Your amount will be refunded.';
    }

    return $default;
}

/**
 * Send the customer's browser to the result page; API clients
 * (Accept: application/json) still get JSON for testing.
 */
private function respondToBrowser(Request $request, array $result)
{
    if ($request->expectsJson()) {
        $codes = [
            PayUOrderPaymentService::PAID => 200,
            PayUOrderPaymentService::FAILED => 402,
            PayUOrderPaymentService::PENDING => 202,
        ];

        return response()->json([
            'success' => $result['status'] === PayUOrderPaymentService::PAID,
            'message' => $result['message'],
            'data' => [
                'order_id' => $result['order_id'],
                'payment_status' => $result['status'],
                'order_status' => $result['order_status'] ?? null,
            ],
        ], $codes[$result['status']]);
    }

    $query = array_filter([
        'status' => $result['status'],
        'order_id' => $result['order_id'],
        'order_status' => $result['order_status'] ?? null,
    ], fn ($value) => $value !== null);

    // 303 so the browser follows PayU's POST with a GET.
    return redirect()->to($this->resultUrl() . '?' . http_build_query($query), 303);
}

private function resultUrl(): string
{
    return (string) (config('services.payu.result_url')
        ?: rtrim((string) config('app.url'), '/') . '/payment/result');
}

private function logContext(Request $request): array
{
    return [
        'method' => $request->method(),
        'txnid' => $request->input('txnid'),
        'status' => $request->input('status'),
        'amount' => $request->input('amount'),
        'productinfo' => $request->input('productinfo'),
    ];
}

}
