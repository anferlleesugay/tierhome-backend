<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Aws\Rekognition\RekognitionClient;
use Aws\Exception\AwsException;

class VerificationController extends Controller
{
    protected $rekognition;
    protected $collectionId = 'tierhome-users';

    public function __construct()
    {
        $this->rekognition = new RekognitionClient([
            'version' => 'latest',
            'region' => config('services.aws.region'),
            'credentials' => [
                'key' => config('services.aws.key'),
                'secret' => config('services.aws.secret'),
            ],
        ]);
    }

    /**
     * SUBMIT VERIFICATION
     * User uploads ID photo + selfie
     */
    public function submit(Request $request)
    {
        $user = Auth::user();

        // ============================
        // PREVENT DUPLICATE SUBMISSION
        // ============================
        $existing = IdentityVerification::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => $existing->status === 'approved'
                    ? 'You are already verified.'
                    : 'You already have a pending verification.',
                'code' => 'DUPLICATE_SUBMISSION',
            ], 422);
        }

        // ============================
        // VALIDATE FILES
        // ============================
        $validated = $request->validate([
            'id_photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
            'selfie'   => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
        ]);

        // ============================
        // STORE FILES TO STORAGE
        // SECURITY: these are government ID photos + selfies — stored on
        // the private 'local' disk (storage/app/private), NOT the public
        // disk. The public disk is symlinked and served directly by Nginx
        // with no auth check, which meant anyone with the URL could view
        // someone's ID/selfie. These are now only reachable through the
        // authenticated image() action below.
        // ============================
        try {
            $idPath = Storage::disk('local')->put('verifications/ids', $validated['id_photo']);
            $selfiePath = Storage::disk('local')->put('verifications/selfies', $validated['selfie']);
        } catch (\Exception $e) {
            \Log::error('File storage error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to store images.',
                'code' => 'FILE_STORAGE_ERROR',
            ], 500);
        }

        // ============================
        // STEP 1: COMPARE ID vs SELFIE
        // ============================
        $score = 0;

        try {
            $idImage = file_get_contents(storage_path("app/private/" . $idPath));
            $selfieImage = file_get_contents(storage_path("app/private/" . $selfiePath));

            $comparisonResult = $this->rekognition->compareFaces([
                'SourceImage' => ['Bytes' => $idImage],
                'TargetImage' => ['Bytes' => $selfieImage],
                'SimilarityThreshold' => 70,
            ]);

            $matches = $comparisonResult['FaceMatches'];

            if (!empty($matches)) {
                $score = $matches[0]['Similarity'];
            }

            \Log::info("CompareFaces score for user {$user->id}: {$score}");

        } catch (AwsException $e) {
            \Log::error('Rekognition CompareFaces error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Verification service error. Please try again.',
                'code' => 'REKOGNITION_ERROR',
            ], 500);
        }

        // ============================
        // CHECK: ID vs SELFIE MATCH
        // ============================
        if ($score < 90) {
            IdentityVerification::create([
                'user_id' => $user->id,
                'id_photo_path' => $idPath,
                'selfie_path' => $selfiePath,
                'rekognition_score' => $score,
                'status' => 'pending',
                'verification_method' => 'automatic',
            ]);

            // ✅ Notify all admins that a verification needs manual review
            \App\Models\User::where('role', 'admin')->get()->each(function ($admin) use ($user, $score) {
                \App\Models\Notification::notify(
                    $admin->id,
                    'verification_pending_review',
                    'Identity Verification Needs Review',
                    "{$user->first_name} {$user->last_name}'s verification was flagged for manual review (score: {$score}%).",
                    '/admin/verification'
                );
            });

            return response()->json([
                'success' => false,
                'message' => 'ID and selfie do not match. Please try again with clearer images.',
                'code' => 'SIMILARITY_LOW',
                'data' => ['score' => $score],
            ], 400);
        }

        // ============================
        // STEP 2: 🔥 CHECK FOR DUPLICATE FACE
        // ============================
        $duplicateCheck = $this->checkForDuplicateFace($selfiePath);

        if (!$duplicateCheck['safe']) {
            IdentityVerification::create([
                'user_id' => $user->id,
                'id_photo_path' => $idPath,
                'selfie_path' => $selfiePath,
                'rekognition_score' => $score,
                'status' => 'rejected',
                'verification_method' => 'automatic',
                'rejection_reason' => 'Duplicate face detected - already registered with another account.',
            ]);

            \Log::warning(
                "Duplicate face detected for user {$user->id}. " .
                "Face matches existing user {$duplicateCheck['existing_user_id']}"
            );

            return response()->json([
                'success' => false,
                'message' => 'This face is already registered with another verified account. One user = one verified account.',
                'code' => 'DUPLICATE_FACE_DETECTED',
                'data' => [
                    'status' => 'rejected',
                    'rejection_reason' => 'Duplicate face detected - already registered with another account.',
                ],
            ], 409);
        }

        // ============================
        // STEP 3: AUTO-APPROVE
        // ============================
        $verification = IdentityVerification::create([
            'user_id' => $user->id,
            'id_photo_path' => $idPath,
            'selfie_path' => $selfiePath,
            'rekognition_score' => $score,
            'status' => 'approved',
            'verification_method' => 'automatic',
            'verified_at' => now(),
        ]);

        // ============================
        // STEP 4: 🔥 INDEX FACE INTO COLLECTION
        // ============================
        $this->indexFaceInCollection($user->id, $selfiePath, $verification);

        // ============================
        // UPDATE USER STATUS
        // ============================
        $user->update([
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Identity verified successfully. Welcome to TierHome!',
            'data' => [
                'status' => 'verified',
                'score' => $score,
                'user_id' => $user->id,
            ],
        ]);
    }

    /**
     * 🔥 CHECK FOR DUPLICATE FACE
     * Uses Face Collections SearchFacesByImage
     */
    private function checkForDuplicateFace($selfiePath)
    {
        try {
                $selfieImage = file_get_contents(storage_path("app/private/" . $selfiePath));

                    $searchResult = $this->rekognition->searchFacesByImage([
                        'CollectionId' => $this->collectionId,
                        'Image' => ['Bytes' => $selfieImage],
                        'FaceMatchThreshold' => 90,
                        'MaxFaces' => 1,
                    ]);

            if (!empty($searchResult['FaceMatches'])) {
                $existingUserId = $searchResult['FaceMatches'][0]['Face']['ExternalImageId'];

                \Log::warning(
                    "Duplicate face found in collection. " .
                    "Existing user: {$existingUserId}"
                );

                return [
                    'safe' => false,
                    'existing_user_id' => (int) $existingUserId,
                ];
            }

            return ['safe' => true];

        } catch (AwsException $e) {
            \Log::error('SearchFacesByImage error: ' . $e->getMessage());
            // If search fails, allow verification (don't block)
            return ['safe' => true];
        }
    }

    /**
     * 🔥 INDEX FACE INTO COLLECTION
     * Uses Face Collections IndexFaces
     */
    private function indexFaceInCollection($userId, $selfiePath, $verification)
    {
        try {
                $selfieImage = file_get_contents(storage_path("app/private/" . $selfiePath));

                $indexResult = $this->rekognition->indexFaces([
                    'CollectionId' => $this->collectionId,
                    'Image' => ['Bytes' => $selfieImage],
                    'ExternalImageId' => (string) $userId,
                    'DetectionAttributes' => ['DEFAULT'],
                ]);

            if (!empty($indexResult['FaceRecords'])) {
                $faceId = $indexResult['FaceRecords'][0]['Face']['FaceId'];

                $verification->update([
                    'face_id' => $faceId,
                    'collection_id' => $this->collectionId,
                    'indexed_at' => now(),
                ]);

                \Log::info("Face indexed for user {$userId}. Face ID: {$faceId}");
            }

        } catch (AwsException $e) {
            \Log::error('IndexFaces error: ' . $e->getMessage());
            // Log error but don't fail - user is already approved
        }
    }

    /**
     * GET USER'S VERIFICATION STATUS
     */
    public function myStatus()
    {
        $verification = IdentityVerification::where('user_id', auth()->id())
            ->latest()
            ->first();

        return response()->json([
            'success' => true,
            'data' => $verification ? [
                'status' => $verification->status,
                'score' => $verification->rekognition_score,
                'rejection_reason' => $verification->rejection_reason,
                'created_at' => $verification->created_at,
                'verified_at' => $verification->verified_at,
            ] : null,
        ]);
    }

    /**
     * ADMIN: LIST PENDING VERIFICATIONS (FIXED - RENAMED)
     * Route calls this method, not listPending()
     */
    public function pending()
    {
        $pending = IdentityVerification::where('status', 'pending')
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $pending,
        ]);
    }

    /**
     * ADMIN: APPROVE VERIFICATION
     */
    public function approve($id)
    {
        $verification = IdentityVerification::findOrFail($id);

        if ($verification->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Already approved.',
            ], 422);
        }

        $verification->update([
            'status' => 'approved',
            'verification_method' => 'manual',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'verified_at' => now(),
        ]);

        $verification->user->update([
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        // Index face if not already indexed
        $this->indexFaceInCollection(
            $verification->user_id,
            $verification->selfie_path,
            $verification
        );

        \App\Models\AuditLog::record(
            auth()->id(),
            'verification_approved',
            'IdentityVerification',
            $verification->id,
            "Approved identity verification for {$verification->user->first_name} {$verification->user->last_name}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Verification approved successfully.',
        ]);
    }

    /**
     * ADMIN: REJECT VERIFICATION
     */
    public function reject(Request $request, $id)
    {
        $verification = IdentityVerification::findOrFail($id);

        $reason = $request->input('reason', 'Verification rejected by administrator.');

        $verification->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $verification->user->update([
            'verification_status' => 'rejected',
        ]);

        \App\Models\AuditLog::record(
            auth()->id(),
            'verification_rejected',
            'IdentityVerification',
            $verification->id,
            "Rejected identity verification for {$verification->user->first_name} {$verification->user->last_name}. Reason: {$reason}"
        );

        return response()->json([
            'success' => true,
            'message' => 'Verification rejected.',
        ]);
    }

    /**
     * ADMIN: GET VERIFICATION DETAILS
     */
    public function show($id)
    {
        $verification = IdentityVerification::with('user')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $verification->id,
                'user' => [
                    'id' => $verification->user->id,
                    'name' => $verification->user->name,
                    'email' => $verification->user->email,
                    'phone_number' => $verification->user->phone_number,
                ],
                'status' => $verification->status,
                'score' => $verification->rekognition_score,
                'verification_method' => $verification->verification_method,
                'id_photo_path' => $verification->id_photo_path,
                'selfie_path' => $verification->selfie_path,
                'face_id' => $verification->face_id,
                'rejection_reason' => $verification->rejection_reason,
                'created_at' => $verification->created_at,
                'verified_at' => $verification->verified_at,
                'indexed_at' => $verification->indexed_at,
                'reviewed_at' => $verification->reviewed_at,
            ],
        ]);
    }

    /**
     * ADMIN: LIST ALL VERIFIED FACES IN COLLECTION
     */
    public function listVerifiedFaces()
    {
        $verified = IdentityVerification::where('status', 'approved')
            ->whereNotNull('face_id')
            ->with('user')
            ->orderBy('verified_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $verified,
        ]);
    }

    /**
     * ADMIN: serve an ID photo or selfie from the private disk.
     * This route sits behind ['auth:api', 'admin', 'active'] in
     * routes/api.php — that's what actually gates access now, replacing
     * the old direct public-storage URL that had no auth check at all.
     */
    public function image($id, $type)
    {
        $verification = IdentityVerification::findOrFail($id);

        $path = match ($type) {
            'id'     => $verification->id_photo_path,
            'selfie' => $verification->selfie_path,
            default  => null,
        };

        if (!$path) {
            abort(404);
        }

        // Verifications submitted before this fix have their files sitting
        // on the old public disk location — fall back to that so existing
        // pending/approved records don't break after the deploy.
        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->response($path);
        }
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->response($path);
        }

        abort(404);
    }
}