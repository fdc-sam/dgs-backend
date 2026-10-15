<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sign-in for the DGS Admin mobile app (admins, super admins and staff). */
class AuthController extends ApiController
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->loginAdminApp($request->input('email'), $request->input('password'), $request->input('device_name'));

        return $this->ok([
            'user' => (new AdminUserResource($result['user']))->resolve($request),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(new AdminUserResource($request->user()->load('staffProfile.branches')));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->ok(null, 'You have been signed out.');
    }
}
