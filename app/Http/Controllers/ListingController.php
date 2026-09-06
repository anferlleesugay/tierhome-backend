<?php

namespace App\Http\Controllers;

use App\Models\PropertyListing;
use App\Models\ListingImage;
use App\Models\Amenity;
use Illuminate\Http\Request;

class ListingController extends Controller
{
    /**
     * GET REAL ACTIVE LISTING COUNT (PUBLIC)
     * Used to replace the previous hardcoded "500+ Listings" marketing text
     * on the Login page with a genuine, live number.
     */
    public function publicCount()
    {
        $count = PropertyListing::where('status', 'available')->count();
        return response()->json(['success' => true, 'data' => ['count' => $count]]);
    }

    /**
     * GET ALL LISTINGS (PUBLIC)
     */
    public function index(Request $request)
    {
        try {
            // ✅ Log this search for demand analytics (only when an actual filter was used, to avoid logging every idle page load)
            if ($request->barangay || $request->budget || $request->type) {
                \App\Models\SearchLog::log(
                    'browse',
                    auth('api')->id(),
                    $request->barangay !== 'All Barangays' ? $request->barangay : null,
                    $request->budget,
                    $request->type !== 'All Types' ? $request->type : null
                );
            }

            $query = PropertyListing::where('is_verified', true)
                ->where('status', 'available')
                ->whereHas('landlord', fn($q) => $q->where('is_active', true));

            if ($request->barangay && $request->barangay !== 'All Barangays') {
                $query->where('barangay', $request->barangay);
            }
            if ($request->budget && is_numeric($request->budget)) {
                $query->where('price', '<=', $request->budget);
            }
            if ($request->type && $request->type !== 'All Types') {
                $query->where('property_type', $request->type);
            }

            $listings = $query->with(['amenities', 'images', 'landlord'])->paginate(12);

            return response()->json([
                'success' => true,
                'data' => $listings,
                'message' => 'Listings retrieved'
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'data' => null, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET SINGLE LISTING BY ID
     */
    public function show($id)
    {
        try {
            $listing = PropertyListing::with(['landlord', 'reservations', 'amenities', 'images'])
                ->findOrFail($id);

            // ✅ Read token even on public route
            $user = auth('api')->user();

            // Block pending/rejected listings from public access
            $isPending = !$listing->is_verified && $listing->status !== 'available';
            if ($isPending || $listing->status === 'rejected') {
                $isOwner = $user && $user->id === $listing->landlord_id;
                $isAdmin = $user && $user->role === 'admin';
                if (!$isOwner && !$isAdmin) {
                    return response()->json(['success' => false, 'message' => 'This listing is pending review.'], 403);
                }
            }

            // Block unavailable listings
            if ($listing->status === 'unavailable') {
                $isOwner = $user && $user->id === $listing->landlord_id;
                $isAdmin = $user && $user->role === 'admin';
                if (!$isOwner && !$isAdmin) {
                    return response()->json(['success' => false, 'message' => 'This listing is no longer available.'], 403);
                }
            }

            // Block listings whose landlord account has been deactivated.
            // A listing with no landlord record at all is a data-integrity problem
            // (orphaned/deleted owner), not a deactivation — still hidden from the
            // public and other landlords, but distinguished here for clearer debugging.
            if (!$listing->landlord) {
                $isAdmin = $user && $user->role === 'admin';
                if (!$isAdmin) {
                    return response()->json(['success' => false, 'message' => 'This listing is currently unavailable.'], 403);
                }
            } elseif (!$listing->landlord->is_active) {
                $isOwner = $user && $user->id === $listing->landlord_id;
                $isAdmin = $user && $user->role === 'admin';
                if (!$isOwner && !$isAdmin) {
                    return response()->json(['success' => false, 'message' => 'This listing is currently unavailable.'], 403);
                }
            }

            return response()->json([
                'success' => true,
                'data'    => $listing,
                'message' => 'Listing retrieved'
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Listing not found'], 404);
        }
    }

    /**
     * GET NEARBY LISTINGS
     */
    public function nearby(Request $request)
    {
        try {
            \App\Models\SearchLog::log('nearby', auth('api')->id());

            $request->validate([
                'latitude'  => 'required|numeric',
                'longitude' => 'required|numeric',
                'radius'    => 'nullable|numeric|min:0.1|max:100',
            ]);

            $latitude  = $request->latitude;
            $longitude = $request->longitude;
            $radius    = $request->radius ?? 5;

            $latOffset = $radius / 111.0;
            $lonOffset = $radius / (111.0 * cos(deg2rad($latitude)));

            $listings = PropertyListing::where('is_verified', true)
                ->where('status', 'available')
                ->whereHas('landlord', fn($q) => $q->where('is_active', true))
                ->whereBetween('latitude', [$latitude - $latOffset, $latitude + $latOffset])
                ->whereBetween('longitude', [$longitude - $lonOffset, $longitude + $lonOffset])
                ->with(['amenities', 'images'])
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $listings,
                'message' => "Found " . count($listings) . " nearby listings"
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'data' => null, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * CREATE NEW LISTING (LANDLORD ONLY)
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'title'                 => 'required|string|max:255',
                'description'           => 'required|string',
                'terms_and_conditions'  => 'nullable|string',
                'reservation_fee'       => 'nullable|numeric|min:0',
                'price'                 => 'required|numeric|min:0',
                'address'               => 'required|string|max:255',
                'barangay'              => 'nullable|string|max:255',
                'property_type'         => 'required|string',
                'latitude'              => 'nullable|numeric',
                'longitude'             => 'nullable|numeric',
                'image'                 => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
                'images.*'              => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
                'amenities'             => 'nullable|json',
                'total_slots'           => 'nullable|integer|min:1',
                'available_slots'       => 'nullable|integer|min:0',
            ]);

            // Set available_slots = total_slots on creation
            if (!empty($data['total_slots']) && !isset($data['available_slots'])) {
                $data['available_slots'] = $data['total_slots'];
            }

            $data['landlord_id'] = auth()->id();
            $data['is_verified'] = false;
            $data['status']      = 'pending';

            // Store first image in legacy column
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('listings', 'public');
                $data['image'] = $path;
            }

            $listing = PropertyListing::create($data);

            // ✅ Notify all admins that a new listing needs review
            \App\Models\User::where('role', 'admin')->get()->each(function ($admin) use ($listing) {
                \App\Models\Notification::notify(
                    $admin->id,
                    'listing_pending_review',
                    'New Listing Pending Review',
                    "\"{$listing->title}\" was submitted and needs approval.",
                    '/admin/listings'
                );
            });

            // ✅ Store all images in listing_images table
            $this->saveImages($request, $listing);

            // ✅ Add amenities
            $this->syncAmenities($request, $listing);

            return response()->json([
                'success' => true,
                'data'    => $listing->load(['amenities', 'images']),
                'message' => 'Listing created successfully'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET MY LISTINGS (LANDLORD ONLY)
     */
    public function myListings(Request $request)
    {
        try {
            $listings = PropertyListing::where('landlord_id', auth()->id())
                ->with(['amenities', 'images', 'landlord'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data'    => $listings,
                'message' => 'My listings retrieved'
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * UPDATE LISTING (LANDLORD ONLY)
     */
    public function update(Request $request, $id)
    {
        try {
            $listing = PropertyListing::findOrFail($id);

            if ($listing->landlord_id !== auth()->id() && auth()->user()->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $data = $request->validate([
                'title'                 => 'sometimes|string|max:255',
                'description'           => 'sometimes|string',
                'terms_and_conditions'  => 'nullable|string',
                'reservation_fee'       => 'nullable|numeric|min:0',
                'price'                 => 'sometimes|numeric|min:0',
                'address'               => 'sometimes|string|max:255',
                'barangay'              => 'nullable|string|max:255',
                'property_type'         => 'sometimes|string',
                'latitude'              => 'nullable|numeric',
                'longitude'             => 'nullable|numeric',
                'image'                 => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
                'images.*'              => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
                'amenities'             => 'nullable|json',
                'total_slots'           => 'nullable|integer|min:1',
                'available_slots'       => 'nullable|integer|min:0',
            ]);

            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('listings', 'public');
                $data['image'] = $path;
            }

            $wasRejected = $listing->status === 'rejected';

            $listing->update($data);

            // ✅ If listing was rejected and landlord resubmits — reset to pending for re-review
            if ($wasRejected) {
                $listing->update(['status' => 'pending', 'is_verified' => false]);
            }

            // ✅ Add new images (don't delete old ones)
            $this->saveImages($request, $listing);

            // ✅ Update amenities
            $this->syncAmenities($request, $listing);

            return response()->json([
                'success' => true,
                'data'    => $listing->load(['amenities', 'images']),
                'message' => 'Listing updated successfully'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE LISTING (LANDLORD ONLY)
     */
    public function destroy($id)
    {
        try {
            $user    = auth()->user();
            $listing = PropertyListing::findOrFail($id);

            if ($listing->landlord_id !== $user->id && $user->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'You do not have permission to delete this listing'], 403);
            }

            $listing->amenities()->detach();
            $listing->images()->delete();
            $listing->delete();

            return response()->json(['success' => true, 'message' => 'Listing deleted successfully']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Listing not found'], 404);
        }
    }

    /**
     * ADMIN: GET PENDING LISTINGS
     */
    public function pending()
    {
        try {
            $pendingListings = PropertyListing::where('is_verified', false)
                ->where('status', '!=', 'rejected')
                ->with(['landlord', 'amenities', 'images'])
                ->orderBy('created_at', 'desc')
                ->paginate(15);

            return response()->json(['success' => true, 'data' => $pendingListings, 'message' => 'Pending listings retrieved']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ADMIN: APPROVE LISTING
     */
    public function approve($id)
    {
        try {
            $listing = PropertyListing::findOrFail($id);

            if ($listing->is_verified) {
                return response()->json(['success' => false, 'message' => 'This listing is already verified'], 422);
            }

            $listing->update(['is_verified' => true, 'status' => 'available']);

            \App\Models\AuditLog::record(
                auth()->id(),
                'listing_approved',
                'PropertyListing',
                $listing->id,
                "Approved listing \"{$listing->title}\"."
            );

            \App\Models\Notification::notify(
                $listing->landlord_id,
                'listing_approved',
                'Listing Approved',
                "Your listing \"{$listing->title}\" is now live.",
                '/landlord/listings'
            );

            return response()->json([
                'success' => true,
                'data'    => $listing->load(['amenities', 'images']),
                'message' => 'Listing approved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Listing not found'], 404);
        }
    }

    /**
     * ADMIN: REJECT LISTING
     */
    public function reject(Request $request, $id)
    {
        try {
            $listing = PropertyListing::findOrFail($id);
            $reason  = $request->input('reason', 'Listing rejected by administrator');

            $listing->update(['status' => 'rejected', 'is_verified' => false]);

            \App\Models\AuditLog::record(
                auth()->id(),
                'listing_rejected',
                'PropertyListing',
                $listing->id,
                "Rejected listing \"{$listing->title}\". Reason: {$reason}"
            );

            \App\Models\Notification::notify(
                $listing->landlord_id,
                'listing_rejected',
                'Listing Rejected',
                "Your listing \"{$listing->title}\" was rejected. Reason: {$reason}",
                '/landlord/listings'
            );

            \Log::info("Listing {$id} rejected. Reason: {$reason}");

            return response()->json(['success' => true, 'message' => 'Listing rejected successfully']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Listing not found'], 404);
        }
    }

    // ================================================
    // PRIVATE HELPERS
    // ================================================

    /**
     * Save uploaded images to listing_images table
     */
    private function saveImages(Request $request, PropertyListing $listing)
    {
        $files = [];

        // Support both images[] array and single image
        if ($request->hasFile('images')) {
            $files = $request->file('images');
        } elseif ($request->hasFile('image')) {
            $files = [$request->file('image')];
        }

        $existingCount = $listing->images()->count();

        foreach ($files as $index => $file) {
            $path = $file->store('listings', 'public');

            ListingImage::create([
                'listing_id' => $listing->id,
                'image_path' => $path,
                'is_cover'   => ($existingCount === 0 && $index === 0), // first image is cover
                'order'      => $existingCount + $index,
            ]);
        }
    }

    /**
     * Sync amenities from request
     */
    private function syncAmenities(Request $request, PropertyListing $listing)
    {
        if (!$request->has('amenities')) return;

        $amenities = json_decode($request->input('amenities'), true);

        if (!is_array($amenities) || empty($amenities)) {
            $listing->amenities()->detach();
            return;
        }

        $amenityIds = [];
        foreach ($amenities as $value) {
            // Try icon match first (static amenities), then name match (custom amenities)
            $amenity = Amenity::where('icon', $value)->first()
                    ?? Amenity::whereRaw('LOWER(name) = ?', [strtolower($value)])->first();
            if ($amenity) $amenityIds[] = $amenity->id;
        }

        $listing->amenities()->sync($amenityIds);
    }
}