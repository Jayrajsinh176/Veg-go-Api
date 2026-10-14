<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Local development gateway (AD_PAYMENT_DRIVER=fake). Never bound in
 * production. The app "pays" by signing order_id|payment_id with
 * FakePaymentGateway::SECRET; a payment id starting with "fake_fail_"
 * comes back as failed.
 */
class FakePaymentGateway implements PaymentGateway
{
    public const SECRET = 'veggo-fake-gateway';

    public function name(): string
    {
        return 'fake';
    }

    public function createOrder(int $amountInPaise, string $currency, string $receipt, array $notes = []): array
    {
        $id = 'fake_order_' . Str::random(14);

        Cache::put('fake_gateway_order:' . $id, ['amount' => $amountInPaise, 'currency' => $currency], now()->addDay());

        return [
            'id' => $id,
            'amount' => $amountInPaise,
            'currency' => $currency,
            'raw' => ['id' => $id, 'receipt' => $receipt, 'notes' => $notes],
        ];
    }

    public function verifyCheckoutSignature(string $orderId, string $paymentId, string $signature): bool
    {
        return hash_equals(self::sign($orderId, $paymentId), $signature);
    }

    public function fetchPayment(string $paymentId, string $orderId): array
    {
        $order = Cache::get('fake_gateway_order:' . $orderId);

        if (!$order) {
            throw new PaymentGatewayException('Unknown fake order.');
        }

        $failed = str_starts_with($paymentId, 'fake_fail_');

        return [
            'id' => $paymentId,
            'order_id' => $orderId,
            'status' => $failed ? self::STATUS_FAILED : self::STATUS_PAID,
            'amount' => $order['amount'],
            'currency' => $order['currency'],
            'method' => 'fake',
            'error' => $failed ? 'Fake payment failure.' : null,
            'raw' => ['id' => $paymentId, 'order_id' => $orderId],
        ];
    }

    public function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        return $signature !== null && hash_equals(hash_hmac('sha256', $payload, self::SECRET), $signature);
    }

    public function checkoutConfig(): array
    {
        return [
            'gateway' => $this->name(),
            'key_id' => null,
        ];
    }

    public static function sign(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId . '|' . $paymentId, self::SECRET);
    }
}
