<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Site\AccountDeletionController;
use App\Http\Controllers\Site\PublicSiteController;
use Illuminate\Support\Facades\Route;

/*
| Admin web application (session auth). Mobile clients use routes/api.php.
| Access: super_admin & admin = everything below "manage"; staff = own appointments/calendar/schedule.
*/

// Public website (indexed by Google when the "website_indexing" setting is on).
Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::get('robots.txt', [PublicSiteController::class, 'robots'])->name('robots');
Route::get('sitemap.xml', [PublicSiteController::class, 'sitemap'])->name('sitemap');
Route::get('privacy', [PublicSiteController::class, 'privacy'])->name('privacy');
Route::get('delete-account', [AccountDeletionController::class, 'create'])->name('account-deletion');
Route::post('delete-account', [AccountDeletionController::class, 'store'])->middleware('throttle:auth')->name('account-deletion.store');

Route::middleware('guest')->group(function () {
    Route::get('login', [Admin\AuthController::class, 'create'])->name('login');
    Route::post('login', [Admin\AuthController::class, 'store'])->middleware('throttle:admin-login')->name('login.store');
});

Route::middleware(['auth', 'auth.session', 'admin'])->group(function () {
    Route::post('logout', [Admin\AuthController::class, 'destroy'])->name('logout');

    Route::get('dashboard', Admin\DashboardController::class)->name('dashboard');
    Route::get('calendar', Admin\CalendarController::class)->name('calendar');

    Route::get('appointments', [Admin\AppointmentController::class, 'index'])->name('appointments.index');
    Route::get('appointments/{appointment}', [Admin\AppointmentController::class, 'show'])->name('appointments.show');
    Route::post('appointments/{appointment}/status', [Admin\AppointmentController::class, 'updateStatus'])->name('appointments.status');
    Route::post('appointments/{appointment}/cancel', [Admin\AppointmentController::class, 'cancel'])->name('appointments.cancel');
    Route::post('appointments/{appointment}/reschedule', [Admin\AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
    Route::get('appointments/{appointment}/slots', [Admin\AppointmentController::class, 'slots'])->name('appointments.slots');
    Route::put('appointments/{appointment}/notes', [Admin\AppointmentController::class, 'updateNotes'])->name('appointments.notes');

    Route::get('my-schedule', Admin\MyScheduleController::class)->name('my-schedule');

    Route::get('notifications', [Admin\NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [Admin\NotificationController::class, 'read'])->name('notifications.read');
    Route::post('notifications/read-all', [Admin\NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::get('account', [Admin\ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('account', [Admin\ProfileController::class, 'update'])->name('profile.update');
    Route::put('account/password', [Admin\ProfileController::class, 'password'])->name('profile.password');

    Route::middleware('can:manage')->group(function () {
        Route::get('payments', [Admin\PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}/proof', [Admin\PaymentController::class, 'proof'])->name('payments.proof');
        Route::post('appointments/{appointment}/payment/approve', [Admin\PaymentController::class, 'approve'])->name('appointments.payment.approve');
        Route::post('appointments/{appointment}/payment/reject', [Admin\PaymentController::class, 'reject'])->name('appointments.payment.reject');
        Route::post('appointments/{appointment}/payment/waive', [Admin\PaymentController::class, 'waive'])->name('appointments.payment.waive');
        Route::post('appointments/{appointment}/payment/refund', [Admin\PaymentController::class, 'refund'])->name('appointments.payment.refund');

        Route::get('walk-ins', [Admin\WalkInVisitController::class, 'index'])->name('walk-ins.index');
        Route::get('walk-ins/create', [Admin\WalkInVisitController::class, 'create'])->name('walk-ins.create');
        Route::post('walk-ins', [Admin\WalkInVisitController::class, 'store'])->name('walk-ins.store');
        Route::get('walk-ins/{walkInVisit}', [Admin\WalkInVisitController::class, 'show'])->name('walk-ins.show');
        Route::put('walk-ins/{walkInVisit}/items/{item}', [Admin\WalkInVisitController::class, 'updateItem'])->name('walk-ins.items.update');
        Route::delete('walk-ins/{walkInVisit}/items/{item}', [Admin\WalkInVisitController::class, 'destroyItem'])->name('walk-ins.items.destroy');
        Route::post('walk-ins/{walkInVisit}/complete', [Admin\WalkInVisitController::class, 'complete'])->name('walk-ins.complete');

        // Shared salon setup: test accounts can open these pages but not change them.
        Route::middleware('live')->group(function () {
            Route::resource('services', Admin\ServiceController::class)->except('show');
            Route::resource('categories', Admin\CategoryController::class)->except('show');
            Route::resource('products', Admin\ProductController::class)->except('show');
            Route::post('products/{product}/restock', [Admin\ProductController::class, 'restock'])->name('products.restock');
            Route::post('products/{product}/count', [Admin\ProductController::class, 'count'])->name('products.count');
            Route::resource('promotions', Admin\PromotionController::class)->except('show');
            Route::get('promotions/{promotion}/preview', [Admin\PromotionController::class, 'preview'])->name('promotions.preview');
            Route::post('promotions/{promotion}/publish', [Admin\PromotionController::class, 'publish'])->name('promotions.publish');
            Route::resource('achievements', Admin\AchievementController::class)->except('show');

            Route::resource('staff', Admin\StaffController::class)->except('show')->parameters(['staff' => 'staff']);
            Route::post('staff/{staff}/days-off', [Admin\StaffController::class, 'storeDayOff'])->name('staff.days-off.store');
            Route::delete('staff/{staff}/days-off/{dayOff}', [Admin\StaffController::class, 'destroyDayOff'])->name('staff.days-off.destroy');

            Route::resource('branches', Admin\BranchController::class)->except('show');
            Route::post('branches/{branch}/closures', [Admin\BranchController::class, 'storeClosure'])->name('branches.closures.store');
            Route::delete('branches/{branch}/closures/{closure}', [Admin\BranchController::class, 'destroyClosure'])->name('branches.closures.destroy');
        });

        Route::get('customers', [Admin\CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}', [Admin\CustomerController::class, 'show'])->name('customers.show');
        Route::get('customers/{customer}/qr', [Admin\CustomerController::class, 'qr'])->name('customers.qr');
        Route::post('customers/{customer}/status', [Admin\CustomerController::class, 'updateStatus'])->name('customers.status');
        Route::post('customers/{customer}/account-type', [Admin\CustomerController::class, 'updateAccountType'])->name('customers.account-type');

        Route::get('reviews', [Admin\ReviewController::class, 'index'])->name('reviews.index');
        Route::post('reviews/{review}/approve', [Admin\ReviewController::class, 'approve'])->name('reviews.approve');
        Route::post('reviews/{review}/reject', [Admin\ReviewController::class, 'reject'])->name('reviews.reject');
        Route::delete('reviews/{review}', [Admin\ReviewController::class, 'destroy'])->name('reviews.destroy');

        Route::get('reports', [Admin\ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{report}', [Admin\ReportController::class, 'show'])
            ->whereIn('report', ['sales', 'appointments', 'services', 'staff', 'branches'])->name('reports.show');

        Route::post('notifications/broadcast', [Admin\NotificationController::class, 'broadcast'])->name('notifications.broadcast');
    });

    Route::middleware(['can:super-admin', 'live'])->group(function () {
        Route::get('settings', [Admin\SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');

        Route::get('admin-accounts', [Admin\AdminAccountController::class, 'index'])->name('admin-accounts.index');
        Route::put('admin-accounts/{user}/account-type', [Admin\AdminAccountController::class, 'updateAccountType'])->name('admin-accounts.account-type');
    });
});
