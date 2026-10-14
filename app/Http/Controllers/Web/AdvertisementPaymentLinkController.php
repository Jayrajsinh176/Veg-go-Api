<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\RestaurantAdvertisement;
use App\Services\Restaurant\RestaurantAdvertisementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdvertisementPaymentLinkController extends Controller
{
    public function __construct(private RestaurantAdvertisementService $service)
    {
    }

    public function show(Request $request, $id)
    {
        $ad = RestaurantAdvertisement::with(['restaurant', 'package', 'packageDuration', 'foodItem'])
            ->find((int) $id);

        if (!$ad) {
            return view('advertisement-pay-status', [
                'title' => 'Advertisement Not Found',
                'message' => 'The advertisement you are looking for does not exist or has been removed.',
                'type' => 'error',
                'ad' => null,
            ]);
        }

        if ($ad->payment_status === RestaurantAdvertisement::PAYMENT_PAID) {
            return view('advertisement-pay-status', [
                'title' => 'Payment Already Completed',
                'message' => 'This advertisement has already been paid for and is active.',
                'type' => 'success',
                'ad' => $ad,
            ]);
        }

        if ($ad->status === RestaurantAdvertisement::STATUS_CANCELLED) {
            return view('advertisement-pay-status', [
                'title' => 'Advertisement Cancelled',
                'message' => 'This advertisement has been cancelled and cannot be paid.',
                'type' => 'error',
                'ad' => $ad,
            ]);
        }

        if ($ad->status === RestaurantAdvertisement::STATUS_EXPIRED) {
            return view('advertisement-pay-status', [
                'title' => 'Advertisement Expired',
                'message' => 'This advertisement campaign duration has expired.',
                'type' => 'error',
                'ad' => $ad,
            ]);
        }

        try {
            $paymentResult = $this->service->startPayment($ad, [
                'type' => 'restaurant',
                'id' => $ad->restaurant_id,
                'name' => $ad->restaurant?->restaurant_name ?? 'Restaurant',
            ]);

            $checkout = $paymentResult['checkout'];
            $params = $checkout['params'] ?? [];
            $action = $params['action'] ?? config('services.payu.base_url', 'https://test.payu.in/_payment');

            return view('advertisement-pay', [
                'ad' => $ad,
                'action' => $action,
                'params' => $params,
            ]);
        } catch (\Throwable $e) {
            Log::error('Advertisement payment link error', ['ad_id' => $id, 'error' => $e->getMessage()]);

            return view('advertisement-pay-status', [
                'title' => 'Payment Error',
                'message' => 'Unable to initiate payment session: ' . $e->getMessage(),
                'type' => 'error',
                'ad' => $ad,
            ]);
        }
    }
}

