<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Message;
use App\Models\User;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $messages = Message::where('sender_id', $user->id)
            ->orWhere('receiver_id', $user->id)
            ->with(['sender', 'receiver', 'group'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $messages
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'content' => 'required|string'
        ]);

        if (!auth()->user()->canMessageUser($request->recipient_id)) {
            return response()->json([
                'message' => 'You are not allowed to message this user'
            ], 403);
        }

        $message = Message::create([
            'sender_id' => auth()->id(),
            'recipient_id' => $request->recipient_id,
            'content' => $request->content
        ]);

        return response()->json($message, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $message = Message::with(['sender', 'receiver', 'group'])->find($id);

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found'
            ], 404);
        }

        // Ensure user can only view their own messages or messages in groups they belong to
        $user = Auth::user();
        $canView = $message->sender_id == $user->id ||
            $message->receiver_id == $user->id ||
            ($message->group_id && $user->groups()->where('group_id', $message->group_id)->exists());

        if (!$canView) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view this message'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $message
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $message = Message::find($id);

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found'
            ], 404);
        }

        // Only allow the sender to update the message
        $user = Auth::user();
        if ($message->sender_id != $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to update this message'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $message->update([
            'content' => $request->content
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message updated successfully',
            'data' => $message->load(['sender', 'receiver', 'group'])
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $message = Message::find($id);

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found'
            ], 404);
        }

        // Only allow the sender to delete the message
        $user = Auth::user();
        if ($message->sender_id != $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete this message'
            ], 403);
        }

        $message->delete();

        return response()->json([
            'success' => true,
            'message' => 'Message deleted successfully'
        ]);
    }

    /**
     * Get conversation history between two users
     */
    public function getConversation(string $userId): JsonResponse
    {
        $currentUser = Auth::user();
        $otherUser = User::find($userId);

        if (!$otherUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $messages = Message::where(function ($query) use ($currentUser, $userId) {
            $query->where('sender_id', $currentUser->id)
                ->where('receiver_id', $userId);
        })
            ->orWhere(function ($query) use ($currentUser, $userId) {
                $query->where('sender_id', $userId)
                    ->where('receiver_id', $currentUser->id);
            })
            ->with(['sender', 'receiver'])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $messages
        ]);
    }

    /**
     * Get messages for a specific group
     */
    public function getGroupMessages(string $groupId): JsonResponse
    {
        $user = Auth::user();
        $group = Group::find($groupId);

        if (!$group) {
            return response()->json([
                'success' => false,
                'message' => 'Group not found'
            ], 404);
        }

        // Check if user is a member of the group
        if (!$group->users()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a member of this group'
            ], 403);
        }

        $messages = Message::where('group_id', $groupId)
            ->with(['sender'])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $messages
        ]);
    }

    /**
     * Mark a message as read
     */
    public function markAsRead(string $id): JsonResponse
    {
        $message = Message::find($id);

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found'
            ], 404);
        }

        $user = Auth::user();

        // Only allow the receiver to mark a message as read
        if ($message->receiver_id != $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to mark this message as read'
            ], 403);
        }

        $message->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Message marked as read',
            'data' => $message
        ]);
    }

    /**
     * Get messages for a user in all their groups
     * This endpoint returns only messages from groups where the user is a member
     */
    public function getUserGroupMessages(): JsonResponse
    {
        $user = Auth::user();
        $groupMessages = [];

        // Get all groups the user belongs to
        $userGroups = $user->groups()->with(['messages' => function ($query) {
            $query->with('sender')->orderBy('created_at', 'desc');
        }])->get();

        foreach ($userGroups as $group) {
            $groupMessages[] = [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'description' => $group->description,
                'messages' => $group->messages
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $groupMessages
        ]);
    }
}
