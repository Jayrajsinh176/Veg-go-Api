<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rider;
use App\Models\RiderApplicationLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminRiderDocumentController extends Controller
{
    private const KYC_STATUSES = [
        'pending',
        'under_review',
        'info_required',
        'verified',
        'rejected',
    ];

    private const STATUS_LABELS = [
        'pending' => 'Pending',
        'under_review' => 'Under Review',
        'info_required' => 'Info Required',
        'verified' => 'Approved',
        'rejected' => 'Rejected',
    ];

    // document key => verified column on rider_kycs
    private const DOCUMENT_COLUMNS = [
        'aadhaar' => 'aadhaar_verified',
        'pan' => 'pan_verified',
        'driving_license' => 'driving_license_verified',
        'puc' => 'puc_verified',
        'rc_book' => 'rc_book_verified',
        'insurance' => 'insurance_verified',
    ];

    // Same names the rider app shows on its upload screens
    // (RiderKycController::requiredDocuments)
    private const DOCUMENT_LABELS = [
        'aadhaar' => 'Aadhaar Card',
        'pan' => 'PAN Card',
        'driving_license' => 'Driving Licence',
        'rc_book' => 'RC Book',
        'insurance' => 'Vehicle Insurance',
        'puc' => 'PUC Certificate',
        'selfie' => 'Selfie',
    ];

    /**
     * Get rider document verification list
     */
    public function index(Request $request)
    {
        $riders = $this->baseQuery()
            ->latest('id')
            ->paginate(10);

        $riders->getCollection()->transform(
            fn ($rider) => $this->transform($rider)
        );

        return response()->json([
            'message' => 'Rider documents fetched successfully.',
            'data' => $riders,
        ]);
    }


    /**
     * Get a specific rider's documents
     */
    public function show($id)
    {
        $rider = $this->baseQuery()->find($id);

        if (!$rider) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Rider documents fetched successfully.',
            'data' => $this->transform($rider),
        ]);
    }


    /**
     * Update application (KYC) status
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::KYC_STATUSES)],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $rider = Rider::with('kyc')->find($id);

        if (!$rider) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        if (!$rider->kyc) {
            return response()->json([
                'message' => 'This rider has not submitted any documents yet.',
            ], 422);
        }

        $kyc = $rider->kyc;
        $status = $validated['status'];

        if ($kyc->status !== $status) {
            $kyc->status = $status;
            $kyc->rejection_reason = $status === 'rejected'
                ? ($validated['rejection_reason'] ?? null)
                : null;
            $kyc->save();

            // Approving / rejecting the application decides the rider account
            if ($status === 'verified' && $rider->status !== 'blocked') {
                $rider->update(['status' => 'approved']);
            }

            if ($status === 'rejected') {
                $rider->update([
                    'status' => 'rejected',
                    'is_online' => false,
                ]);
            }

            $title = $status === 'under_review'
                ? 'Application moved to under review.'
                : 'Application status changed to ' . self::STATUS_LABELS[$status] . '.';

            if ($status === 'rejected' && !empty($validated['rejection_reason'])) {
                $title .= ' Reason: ' . $validated['rejection_reason'];
            }

            $this->log($rider->id, $title);
        }

        return response()->json([
            'message' => 'Application status updated successfully.',
            'data' => $this->transform($this->baseQuery()->find($rider->id)),
        ]);
    }


    /**
     * Mark a single document verified / unverified
     */
    public function verifyDocument(Request $request, $id)
    {
        $validated = $request->validate([
            'document' => ['required', Rule::in(array_keys(self::DOCUMENT_COLUMNS))],
            'verified' => ['required', 'boolean'],
        ]);

        $rider = Rider::with('kyc')->find($id);

        if (!$rider || !$rider->kyc) {
            return response()->json([
                'message' => 'Rider documents not found.',
            ], 404);
        }

        $column = self::DOCUMENT_COLUMNS[$validated['document']];

        if ((bool) $rider->kyc->{$column} !== (bool) $validated['verified']) {
            $rider->kyc->update([$column => $validated['verified']]);

            $this->log(
                $rider->id,
                self::DOCUMENT_LABELS[$validated['document']] . ' ' .
                    ($validated['verified'] ? 'verified.' : 'marked unverified.')
            );
        }

        return response()->json([
            'message' => 'Document verification updated successfully.',
            'data' => $this->transform($this->baseQuery()->find($rider->id)),
        ]);
    }


    private function baseQuery()
    {
        return Rider::query()
            ->select([
                'id',
                'name',
                'phone',
                'email',
                'status',
                'city_id',
                'vehicle_type_id',
                'vehicle_number',
                'created_at',
            ])
            ->with([
                'kyc',
                'city:id,name',
                'vehicleType:id,name,code',
                'deliveryAddress',
                'applicationLogs' => fn ($query) => $query->latest('id'),
            ]);
    }


    private function transform(Rider $rider): array
    {
        $kyc = $rider->kyc;
        $address = $rider->deliveryAddress;

        $history = $rider->applicationLogs
            ->map(fn ($log) => [
                'title' => $log->title,
                'actor' => $log->actor,
                'created_at' => $log->created_at,
            ])
            ->values()
            ->all();

        if ($kyc) {
            $history[] = [
                'title' => 'Application submitted.',
                'actor' => 'Applicant Action',
                'created_at' => $kyc->created_at,
            ];
        }

        return [
            'id' => $rider->id,
            'rider_id' => $rider->id,
            'rider_name' => $rider->name,
            'phone' => $rider->phone,
            'email' => $rider->email,
            'account_status' => $rider->status,

            'city' => $rider->city?->name,
            'vehicle_type' => $rider->vehicleType?->name,
            'vehicle_number' => $rider->vehicle_number,

            'address' => $address ? [
                'line' => collect([
                    $address->house_flat,
                    $address->locality,
                    $address->landmark,
                ])->filter()->implode(', '),
                'city' => $address->city,
                'state' => $address->state,
                'pincode' => $address->pincode,
            ] : null,

            'kyc_status' => $kyc?->status,
            'submitted_at' => $kyc?->created_at,
            'joined_at' => $rider->created_at,

            'aadhaar' => [
                'last_four' => $kyc?->aadhaar_last_four,
                'verified' => (bool) $kyc?->aadhaar_verified,
                'document' => $this->documentUrl($kyc?->aadhaar_document),
            ],

            'pan' => [
                'has_pan' => $kyc?->has_pan,
                'verified' => (bool) $kyc?->pan_verified,
                'document' => $this->documentUrl($kyc?->pan_document),
            ],

            'driving_license' => [
                'number' => $kyc?->driving_license_number,
                'verified' => (bool) $kyc?->driving_license_verified,
                'document' => $this->documentUrl($kyc?->driving_license_document),
            ],

            'puc' => [
                'verified' => (bool) $kyc?->puc_verified,
                'document' => $this->documentUrl($kyc?->puc_document),
            ],

            'rc_book' => [
                'verified' => (bool) $kyc?->rc_book_verified,
                'document' => $this->documentUrl($kyc?->rc_book_document),
            ],

            'insurance' => [
                'verified' => (bool) $kyc?->insurance_verified,
                'document' => $this->documentUrl($kyc?->insurance_document),
            ],

            'selfie' => $this->documentUrl($kyc?->selfie),

            'document_list' => $this->documentList($rider),

            'rejection_reason' => $kyc?->rejection_reason,

            'history' => $history,
        ];
    }


    /**
     * Documents in the order / names the rider uploads them
     */
    private function documentList(Rider $rider): array
    {
        $kyc = $rider->kyc;

        $paths = [
            'aadhaar' => $kyc?->aadhaar_document,
            'pan' => $kyc?->pan_document,
            'driving_license' => $kyc?->driving_license_document,
            'rc_book' => $kyc?->rc_book_document,
            'insurance' => $kyc?->insurance_document,
            'puc' => $kyc?->puc_document,
            'selfie' => $kyc?->selfie,
        ];

        $list = [];

        foreach ($paths as $code => $path) {
            // PAN is optional (rider can skip it)
            if ($code === 'pan' && !$path && $kyc?->has_pan === false) {
                $list[] = [
                    'code' => $code,
                    'name' => self::DOCUMENT_LABELS[$code] . ' (Skipped)',
                    'document' => null,
                    'verified' => false,
                    'verifiable' => false,
                ];
                continue;
            }

            // PUC is only asked for petrol vehicles
            if ($code === 'puc' && !$path && $rider->vehicleType?->code !== 'petrol') {
                continue;
            }

            $verifiable = isset(self::DOCUMENT_COLUMNS[$code]);

            $list[] = [
                'code' => $code,
                'name' => self::DOCUMENT_LABELS[$code],
                'document' => $this->documentUrl($path),
                'verified' => $verifiable && (bool) $kyc?->{self::DOCUMENT_COLUMNS[$code]},
                'verifiable' => $verifiable,
            ];
        }

        return $list;
    }


    private function log(int $riderId, string $title): void
    {
        RiderApplicationLog::create([
            'rider_id' => $riderId,
            'title' => $title,
            'actor' => 'Admin Action',
        ]);
    }


    /**
     * Convert stored document path into URL
     */
    private function documentUrl($path)
    {
        if (!$path) {
            return null;
        }

        if (
            str_starts_with($path, 'http://') ||
            str_starts_with($path, 'https://')
        ) {
            return $path;
        }

        return asset(
            'storage/' . ltrim($path, '/')
        );
    }
}
