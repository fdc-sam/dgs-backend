<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminLiveStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Polled by every open admin page (resources/js/live.js) about every 20 seconds. */
class LiveStatusController extends Controller
{
    public function __invoke(Request $request, AdminLiveStatus $live): JsonResponse
    {
        return response()->json($live->snapshot($request->user()))->header('Cache-Control', 'no-store');
    }
}
