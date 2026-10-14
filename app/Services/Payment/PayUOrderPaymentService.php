<?php

namespace App\Services\Payment;

use App\Models\OrderStatusHistory;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Confirms customer order payments with PayU and updates the
 * payment/order records. Shared by the browser callbacks, the
 * PayU webhook, the customer verify API and the reconcile job.
 */
class PayUOrderPaymentService
{
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const PENDING = 'pending';

    /**
     * Ask PayU for the latest state of the payment's current
     * transaction and apply it. Never throws.
     */
    public function reconcile(RestaurantOrderPayment $payment): string
    {
        if (strtoupper((string) $payment->payment_status) === 'PAID') {
            return self::PAID;
        }

        $txnid = (string) $payment->transaction_id;

        if ($txnid === '' || strtoupper((string) $payment->payment_gateway) !== 'PAYU') {
            return self::PENDING;
        }

        $order = RestaurantOrder::find($payment->order_id);

        if (!$order) {
            return self::PENDING;
        }

        try {
            $verified = $this->verifyTransaction($txnid);
        } catch (\Throwable $e) {
            Log::warning('PayU reconcile could not verify transaction', [
                'txnid' => $txnid,
                'error' => $e->getMessage(),
            ]);

            return self::PENDING;
        }

        return $this->applyVerification($payment, $order, $txnid, $verified);
    }

    /**
     * Update payment/order from a PayU verify_payment result.
     *
     * A failure only marks the payment failed when $txnid is still the
     * payment's current transaction, so an old attempt can never
     * overwrite a newer retry. A verified success always wins.
     */
    public function applyVerification(
        RestaurantOrderPayment $payment,
        RestaurantOrder $order,
        string $txnid,
        array $verified,
        ?string $callbackStatus = null
    ): string {
        $expectedAmount = number_format((float) $payment->total_amount, 2, '.', '');

        $verifiedStatus = strtolower((string) ($verified['status'] ?? ''));
        $verifiedTxnid = (string) ($verified['txnid'] ?? '');
        $verifiedAmount = $verified['amt'] ?? $verified['amount'] ?? null;
        $unmappedStatus = strtolower((string) ($verified['unmappedstatus'] ?? ''));

        $isVerifiedSuccess =
            $verifiedStatus === 'success' &&
            $verifiedTxnid === $txnid &&
            $verifiedAmount !== null &&
            number_format((float) $verifiedAmount, 2, '.', '') === $expectedAmount;

        if ($isVerifiedSuccess) {
            DB::transaction(function () use ($payment, $order, $txnid) {
                $lockedPayment = RestaurantOrderPayment::whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedOrder = RestaurantOrder::whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (strtoupper((string) $lockedPayment->payment_status) !== 'PAID') {
                    $lockedPayment->update([
                        'transaction_id' => $txnid,
                        'payment_gateway' => 'PAYU',
                        'payment_status' => 'PAID',
                        'paid_at' => now(),
                    ]);

                    $lockedOrder->update([
                        'payment_status' => 'paid',
                    ]);

                    $notifications = app(\App\Services\Notification\OrderNotificationService::class);

                    // Money was taken for an order that is already cancelled
                    // (e.g. auto-expired): record it, keep the order cancelled
                    if ($lockedOrder->order_status === 'cancelled') {
                        OrderStatusHistory::create([
                            'order_id'   => $lockedOrder->id,
                            'status'     => 'cancelled',
                            'changed_by' => 'system',
                            'remarks'    => 'Payment received after cancellation (PayU txn ' . $txnid . ') - refund needed.',
                        ]);

                        Log::warning('PayU payment received for cancelled order, refund needed', [
                            'order_id'     => $lockedOrder->id,
                            'order_number' => $lockedOrder->order_number,
                            'txnid'        => $txnid,
                        ]);

                        $notifications->paymentReceivedForCancelledOrder($lockedOrder);
                    } else {
                        // Notify restaurant and customer that paid online order is confirmed
                        $notifications->orderPlaced($lockedOrder);
                    }
                }
            });

            return self::PAID;
        }

        $isFailure =
            in_array($verifiedStatus, ['failure', 'failed'], true) ||
            in_array($unmappedStatus, ['failed', 'usercancelled', 'bounced', 'dropped'], true) ||
            $callbackStatus === 'failure';

        if ($isFailure) {
            $markedFailed = DB::transaction(function () use ($payment, $order, $txnid) {
                $lockedPayment = RestaurantOrderPayment::whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedOrder = RestaurantOrder::whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (strtoupper((string) $lockedPayment->payment_status) === 'PAID') {
                    return false;
                }

                // An older attempt failed but the customer has already retried.
                if ((string) $lockedPayment->transaction_id !== $txnid) {
                    return false;
                }

                $lockedPayment->update([
                    'payment_status' => 'FAILED',
                ]);

                $lockedOrder->update([
                    'payment_status' => 'failed',
                ]);

                return true;
            });

            if ($markedFailed) {
                return self::FAILED;
            }

            $payment->refresh();

            return strtoupper((string) $payment->payment_status) === 'PAID'
                ? self::PAID
                : self::PENDING;
        }

        return self::PENDING;
    }

    /**
     * Verify a transaction using PayU's server-to-server API.
     */
    public function verifyTransaction(string $txnid): array
    {
        $key = (string) config('services.payu.key');
        $salt = (string) config('services.payu.salt');
        $url = (string) config('services.payu.verify_url');

        if ($key === '' || $salt === '' || $url === '') {
            throw new \RuntimeException('PayU verification settings are missing.');
        }

        $command = 'verify_payment';

        $hash = hash(
            'sha512',
            $key . '|' . $command . '|' . $txnid . '|' . $salt
        );

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post($url, [
                'key' => $key,
                'command' => $command,
                'var1' => $txnid,
                'hash' => $hash,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                'PayU verification returned HTTP ' . $response->status()
            );
        }

        $body = $response->json();
        $details = $body['transaction_details'][$txnid] ?? null;

        if (
            !is_array($body) ||
            (int) ($body['status'] ?? 0) !== 1 ||
            !is_array($details)
        ) {
            throw new \RuntimeException(
                'PayU did not return a confirmed transaction record.'
            );
        }

        return $details;
    }
}
