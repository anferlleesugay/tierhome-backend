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
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\MaintenanceRequestController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\DeactivationRequestController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    // OTP registration flow — throttled per IP on top of the per-code
    // attempt lock in OtpCode/AuthController, since neither existed before
    // and the 6-digit code was otherwise brute-forceable within its
    // 10-minute window.
    //
    // NOTE: the third throttle argument is an explicit bucket prefix.
    // Laravel's basic throttle:max,decay middleware keys its rate limit by
    // domain+IP only (not the route), so any two routes using the exact
    // same max,decay numbers would silently share one combined bucket per
    // IP without a distinct prefix here — confirmed by testing, where
    // exhausting request-reactivation's budget also 429'd login.
    Route::post('/send-otp',        [AuthController::class, 'sendOtp'])->middleware('throttle:5,1,send-otp');
    Route::post('/verify-otp',      [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1,verify-otp');
    Route::post('/register',        [AuthController::class, 'register'])->middleware('throttle:10,1,register');
    // login: reCAPTCHA v3 covers bot/automated traffic, but had no rate
    // limit at all — throttle added as defense-in-depth against a human
    // attacker (or a token farming service) hammering real credentials.
    Route::post('/login',           [AuthController::class, 'login'])->middleware('throttle:10,1,login');

    // Deactivated account requesting reactivation — public, verifies
    // credentials itself. Had neither reCAPTCHA nor a rate limit (unlike
    // login), so this was the weaker password-checking endpoint.
    Route::post('/request-reactivation', [DeactivationRequestController::class, 'requestReactivation'])->middleware('throttle:10,1,reactivation');

    // Password reset
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);

    Route::middleware(['auth:api', 'active'])->group(function () {
        Route::get('/me',          [AuthController::class, 'me']);
        Route::post('/logout',     [AuthController::class, 'logout']);
    });
});

Route::get('/listings', [ListingController::class, 'index']);
Route::get('/listings/count', [ListingController::class, 'publicCount']);
Route::get('/listings/nearby', [ListingController::class, 'nearby']);
Route::get('/listings/{id}', [ListingController::class, 'show']);

// ✅ Public profile (anyone can view)
Route::get('/profile/{id}', [ProfileController::class, 'show']);

// ✅ Public amenities list
Route::get('/amenities', [AmenityController::class, 'index']);

// ✅ Chatbot endpoint — public (no login required, so visitors can ask
// questions before signing up), but was previously uncapped: anyone could
// script requests against it and each one hits the paid Gemini API.
// Two-tier throttle, keyed by IP: a burst limit to stop rapid scripted
// abuse, and a daily cap to bound total API spend even from a slow drip.
Route::post('/chatbot/message', [ChatbotController::class, 'message'])
    ->middleware(['throttle:15,1,chatbot-burst', 'throttle:200,1440,chatbot-daily']);

