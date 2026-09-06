<?php

namespace App\Http\Controllers;

use App\Models\DeactivationRequest;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Aws\Rekognition\RekognitionClient;
use Aws\Exception\AwsException;

class DeactivationRequestController extends Controller
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
     * SUBMIT a deactivation or deletion request (Tenant or Landlord)
     */
    public function submit(Request $request)
    {
        $user = auth()->user();

        $existing = DeactivationRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json(['success' => false, 'message' => 'You already have a pending ' . $existing->type . ' request.'], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'type'   => 'nullable|string|in:deactivate,delete',
        ]);

        $type = $validated['type'] ?? 'deactivate';

        $reqModel = DeactivationRequest::create([
            'user_id' => $user->id,
            'type'    => $type,
            'reason'  => $validated['reason'] ?? null,
            'status'  => 'pending',
        ]);

        $actionLabel = $type === 'delete' ? 'permanently delete' : 'deactivate';

        // Notify all admins
        User::where('role', 'admin')->get()->each(function ($admin) use ($user, $actionLabel) {
            \App\Models\Notification::notify(
                $admin->id,
                'deactivation_request_pending',
                'Account Deactivation Request',
                "{$user->first_name} {$user->last_name} ({$user->role}) has requested to {$actionLabel} their account.",
                '/admin/deactivation-requests'
            );
        });

        $message = $type === 'delete'
            ? 'Deletion request submitted for admin review.'
            : 'Deactivation request submitted for admin review.';

        return response()->json(['success' => true, 'message' => $message, 'data' => $reqModel]);
    }

    /**
     * CHECK my own request status (Tenant or Landlord)
     */
    public function myStatus()
    {
        $reqModel = DeactivationRequest::where('user_id', auth()->id())
            ->latest()
            ->first();

        return response()->json(['success' => true, 'data' => $reqModel]);
    }

    /**
     * CANCEL my own pending request (Tenant or Landlord changes their mind)
     */
    public function cancel($id)
    {
        $reqModel = DeactivationRequest::where('id', $id)
            ->where('user_id', auth()->id())
            ->where('status', 'pending')
            ->first();

        if (!$reqModel) {
            return response()->json(['success' => false, 'message' => 'Request not found or already reviewed.'], 404);
        }

        $reqModel->delete();

        return response()->json(['success' => true, 'message' => 'Deactivation request cancelled.']);
    }

    /**
     * ADMIN: list all pending requests
     */
    public function pending()
    {
        $requests = DeactivationRequest::where('status', 'pending')
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $requests]);
    }

    /**
     * ADMIN: approve — deactivates OR permanently deletes the account,
     * depending on the request's type.
     */
    public function approve(Request $request, $id)
    {
        $reqModel = DeactivationRequest::with('user')->findOrFail($id);

        if ($reqModel->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This request has already been reviewed.'], 422);
        }

        $validated = $request->validate(['admin_note' => 'nullable|string|max:500']);

        $reqModel->update([
            'status'      => 'approved',
            'admin_note'  => $validated['admin_note'] ?? null,
            'reviewed_at' => now(),
        ]);

        if ($reqModel->type === 'delete') {
            $this->performAccountDeletion($reqModel->user);

            \App\Models\AuditLog::record(
                auth()->id(),
                'account_deleted',
                'User',
                $reqModel->user_id,
                "Approved and executed permanent deletion request for user #{$reqModel->user_id}."
            );

            return response()->json(['success' => true, 'message' => 'Account permanently deleted.']);
        }

        $reqModel->user->update(['is_active' => false]);

        \App\Models\AuditLog::record(
            auth()->id(),
            'deactivation_request_approved',
            'User',
            $reqModel->user_id,
            "Approved deactivation request for {$reqModel->user->first_name} {$reqModel->user->last_name} ({$reqModel->user->email})."
        );

        \App\Models\Notification::notify(
            $reqModel->user_id,
            'deactivation_approved',
            'Account Deactivated',
            "Your account deactivation request has been approved. Your account is now inactive." . (!empty($validated['admin_note']) ? " Note: {$validated['admin_note']}" : ""),
            null
        );

        return response()->json(['success' => true, 'message' => 'Account deactivated successfully.']);
    }

    /**
     * Anonymize a user's personal data and purge their biometric data.
     *
     * Does NOT hard-delete the user row — listings, reservations, and
     * messages all reference user_id, and other people (landlords, other
     * tenants) legitimately still need those shared records to remain
     * intact. This scrubs only THIS user's personal identifying data,
     * consistent with a "right to erasure" request rather than a raw
     * `DELETE FROM users`.
     *
     * Also purges their face from the AWS Rekognition collection — without
     * this, re-registering later would be wrongly rejected by the
     * duplicate-face check (VerificationController::checkForDuplicateFace),
     * since their old face entry would still be indexed there pointing at
     * an account that (from their perspective) no longer meaningfully exists.
     */
    private function performAccountDeletion(User $user): void
    {
        // 1. Remove their face(s) from the Rekognition collection.
        // Best-effort: an AWS hiccup here shouldn't block the rest of the
        // deletion, so failures are logged, not thrown.
        $verifications = IdentityVerification::where('user_id', $user->id)
            ->whereNotNull('face_id')
            ->get();

        foreach ($verifications as $verification) {
            try {
                $this->rekognition->deleteFaces([
                    'CollectionId' => $verification->collection_id ?? $this->collectionId,
                    'FaceIds'      => [$verification->face_id],
                ]);
            } catch (AwsException $e) {
                \Log::warning("Failed to delete Rekognition face for user {$user->id}: " . $e->getMessage());
            }

            // Delete the actual ID/selfie image files. Check both disks —
            // verifications from before the 15c private-storage fix may
            // still be sitting on the old public disk.
            foreach (['id_photo_path', 'selfie_path'] as $field) {
                $path = $verification->$field;
                if (!$path) continue;
                try {
                    if (Storage::disk('local')->exists($path)) {
                        Storage::disk('local')->delete($path);
                    }
                    if (Storage::disk('public')->exists($path)) {
                        Storage::disk('public')->delete($path);
                    }
                } catch (\Exception $e) {
                    \Log::warning("Failed to delete verification file for user {$user->id}: " . $e->getMessage());
                }
            }

            // Clear the now-invalid references, but keep the row itself
            // (score/status/timestamps) for historical audit purposes —
            // none of that is personally identifying on its own.
            $verification->update([
                'id_photo_path' => null,
                'selfie_path'   => null,
                'face_id'       => null,
            ]);
        }

        // 2. Delete their profile picture file.
        if ($user->profile_picture) {
            try {
                Storage::disk('public')->delete($user->profile_picture);
            } catch (\Exception $e) {
                \Log::warning("Failed to delete profile picture for user {$user->id}: " . $e->getMessage());
            }
        }

        // 3. Anonymize the user record. Email/phone need unique placeholder
        // values (not null) since those columns carry unique constraints.
        $user->update([
            'first_name'           => 'Deleted',
            'last_name'            => 'User',
            'middle_initial'       => null,
            'name'                 => 'Deleted User',
            'email'                => "deleted-user-{$user->id}@tierhome.invalid",
            'phone_number'         => '09' . str_pad($user->id, 9, '0', STR_PAD_LEFT),
            'profile_picture'      => null,
            'password'             => Hash::make(Str::random(40)),
            'verification_status'  => 'unverified',
            'is_active'            => false,
        ]);
    }

    /**
     * ADMIN: reject — account stays active
     */
    public function reject(Request $request, $id)
    {
        $reqModel = DeactivationRequest::with('user')->findOrFail($id);

        if ($reqModel->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This request has already been reviewed.'], 422);
        }

        $validated = $request->validate(['admin_note' => 'nullable|string|max:500']);

        $reqModel->update([
            'status'      => 'rejected',
            'admin_note'  => $validated['admin_note'] ?? null,
            'reviewed_at' => now(),
        ]);

        \App\Models\AuditLog::record(
            auth()->id(),
            'deactivation_request_rejected',
            'User',
            $reqModel->user_id,
            "Declined deactivation request for {$reqModel->user->first_name} {$reqModel->user->last_name} ({$reqModel->user->email})."
        );

        \App\Models\Notification::notify(
            $reqModel->user_id,
            'deactivation_rejected',
            'Deactivation Request Declined',
            "Your account deactivation request was declined." . (!empty($validated['admin_note']) ? " Reason: {$validated['admin_note']}" : ""),
            null
        );

        return response()->json(['success' => true, 'message' => 'Request rejected.']);
    }

    /**
     * PUBLIC: a deactivated account holder requests reactivation from the login screen.
     * Verifies their credentials directly (they cannot get an authenticated session while
     * deactivated) and notifies all admins. Does NOT reactivate the account itself — an
     * admin must still approve, consistent with the rest of this deactivation flow.
     */
    public function requestReactivation(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json(['success' => false, 'message' => 'Invalid email or password.'], 401);
        }

        if ($user->is_active) {
            return response()->json(['success' => false, 'message' => 'This account is already active. Please log in normally.'], 422);
        }

        $adminIds = User::where('role', 'admin')->pluck('id');
        foreach ($adminIds as $adminId) {
            \App\Models\Notification::notify(
                $adminId,
                'reactivation_requested',
                'Reactivation Requested',
                "{$user->first_name} {$user->last_name} ({$user->email}) has requested their account be reactivated.",
                null
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Your request has been sent to our admin team. You will be notified once reviewed.',
        ]);
    }

    /**
     * ADMIN: list all currently deactivated accounts (tenant or landlord)
     */
    public function deactivatedUsers()
    {
        $users = User::where('is_active', false)
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'first_name', 'last_name', 'email', 'role', 'profile_picture', 'updated_at']);

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * ADMIN: list all tenant/landlord accounts for User Management
     */
    public function allUsers()
    {
        $users = User::whereIn('role', ['tenant', 'landlord'])
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'role', 'profile_picture', 'is_active', 'created_at']);

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * ADMIN: directly deactivate an account — no pending request required
     * (support/override path, distinct from the approve() request-review flow)
     */
    public function adminDeactivate($userId)
    {
        $user = User::findOrFail($userId);

        if ($user->role === 'admin') {
            return response()->json(['success' => false, 'message' => 'Admin accounts cannot be deactivated this way.'], 403);
        }

        if (!$user->is_active) {
            return response()->json(['success' => false, 'message' => 'This account is already deactivated.'], 422);
        }

        $user->update(['is_active' => false, 'reactivation_requested_at' => null]);

        \App\Models\AuditLog::record(
            auth()->id(),
            'account_deactivated_direct',
            'User',
            $user->id,
            "Directly deactivated {$user->first_name} {$user->last_name} ({$user->email}) via User Management."
        );

        \App\Models\Notification::notify(
            $user->id,
            'account_deactivated_by_admin',
            'Account Deactivated',
            "Your account has been deactivated by an administrator. Contact support if you believe this is an error.",
            null
        );

        return response()->json(['success' => true, 'message' => 'Account deactivated.']);
    }

    /**
     * ADMIN: reactivate a deactivated account directly (support/override path)
     */
    public function reactivate($userId)
    {
        $user = User::findOrFail($userId);
        $user->update(['is_active' => true, 'reactivation_requested_at' => null]);

        \App\Models\AuditLog::record(
            auth()->id(),
            'account_reactivated',
            'User',
            $user->id,
            "Reactivated {$user->first_name} {$user->last_name} ({$user->email})."
        );

        \App\Models\Notification::notify(
            $user->id,
            'account_reactivated',
            'Account Reactivated',
            "Your account has been reactivated by an administrator. You can now log in normally.",
            null
        );

        return response()->json(['success' => true, 'message' => 'Account reactivated.']);
    }
}