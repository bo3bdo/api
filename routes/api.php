<?php

use App\Models\User;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;

Route::middleware('guest')->group(function () {
    //Login
    Route::post('login', function (Request $request) {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        return response()->json([
            'token' => $user->createToken($request->device_name)->plainTextToken,
            'user' => $user,
        ]);
    })->name('login');

    //Register
    Route::post('register', function (Request $request) {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8',
            'device_name' => 'required',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'token' => $user->createToken($request->device_name)->plainTextToken,
            'user' => $user,
        ]);
    })->name('register');

    //Forgot Password
    Route::get('forgot-password', function (Request $request) {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['status' => __($status)])
            : response()->json(['email' => __($status)], 400);
    })->name('password.request');

    //Reset Password
    Route::get('reset-password/{token}', function (Request $request) {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        return $status == Password::PASSWORD_RESET
            ? response()->json(['status' => __($status)])
            : response()->json(['email' => __($status)], 400);
    })->name('password.reset');
});

Route::middleware('auth:sanctum')->group(function () {

    //Get User And Test Auth token
    Route::get('user', function (Request $request) {
        $user = $request->user();
        // return response()->json([
        //     'name' => $user->name,
        //     'email' => $user->email,
        // ]);
        return $user;
    });

    //Logout
    Route::post('logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    })->name('logout');

    // Get all messages from user's groups
    Route::get('user/group-messages', function () {
        $user = auth()->user();

        // Get IDs of all groups that the user belongs to
        $groupIds = $user->groups()->pluck('group_id')->toArray();

        // Get messages for these groups
        $groupMessages = Message::whereIn('group_id', $groupIds)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $groupMessages
        ]);
    });

    // Change Password
    Route::post('change-password', function (Request $request) {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|different:current_password',
            'new_password_confirmation' => 'required|same:new_password'
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect'
            ], 401);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully'
        ]);
    });

    // Allowed Contacts Management
    Route::get('allowed-contacts', function (Request $request) {
        $contacts = $request->user()->allowedContacts()->with('contact')->get();
        return response()->json($contacts);
    });

    Route::post('allowed-contacts', function (Request $request) {
        $request->validate([
            'contact_id' => 'required|exists:users,id'
        ]);

        $request->user()->allowedContacts()->create([
            'contact_id' => $request->contact_id
        ]);

        return response()->json(['message' => 'Contact added successfully']);
    });

    Route::delete('allowed-contacts/{contact_id}', function ($contact_id) {
        auth()->user()->allowedContacts()->where('contact_id', $contact_id)->delete();
        return response()->json(['message' => 'Contact removed successfully']);
    });

    // Groups API
    Route::apiResource('groups', GroupController::class);
    Route::post('groups/{group}/users', [GroupController::class, 'addUser']);
    Route::delete('groups/{group}/users/{user}', [GroupController::class, 'removeUser']);

    // Notifications API
    Route::apiResource('notifications', NotificationController::class);
    Route::get('users/{user}/notifications', [NotificationController::class, 'userNotifications']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/send-to-group', [NotificationController::class, 'sendToGroup']);

    // Messages API
    Route::apiResource('messages', MessageController::class);
    Route::get('conversations/{user}', [MessageController::class, 'getConversation']);
    Route::get('groups/{group}/messages', [MessageController::class, 'getGroupMessages']);
    Route::post('messages/{message}/read', [MessageController::class, 'markAsRead']);
});
