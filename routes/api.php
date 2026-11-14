<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\HobbyController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\BetaDeviceController;
use App\Http\Controllers\UserHobbyController;
use App\Http\Controllers\ResearchController;
use App\Http\Controllers\CountriesController;
use App\Http\Controllers\RevenueCatWebhookController;
use App\Http\Controllers\TeamInviteController;
use App\Http\Controllers\UserDailyArticlesController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AppBlockLogController;
use App\Http\Controllers\AppUsageLogsController;
use App\Http\Controllers\ChallengeLogsController;
use App\Http\Controllers\ActivityLogsController;
use App\Http\Controllers\ChallengePacksWebhookController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\FocusSessionLogsController;
use App\Http\Controllers\GoalLogsController;
use App\Http\Controllers\EmotionLogsController;
use App\Http\Controllers\StreakLogsController;
use App\Http\Controllers\AppleAuthController;
use App\Http\Controllers\ChallengePackController;
use App\Http\Controllers\GroupChallengeController;
use App\Http\Controllers\GroupChallengeSessionController;
use App\Http\Controllers\PromoCodeController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\ChallengeReservationController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {

    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/google', [GoogleAuthController::class, 'GoogleLogin']);
    Route::post('/auth/apple', [AppleAuthController::class, 'appleLogin']);
    Route::post('/webhooks/revenuecat', RevenueCatWebhookController::class);
    Route::post('/webhooks/revenuecat/challenge-packs', [ChallengePacksWebhookController::class, 'handleChallengePackWebhook']);

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:forgot-password');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // Beta testing
    Route::post('/beta-login', [BetaDeviceController::class, 'checkDevice']);
    Route::get('/devices', [BetaDeviceController::class, 'getAll']);
    Route::delete('/devices', [BetaDeviceController::class, 'deleteAll']);
    Route::delete('/devices/{id}', [BetaDeviceController::class, 'deleteById']);

    // Leaderboard routes
    Route::get('leaderboard', [GroupChallengeSessionController::class, 'index']);
    Route::get('leaderboard/emotion', [EmotionLogsController::class, 'counts']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/access', [MembershipController::class, 'access']);
        Route::get('/membership/plans', fn (\App\Services\Membership\MembershipPlanCatalog $catalog) => response()->json($catalog->response()));
        Route::post('/trial-checkouts', [MembershipController::class, 'trialCheckout']);
        Route::post('/trial-checkouts/cancel', [MembershipController::class, 'cancelTrialCheckout']);
        Route::post('/trials/start', [MembershipController::class, 'startTrial']);
        Route::get('/access/categories', [MembershipController::class, 'categories']);
        Route::put('/access/categories', [MembershipController::class, 'updateCategories']);
        Route::post('/access/announcements/acknowledge', [MembershipController::class, 'acknowledge']);
        Route::post('/access/reconcile', [MembershipController::class, 'reconcile']);
        Route::post('/membership/purchase-checkouts', [MembershipController::class, 'purchaseCheckout']);
        Route::post('/membership/change-checkouts', [MembershipController::class, 'changeCheckout']);
        Route::post('/membership/attempt-status', [MembershipController::class, 'attemptStatus']);
        Route::post('/membership/checkout-recovery', [MembershipController::class, 'recoverCheckout']);
        Route::post('/membership/checkouts/cancel', [MembershipController::class, 'cancelCheckout']);
        Route::post('/challenge-reservations', [ChallengeReservationController::class, 'reserve']);
        Route::get('/challenge-reservations', [ChallengeReservationController::class, 'index']);
        Route::post('/challenge-reservations/{reservationId}/resume', [ChallengeReservationController::class, 'resume']);
        Route::post('/challenge-reservations/{reservationId}/complete', [ChallengeReservationController::class, 'complete']);
        Route::post('/challenge-reservations/{reservationId}/abandon', [ChallengeReservationController::class, 'abandon']);

        Route::post('promo-codes/validate', [PromoCodeController::class, 'validateCode']);
        Route::post('promo-codes/confirm', [PromoCodeController::class, 'confirm']);

        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/user/current-user', [AuthController::class, 'currentUser']);
        Route::get('/user/emergency', [AuthController::class, 'currentUserCountry']);
        Route::patch('/user/status', [AuthController::class, 'updateStatus']);
        Route::post('/user/fcm-token', [AuthController::class, 'updateFcmToken']);
        Route::post('/user/activity', [AuthController::class, 'recordActivity']);
        Route::delete('/user/delete', [AuthController::class, 'deleteAccount']);

        // Challenge pack routes
        Route::get('challenge-pack', [ChallengePackController::class, 'index']);
        Route::patch('challenge-pack', [ChallengePackController::class, 'decrementRemaining']);
        Route::post('challenge-pack/reconcile-legacy', \App\Http\Controllers\Api\V1\LegacyPackReconciliationController::class);
    
        // Group challenge routes
        Route::post('group-challenges/start', [GroupChallengeController::class, 'start']);
        Route::get('group-challenges/{session}', [GroupChallengeController::class, 'show']);
        Route::post('group-challenges/{session}/invite', [GroupChallengeController::class, 'invite']);
        Route::post('group-challenges/{session}/accept', [GroupChallengeController::class, 'accept']);
        Route::post('group-challenges/{session}/decline', [GroupChallengeController::class, 'decline']);
        Route::post('group-challenges/{session}/begin', [GroupChallengeController::class, 'begin']);
        Route::post('group-challenges/{session}/cancel', [GroupChallengeController::class, 'cancel']);
        Route::post('group-challenges/{session}/pause', [GroupChallengeController::class, 'pause']);
        Route::post('group-challenges/{session}/resume', [GroupChallengeController::class, 'resume']);
        Route::post('group-challenges/{session}/leave', [GroupChallengeController::class, 'leave']);
        Route::delete('group-challenges/{session}/participants/{user}', [GroupChallengeController::class, 'removeParticipant']);
        Route::post('group-challenges/{session}/progress', [GroupChallengeController::class, 'progress']);
        Route::post('group-challenges/{session}/complete', [GroupChallengeController::class, 'complete']);
    });
});

