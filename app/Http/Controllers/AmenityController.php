<?php

namespace App\Http\Controllers;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use Illuminate\Http\Request;

class AmenityController extends Controller
{
    // ── GET ALL APPROVED AMENITIES (for listing form) ──
    public function index()
    {
        $amenities = Amenity::orderBy('name')->get();
        return response()->json(['success' => true, 'data' => $amenities]);
    }

    // ── LANDLORD: REQUEST NEW AMENITY ──
    public function request(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'icon' => 'nullable|string|max:50',
        ]);

        $user = auth()->user();

        // Check if already requested by this landlord
        $existing = AmenityRequest::where('landlord_id', $user->id)
            ->where('name', $request->name)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending request for this amenity.',
            ], 422);
        }

        // Check if amenity already exists globally
        $exists = Amenity::whereRaw('LOWER(name) = ?', [strtolower($request->name)])->first();
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'This amenity already exists in the list.',
            ], 422);
        }

        $amenityRequest = AmenityRequest::create([
            'landlord_id' => $user->id,
            'name'        => $request->name,
            'icon'        => $request->icon ?? 'star',
            'status'      => 'pending',
        ]);

        // ✅ Notify all admins that a new amenity request needs review
        \App\Models\User::where('role', 'admin')->get()->each(function ($admin) use ($user, $amenityRequest) {
            \App\Models\Notification::notify(
                $admin->id,
                'amenity_request_pending',
                'New Amenity Request',
                "{$user->first_name} requested a new amenity: \"{$amenityRequest->name}\".",
                '/admin/amenities'
            );
        });

        return response()->json([
            'success' => true,
            'data'    => $amenityRequest,
            'message' => 'Amenity request submitted! Admin will review it shortly.',
        ], 201);
    }

    // ── LANDLORD: GET MY REQUESTS ──
    public function myRequests()
    {
        $requests = AmenityRequest::where('landlord_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $requests]);
    }

    // ── ADMIN: UPDATE AMENITY ICON ──
    public function updateIcon(Request $request, $id)
    {
        $request->validate(['icon' => 'required|string|max:50']);
        $amenity = Amenity::findOrFail($id);
        $amenity->update(['icon' => $request->icon]);
        return response()->json(['success' => true, 'message' => 'Icon updated.', 'data' => $amenity]);
    }

    // ── ADMIN: GET ALL APPROVED AMENITIES (for management) ──
    public function indexAll()
    {
        $amenities = Amenity::orderBy('name')->get();
        return response()->json(['success' => true, 'data' => $amenities]);
    }

    // ── ADMIN: DELETE AMENITY ──
    public function destroy($id)
    {
        $amenity = Amenity::findOrFail($id);
        $amenity->delete();
        return response()->json(['success' => true, 'message' => "Amenity \"{$amenity->name}\" removed."]);
    }
    public function pending()
    {
        $requests = AmenityRequest::where('status', 'pending')
            ->with('landlord')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['success' => true, 'data' => $requests]);
    }

    // ── ADMIN: GET ALL REQUESTS ──
    public function allRequests()
    {
        $requests = AmenityRequest::with('landlord')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $requests]);
    }

    // ── ADMIN: APPROVE REQUEST ──
    public function approve(Request $request, $id)
    {
        $amenityRequest = AmenityRequest::findOrFail($id);

        if ($amenityRequest->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Request already processed.'], 422);
        }

        // Check if amenity already exists
        $existing = Amenity::whereRaw('LOWER(name) = ?', [strtolower($amenityRequest->name)])->first();

        if (!$existing) {
            // Add to global amenities list
            Amenity::create([
                'name' => $amenityRequest->name,
                'icon' => $amenityRequest->icon ?? 'star',
            ]);
        }

        $amenityRequest->update(['status' => 'approved']);

        return response()->json([
            'success' => true,
            'message' => "Amenity \"{$amenityRequest->name}\" approved and added to the global list.",
        ]);
    }

    // ── ADMIN: REJECT REQUEST ──
    public function reject(Request $request, $id)
    {
        $request->validate(['admin_note' => 'nullable|string|max:500']);

        $amenityRequest = AmenityRequest::findOrFail($id);

        if ($amenityRequest->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Request already processed.'], 422);
        }

        $amenityRequest->update([
            'status'     => 'rejected',
            'admin_note' => $request->admin_note ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Amenity request rejected.',
        ]);
    }
}