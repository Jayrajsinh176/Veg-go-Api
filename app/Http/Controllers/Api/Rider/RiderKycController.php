<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Models\RiderKyc;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RiderKycController extends Controller
{
    public function saveAadhaar(Request $request)
{
    $validator = Validator::make($request->all(), [
        'aadhaar_number' => [
            'required',
            'digits:12',
            'regex:/^[2-9][0-9]{11}$/',
        ],

        'aadhaar_document' => [
            'required',
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'max:5120',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    if (!$rider->personal_details_completed) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete personal details first',
        ], 400);
    }

    $kyc = RiderKyc::firstOrNew([
        'rider_id' => $rider->id,
    ]);

    if ($kyc->aadhaar_document) {
        Storage::disk('public')->delete(
            $kyc->aadhaar_document
        );
    }

    $aadhaarDocument = $request
        ->file('aadhaar_document')
        ->store('riders/kyc/aadhaar', 'public');

    $aadhaarNumber = $request->aadhaar_number;

    $kyc->aadhaar_number = $aadhaarNumber;
    $kyc->aadhaar_last_four = substr($aadhaarNumber, -4);
    $kyc->aadhaar_document = $aadhaarDocument;
    $kyc->aadhaar_verified = false;
    $kyc->status = 'pending';

    $kyc->save();

    return response()->json([
        'success' => true,
        'message' => 'Aadhaar details and document uploaded successfully',
        'aadhaar' => [
            'last_four' => $kyc->aadhaar_last_four,
            'document_url' => asset(
                'storage/' . $kyc->aadhaar_document
            ),
            'verified' => (bool) $kyc->aadhaar_verified,
        ],
    ]);
}

    public function savePan(Request $request)
{
    $validator = Validator::make($request->all(), [
        'pan_number' => [
            'required',
            'string',
            'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
        ],

        'pan_document' => [
            'required',
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'max:5120',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    $kyc = RiderKyc::where('rider_id', $rider->id)->first();

    if (!$kyc || !$kyc->aadhaar_number || !$kyc->aadhaar_document) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete Aadhaar details first',
        ], 400);
    }

    if ($kyc->pan_document) {
        Storage::disk('public')->delete(
            $kyc->pan_document
        );
    }

    $panDocument = $request
        ->file('pan_document')
        ->store('riders/kyc/pan', 'public');

    $kyc->update([
        'pan_number' => strtoupper($request->pan_number),
        'pan_document' => $panDocument,
        'has_pan' => true,
        'pan_verified' => false,
        'status' => 'pending',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'PAN details and document uploaded successfully',
        'pan' => [
            'has_pan' => true,
            'document_url' => asset(
                'storage/' . $kyc->pan_document
            ),
            'verified' => (bool) $kyc->pan_verified,
        ],
    ]);
}

public function saveDrivingLicense(Request $request)
{
    $validator = Validator::make($request->all(), [
        'driving_license_number' => [
            'required',
            'string',
            'max:30',
        ],
        'driving_license_document' => [
            'required',
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'max:5120',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    $kyc = RiderKyc::where('rider_id', $rider->id)->first();

    if (!$kyc || !$kyc->aadhaar_document) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete Aadhaar details first',
        ], 400);
    }

    if ($kyc->has_pan && !$kyc->pan_document) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete PAN details first',
        ], 400);
    }

    if ($kyc->driving_license_document) {
        Storage::disk('public')->delete(
            $kyc->driving_license_document
        );
    }

    $document = $request
        ->file('driving_license_document')
        ->store('riders/kyc/driving-license', 'public');

    $kyc->update([
        'driving_license_number' => strtoupper(
            $request->driving_license_number
        ),
        'driving_license_document' => $document,
        'driving_license_verified' => false,
        'status' => 'pending',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Driving licence details and document uploaded successfully',
        'driving_license' => [
            'document_url' => asset(
                'storage/' . $kyc->driving_license_document
            ),
            'verified' => (bool) $kyc->driving_license_verified,
        ],
    ]);
}

public function uploadPuc(Request $request)
{
    $rider = $request->user();

    $rider->load('vehicleType');

    if (!$rider->vehicleType) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your vehicle type first',
        ], 400);
    }

