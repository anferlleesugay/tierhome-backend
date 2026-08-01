<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    /**
     * SEND A MESSAGE
     * User sends a message in a conversation
     */
    public function send(Request $request)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Validate input
            $validated = $request->validate([
                'conversation_id' => 'required|integer|exists:conversations,id',
                'message'         => 'required|string|min:1|max:5000',
                'reply_to_id'     => 'nullable|integer|exists:messages,id',
                'message_type'    => 'nullable|string|in:text,listing_card,listing_message',
                'listing_card_id' => 'nullable|integer|exists:property_listings,id',
            ]);

            // Check if conversation exists
            $conversation = Conversation::findOrFail($validated['conversation_id']);

            // Check if user is part of this conversation
            if ($user->id !== $conversation->tenant_id && $user->id !== $conversation->landlord_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not part of this conversation'
                ], 403);
            }

            // Create message
            $message = Message::create([
                'conversation_id' => $validated['conversation_id'],
                'sender_id'       => $user->id,
                'message'         => $validated['message'],
                'reply_to_id'     => $validated['reply_to_id'] ?? null,
                'message_type'    => $validated['message_type'] ?? 'text',
                'listing_card_id' => $validated['listing_card_id'] ?? null,
            ]);

            $conversation->touch();
            $message->load(['sender', 'replyTo.sender', 'listingCard.images']);

            // ✅ Notify the recipient (whichever party didn't just send this message)
            $recipientId = $user->id === $conversation->tenant_id ? $conversation->landlord_id : $conversation->tenant_id;
            \App\Models\Notification::notify(
                $recipientId,
                'new_message',
                'New Message',
                "{$user->first_name} sent you a message.",
                $user->role === 'landlord' ? '/tenant/messages' : '/landlord/messages'
            );

            return response()->json([
                'success' => true,
                'data' => $message,
                'message' => 'Message sent successfully'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * MARK MESSAGE AS READ
     */
    public function markAsRead(Request $request, $id)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $message = Message::with('conversation')->findOrFail($id);

            // Check if user is part of the conversation
            $conversation = $message->conversation;
            if ($user->id !== $conversation->tenant_id && $user->id !== $conversation->landlord_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this message'
                ], 403);
            }

            // Mark as read if not already read
            if (!$message->read_at) {
                $message->update(['read_at' => now()]);
            }

            return response()->json([
                'success' => true,
                'data' => $message,
                'message' => 'Message marked as read'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found'
            ], 404);
        }
    }

    /**
     * DELETE MESSAGE
     * Only the sender can delete their own message
     */
    public function delete($id)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $message = Message::findOrFail($id);

            // Only sender or admin can delete
            if ($message->sender_id !== $user->id && $user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only delete your own messages'
                ], 403);
            }

            $message->delete();

            return response()->json([
                'success' => true,
                'message' => 'Message deleted'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found'
            ], 404);
        }
    }

    /**
     * GET MESSAGES IN A CONVERSATION
     * Paginated messages with sender info
     */
    public function getMessages($conversationId)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $conversation = Conversation::findOrFail($conversationId);

            // Check if user is part of this conversation
            if ($user->id !== $conversation->tenant_id && $user->id !== $conversation->landlord_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this conversation'
                ], 403);
            }

            $messages = Message::where('conversation_id', $conversationId)
                ->with(['sender', 'replyTo.sender', 'listingCard.images'])
                ->orderBy('created_at', 'asc')
                ->paginate(50);

            return response()->json([
                'success' => true,
                'data' => $messages,
                'message' => 'Messages retrieved'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}