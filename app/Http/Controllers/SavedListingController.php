<?php

namespace App\Http\Controllers;

use App\Models\SavedListing;

class SavedListingController extends Controller
{
    /**
     * SAVE A LISTING
     * User can save/bookmark a property listing
     */
    public function save($id)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Check if already saved
            $exists = SavedListing::where('tenant_id', $user->id)
                ->where('listing_id', $id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Already saved'
                ], 422);
            }

            $saved = new SavedListing();
            $saved->tenant_id = $user->id;
            $saved->listing_id = $id;
            $saved->save();

            return response()->json([
                'success' => true,
                'message' => 'Saved successfully'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * UNSAVE A LISTING
     * User can remove a saved listing
     */
    public function unsave($id)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $deleted = SavedListing::where('tenant_id', $user->id)
                ->where('listing_id', $id)
                ->delete();

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Removed from saved'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'This listing was not saved'
                ], 404);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET MY SAVED LISTINGS
     * Returns all listings saved by the current user
     */
    public function mySaved()
    {
        try {
            $user = auth()->user();

            // ✅ FIXED: Check authentication
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $saved = SavedListing::with('listing')
                ->where('tenant_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $saved,
                'message' => 'Saved listings retrieved'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