if ($rider->vehicleType->code === 'ev') {
    return response()->json([
        'success' => false,
        'message' => 'PUC document is not required for EV',
    ], 400);
}

    $validator = Validator::make($request->all(), [
        'puc_document' => [
            'required',
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'max:5120',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $kyc = RiderKyc::where('rider_id', $rider->id)->first();

    if (
        !$kyc ||
        !$kyc->driving_license_number ||
        !$kyc->driving_license_document
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete driving licence details first',
        ], 400);
    }

    if ($kyc->puc_document) {
        Storage::disk('public')->delete(
            $kyc->puc_document
        );
    }

    $document = $request
        ->file('puc_document')
        ->store('riders/kyc/puc', 'public');

    $kyc->update([
        'puc_document' => $document,
        'puc_verified' => false,
        'status' => 'pending',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'PUC document uploaded successfully',
        'puc' => [
            'document_url' => asset(
                'storage/' . $kyc->puc_document
            ),
            'verified' => (bool) $kyc->puc_verified,
        ],
    ]);
}

public function requiredDocuments(Request $request)
{
    $rider = $request->user();

    $rider->load('vehicleType');

    if (!$rider->vehicleType) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your vehicle type first',
        ], 400);
    }

    $vehicleType = $rider->vehicleType;

    $documents = [
        [
            'code' => 'aadhaar',
            'name' => 'Aadhaar Card',
            'required' => true,
        ],
        [
            'code' => 'pan',
            'name' => 'PAN Card',
            'required' => false,
            'can_skip' => true,
        ],
        [
            'code' => 'driving_license',
            'name' => 'Driving Licence',
            'required' => true,
        ],
        [
            'code' => 'rc_book',
            'name' => 'RC Book',
            'required' => true,
        ],
        [
            'code' => 'insurance',
            'name' => 'Vehicle Insurance',
            'required' => true,
        ],
    ];

    if ($vehicleType->code === 'petrol') {
        $documents[] = [
            'code' => 'puc',
            'name' => 'PUC Certificate',
            'required' => true,
        ];
    }

    $documents[] = [
        'code' => 'selfie',
        'name' => 'Selfie',
        'required' => true,
    ];

    return response()->json([
        'success' => true,
        'vehicle_type' => [
            'id' => $vehicleType->id,
            'name' => $vehicleType->name,
            'code' => $vehicleType->code,
        ],
        'documents' => $documents,
    ]);
}

public function uploadRcBook(Request $request)
{
    
    $validator = Validator::make($request->all(), [
        'rc_book_document' => [
            'required',
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'max:5120',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    $rider->load('vehicleType');

    if (!$rider->vehicleType) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your vehicle type first',
        ], 400);
    }

    $kyc = RiderKyc::where('rider_id', $rider->id)->first();

    if (!$kyc || !$kyc->driving_license_document) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete driving licence details first',
        ], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | PUC requirement
    |--------------------------------------------------------------------------
    | Petrol vehicles require PUC.
    | EV vehicles do not require PUC.
    |--------------------------------------------------------------------------
    */

    if (
        $rider->vehicleType->code !== 'ev' &&
        !$kyc->puc_document
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Please upload PUC document first',
        ], 400);
    }

    if ($kyc->rc_book_document) {
        Storage::disk('public')->delete(
            $kyc->rc_book_document
        );
    }

    $document = $request
        ->file('rc_book_document')
        ->store('riders/kyc/rc-book', 'public');

    $kyc->update([
        'rc_book_document' => $document,
        'rc_book_verified' => false,
        'status' => 'pending',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'RC Book document uploaded successfully',
        'rc_book' => [
            'document_url' => asset(
                'storage/' . $kyc->rc_book_document
            ),
            'verified' => (bool) $kyc->rc_book_verified,
        ],
    ]);
}

