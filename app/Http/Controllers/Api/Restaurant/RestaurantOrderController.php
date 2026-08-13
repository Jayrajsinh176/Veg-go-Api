<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Services\Restaurant\RestaurantOrderService;
use Illuminate\Http\Request;

class RestaurantOrderController extends Controller
{
    protected RestaurantOrderService $orderService;

    public function __construct(RestaurantOrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Order List
     */
    public function index(Request $request)
    {
        return $this->orderService->index($request);
    }

    public function show(Request $request, $order)
{
    return $this->orderService->show($request, $order);
}

public function accept(Request $request, $order)
{
    return $this->orderService->accept($request, $order);
}

public function updateStatus(Request $request, $order)
{
    return $this->orderService->updateStatus($request, $order);
}

public function reject(Request $request, $order)
{
    return $this->orderService->reject($request, $order);
}

public function pending(Request $request)
{
    return $this->orderService->pending($request);
}

public function preparing(Request $request)
{
    return $this->orderService->preparing($request);
}

}