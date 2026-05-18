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
     * GET PUBLIC PROFILE (landlord viewable by anyone)
     */
    public function show($id)
    {
        $user = User::findOrFail($id);

        $data = $this->formatUser($user);

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