Route::prefix('v1')->group(function () {

    Route::get('/invites/token/{token}', [TeamInviteController::class, 'resolveByToken']);
    Route::get('/teams/invites/{token}/preview', [TeamInviteController::class, 'preview']);
    // User Routes
    Route::apiResource('users', UserController::class)->only(['index', 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        // User Profile Routes 
        Route::get('profile/me', [ProfileController::class, 'me']);
        Route::get('profile/countries', [ProfileController::class, 'countries']);
        Route::patch('profile/activities', [ProfileController::class, 'updateActivities']);
        Route::get('profile/article-library', [UserDailyArticlesController::class, 'getArticleLibrary']);
        Route::post('profile/article-library', [UserDailyArticlesController::class, 'addArticleLibrary']);
        Route::delete('profile/article-library/{id}', [UserDailyArticlesController::class, 'deleteArticleLibrary']);
        Route::apiResource('profile', ProfileController::class);

        // Hobby Management Routes
        Route::apiResource('hobbies', HobbyController::class);

        Route::apiResource('activities', ActivityController::class);
        Route::get('group-activities', [ActivityController::class, 'groupActivities']);
        Route::get('user-tier-activities', [ActivityController::class, 'userTierActivities']);
        Route::get('user-activities', [ActivityController::class, 'userActivitiesFiltered']);
        Route::get('user-activities/all', [ActivityController::class, 'userActivitiesAll']);
        Route::get('search-activities', [ActivityController::class, 'searchActivities']);
        // User Hobby Management Routes
        Route::apiResource('user/hobbies', UserHobbyController::class);

        // Research Management Routes
        Route::apiResource('research', ResearchController::class);
        Route::get('daily-article', [UserDailyArticlesController::class, 'getDailyArticle']);

        // Countries Routes
        Route::apiResource('countries', CountriesController::class)->only(['index']);

        Route::post('/invites/generate', [TeamInviteController::class, 'generate']);
        Route::post('/invites/code', [TeamInviteController::class, 'joinByCode']);
        Route::post('/invites/accept', [TeamInviteController::class, 'accept']);
        Route::post('/invites/reject', [TeamInviteController::class, 'reject']);
        Route::get('/teams/connections', [TeamInviteController::class, 'connectedUsers']);
        Route::delete('/connections/remove/{connection}', [TeamInviteController::class, 'removeConnection']);

        Route::post('/invites/link', [TeamInviteController::class, 'generateLink']);

        // App Block Log Routes
        Route::apiResource('app-block-log', AppBlockLogController::class);
        Route::get('/app-block-log/user/logs', [AppBlockLogController::class, 'userLogs']);
        Route::get('/app-block-log/user/statistics', [AppBlockLogController::class, 'statistics']);

        // App Usage Log Routes
        Route::apiResource('app-usage-log', AppUsageLogsController::class);
        Route::get('/app-usage-log/daily-stats', [AppUsageLogsController::class, 'dailyStats']);
        Route::get('/app-usage-log/summary', [AppUsageLogsController::class, 'summary']);

        // challenge log routes
        Route::apiResource('challenge-log', ChallengeLogsController::class);

        // Activity Logs Routes
        Route::apiResource('activity-log', ActivityLogsController::class);

        // Focus logs Routes
        Route::apiResource('focus-log', FocusSessionLogsController::class);

        // Goal Logs Routes
        Route::apiResource('goal-log', GoalLogsController::class);

        // Emotion Logs Routes
        Route::get('emotion-log/counts', [EmotionLogsController::class, 'counts']);
        Route::apiResource('emotion-log', EmotionLogsController::class)->only(['store']);

        // Streak Logs Routes
        Route::apiResource('streak-log', StreakLogsController::class);

        // Event Routes
        Route::get('events', [EventController::class, 'index']);
        Route::get('events/{id}', [EventController::class, 'show']);
    });
});
