<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Mobile API endpoints are for active customer accounts only. */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isActive()) {
            $user?->currentAccessToken()?->delete();
            throw ApiException::forbidden('Your account is not active. Please contact the salon.');
        }

        if (! $user->isCustomer()) {
            throw ApiException::forbidden('This app is for customers. Staff and administrators should use the admin portal.');
        }

        return $next($request);
    }
}
