<?php

namespace App\Services\Payment;

interface PaymentGateway
{
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';

    /**
     * Short name stored with each payment (payu, fake, ...).
     */
    public function name(): string;

    /**
     * Create a gateway order the app opens the checkout with.
     *
     * @return array{id: string, amount: int, currency: string, raw: array}
     */
    public function createOrder(int $amountInPaise, string $currency, string $receipt, array $notes = []): array;

    /**
     * Check the signature the checkout returns to the app.
     */
    public function verifyCheckoutSignature(string $orderId, string $paymentId, string $signature): bool;

    /**
     * Ask the gateway for the real state of a payment (captures an
     * authorized payment when needed).
     *
     * @return array{id: string, order_id: ?string, status: string, amount: int, currency: string, method: ?string, error: ?string, raw: array}
     */
    public function fetchPayment(string $paymentId, string $orderId): array;

    /**
     * Check a webhook request body against its signature header.
     */
    public function verifyWebhookSignature(string $payload, ?string $signature): bool;

    /**
     * Values the app needs to open the checkout (public key only).
     */
    public function checkoutConfig(): array;
}
