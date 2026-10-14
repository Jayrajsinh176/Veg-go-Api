<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Rider;
use App\Models\RiderApplicationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminRiderApplicationController extends Controller
{
    // document key => verified column on rider_kycs
    private const DOCUMENT_COLUMNS = [
        'aadhaar' => 'aadhaar_verified',
        'pan' => 'pan_verified',
        'driving_license' => 'driving_license_verified',
        'rc_book' => 'rc_book_verified',
        'insurance' => 'insurance_verified',
        'puc' => 'puc_verified',
    ];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $riders = $this->baseQuery()
            ->latest('id')
            ->paginate($validated['per_page'] ?? 100);

        $riders->getCollection()->transform(
            fn (Rider $rider) => $this->applicationRow($rider)
        );

        return response()->json([
            'message' => 'Rider applications fetched successfully.',
            'data' => $riders,
        ]);
    }

    /**
     * Mark a single document verified / unverified
     */
    public function verifyDocument(Request $request, $id, $key)
    {
        $validated = $request->validate([
            'verified' => ['required', 'boolean'],
        ]);

        $rider = $this->baseQuery()->find($id);

        if (!$rider) {
            return $this->notFound();
        }

        $document = collect($this->applicationRow($rider)['documentsList'])
            ->firstWhere('key', $key);

        if (!$document) {
            return response()->json([
                'message' => 'This document has not been submitted.',
            ], 422);
        }

        $column = self::DOCUMENT_COLUMNS[$key];
        $verified = (bool) $validated['verified'];

        if ((bool) $rider->kyc->{$column} !== $verified) {
            $rider->kyc->update([$column => $verified]);

            $this->log(
                $rider->id,
                $document['name'] . ' ' . ($verified ? 'verified.' : 'marked unverified.')
            );
        }

        return $this->rowResponse($rider->id, 'Document verification updated successfully.');
    }

    /**
     * Approve: every verifiable document must be verified first
     */
    public function approve($id)
    {
        $rider = $this->baseQuery()->find($id);

        if (!$rider) {
            return $this->notFound();
        }

        if ($rider->kyc->status === 'verified') {
            return response()->json([
                'message' => 'This application is already approved.',
            ], 422);
        }

        $documents = collect($this->applicationRow($rider)['documentsList'])
            ->where('verifiable', true);

        if ($documents->isEmpty() || $documents->contains(fn ($document) => $document['status'] !== 'Verified')) {
            return response()->json([
                'message' => 'Please verify all documents before approval.',
            ], 422);
        }

        DB::transaction(function () use ($rider) {
            $rider->kyc->update([
                'status' => 'verified',
                'rejection_reason' => null,
            ]);

            if ($rider->status !== 'blocked') {
                $rider->update(['status' => 'approved']);
            }

            $this->log($rider->id, 'Application status changed to Approved.');
        });

        return $this->rowResponse($rider->id, 'Application approved successfully.');
    }

    /**
     * Reject with a reason the rider can see (also used to update the reason)
     */
    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $rider = $this->baseQuery()->find($id);

        if (!$rider) {
            return $this->notFound();
        }

        $reason = trim($validated['reason']);

        DB::transaction(function () use ($rider, $reason) {
            $rider->kyc->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

            $rider->update([
                'status' => 'rejected',
                'is_online' => false,
            ]);

            $this->log($rider->id, 'Application status changed to Rejected. Reason: ' . $reason);
        });

        return $this->rowResponse($rider->id, 'Application rejected successfully.');
    }

    private function baseQuery()
    {
        return Rider::query()
            ->whereHas('kyc')
            ->select([
                'id',
                'status',
                'name',
                'phone',
                'email',
                'city_id',
                'city_area_id',
                'vehicle_type_id',
                'vehicle_number',
                'created_at',
            ])
            ->with([
                'kyc:id,rider_id,aadhaar_last_four,aadhaar_verified,aadhaar_document,has_pan,pan_document,pan_verified,driving_license_number,driving_license_document,driving_license_verified,puc_document,puc_verified,rc_book_document,rc_book_verified,insurance_document,insurance_verified,selfie,status,rejection_reason,created_at',
                'city:id,name',
                'cityArea:id,name',
                'vehicleType:id,name,code',
            ]);
    }

    private function rowResponse(int $id, string $message)
    {
        return response()->json([
            'message' => $message,
            'data' => $this->applicationRow($this->baseQuery()->find($id)),
        ]);
    }

    private function notFound()
    {
        return response()->json([
            'message' => 'Rider application not found.',
        ], 404);
    }

    private function log(int $riderId, string $title): void
    {
        RiderApplicationLog::create([
            'rider_id' => $riderId,
            'title' => $title,
            'actor' => 'Admin Action',
        ]);
    }

    private function applicationRow(Rider $rider): array
    {
        $kyc = $rider->kyc;
        $documentDefinitions = [
            ['key' => 'aadhaar', 'name' => 'Aadhaar Card', 'path' => 'aadhaar_document', 'verified' => 'aadhaar_verified'],
            ['key' => 'pan', 'name' => 'PAN Card', 'path' => 'pan_document', 'verified' => 'pan_verified'],
            ['key' => 'driving_license', 'name' => 'Driving Licence', 'path' => 'driving_license_document', 'verified' => 'driving_license_verified'],
            ['key' => 'rc_book', 'name' => 'RC Book', 'path' => 'rc_book_document', 'verified' => 'rc_book_verified'],
            ['key' => 'insurance', 'name' => 'Vehicle Insurance', 'path' => 'insurance_document', 'verified' => 'insurance_verified'],
            ['key' => 'puc', 'name' => 'PUC Certificate', 'path' => 'puc_document', 'verified' => 'puc_verified'],
            ['key' => 'selfie', 'name' => 'Profile Document', 'path' => 'selfie', 'verified' => null],
        ];

        $documents = collect($documentDefinitions)
            ->filter(function (array $definition) use ($kyc, $rider) {
                if (!$kyc?->{$definition['path']}) {
                    return false;
                }

                return !($definition['key'] === 'pan' && $kyc?->has_pan === false);
            })
            ->map(function (array $definition) use ($kyc) {
                $path = $kyc?->{$definition['path']};
                $verified = $definition['verified'] && (bool) $kyc?->{$definition['verified']};

                return [
                    'key' => $definition['key'],
                    'name' => $definition['name'],
                    // The selfie has no verified column, so it never blocks approval
                    'verifiable' => (bool) $definition['verified'],
                    'status' => !$definition['verified']
                        ? 'Uploaded'
                        : ($verified ? 'Verified' : 'Pending'),
                    'documentUrl' => $this->documentUrl($path),
                ];
            })
            ->values();

        $verifiableDocuments = $documents->where('verifiable', true);
        $verifiedCount = $verifiableDocuments->where('status', 'Verified')->count();
        $totalDocuments = $verifiableDocuments->count();
        $identityStatus = $kyc?->aadhaar_verified ? 'Verified' : 'Pending';
        $licenseStatus = $kyc?->driving_license_verified ? 'Verified' : 'Pending';
        $applicationStatus = match (true) {
            $kyc?->status === 'rejected' => 'Rejected',
            $kyc?->status === 'verified' => 'Approved',
            $kyc?->status === 'under_review' => 'Under Review',
            default => 'Docs Pending',
        };
        $verification = match ($kyc?->status) {
            'verified' => 'Cleared',
            'rejected' => 'Failed',
            default => 'Pending',
        };
        $submittedAt = $kyc?->created_at ?? $rider->created_at;

        return [
            'id' => $rider->id,
            'applicationId' => sprintf('#APP-%04d', $rider->id),
            'name' => $rider->name ?: '—',
            'phone' => $rider->phone ?: '—',
            'city' => $rider->city?->name ?: '—',
            'area' => $rider->cityArea?->name ?: '—',
            'submittedDate' => $submittedAt?->format('M d, Y') ?? '—',
            'documents' => "{$verifiedCount}/{$totalDocuments}",
            'documentProgress' => $totalDocuments
                ? (int) round(($verifiedCount / $totalDocuments) * 100)
                : 0,
            'verification' => $verification,
            'identityVerification' => $identityStatus,
            'licenseVerification' => $licenseStatus,
            'assignedAdmin' => 'Unassigned',
            'assignedInitial' => '',
            'status' => $applicationStatus,
            'vehicle' => $rider->vehicleType?->name ?: '—',
            'vehicleNumber' => $rider->vehicle_number ?: '—',
            'email' => $rider->email ?: '—',
            'license' => $kyc?->driving_license_number ?: '—',
            'aadhaarLastFour' => $kyc?->aadhaar_last_four,
            'documentsList' => $documents->all(),
            'rejectionReason' => $kyc?->rejection_reason ?: '',
            'additionalInformation' => '',
            'manualReviewStatus' => in_array($applicationStatus, ['Approved', 'Rejected'], true)
                ? 'Completed'
                : 'Pending',
            'backgroundCheckStatus' => 'Pending',
            'checklist' => [
                'ageCriteria' => 'Pending',
                'identityMatch' => $identityStatus,
                'licenseValidity' => $licenseStatus,
                'backgroundCheck' => 'Pending',
            ],
            'avatar' => collect(preg_split('/\s+/', trim($rider->name ?: 'Rider')))
                ->filter()
                ->take(2)
                ->map(fn (string $part) => mb_substr($part, 0, 1))
                ->implode(''),
        ];
    }

    private function documentUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }
}