public function uploadInsurance(Request $request)
{
    $validator = Validator::make($request->all(), [
        'insurance_document' => [
            'required',
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'max:5120',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    $kyc = RiderKyc::where('rider_id', $rider->id)->first();

    if (!$kyc || !$kyc->rc_book_document) {
        return response()->json([
            'success' => false,
            'message' => 'Please upload RC Book document first',
        ], 400);
    }

    if ($kyc->insurance_document) {
        Storage::disk('public')->delete(
            $kyc->insurance_document
        );
    }

    $document = $request
        ->file('insurance_document')
        ->store('riders/kyc/insurance', 'public');

    $kyc->update([
        'insurance_document' => $document,
        'insurance_verified' => false,
        'status' => 'pending',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Insurance document uploaded successfully',
        'insurance' => [
            'document_url' => asset(
                'storage/' . $kyc->insurance_document
            ),
            'verified' => (bool) $kyc->insurance_verified,
        ],
    ]);
}

public function skipPan(Request $request)
{
    $rider = $request->user();

    $kyc = RiderKyc::where('rider_id', $rider->id)->first();

    if (!$kyc || !$kyc->aadhaar_number) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete Aadhaar details first',
        ], 400);
    }

    $kyc->update([
        'pan_number' => null,
        'has_pan' => false,
        'pan_verified' => false,
        'status' => 'pending',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'PAN skipped successfully',
        'pan' => [
            'has_pan' => false,
            'verified' => false,
        ],
    ]);
}

public function uploadSelfie(Request $request)
{
    $validator = Validator::make($request->all(), [
        'selfie' => [
            'required',
            'image',
            'mimes:jpg,jpeg,png',
            'max:5120',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    $kyc = RiderKyc::where('rider_id', $rider->id)->first();

    if (!$kyc || !$kyc->aadhaar_number) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete Aadhaar details first',
        ], 400);
    }

    if ($kyc->has_pan && !$kyc->pan_number) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete PAN details first',
        ], 400);
    }

    if ($kyc->selfie) {
        Storage::disk('public')
            ->delete($kyc->selfie);
    }

    $selfiePath = $request->file('selfie')
        ->store('riders/selfies', 'public');

    $kyc->update([
        'selfie' => $selfiePath,
        'status' => 'pending',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Selfie uploaded successfully',
        'selfie' => [
            'path' => $selfiePath,
            'url' => asset('storage/' . $selfiePath),
        ],
    ]);
}

public function completeProfile(Request $request)
{
    $rider = $request->user();

    if (!$rider->personal_details_completed) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete personal details first',
        ], 400);
    }

    if (!$rider->vehicle_type_id) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your vehicle type',
        ], 400);
    }

    $rider->load('vehicleType');

    $vehicleType = $rider->vehicleType;

    if (!$vehicleType) {
        return response()->json([
            'success' => false,
            'message' => 'Selected vehicle type not found',
        ], 400);
    }

    $kyc = RiderKyc::where('rider_id', $rider->id)->first();

    if (
        !$kyc ||
        !$kyc->aadhaar_number ||
        !$kyc->aadhaar_document
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete Aadhaar details and document',
        ], 400);
    }

    if (
        $kyc->has_pan &&
        (
            !$kyc->pan_number ||
            !$kyc->pan_document
        )
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete PAN details and document',
        ], 400);
    }

    if (
        !$kyc->driving_license_number ||
        !$kyc->driving_license_document
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete driving licence details',
        ], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | Petrol Vehicle Documents
    |--------------------------------------------------------------------------
    */

    if ($vehicleType->code === 'petrol') {

        if (!$kyc->puc_document) {
            return response()->json([
                'success' => false,
                'message' => 'Please upload PUC document',
            ], 400);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Common Vehicle Documents
    |--------------------------------------------------------------------------
    */

    if (!$kyc->rc_book_document) {
        return response()->json([
            'success' => false,
            'message' => 'Please upload RC Book document',
        ], 400);
    }

    if (!$kyc->insurance_document) {
        return response()->json([
            'success' => false,
            'message' => 'Please upload insurance document',
        ], 400);
    }

    if (!$kyc->selfie) {
        return response()->json([
            'success' => false,
            'message' => 'Please upload your selfie',
        ], 400);
    }

    $kyc->update([
        'status' => 'pending',
    ]);

    $rider->update([
        'profile_completed' => true,
        'status' => 'pending',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Profile and KYC documents completed successfully',
        'profile_completed' => true,
        'vehicle_type' => [
            'id' => $vehicleType->id,
            'name' => $vehicleType->name,
            'code' => $vehicleType->code,
        ],
        'kyc_status' => $kyc->status,
        'rider_status' => $rider->status,
        'next_step' => 'delivery_kit',
    ]);
}
}