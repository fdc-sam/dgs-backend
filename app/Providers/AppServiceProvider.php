<?php

namespace App\Providers;

use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Achievement;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\AppointmentItem;
use App\Models\Product;
use App\Models\WalkInVisitItem;
use App\Models\Promotion;
use App\Models\Review;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\WalkInVisit;
use App\Models\User;
use App\Policies\ProfilePolicy;
use App\Services\SettingsService;
use App\Support\FavoriteLookup;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(FavoriteLookup::class);
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        // Immutable dates avoid accidental mutation in slot arithmetic.
        Date::use(CarbonImmutable::class);

        // Stable aliases instead of class names in *_type columns (favorites, tokens, notifications).
        Relation::enforceMorphMap([
            'user' => User::class,
            'service' => Service::class,
            'staff' => StaffProfile::class,
            'promotion' => Promotion::class,
            'branch' => Branch::class,
            'appointment' => Appointment::class,
            'review' => Review::class,
            'achievement' => Achievement::class,
            'walk_in_visit' => WalkInVisit::class,
            'payment' => Payment::class,
            'product' => Product::class,
            'appointment_item' => AppointmentItem::class,
            'walk_in_visit_item' => WalkInVisitItem::class,
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('bookings', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        Gate::policy(User::class, ProfilePolicy::class);
        Gate::define('manage', fn (User $user) => $user->isManager() && $user->isActive());
        Gate::define('super-admin', fn (User $user) => $user->isSuperAdmin() && $user->isActive());

        // Shared chrome data for admin/guest layouts.
        View::composer(['components.admin.layout', 'components.admin.sidebar', 'components.admin.topbar', 'components.guest.layout'], function ($view) {
            $settings = app(SettingsService::class);
            $view->with('salonName', $settings->get('salon_name', config('app.name')));
            $view->with('logoUrl', $settings->logoUrl());
            $view->with('unreadCount', once(fn () => auth()->user()?->unreadNotifications()->count() ?? 0));
            // Products out of stock or at their low-stock alert (sidebar badge).
            $view->with('lowStockCount', once(fn () => auth()->user()?->isManager() ? Product::query()->needsRestock()->count() : 0));
            // GCash receipts waiting for a manager to check them (sidebar badge).
            $view->with('paymentsToVerify', once(fn () => auth()->user()?->isManager()
                ? Appointment::query()->where('payment_status', PaymentStatus::Submitted->value)->count() : 0));
        });
        // Super admins can do everything.
        Gate::before(fn (User $user) => $user->isSuperAdmin() && $user->isActive() ? true : null);

        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();
    }
}
