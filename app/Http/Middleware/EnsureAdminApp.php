<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The DGS Admin mobile app API: active admins / super admins / staff, holding a token issued for the admin app.
 * A customer token is refused here, and an admin-app token is refused on the customer endpoints.
 */
class EnsureAdminApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isActive()) {
            $user?->currentAccessToken()?->delete();
            throw ApiException::forbidden('Your account is not active. Please contact the owner.');
        }

        if (! $user->role->canAccessAdmin() || ! $user->tokenCan('admin-app')) {
            throw ApiException::forbidden('This app is for DGS staff. Customers should use the DGS Salon app.');
        }

        return $next($request);
    }
}
