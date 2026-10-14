<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;

class AdminRestaurantDocumentController extends Controller
{
    /**
     * Get restaurant documents list
     */
    public function index(Request $request)
    {
        $restaurants = Restaurant::query()
            ->select([
                'id',
                'restaurant_name',
                'owner_name',
            ])
            ->with([
                'document' => function ($query) {
                    $query->select([
                        'id',
                        'restaurant_id',
                        'outlet_type_id',
                        'pan_number',
                        'gstin_number',
                        'upi_id',
                        'upi_holder_name',
                        'fssai_number',
                        'status',
                        'rejection_reason',
                        'reviewed_at',
                        'reviewed_by',
                        'created_at',
                    ]);
                },
            ])
            ->latest('id')
            ->paginate(10);

        $restaurants->getCollection()->transform(
            function ($restaurant) {

                $document = $restaurant->document;

                return [
                    'id' => $restaurant->id,

                    'name' =>
                        $restaurant->restaurant_name,

                    'owner' =>
                        $restaurant->owner_name,

                    'documents' => $document
                        ? [
                            'id' => $document->id,

                            'outlet_type_id' =>
                                $document->outlet_type_id,

                            'fssai' =>
                                $document->fssai_number,

                            'gst' =>
                                $document->gstin_number,

                            'pan' =>
                                $document->pan_number,

                            'upi' =>
                                $document->upi_id,

                            'upi_holder_name' =>
                                $document->upi_holder_name,

                            'status' =>
                                $document->status,

                            'rejection_reason' =>
                                $document->rejection_reason,

                            'reviewed_at' =>
                                $document->reviewed_at,

                            'reviewed_by' =>
                                $document->reviewed_by,

                                'created_at' =>
    $document->created_at,
                        ]
                        : null,
                ];
            }
        );

        return response()->json([
            'message' =>
                'Restaurant documents fetched successfully.',

            'data' => $restaurants,
        ]);
    }


    /**
     * Get restaurant document details
     */
    public function show($restaurantId)
    {
        $restaurant = Restaurant::query()
            ->select([
                'id',
                'restaurant_name',
                'owner_name',
            ])
            ->with([
                'document' => function ($query) {
                    $query->select([
                        'id',
                        'restaurant_id',
                        'outlet_type_id',
                        'pan_number',
                        'gstin_number',
                        'upi_id',
                        'upi_holder_name',
                        'fssai_number',
                        'status',
                        'rejection_reason',
                        'reviewed_at',
                        'reviewed_by',
                        'created_at',
                    ]);
                },
            ])
            ->find($restaurantId);

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        if (!$restaurant->document) {
            return response()->json([
                'message' =>
                    'Restaurant documents not found.',
            ], 404);
        }

        $document = $restaurant->document;

        return response()->json([
            'message' =>
                'Restaurant document details fetched successfully.',

            'data' => [
                'id' =>
                    $restaurant->id,

                'name' =>
                    $restaurant->restaurant_name,

                'owner' =>
                    $restaurant->owner_name,

                'documents' => [
                    'id' =>
                        $document->id,

                    'outlet_type_id' =>
                        $document->outlet_type_id,

                    'fssai' =>
                        $document->fssai_number,

                    'gst' =>
                        $document->gstin_number,

                    'pan' =>
                        $document->pan_number,

                    'upi' =>
                        $document->upi_id,

                    'upi_holder_name' =>
                        $document->upi_holder_name,

                    'status' =>
                        $document->status,

                    'rejection_reason' =>
                        $document->rejection_reason,

                    'reviewed_at' =>
                        $document->reviewed_at,

                    'reviewed_by' =>
                        $document->reviewed_by,
                ],
            ],
        ]);
    }

    /**
     * Mark restaurant documents as under review
     * (only moves pending applications forward)
     */
    public function markUnderReview(Request $request, $restaurantId)
    {
        $restaurant = Restaurant::find($restaurantId);

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $document = $restaurant->document;

        if (!$document) {
            return response()->json([
                'message' => 'Restaurant documents not found.',
            ], 404);
        }

        if ($document->status === 'pending') {
            $document->update([
                'status' => 'under_review',
                'reviewed_by' => $request->user()?->id,
            ]);
        }

        return response()->json([
            'message' =>
                'Restaurant documents marked as under review.',

            'data' => [
                'restaurant_id' =>
                    $restaurant->id,

                'document_id' =>
                    $document->id,

                'status' =>
                    $document->status,

                'reviewed_by' =>
                    $document->reviewed_by,
            ],
        ]);
    }

/**
 * Approve restaurant documents
 */
public function approve(Request $request, $restaurantId)
{
    $restaurant = Restaurant::find($restaurantId);

    if (!$restaurant) {
        return response()->json([
            'message' => 'Restaurant not found.',
        ], 404);
    }

    $document = $restaurant->document;

    if (!$document) {
        return response()->json([
            'message' => 'Restaurant documents not found.',
        ], 404);
    }

    $admin = $request->user();

    $document->update([
        'status' => 'approved',
        'rejection_reason' => null,
        'reviewed_at' => now(),
        'reviewed_by' => $admin?->id,
    ]);

    return response()->json([
        'message' =>
            'Restaurant documents approved successfully.',

        'data' => [
            'restaurant_id' =>
                $restaurant->id,

            'document_id' =>
                $document->id,

            'status' =>
                $document->status,

            'rejection_reason' =>
                $document->rejection_reason,

            'reviewed_at' =>
                $document->reviewed_at,

            'reviewed_by' =>
                $document->reviewed_by,
        ],
    ]);
}


    /**
     * Reject restaurant documents
     */
    public function reject(
        Request $request,
        $restaurantId
    ) {
        $request->validate([
            'rejection_reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $restaurant = Restaurant::find($restaurantId);

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $document = $restaurant->document;

        if (!$document) {
            return response()->json([
                'message' =>
                    'Restaurant documents not found.',
            ], 404);
        }

        $document->update([
            'status' => 'rejected',

            'rejection_reason' =>
                trim(
                    $request->rejection_reason
                ),

            'reviewed_at' => now(),

            'reviewed_by' =>
                auth()->id(),
        ]);

        return response()->json([
            'message' =>
                'Restaurant documents rejected successfully.',

            'data' => [
                'restaurant_id' =>
                    $restaurant->id,

                'document_id' =>
                    $document->id,

                'status' =>
                    $document->status,

                'rejection_reason' =>
                    $document->rejection_reason,

                'reviewed_at' =>
                    $document->reviewed_at,

                'reviewed_by' =>
                    $document->reviewed_by,
            ],
        ]);
    }
}