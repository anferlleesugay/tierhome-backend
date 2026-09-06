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

            // Check if tenant already has an active reservation for this listing
            $existing = Reservation::where('tenant_id', $user->id)
                ->where('listing_id', $listingId)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->first();

            if ($existing) {
                // ✅ If the existing reservation never actually got paid (fee required but abandoned),
                // treat it as void and let the tenant start fresh instead of getting stuck.
                $feeRequired = $listing->reservation_fee && $listing->reservation_fee > 0;
                $neverPaid   = in_array($existing->payment_status, ['pending', 'refund_failed']) || !$existing->payment_status;

                if ($feeRequired && $neverPaid) {
                    $existing->update([
                        'status' => 'cancelled',
                        'cancellation_reason' => 'Abandoned — reservation fee was never completed',
                        'cancelled_by' => 'system',
                    ]);
                } else {
                    return response()->json(['success' => false, 'message' => 'You already have a ' . $existing->status . ' reservation for this property'], 422);
                }
            }

            $validated = $request->validate([
                'move_in_date'   => 'nullable|date|after_or_equal:today',
                'move_out_date'  => 'nullable|date|after:move_in_date',
                'notes'          => 'nullable|string|max:500',
                'terms_accepted' => 'required|accepted',
            ]);

            // ✅ If listing requires a reservation fee, payment_status starts as 'pending' instead of 'not_required'
            $paymentStatus = ($listing->reservation_fee && $listing->reservation_fee > 0) ? 'pending' : 'not_required';

            $reservation = Reservation::create([
                'tenant_id'         => $user->id,
                'listing_id'        => $listingId,
                'status'            => 'pending',
                'move_in_date'      => $validated['move_in_date'] ?? null,
                'move_out_date'     => $validated['move_out_date'] ?? null,
                'notes'             => $validated['notes'] ?? null,
                'terms_accepted_at' => now(),
                'payment_status'    => $paymentStatus,
            ]);

            $reservation->load(['listing.landlord', 'listing.images', 'tenant']);

            // ✅ Only notify the landlord immediately if no payment is required —
            // if a fee is required, we wait until payment actually succeeds (see PaymentController::confirmPayment)
            if ($paymentStatus === 'not_required') {
                \App\Models\Notification::notify(
                    $listing->landlord_id,
                    'new_reservation',
                    'New Reservation Request',
                    "{$user->first_name} requested to reserve \"{$listing->title}\".",
                    '/landlord/reservations'
                );
            }

            return response()->json([
                'success' => true,
                'data'    => $reservation,
                'message' => 'Reservation request sent successfully!',
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET MY RESERVATIONS (Tenant)
     * Hides reservations where a fee is required but was never actually paid.
     */
    public function myReservations()
    {
        try {
            $userId = auth()->id();

            $reservations = Reservation::where('tenant_id', $userId)
                ->with(['listing.landlord', 'listing.images'])
                ->where(function ($q) {
                    $q->whereHas('listing', function ($q2) {
                        $q2->where(function ($q3) {
                            $q3->whereNull('reservation_fee')->orWhere('reservation_fee', '<=', 0);
                        });
                    })->orWhere('payment_status', 'paid');
                })
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json(['success' => true, 'data' => $reservations]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * CANCEL RESERVATION (Tenant or Landlord)
     */
    public function cancel(Request $request, $id)
    {
        try {
            $user = auth()->user();
            $reservation = Reservation::with('listing')->findOrFail($id);

            $isTenant   = $reservation->tenant_id === $user->id;
            $isLandlord = $reservation->listing && $reservation->listing->landlord_id === $user->id;

            if (!$isTenant && !$isLandlord) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            if (in_array($reservation->status, ['cancelled', 'rejected'])) {
                return response()->json(['success' => false, 'message' => 'Cannot cancel a ' . $reservation->status . ' reservation'], 422);
            }

            $validated = $request->validate([
                'reason' => 'nullable|string|max:500',
            ]);

            $wasApproved = $reservation->status === 'approved';

            $reservation->update([
                'status'              => 'cancelled',
                'cancellation_reason' => $validated['reason'] ?? null,
                'cancelled_by'        => $isTenant ? 'tenant' : 'landlord',
            ]);

            // ✅ Automatically refund via Xendit sandbox if the reservation fee was already paid
            if ($reservation->payment_status === 'paid') {
                (new PaymentController())->refund($reservation);
            }

            // Notify the other party (not whoever just cancelled)
            $notifyUserId = $isTenant ? $reservation->listing->landlord_id : $reservation->tenant_id;
            \App\Models\Notification::notify(
                $notifyUserId,
                'reservation_cancelled',
                'Reservation Cancelled',
                "A reservation for \"{$reservation->listing->title}\" was cancelled by the " . ($isTenant ? 'tenant' : 'landlord') . ".",
                $isTenant ? '/landlord/reservations' : '/tenant/reservations'
            );

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

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET LANDLORD'S RESERVATIONS
     * Hides reservations where a fee is required but was never actually paid.
     */
    public function landlordReservations()
    {
        try {
            $user = auth()->user();

            $listingIds = PropertyListing::where('landlord_id', $user->id)->pluck('id');

            $reservations = Reservation::whereIn('listing_id', $listingIds)
                ->with(['tenant', 'listing.images'])
                ->where(function ($q) {
                    $q->whereHas('listing', function ($q2) {
                        $q2->where(function ($q3) {
                            $q3->whereNull('reservation_fee')->orWhere('reservation_fee', '<=', 0);
                        });
                    })->orWhere('payment_status', 'paid');
                })
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

            $updateData = ['status' => $validated['status']];

            if ($validated['status'] === 'rejected') {
                $updateData['cancellation_reason'] = $validated['reason'] ?? null;
                $updateData['cancelled_by'] = 'landlord';
            }

            $reservation->update($updateData);

            // ✅ Automatically refund via Xendit sandbox if the reservation fee was already paid
            if ($validated['status'] === 'rejected' && $reservation->payment_status === 'paid') {
                (new PaymentController())->refund($reservation);
            }

            \App\Models\Notification::notify(
                $reservation->tenant_id,
                $validated['status'] === 'approved' ? 'reservation_approved' : 'reservation_rejected',
                $validated['status'] === 'approved' ? 'Reservation Approved' : 'Reservation Rejected',
                $validated['status'] === 'approved'
                    ? "Your reservation for \"{$reservation->listing->title}\" was approved."
                    : "Your reservation for \"{$reservation->listing->title}\" was rejected." . (!empty($validated['reason']) ? " Reason: {$validated['reason']}" : ""),
                '/tenant/reservations'
            );

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

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}