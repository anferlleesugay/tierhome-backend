<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\RankingController;
use App\Http\Controllers\SavedListingController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AmenityController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    // OTP registration flow
    Route::post('/send-otp',        [AuthController::class, 'sendOtp']);
    Route::post('/verify-otp',      [AuthController::class, 'verifyOtp']);
    Route::post('/register',        [AuthController::class, 'register']);
    Route::post('/login',           [AuthController::class, 'login']);

    // Password reset
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);

    Route::middleware('auth:api')->group(function () {
        Route::get('/me',          [AuthController::class, 'me']);
        Route::post('/logout',     [AuthController::class, 'logout']);
    });
});

Route::get('/listings', [ListingController::class, 'index']);
Route::get('/listings/nearby', [ListingController::class, 'nearby']);
Route::get('/listings/{id}', [ListingController::class, 'show']);

// ✅ Public profile (anyone can view)
Route::get('/profile/{id}', [ProfileController::class, 'show']);

// ✅ Public amenities list
Route::get('/amenities', [AmenityController::class, 'index']);

// ✅ Chatbot endpoint
Route::post('/chatbot/message', [ChatbotController::class, 'message']);

Route::middleware('auth:api')->group(function () {
    // ✅ Notifications
    Route::get('/notifications',              [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read',    [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all',     [NotificationController::class, 'markAllAsRead']);

    // ✅ Profile management
    Route::get('/profile',           [ProfileController::class, 'me']);
    Route::post('/profile/phone',    [ProfileController::class, 'updatePhone']);
    Route::post('/profile/email',    [ProfileController::class, 'updateEmail']);
    Route::post('/profile/password', [ProfileController::class, 'updatePassword']);
    Route::post('/profile/picture',  [ProfileController::class, 'updateProfilePicture']);

    Route::post('/save/{id}', [SavedListingController::class, 'save']);
    Route::delete('/save/{id}', [SavedListingController::class, 'unsave']);
    Route::get('/saved', [SavedListingController::class, 'mySaved']);

    Route::post('/ranking', [RankingController::class, 'store']);
    Route::get('/my-ranking', [RankingController::class, 'myRanking']);

    Route::post('/verify', [VerificationController::class, 'submit']);
    Route::get('/verify/status', [VerificationController::class, 'myStatus']);

    Route::post('/conversation/{landlordId}', [ConversationController::class, 'start']);
    Route::get('/conversations', [ConversationController::class, 'myConversations']);
    Route::get('/conversation/{id}', [ConversationController::class, 'show']);
    Route::delete('/conversation/{id}', [ConversationController::class, 'delete']);

    Route::post('/message', [MessageController::class, 'send']);
    Route::put('/message/{id}/read', [MessageController::class, 'markAsRead']);
    Route::delete('/message/{id}', [MessageController::class, 'delete']);
    Route::get('/conversation/{conversationId}/messages', [MessageController::class, 'getMessages']);

    Route::post('/reserve/{listingId}', [ReservationController::class, 'reserve']);
    Route::get('/reservations', [ReservationController::class, 'myReservations']);
    Route::put('/reservation/{id}/cancel', [ReservationController::class, 'cancel']);

    // ✅ PayMongo sandbox payment routes (tenant-facing)
    Route::post('/reservation/{id}/pay',             [PaymentController::class, 'createIntent']);
    Route::post('/reservation/{id}/confirm-payment', [PaymentController::class, 'confirmPayment']);
});

Route::middleware(['auth:api', 'verified'])->group(function () {
    Route::post('/listings', [ListingController::class, 'store']);
    Route::get('/my-listings', [ListingController::class, 'myListings']);
    Route::put('/listings/{id}', [ListingController::class, 'update']);
    Route::post('/listings/{id}', [ListingController::class, 'update']); // for FormData _method spoofing
    Route::delete('/listings/{id}', [ListingController::class, 'destroy']);

    Route::get('/landlord-reservations', [ReservationController::class, 'landlordReservations']);

    // ✅ Amenity requests (landlord)
    Route::post('/amenity-requests',     [AmenityController::class, 'request']);
    Route::get('/amenity-requests/mine', [AmenityController::class, 'myRequests']);
    Route::put('/reservation/{id}/respond', [ReservationController::class, 'respond']);
});

Route::middleware(['auth:api', 'admin'])->group(function () {
    Route::get('/admin/verify/pending', [VerificationController::class, 'pending']);
    Route::get('/admin/verify/{id}', [VerificationController::class, 'show']);
    Route::post('/admin/verify/{id}/approve', [VerificationController::class, 'approve']);
    Route::post('/admin/verify/{id}/reject', [VerificationController::class, 'reject']);
    Route::get('/admin/verify/faces/verified', [VerificationController::class, 'listVerifiedFaces']);

    // ✅ Amenity requests (admin)
    Route::get('/admin/amenity-requests',               [AmenityController::class, 'allRequests']);
    Route::get('/admin/amenity-requests/pending',       [AmenityController::class, 'pending']);
    Route::post('/admin/amenity-requests/{id}/approve', [AmenityController::class, 'approve']);
    Route::post('/admin/amenity-requests/{id}/reject',  [AmenityController::class, 'reject']);
    Route::get('/admin/amenities',                      [AmenityController::class, 'indexAll']);
    Route::delete('/admin/amenities/{id}',              [AmenityController::class, 'destroy']);
    Route::put('/admin/amenities/{id}/icon',            [AmenityController::class, 'updateIcon']);

    Route::get('/admin/listings/pending', [ListingController::class, 'pending']);
    Route::post('/admin/listings/{id}/approve', [ListingController::class, 'approve']);
    Route::post('/admin/listings/{id}/reject', [ListingController::class, 'reject']);
});