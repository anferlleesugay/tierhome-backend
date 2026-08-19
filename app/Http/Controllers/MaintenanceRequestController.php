<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Models\PropertyListing;
use App\Models\Reservation;
use Illuminate\Http\Request;

class MaintenanceRequestController extends Controller
{
    /**
     * TENANT: submit a maintenance request. Only allowed for a listing the
     * tenant actually has an approved reservation on — prevents random
     * submissions for units they have no connection to.
     */
    public function submit(Request $request)
    {
        $validated = $request->validate([
            'listing_id'  => 'required|exists:property_listings,id',
            'title'       => 'required|string|max:150',
            'description' => 'required|string|max:2000',
            'category'    => 'nullable|string|max:50',
        ]);

        $tenantId = auth()->id();

        $hasApprovedReservation = Reservation::where('listing_id', $validated['listing_id'])
            ->where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->exists();

        if (!$hasApprovedReservation) {
            return response()->json([
                'success' => false,
                'message' => 'You can only report issues for a listing you have an approved reservation on.',
            ], 403);
        }

        $maintenanceRequest = MaintenanceRequest::create([
            'listing_id'  => $validated['listing_id'],
            'tenant_id'   => $tenantId,
            'title'       => $validated['title'],
            'description' => $validated['description'],
            'category'    => $validated['category'] ?? null,
            'status'      => 'pending',
        ]);

        $listing = PropertyListing::find($validated['listing_id']);
        if ($listing) {
            \App\Models\Notification::notify(
                $listing->landlord_id,
                'maintenance_request_submitted',
                'New Maintenance Request',
                "A tenant reported an issue with \"{$listing->title}\": {$validated['title']}",
                null
            );
        }

        return response()->json(['success' => true, 'data' => $maintenanceRequest]);
    }

    /**
     * TENANT: view their own submitted requests, across any listing.
     */
    public function myRequests()
    {
        $requests = MaintenanceRequest::where('tenant_id', auth()->id())
            ->with('listing:id,title,barangay')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $requests]);
    }

    /**
     * LANDLORD: view all maintenance requests across their listings.
     */
    public function landlordRequests()
    {
        $landlordId = auth()->id();
        $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');

        $requests = MaintenanceRequest::whereIn('listing_id', $listingIds)
            ->with(['listing:id,title,barangay', 'tenant:id,first_name,last_name,email,phone_number'])
            ->orderByRaw("FIELD(status, 'pending', 'in_progress', 'resolved', 'rejected')")
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $requests]);
    }

    /**
     * LANDLORD: update a maintenance request's status, with an optional note.
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status'        => 'required|in:pending,in_progress,resolved,rejected',
            'landlord_note' => 'nullable|string|max:1000',
        ]);

        $maintenanceRequest = MaintenanceRequest::with('listing')->findOrFail($id);

        // Confirm this request belongs to one of the current landlord's listings
        if (!$maintenanceRequest->listing || $maintenanceRequest->listing->landlord_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Not authorized to update this request.'], 403);
        }

        $maintenanceRequest->update([
            'status'        => $validated['status'],
            'landlord_note' => $validated['landlord_note'] ?? $maintenanceRequest->landlord_note,
            'resolved_at'   => $validated['status'] === 'resolved' ? now() : $maintenanceRequest->resolved_at,
        ]);

        \App\Models\Notification::notify(
            $maintenanceRequest->tenant_id,
            'maintenance_request_updated',
            'Maintenance Request Update',
            "Your report \"{$maintenanceRequest->title}\" is now marked as " . str_replace('_', ' ', $validated['status']) . ".",
            null
        );

        return response()->json(['success' => true, 'data' => $maintenanceRequest]);
    }
}