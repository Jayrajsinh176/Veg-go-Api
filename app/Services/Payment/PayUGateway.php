<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PayUGateway implements PaymentGateway
{
    public function __construct(
        private ?string $key = null,
        private ?string $salt = null,
        private ?string $baseUrl = null,
        private ?string $verifyUrl = null,
    ) {
        $this->key = $this->key ?: (string) config('services.payu.key');
        $this->salt = $this->salt ?: (string) config('services.payu.salt');
        $this->baseUrl = $this->baseUrl ?: (string) config('services.payu.base_url', 'https://test.payu.in/_payment');
        $this->verifyUrl = $this->verifyUrl ?: (string) config('services.payu.verify_url', 'https://test.payu.in/merchant/postservice?form=2');
    }

    public function name(): string
    {
        return 'payu';
    }

    public function createOrder(int $amountInPaise, string $currency, string $receipt, array $notes = []): array
    {
        $this->ensureConfigured();

        $amountInRupees = number_format($amountInPaise / 100, 2, '.', '');
        $txnid = $receipt ?: ('VGAD' . date('YmdHis') . strtoupper(Str::random(6)));

        $productinfo = $notes['package'] ?? 'VegGo Advertisement';
        $firstname = trim((string) ($notes['restaurant_name'] ?? 'Restaurant'));
        $email = trim((string) ($notes['email'] ?? 'restaurant@veggo.com'));
        $phone = trim((string) ($notes['mobile'] ?? ''));

        $baseUrl = rtrim((string) config('app.url'), '/');
        $surl = $baseUrl . '/api/restaurant/advertisements/payment/callback';
        $furl = $baseUrl . '/api/restaurant/advertisements/payment/callback';

        // PayU hosted checkout request hash: key|txnid|amount|productinfo|firstname|email|udf1..udf5||||||salt
        $hashString = implode('|', [
            $this->key,
            $txnid,
            $amountInRupees,
            $productinfo,
            $firstname,
            $email,
            '', '', '', '', '',
            '', '', '', '', '',
            $this->salt,
        ]);

        $hash = strtolower(hash('sha512', $hashString));

        $payuParams = [
            'key' => $this->key,
            'txnid' => $txnid,
            'amount' => $amountInRupees,
            'productinfo' => $productinfo,
            'firstname' => $firstname,
            'email' => $email,
            'phone' => $phone,
            'surl' => $surl,
            'furl' => $furl,
            'hash' => $hash,
            'action' => $this->baseUrl,
        ];

        return [
            'id' => $txnid,
            'amount' => $amountInPaise,
            'currency' => $currency,
            'raw' => $payuParams,
        ];
    }

    public function verifyCheckoutSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $this->ensureConfigured();

        // PayU transaction integrity is verified server-to-server in fetchPayment().
        return true;
    }

    public function fetchPayment(string $paymentId, string $orderId): array
    {
        $this->ensureConfigured();

        $txnid = $orderId ?: $paymentId;
        $command = 'verify_payment';

        $hash = hash(
            'sha512',
            $this->key . '|' . $command . '|' . $txnid . '|' . $this->salt
        );

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post($this->verifyUrl, [
                'key' => $this->key,
                'command' => $command,
                'var1' => $txnid,
                'hash' => $hash,
            ]);

        if (!$response->successful()) {
            throw new PaymentGatewayException('PayU verification returned HTTP ' . $response->status());
        }

        $body = $response->json();
        $details = $body['transaction_details'][$txnid] ?? null;

        if (!is_array($body) || (int) ($body['status'] ?? 0) !== 1 || !is_array($details)) {
            throw new PaymentGatewayException('Unable to confirm payment with PayU.');
        }

        $statusStr = strtolower((string) ($details['status'] ?? ''));
        $unmapped = strtolower((string) ($details['unmappedstatus'] ?? ''));

        $isPaid = $statusStr === 'success';
        $isFailed = in_array($statusStr, ['failure', 'failed'], true)
            || in_array($unmapped, ['failed', 'usercancelled', 'bounced', 'dropped'], true);

        $status = match (true) {
            $isPaid => PaymentGateway::STATUS_PAID,
            $isFailed => PaymentGateway::STATUS_FAILED,
            default => PaymentGateway::STATUS_PENDING,
        };

        $amt = (float) ($details['amount'] ?? $details['amt'] ?? 0);
        $amountInPaise = (int) round($amt * 100);

        return [
            'id' => (string) ($details['mihpayid'] ?? $paymentId ?: $txnid),
            'order_id' => $txnid,
            'status' => $status,
            'amount' => $amountInPaise,
            'currency' => 'INR',
            'method' => (string) ($details['mode'] ?? 'online'),
            'error' => $details['error_Message'] ?? $details['error'] ?? null,
            'raw' => $details,
        ];
    }

    public function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        $this->ensureConfigured();

        return true;
    }

    public function refund(string $paymentId, float $amount, string $refundId): array
    {
        $this->ensureConfigured();

        $command = 'cancel_refund_transaction';
        $amountStr = number_format($amount, 2, '.', '');

        $hash = hash(
            'sha512',
            $this->key . '|' . $command . '|' . $paymentId . '|' . $this->salt
        );

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post($this->verifyUrl, [
                'key' => $this->key,
                'command' => $command,
                'var1' => $paymentId,
                'var2' => $refundId,
                'var3' => $amountStr,
                'hash' => $hash,
            ]);

        if (!$response->successful()) {
            throw new PaymentGatewayException('PayU refund request returned HTTP ' . $response->status());
        }

        $body = $response->json();
        $status = (int) ($body['status'] ?? 0);
        $msg = $body['msg'] ?? $body['message'] ?? 'Refund processed';

        if ($status !== 1 && !str_contains(strtolower((string) $msg), 'success')) {
            Log::warning('PayU refund non-success response', [
                'txnid' => $paymentId,
                'refund_id' => $refundId,
                'response' => $body,
            ]);
        }

        return [
            'success' => $status === 1 || str_contains(strtolower((string) $msg), 'success'),
            'refund_id' => (string) ($body['request_id'] ?? $refundId),
            'status' => $status === 1 ? 'completed' : 'processing',
            'message' => (string) $msg,
            'raw' => is_array($body) ? $body : [],
        ];
    }

    public function checkoutConfig(): array
    {
        return [
            'gateway' => 'payu',
            'key' => $this->key,
            'base_url' => $this->baseUrl,
            'mode' => config('services.payu.mode', 'sandbox'),
        ];
    }

    private function ensureConfigured(): void
    {
        if (empty($this->key) || empty($this->salt)) {
            throw new PaymentGatewayException('PayU key and salt must be configured in services.payu.');
        }
    }
}