Route::middleware(['auth:api', 'active'])->group(function () {
    // ✅ Notifications
    Route::get('/notifications',              [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read',    [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all',     [NotificationController::class, 'markAllAsRead']);

    // ✅ Push Notification Device Tokens
    Route::post('/device-token',        [DeviceTokenController::class, 'register']);
    Route::delete('/device-token',      [DeviceTokenController::class, 'unregister']);

    // ✅ Account deactivation (self-request, admin approves)
    Route::post('/account/deactivate',        [DeactivationRequestController::class, 'submit']);
    Route::get('/account/deactivate/status',  [DeactivationRequestController::class, 'myStatus']);
    Route::delete('/account/deactivate/{id}', [DeactivationRequestController::class, 'cancel']);

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
    Route::get('/ranking/national-income-context', [RankingController::class, 'nationalIncomeContext']);

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

    // ✅ Per-listing analytics — admin (any listing) or the owning landlord (their own), enforced in the controller
    Route::get('/listings/{id}/analytics', [AnalyticsController::class, 'listingAnalytics']);
});

Route::middleware(['auth:api', 'verified', 'active'])->group(function () {
    Route::post('/listings', [ListingController::class, 'store']);
    Route::get('/my-listings', [ListingController::class, 'myListings']);
    Route::get('/landlord/analytics', [AnalyticsController::class, 'landlordOverview']);
    Route::get('/landlord/dashboard-widgets', [AnalyticsController::class, 'landlordDashboardWidgets']);
    Route::get('/landlord/reports/reservations', [ReportController::class, 'reservationHistory']);
    Route::get('/landlord/reports/revenue', [ReportController::class, 'revenueSummary']);
    Route::get('/landlord/reports/occupancy', [ReportController::class, 'occupancyReport']);
    Route::get('/landlord/reports/expenses', [ReportController::class, 'expenseReport']);
    Route::get('/landlord/report-preference', [ReportController::class, 'getPreference']);
    Route::put('/landlord/report-preference', [ReportController::class, 'updatePreference']);

    Route::post('/maintenance-requests', [MaintenanceRequestController::class, 'submit']);
    Route::get('/maintenance-requests/mine', [MaintenanceRequestController::class, 'myRequests']);
    Route::get('/landlord/maintenance-requests', [MaintenanceRequestController::class, 'landlordRequests']);
    Route::put('/maintenance-requests/{id}/status', [MaintenanceRequestController::class, 'updateStatus']);

    Route::get('/landlord/expenses', [ExpenseController::class, 'index']);
    Route::post('/landlord/expenses', [ExpenseController::class, 'store']);
    Route::delete('/landlord/expenses/{id}', [ExpenseController::class, 'destroy']);
    Route::get('/landlord/expenses-summary', [ExpenseController::class, 'summary']);
    Route::put('/listings/{id}', [ListingController::class, 'update']);
    Route::post('/listings/{id}', [ListingController::class, 'update']); // for FormData _method spoofing
    Route::delete('/listings/{id}', [ListingController::class, 'destroy']);

    Route::get('/landlord-reservations', [ReservationController::class, 'landlordReservations']);

    // ✅ Amenity requests (landlord)
    Route::post('/amenity-requests',     [AmenityController::class, 'request']);
    Route::get('/amenity-requests/mine', [AmenityController::class, 'myRequests']);
    Route::put('/reservation/{id}/respond', [ReservationController::class, 'respond']);
});

Route::middleware(['auth:api', 'admin', 'active'])->group(function () {
    Route::get('/admin/verify/pending', [VerificationController::class, 'pending']);
    Route::get('/admin/verify/{id}', [VerificationController::class, 'show']);
    // Serves the actual ID/selfie image bytes from the private disk —
    // this route is inside the ['auth:api','admin','active'] group above,
    // which is what gates access now, replacing the old direct
    // public-storage URL that had no auth check at all.
    Route::get('/admin/verify/{id}/image/{type}', [VerificationController::class, 'image']);
    Route::post('/admin/verify/{id}/approve', [VerificationController::class, 'approve']);
    Route::post('/admin/verify/{id}/reject', [VerificationController::class, 'reject']);
    Route::get('/admin/verify/faces/verified', [VerificationController::class, 'listVerifiedFaces']);
    Route::get('/admin/analytics', [AnalyticsController::class, 'adminOverview']);

    // ✅ Account deactivation review (admin)
    Route::get('/admin/deactivation-requests',               [DeactivationRequestController::class, 'pending']);
    Route::post('/admin/deactivation-requests/{id}/approve', [DeactivationRequestController::class, 'approve']);
    Route::post('/admin/deactivation-requests/{id}/reject',  [DeactivationRequestController::class, 'reject']);
    Route::get('/admin/users',                                [DeactivationRequestController::class, 'allUsers']);
    Route::get('/admin/users/deactivated',                    [DeactivationRequestController::class, 'deactivatedUsers']);
    Route::post('/admin/users/{userId}/deactivate',           [DeactivationRequestController::class, 'adminDeactivate']);
    Route::post('/admin/users/{userId}/reactivate',           [DeactivationRequestController::class, 'reactivate']);

    // ✅ Per-landlord analytics (admin)
    Route::get('/admin/landlords',                    [AnalyticsController::class, 'landlordsList']);
    Route::get('/admin/landlords/{id}/analytics',      [AnalyticsController::class, 'landlordOverviewForAdmin']);

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
    Route::get('/admin/listings-summary', [AnalyticsController::class, 'allListingsSummary']);
    Route::get('/admin/dashboard-widgets', [AnalyticsController::class, 'adminDashboardWidgets']);
    Route::get('/admin/model-monitoring', [AnalyticsController::class, 'modelMonitoring']);
    Route::get('/admin/revenue-by-owner', [AnalyticsController::class, 'revenueByOwner']);
    Route::get('/admin/platform-revenue-report', [AnalyticsController::class, 'platformRevenueReport']);
    Route::get('/admin/audit-logs', [AuditLogController::class, 'index']);
});