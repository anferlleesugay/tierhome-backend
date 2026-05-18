<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * START OR GET CONVERSATION
     * One conversation per tenant+landlord pair
     */
    public function start(Request $request, $landlordId)
    {
        try {
            $user = auth()->user();

            if ($user->id === (int)$landlordId) {
                return response()->json(['success' => false, 'message' => 'Cannot message yourself'], 422);
            }

            $landlord = User::find($landlordId);
            if (!$landlord) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $listingId = $request->input('listing_id');

            // ✅ ONE conversation per tenant+landlord
            $conversation = Conversation::where('tenant_id', $user->id)
                ->where('landlord_id', $landlordId)
                ->first();

            if (!$conversation) {
                $conversation = Conversation::create([
                    'tenant_id'   => $user->id,
                    'landlord_id' => $landlordId,
                    'listing_id'  => $listingId,
                ]);
            } elseif ($listingId) {
                // Update to latest listing being inquired about
                $conversation->update(['listing_id' => $listingId]);
            }

            $conversation->load([
                'messages.sender',
                'messages.listingCard.images',
                'messages.replyTo.sender',
                'tenant',
                'landlord',
                'listing.images',
            ]);

            return response()->json([
                'success' => true,
                'data'    => $conversation,
                'message' => 'Conversation ready',
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET ALL CONVERSATIONS FOR CURRENT USER
     */
    public function myConversations()
    {
        try {
            $userId = auth()->id();

            $conversations = Conversation::where('tenant_id', $userId)
                ->orWhere('landlord_id', $userId)
                ->with([
                    'messages'    => fn($q) => $q->latest()->limit(1),
                    'tenant',
                    'landlord',
                    'listing.images',
                    // ✅ Load all listing_message messages so landlord can group by listing
                    'listingMessages.listingCard.images',
                ])
                ->orderBy('updated_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $conversations,
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET SINGLE CONVERSATION WITH MESSAGES
     */
    public function show($id)
    {
        try {
            $user         = auth()->user();
            $conversation = Conversation::with([
                'messages.sender',
                'messages.listingCard.images',
                'messages.replyTo.sender',
                'tenant',
                'landlord',
                'listing.images',
            ])->findOrFail($id);

            if ($user->id !== $conversation->tenant_id && $user->id !== $conversation->landlord_id) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            return response()->json(['success' => true, 'data' => $conversation]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
    }

    /**
     * DELETE CONVERSATION
     */
    public function delete($id)
    {
        try {
            $user         = auth()->user();
            $conversation = Conversation::findOrFail($id);

            if ($user->id !== $conversation->tenant_id && $user->id !== $conversation->landlord_id) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            $conversation->delete();
            return response()->json(['success' => true, 'message' => 'Conversation deleted']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
    }
}