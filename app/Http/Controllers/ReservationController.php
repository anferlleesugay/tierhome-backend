<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\PropertyListing;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    /**
     * CREATE A RESERVATION (Tenant)
     */
    public function reserve(Request $request, $listingId)
    {
        try {
            $user = auth()->user();

            if ($user->role !== 'tenant') {
                return response()->json(['success' => false, 'message' => 'Only tenants can make reservations'], 403);
            }

            $listing = PropertyListing::findOrFail($listingId);

            if ($listing->status !== 'available') {
                return response()->json(['success' => false, 'message' => 'This property is not available for reservation'], 422);
            }

            // Check if tenant already has active reservation for this listing
            $existing = Reservation::where('tenant_id', $user->id)
                ->where('listing_id', $listingId)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->first();

            if ($existing) {
                return response()->json(['success' => false, 'message' => 'You already have a ' . $existing->status . ' reservation for this property'], 422);
            }

            $validated = $request->validate([
                'move_in_date'   => 'nullable|date|after_or_equal:today',
                'notes'          => 'nullable|string|max:500',
                'terms_accepted' => 'required|accepted',
            ]);

            $reservation = Reservation::create([
                'tenant_id'         => $user->id,
                'listing_id'        => $listingId,
                'status'            => 'pending',
                'move_in_date'      => $validated['move_in_date'] ?? null,
                'notes'             => $validated['notes'] ?? null,
                'terms_accepted_at' => now(),
            ]);

            $reservation->load(['listing.landlord', 'listing.images', 'tenant']);

            return response()->json([
                'success' => true,
                'data'    => $reservation,
                'message' => 'Reservation request sent successfully!',
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET MY RESERVATIONS (Tenant)
     */
    public function myReservations()
    {
        try {
            $reservations = Reservation::where('tenant_id', auth()->id())
                ->with(['listing.landlord', 'listing.images'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json(['success' => true, 'data' => $reservations]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * CANCEL RESERVATION (Tenant)
     */
    public function cancel($id)
    {
        try {
            $reservation = Reservation::findOrFail($id);

            if ($reservation->tenant_id !== auth()->id()) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            if (in_array($reservation->status, ['cancelled', 'rejected'])) {
                return response()->json(['success' => false, 'message' => 'Cannot cancel a ' . $reservation->status . ' reservation'], 422);
            }

            $wasApproved = $reservation->status === 'approved';
            $reservation->update(['status' => 'cancelled']);

            if ($wasApproved) {
                $listing     = PropertyListing::find($reservation->listing_id);
                $multiTenant = in_array($listing?->property_type, ['Dormitory', 'Boarding House', 'Bedspace']);

                if ($listing) {
                    if ($multiTenant) {
                        $newSlots = ($listing->available_slots ?? 0) + 1;
                        $listing->update(['available_slots' => $newSlots, 'status' => 'available']);
                    } else {
                        $listing->update(['status' => 'available']);
                    }
                }
            }

            return response()->json(['success' => true, 'message' => 'Reservation cancelled successfully.']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET LANDLORD'S RESERVATIONS
     */
    public function landlordReservations()
    {
        try {
            $user = auth()->user();

            $listingIds = PropertyListing::where('landlord_id', $user->id)->pluck('id');

            $reservations = Reservation::whereIn('listing_id', $listingIds)
                ->with(['tenant', 'listing.images'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json(['success' => true, 'data' => $reservations]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * APPROVE OR REJECT RESERVATION (Landlord)
     */
    public function respond(Request $request, $id)
    {
        try {
            $user = auth()->user();

            $validated = $request->validate([
                'status' => 'required|in:approved,rejected',
                'reason' => 'nullable|string|max:500',
            ]);

            $reservation = Reservation::with('listing')->findOrFail($id);

            if ($reservation->listing->landlord_id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            if ($reservation->status !== 'pending' && !($reservation->status === 'approved' && $validated['status'] === 'rejected')) {
                return response()->json(['success' => false, 'message' => 'This reservation has already been ' . $reservation->status], 422);
            }

            $reservation->update(['status' => $validated['status']]);

            // ✅ Fresh fetch of listing
            $listing = PropertyListing::find($reservation->listing_id);

            if ($validated['status'] === 'approved' && $listing) {
                $multiTenant = in_array($listing->property_type, ['Dormitory', 'Boarding House', 'Bedspace']);

                if ($multiTenant) {
                    // ✅ Decrement available slots
                    $newSlots = max(0, ($listing->available_slots ?? 1) - 1);
                    $listing->update([
                        'available_slots' => $newSlots,
                        'status'          => $newSlots === 0 ? 'unavailable' : 'available',
                    ]);
                } else {
                    $listing->update(['status' => 'unavailable']);
                }
            }

            if ($validated['status'] === 'rejected' && $listing) {
                $multiTenant = in_array($listing->property_type, ['Dormitory', 'Boarding House', 'Bedspace']);

                if ($multiTenant) {
                    // ✅ Restore slot
                    $newSlots = ($listing->available_slots ?? 0) + 1;
                    $listing->update([
                        'available_slots' => $newSlots,
                        'status'          => 'available',
                    ]);
                } else {
                    $listing->update(['status' => 'available']);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Reservation ' . $validated['status'] . ' successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}