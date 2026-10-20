<?php

use App\Http\Controllers\Api\V1\AchievementController;
use App\Http\Controllers\Api\V1\Admin as AdminApi;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\StaffController;
use Illuminate\Support\Facades\Route;

/*
| Mobile REST API — documented in docs/API.md.
*/

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function () {

    // Authentication (stricter throttle against credential stuffing / code guessing)
    Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
    });

    // Public catalog (a Bearer token is optional and only personalises is_favorite)
    Route::get('settings', SettingsController::class)->name('settings');
    Route::get('achievements', AchievementController::class)->name('achievements.index');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{category}', [CategoryController::class, 'show'])->name('categories.show');

    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('services/featured', [ServiceController::class, 'featured'])->name('services.featured');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');

    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
    Route::get('branches/{branch}', [BranchController::class, 'show'])->name('branches.show');
    Route::get('branches/{branch}/services', [BranchController::class, 'services'])->name('branches.services');
    Route::get('branches/{branch}/staff', [BranchController::class, 'staff'])->name('branches.staff');

    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::get('staff/{staff}', [StaffController::class, 'show'])->name('staff.show');
    Route::get('staff/{staff}/services', [StaffController::class, 'services'])->name('staff.services');

    Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
    Route::get('promotions/active', [PromotionController::class, 'active'])->name('promotions.active');
    Route::get('promotions/{promotion}', [PromotionController::class, 'show'])->name('promotions.show');

    Route::get('availability', [AvailabilityController::class, 'day'])->name('availability.day');
    Route::get('availability/dates', [AvailabilityController::class, 'dates'])->name('availability.dates');

    Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::get('reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');

    // DGS Admin mobile app (admins, super admins and staff). Separate from the customer endpoints below.
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('auth/login', [AdminApi\AuthController::class, 'login'])->middleware('throttle:auth')->name('auth.login');

        Route::middleware(['auth:sanctum', 'admin.app'])->group(function () {
            Route::get('auth/me', [AdminApi\AuthController::class, 'me'])->name('auth.me');
            Route::post('auth/logout', [AdminApi\AuthController::class, 'logout'])->name('auth.logout');

            Route::get('customers/lookup', [AdminApi\CustomerController::class, 'lookup'])->middleware('throttle:120,1')->name('customers.lookup');
            Route::get('customers', [AdminApi\CustomerController::class, 'index'])->name('customers.index');
            Route::get('customers/{customer}', [AdminApi\CustomerController::class, 'show'])->name('customers.show');
            Route::post('customers/{customer}/appointments', [AdminApi\AppointmentController::class, 'store'])->middleware('throttle:bookings')->name('customers.appointments.store');

            Route::get('appointments', [AdminApi\AppointmentController::class, 'index'])->name('appointments.index');
            Route::get('appointments/{appointment}', [AdminApi\AppointmentController::class, 'show'])->name('appointments.show');
            Route::post('appointments/{appointment}/check-in', [AdminApi\AppointmentController::class, 'checkIn'])->name('appointments.check-in');
            Route::post('appointments/{appointment}/status', [AdminApi\AppointmentController::class, 'status'])->name('appointments.status');
            Route::post('appointments/{appointment}/cancel', [AdminApi\AppointmentController::class, 'cancel'])->name('appointments.cancel');
            Route::put('appointments/{appointment}/notes', [AdminApi\AppointmentController::class, 'notes'])->name('appointments.notes');

            Route::get('availability', [AdminApi\AvailabilityController::class, 'day'])->name('availability');
        });
    });

    // Authenticated customer endpoints
    Route::middleware(['auth:sanctum', 'customer'])->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::get('qr', [AuthController::class, 'qr'])->name('qr');
            Route::put('profile', [AuthController::class, 'updateProfile'])->name('profile');
            Route::post('profile/photo', [AuthController::class, 'updatePhoto'])->name('profile.photo');
            Route::put('password', [AuthController::class, 'changePassword'])->name('password');
            Route::delete('account', [AuthController::class, 'deleteAccount'])->middleware('throttle:auth')->name('account.destroy');
        });

        Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::post('appointments', [AppointmentController::class, 'store'])->middleware('throttle:bookings')->name('appointments.store');
        Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
        Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
        Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('throttle:bookings')->name('appointments.reschedule');
        Route::post('appointments/{appointment}/payment', [AppointmentController::class, 'pay'])->middleware('throttle:bookings')->name('appointments.payment');

        Route::get('favorites', [FavoriteController::class, 'index'])->name('favorites.index');
        Route::post('favorites', [FavoriteController::class, 'store'])->name('favorites.store');
        Route::delete('favorites/{favorite}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');

        Route::post('reviews', [ReviewController::class, 'store'])->name('reviews.store');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/device-token', [NotificationController::class, 'registerDevice'])->name('notifications.device-token');
        Route::get('notifications/realtime', [NotificationController::class, 'realtime'])->name('notifications.realtime');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    });
});
