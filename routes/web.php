<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Site\AccountDeletionController;
use App\Http\Controllers\Site\InviteController;
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

// Invite links customers share from the app (Invites), and the file that lets Android open them in the app.
Route::get('i/{code}/card.jpg', [InviteController::class, 'card'])->where('code', '[A-Za-z0-9-]{1,64}')->middleware('throttle:120,1')->name('invite.card');
// The first version's address, still in previews chat apps saved.
Route::get('i/{code}/card.png', [InviteController::class, 'card'])->where('code', '[A-Za-z0-9-]{1,64}')->middleware('throttle:120,1');
Route::get('i/{code}', [InviteController::class, 'show'])->where('code', '[A-Za-z0-9-]{1,64}')->middleware('throttle:60,1')->name('invite.show');
Route::get('.well-known/assetlinks.json', [InviteController::class, 'assetLinks'])->name('asset-links');

Route::middleware('guest')->group(function () {
    Route::get('login', [Admin\AuthController::class, 'create'])->name('login');
    Route::post('login', [Admin\AuthController::class, 'store'])->middleware('throttle:admin-login')->name('login.store');
});

Route::middleware(['auth', 'auth.session', 'admin'])->group(function () {
    Route::post('logout', [Admin\AuthController::class, 'destroy'])->name('logout');

    Route::get('dashboard', Admin\DashboardController::class)->name('dashboard');
    Route::get('live', Admin\LiveStatusController::class)->middleware('can:operations-portal')->name('live');
    Route::get('calendar', Admin\CalendarController::class)->middleware('can:operations-portal')->name('calendar');

    Route::get('appointments', [Admin\AppointmentController::class, 'index'])->middleware('can:operations-portal')->name('appointments.index');
    Route::get('appointments/{appointment}', [Admin\AppointmentController::class, 'show'])->middleware('can:operations-portal')->name('appointments.show');

    Route::middleware('can:team-portal')->group(function () {
        Route::post('appointments/{appointment}/status', [Admin\AppointmentController::class, 'updateStatus'])->name('appointments.status');
        Route::post('appointments/{appointment}/cancel', [Admin\AppointmentController::class, 'cancel'])->name('appointments.cancel');
        Route::post('appointments/{appointment}/reschedule', [Admin\AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
        Route::get('appointments/{appointment}/slots', [Admin\AppointmentController::class, 'slots'])->name('appointments.slots');
        Route::put('appointments/{appointment}/notes', [Admin\AppointmentController::class, 'updateNotes'])->name('appointments.notes');
        Route::put('appointments/{appointment}/additional-charge', [Admin\AppointmentController::class, 'updateAdditionalCharge'])->name('appointments.additional-charge');
        Route::get('my-schedule', Admin\MyScheduleController::class)->name('my-schedule');
        Route::get('notifications', [Admin\NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/{notification}/read', [Admin\NotificationController::class, 'read'])->name('notifications.read');
        Route::post('notifications/read-all', [Admin\NotificationController::class, 'readAll'])->name('notifications.read-all');
    });

    Route::get('account', [Admin\ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('account', [Admin\ProfileController::class, 'update'])->name('profile.update');
    Route::put('account/password', [Admin\ProfileController::class, 'password'])->name('profile.password');

    Route::middleware('can:manage')->group(function () {
        Route::post('appointments/{appointment}/payment/waive', [Admin\PaymentController::class, 'waive'])->name('appointments.payment.waive');
        Route::post('appointments/{appointment}/payment/refund', [Admin\PaymentController::class, 'refund'])->name('appointments.payment.refund');

        Route::get('walk-ins/create', [Admin\WalkInVisitController::class, 'create'])->name('walk-ins.create');
        Route::get('walk-ins/customers', [Admin\WalkInVisitController::class, 'customers'])->name('walk-ins.customers');
        Route::post('walk-ins', [Admin\WalkInVisitController::class, 'store'])->name('walk-ins.store');
        Route::put('walk-ins/{walkInVisit}/items/{item}', [Admin\WalkInVisitController::class, 'updateItem'])->name('walk-ins.items.update');
        Route::delete('walk-ins/{walkInVisit}/items/{item}', [Admin\WalkInVisitController::class, 'destroyItem'])->name('walk-ins.items.destroy');
        Route::post('walk-ins/{walkInVisit}/complete', [Admin\WalkInVisitController::class, 'complete'])->name('walk-ins.complete');

        Route::get('loyalty-cards', [Admin\LoyaltyCardController::class, 'index'])->name('loyalty-cards.index');
        Route::post('loyalty-cards/generate', [Admin\LoyaltyCardController::class, 'generate'])->name('loyalty-cards.generate');
        Route::post('loyalty-cards/{loyaltyCard}/disconnect', [Admin\LoyaltyCardController::class, 'disconnect'])->name('loyalty-cards.disconnect');
        // Cards asked for in the app through a friend: check the GCash receipt, then approve or reject.
        Route::get('loyalty-cards/requests', [Admin\LoyaltyCardRequestController::class, 'index'])->name('loyalty-cards.requests.index');
        Route::get('loyalty-cards/requests/{cardRequest}', [Admin\LoyaltyCardRequestController::class, 'show'])->name('loyalty-cards.requests.show');
        Route::get('loyalty-cards/requests/{cardRequest}/proof', [Admin\LoyaltyCardRequestController::class, 'proof'])->name('loyalty-cards.requests.proof');
        Route::post('loyalty-cards/requests/{cardRequest}/approve', [Admin\LoyaltyCardRequestController::class, 'approve'])->name('loyalty-cards.requests.approve');
        Route::post('loyalty-cards/requests/{cardRequest}/reject', [Admin\LoyaltyCardRequestController::class, 'reject'])->name('loyalty-cards.requests.reject');

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
        Route::post('customers/{customer}/loyalty-card', [Admin\CustomerController::class, 'updateLoyaltyCard'])->name('customers.loyalty-card');
        Route::post('customers/{customer}/loyalty-card/renew', [Admin\CustomerController::class, 'renewLoyaltyCard'])->name('customers.loyalty-card.renew');
        // Moving a customer (and their bookings, reviews, referrals) between test and live: live accounts only, so a
        // test super admin can't push test data into the live reports.
        Route::post('customers/{customer}/account-type', [Admin\CustomerController::class, 'updateAccountType'])->name('customers.account-type')
            ->middleware('live');

        Route::post('referrals/{referral}/status', [Admin\ReferralController::class, 'status'])->name('referrals.status');
        Route::put('referrals/{referral}/items/{item}', [Admin\ReferralController::class, 'updateItem'])->name('referrals.items.update');
        Route::delete('referrals/{referral}/items/{item}', [Admin\ReferralController::class, 'destroyItem'])->name('referrals.items.destroy');
        Route::post('referrals/{referral}/complete', [Admin\ReferralController::class, 'complete'])->name('referrals.complete');

        // Customers asking for their earnings in GCash: send it, then record the reference number.
        Route::get('cash-outs', [Admin\CashOutController::class, 'index'])->name('cash-outs.index');
        Route::post('cash-outs/{cashOut}/complete', [Admin\CashOutController::class, 'complete'])->name('cash-outs.complete');
        Route::post('cash-outs/{cashOut}/reject', [Admin\CashOutController::class, 'reject'])->name('cash-outs.reject');

        Route::get('reviews', [Admin\ReviewController::class, 'index'])->name('reviews.index');
        Route::post('reviews/{review}/approve', [Admin\ReviewController::class, 'approve'])->name('reviews.approve');
        Route::post('reviews/{review}/reject', [Admin\ReviewController::class, 'reject'])->name('reviews.reject');
        Route::delete('reviews/{review}', [Admin\ReviewController::class, 'destroy'])->name('reviews.destroy');

        Route::get('reports', [Admin\ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{report}', [Admin\ReportController::class, 'show'])
            ->whereIn('report', ['sales', 'appointments', 'services', 'staff', 'branches'])->name('reports.show');

        Route::post('notifications/broadcast', [Admin\NotificationController::class, 'broadcast'])->name('notifications.broadcast');
    });

    // Cashiers can review submitted deposits and view referral records, but cannot access other management screens.
    Route::middleware('can:cashier-area')->group(function () {
        Route::get('payments', [Admin\PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}/proof', [Admin\PaymentController::class, 'proof'])->name('payments.proof');
        Route::post('appointments/{appointment}/payment/approve', [Admin\PaymentController::class, 'approve'])->name('appointments.payment.approve');
        Route::post('appointments/{appointment}/payment/reject', [Admin\PaymentController::class, 'reject'])->name('appointments.payment.reject');
        Route::post('appointments/{appointment}/items', [Admin\AppointmentController::class, 'addItem'])->name('appointments.items.add');
        Route::get('walk-ins', [Admin\WalkInVisitController::class, 'index'])->name('walk-ins.index');
        Route::get('walk-ins/{walkInVisit}', [Admin\WalkInVisitController::class, 'show'])->name('walk-ins.show');
        Route::post('walk-ins/{walkInVisit}/items', [Admin\WalkInVisitController::class, 'storeItem'])->name('walk-ins.items.store');
        Route::get('referrals', [Admin\ReferralController::class, 'index'])->name('referrals.index');
        Route::get('referrals/{referral}', [Admin\ReferralController::class, 'show'])->name('referrals.show');
        Route::post('referrals/{referral}/items', [Admin\ReferralController::class, 'storeItem'])->name('referrals.items.store');
    });

    Route::middleware(['can:super-admin', 'live'])->group(function () {
        Route::get('settings', [Admin\SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');

        Route::get('admin-accounts', [Admin\AdminAccountController::class, 'index'])->name('admin-accounts.index');
        Route::get('admin-accounts/create', [Admin\AdminAccountController::class, 'create'])->name('admin-accounts.create');
        Route::post('admin-accounts', [Admin\AdminAccountController::class, 'store'])->name('admin-accounts.store');
        Route::get('admin-accounts/{user}/edit', [Admin\AdminAccountController::class, 'edit'])->name('admin-accounts.edit');
        Route::put('admin-accounts/{user}', [Admin\AdminAccountController::class, 'update'])->name('admin-accounts.update');
        Route::put('admin-accounts/{user}/account-type', [Admin\AdminAccountController::class, 'updateAccountType'])->name('admin-accounts.account-type');
    });
});
