<?php

namespace App\Console\Commands;

use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderPayment;
use App\Services\Order\OrderCancellationService;
use App\Services\Payment\PayUOrderPaymentService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cancels online-payment orders that remain unpaid for more than 5 minutes.
 *
 * When a customer selects online payment but closes the app / abandons
 * the PayU gateway, the order sits in pending/pending forever.  This
 * command cleans those up so the restaurant does not see stale orders
 * and inventory is not held indefinitely.
 *
 * Before cancelling, any PayU transaction on the order is verified with
 * PayU, so a payment whose callback was lost is confirmed instead of the
 * order being cancelled. If PayU still reports it in progress (or cannot
 * be reached) the order is left alone until MAX_WAIT_MINUTES.
 *
 * Registered in routes/console.php to run every minute.
 */
class ExpireUnpaidOnlineOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Cancel online-payment orders that are still unpaid after 5 minutes';

    /**
     * Orders older than this many minutes are expired.
     */
    private const EXPIRY_MINUTES = 5;

    /**
     * Orders whose PayU transaction is still unconfirmed are cancelled
     * anyway after this many minutes.
     */
    private const MAX_WAIT_MINUTES = 30;

    public function handle(PayUOrderPaymentService $payU, OrderCancellationService $cancellation): int
    {
        $cutoff = now()->subMinutes(self::EXPIRY_MINUTES);

        /*
         * Find online orders that:
         *   1. Use online payment (not cash)
         *   2. Payment is still pending
         *   3. Order status is still pending (restaurant hasn't acted)
         *   4. Were placed more than 5 minutes ago
         */
        $staleOrders = RestaurantOrder::query()
            ->where('payment_method', 'online')
            ->where('payment_status', 'pending')
            ->where('order_status', 'pending')
            ->where('placed_at', '<=', $cutoff)
            ->get();

        if ($staleOrders->isEmpty()) {
            $this->line('No unpaid online orders to expire.');

            return self::SUCCESS;
        }

        $this->info("Found {$staleOrders->count()} unpaid online order(s) to expire.");

        foreach ($staleOrders as $order) {
            try {
                $paymentState = $this->verifyWithPayU($payU, $order);

                if ($paymentState === PayUOrderPaymentService::PAID) {
                    $this->line(" • Order #{$order->order_number} was paid on PayU, kept.");

                    continue;
                }

                if (
                    $paymentState === PayUOrderPaymentService::PENDING
                    && Carbon::parse($order->placed_at)->gt(now()->subMinutes(self::MAX_WAIT_MINUTES))
                ) {
                    $this->line(" • Order #{$order->order_number} PayU payment still unconfirmed, waiting.");

                    continue;
                }

                // Re-checks under a lock and skips the order if a callback marked it paid meanwhile
                $cancelled = $cancellation->cancel(
                    $order,
                    OrderCancellationService::BY_SYSTEM,
                    'Payment not completed within ' . self::EXPIRY_MINUTES . ' minutes.'
                );

                $this->line($cancelled
                    ? " • Order #{$order->order_number} expired."
                    : " • Order #{$order->order_number} was paid or changed meanwhile, kept.");

            } catch (\Throwable $e) {
                Log::error('Failed to expire unpaid order', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);

                $this->error(" • Order #{$order->order_number} failed: {$e->getMessage()}");
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Ask PayU about the order's pending PayU transactions.
     *
     * Returns paid if any of them succeeded, pending if one could not be
     * confirmed either way, and failed otherwise (including when the
     * customer never reached PayU).
     */
    private function verifyWithPayU(PayUOrderPaymentService $payU, RestaurantOrder $order): string
    {
        $payments = RestaurantOrderPayment::query()
            ->where('order_id', $order->id)
            ->where('payment_gateway', 'PAYU')
            ->where('payment_status', 'PENDING')
            ->whereNotNull('transaction_id')
            ->where('transaction_id', '!=', '')
            ->get();

        $state = PayUOrderPaymentService::FAILED;

        foreach ($payments as $payment) {
            $result = $payU->reconcile($payment);

            if ($result === PayUOrderPaymentService::PAID) {
                return PayUOrderPaymentService::PAID;
            }

            if ($result === PayUOrderPaymentService::PENDING) {
                $state = PayUOrderPaymentService::PENDING;
            }
        }

        return $state;
    }
}
