<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PropertyListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * GET OWN PROFILE
     */
    public function me()
    {
        $user = auth()->user();
        return response()->json([
            'success' => true,
            'data'    => $this->formatUser($user),
        ]);
    }

    /**
     * GET PUBLIC PROFILE (viewable by anyone, no auth required)
     *
     * SECURITY: this route is intentionally public, but must only ever
     * return display-safe fields. Previously this called formatUser(),
     * which dumps the full user model (email, phone_number,
     * verification_status internals, is_active, deactivation timestamps,
     * etc.) for ANY user ID — allowing anyone to enumerate /profile/1,
     * /profile/2, ... and harvest PII for every tenant and landlord on
     * the platform. Use formatPublicUser() here instead, which whitelists
     * only the fields the public profile page actually displays.
     */
    public function show($id)
    {
        $user = User::findOrFail($id);

        $data = $this->formatPublicUser($user);

        // If landlord, include their active listings
        if ($user->role === 'landlord') {
            $listings = PropertyListing::where('landlord_id', $id)
                ->where('is_verified', true)
                ->where('status', 'available')
                ->with(['amenities', 'images'])
                ->latest()
                ->get();

            $data['listings'] = $listings;
        }

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * UPDATE PHONE NUMBER
     */
    public function updatePhone(Request $request)
    {
        $request->validate([
            'phone_number' => ['required', 'digits:11', 'regex:/^09[0-9]{9}$/'],
        ]);

        auth()->user()->update([
            'phone_number' => $request->phone_number,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatUser(auth()->user()->fresh()),
            'message' => 'Phone number updated successfully.',
        ]);
    }

    /**
     * UPDATE EMAIL
     */
    public function updateEmail(Request $request)
    {
        $request->validate([
            'email'    => 'required|email|unique:users,email,' . auth()->id(),
            'password' => 'required',
        ]);

        if (!Hash::check($request->password, auth()->user()->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        auth()->user()->update(['email' => $request->email]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatUser(auth()->user()->fresh()),
            'message' => 'Email updated successfully.',
        ]);
    }

    /**
     * UPDATE PASSWORD
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password'         => 'required|min:8|confirmed',
        ]);

        if (!Hash::check($request->current_password, auth()->user()->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        auth()->user()->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }

    /**
     * UPLOAD PROFILE PICTURE
     */
    public function updateProfilePicture(Request $request)
    {
        $request->validate([
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
        ]);

        $user = auth()->user();

        // Delete old picture if exists
        if ($user->profile_picture) {
            Storage::disk('public')->delete($user->profile_picture);
        }

        $path = $request->file('profile_picture')->store('profiles', 'public');

        $user->update(['profile_picture' => $path]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatUser($user->fresh()),
            'message' => 'Profile picture updated successfully.',
        ]);
    }

    /**
     * Format user data for the PUBLIC, unauthenticated profile endpoint.
     * Whitelist only — do not swap this back to formatUser()/toArray().
     * Excludes: email, phone_number, is_active, reactivation_requested_at,
     * report_frequency, last_report_sent_at, and any other account/admin
     * fields that aren't meant to be publicly readable by user ID.
     */
    private function formatPublicUser($user)
    {
        return [
            'id'                  => $user->id,
            'first_name'          => $user->first_name,
            'last_name'           => $user->last_name,
            'middle_name'         => $user->middle_name,
            'name'                => $user->name,
            'role'                => $user->role,
            'verification_status' => $user->verification_status,
            'profile_picture_url' => $user->profile_picture
                ? url('storage/' . $user->profile_picture)
                : null,
            'created_at'          => $user->created_at,
        ];
    }

    /**
     * Format user data with full profile picture URL
     */
    private function formatUser($user)
    {
        $data = $user->toArray();
        $data['profile_picture_url'] = $user->profile_picture
            ? url('storage/' . $user->profile_picture)
            : null;
        return $data;
    }
}