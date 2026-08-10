<?php

namespace App\Http\Controllers;

use App\Models\DeactivationRequest;
use App\Models\User;
use Illuminate\Http\Request;

class DeactivationRequestController extends Controller
{
    /**
     * SUBMIT a deactivation request (Tenant or Landlord)
     */
    public function submit(Request $request)
    {
        $user = auth()->user();

        $existing = DeactivationRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json(['success' => false, 'message' => 'You already have a pending deactivation request.'], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $reqModel = DeactivationRequest::create([
            'user_id' => $user->id,
            'reason'  => $validated['reason'] ?? null,
            'status'  => 'pending',
        ]);

        // Notify all admins
        User::where('role', 'admin')->get()->each(function ($admin) use ($user) {
            \App\Models\Notification::notify(
                $admin->id,
                'deactivation_request_pending',
                'Account Deactivation Request',
                "{$user->first_name} {$user->last_name} ({$user->role}) has requested to deactivate their account.",
                '/admin/deactivation-requests'
            );
        });

        return response()->json(['success' => true, 'message' => 'Deactivation request submitted for admin review.', 'data' => $reqModel]);
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
     * ADMIN: approve — deactivates the account
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

        $reqModel->user->update(['is_active' => false, 'reactivation_requested_at' => null]);

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

        $user->update(['reactivation_requested_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Your request has been sent to our admin team. You will be notified once reviewed.',
        ]);
    }

    /**
     * ADMIN: list all currently deactivated accounts (tenant or landlord).
     * Accounts with a pending reactivation request are surfaced first.
     */
    public function deactivatedUsers()
    {
        $users = User::where('is_active', false)
            ->orderByRaw('reactivation_requested_at IS NULL')
            ->orderBy('reactivation_requested_at', 'desc')
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'first_name', 'last_name', 'email', 'role', 'profile_picture', 'updated_at', 'reactivation_requested_at']);

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