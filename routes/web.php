<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PromoCodeController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserProfileController;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ChallengePackController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\AppAnalyzeController;
use App\Http\Controllers\Admin\ResearchArticleController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\SubscriptionController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/view-logs', function () {
    return view('viewLogs');
});

// Route::get('/app-analyze', function () {
//     return view('appAnalyze');
// });

Route::get('/invite', function () {
    return view('invite-fallback');
});

Route::prefix('dashboard')->name('admin.')->group(function () {

    // 1. Guest Auth Routes (Accessible without logging in)
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AuthController::class, 'showLoginForm'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->name('login.post');
    });

    // 2. Protected Routes (Requires authentication)
    Route::middleware('auth:admin')->group(function () {

        // Logout
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        // Dashboard Home
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('membership-plans', [\App\Http\Controllers\Admin\MembershipPlanController::class, 'index'])->name('membership-plans.index');
        Route::put('membership-plans/{plan}', [\App\Http\Controllers\Admin\MembershipPlanController::class, 'update'])->name('membership-plans.update');
        Route::get('promo-codes', [PromoCodeController::class, 'index'])->name('promo-codes.index');
        Route::post('promo-codes', [PromoCodeController::class, 'store'])->name('promo-codes.store');
        Route::get('promo-codes/{promoCode}/edit', [PromoCodeController::class, 'edit'])->name('promo-codes.edit');
        Route::put('promo-codes/{promoCode}', [PromoCodeController::class, 'update'])->name('promo-codes.update');
        Route::delete('promo-codes/{promoCode}', [PromoCodeController::class, 'destroy'])->name('promo-codes.destroy');
        Route::patch('promo-codes/{promoCode}/toggle-status', [PromoCodeController::class, 'toggleStatus'])->name('promo-codes.toggle-status');

        // Users Management
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
        Route::resource('users', UserController::class);

        // User Profiles
        Route::resource('user-profiles', UserProfileController::class)->only(['index', 'show', 'update', 'destroy']);

        // Activities
        Route::get('activities/export', [ActivityController::class, 'export'])->name('activities.export');
        Route::resource('activities', ActivityController::class);

        // Categories
        Route::resource('categories', CategoryController::class);

        // // Challenge Packs
        Route::resource('challenge-packs', ChallengePackController::class);

        // // Groups
        // Route::resource('groups', GroupController::class);

        // Research Articles
        Route::resource('research-articles', ResearchArticleController::class);

        // Events
        Route::resource('events', EventController::class);

        // // Subscription Management
        // Route::resource('subscriptions', SubscriptionController::class);

        // // Payment History
        // Route::resource('payment-history', PaymentHistoryController::class)->only(['index', 'show']);

        // // User Activity Logs
        // Route::get('activity-logs', [UserActivityLogController::class, 'index'])->name('activity-logs.index');
        // Route::get('activity-logs/{log}', [UserActivityLogController::class, 'show'])->name('activity-logs.show');

        // // Notifications
        // Route::post('notifications/{notification}/send', [NotificationController::class, 'sendNow'])->name('notifications.send');
        // Route::resource('notifications', NotificationController::class);

        // // Reports & Analytics
        // Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        // Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

        // // Admin Management
        // Route::resource('admins', AdminManagementController::class);

        // // Roles & Permissions
        // Route::resource('roles', RolePermissionController::class);

        Route::middleware('auth:admin')->group(function () {
            Route::get('app-analyze', [AppAnalyzeController::class, 'index'])->name('app-analyze.index');
            Route::delete('app-analyze/purge', [AppAnalyzeController::class, 'purgeOldLogs'])->name('app-analyze.purge');
            Route::get('users/{user}/analytics', [AppAnalyzeController::class, 'userAnalytics'])->name('users.analytics');
        });

        Route::get('profile', [ProfileController::class, 'index'])->name('profile.index');
        Route::put('profile', [ProfileController::class, 'updateProfile'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    });
});
