<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Rider\RiderShiftService;

class RiderShiftController extends Controller
{
    protected $shiftService;

    public function __construct(RiderShiftService $shiftService)
    {
        $this->shiftService = $shiftService;
    }

    /**
     * Get all available shifts.
     */
    public function availableShifts()
    {
        return response()->json([
            'success' => true,
            'shifts' => $this->shiftService->getAvailableShifts(),
        ]);
    }


  /**
 * Start current shift.
 */
public function startShift(Request $request)
{
    $result = $this->shiftService->startShift(
        $request->user()->id
    );

    return response()->json($result);
}

    /**
     * Complete shift.
     */
    public function completeShift(Request $request)
    {
        $result = $this->shiftService->completeShift(
            $request->user()->id
        );

        return response()->json($result);
    }
}