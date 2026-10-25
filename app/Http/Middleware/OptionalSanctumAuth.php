<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Resolve an optional bearer token on public catalog routes for customer-specific prices. */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->setUserResolver(fn () => Auth::guard('sanctum')->user());

        return $next($request);
    }
}
