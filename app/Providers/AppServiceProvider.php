<?php

namespace App\Providers;

use App\Services\Payment\FakePaymentGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayException;
use App\Services\Payment\PayUGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function ($app) {
            $driver = config('advertisement.payment_driver', 'payu');

            if ($driver === 'fake') {
                // The fake gateway accepts self-signed payments, so it must never run live
                if ($app->environment('production')) {
                    throw new PaymentGatewayException('The fake payment gateway cannot be used in production.');
                }

                return new FakePaymentGateway();
            }

            return new PayUGateway(
                config('services.payu.key'),
                config('services.payu.salt'),
                config('services.payu.base_url', 'https://test.payu.in/_payment'),
                config('services.payu.verify_url', 'https://test.payu.in/merchant/postservice?form=2'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
