<?php

namespace App\Console\Commands;

use App\Models\RestaurantOrderPayment;
use App\Services\Payment\PayUOrderPaymentService;
use Illuminate\Console\Command;

class ReconcilePayUPayments extends Command
{
    protected $signature = 'payu:reconcile-pending';

    protected $description = 'Re-check pending PayU order payments whose callback never arrived';

    public function handle(PayUOrderPaymentService $payU): int
    {
        $counts = [
            PayUOrderPaymentService::PAID => 0,
            PayUOrderPaymentService::FAILED => 0,
            PayUOrderPaymentService::PENDING => 0,
        ];

        // Give the customer a few minutes to finish on PayU first;
        // stop retrying after a day.
        RestaurantOrderPayment::query()
            ->where('payment_gateway', 'PAYU')
            ->where('payment_status', 'PENDING')
            ->whereNotNull('transaction_id')
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->where('updated_at', '>=', now()->subDay())
            ->orderBy('id')
            ->each(function (RestaurantOrderPayment $payment) use ($payU, &$counts) {
                $counts[$payU->reconcile($payment)]++;
            });

        $this->info(sprintf(
            'PayU reconcile: %d paid, %d failed, %d still pending.',
            $counts[PayUOrderPaymentService::PAID],
            $counts[PayUOrderPaymentService::FAILED],
            $counts[PayUOrderPaymentService::PENDING]
        ));

        return self::SUCCESS;
    }
}
