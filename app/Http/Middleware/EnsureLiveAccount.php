<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The salon setup (services, prices, staff, branches, stock, promotions, achievements, settings) is shared by the test
 * and live sides, so a test account may open those pages but not change anything on them.
 */
class EnsureLiveAccount
{
    public const MESSAGE = 'This is a test account: the salon setup is view-only. Use a live account to change it.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! $request->user()?->is_test) {
            return $next($request);
        }

        abort_if($request->expectsJson(), 403, self::MESSAGE);

        return back()->with('error', self::MESSAGE);
    }
}
