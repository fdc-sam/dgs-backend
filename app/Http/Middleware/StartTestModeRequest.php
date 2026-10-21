<?php

namespace App\Http\Middleware;

use App\Support\TestMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Marks web/API requests so a guest sees live data only (console commands and queued jobs see both sides). */
class StartTestModeRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        app(TestMode::class)->startRequest();

        return $next($request);
    }
}
