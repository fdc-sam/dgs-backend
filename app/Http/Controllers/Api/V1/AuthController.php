<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Http\Requests\Api\DeleteAccountRequest;
use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Http\Requests\Api\UpdateProfilePhotoRequest;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\AccountDeletionService;
use App\Services\AuthService;
use App\Services\CustomerQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends ApiController
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->validated());

        return $this->created($this->tokenPayload($result), 'Welcome to DGS Hair Salon & Spa!');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->input('email'), $request->input('password'), $request->input('device_name'));

        return $this->ok($this->tokenPayload($result));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->validate(['device_token' => ['nullable', 'string', 'max:512']]);
        $this->auth->logout($request->user(), $request->input('device_token'));

        return $this->ok(null, 'You have been signed out.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(new UserResource($request->user()->load('customerProfile')));
    }

    /** The signed-in customer's own QR code as a PNG (their unique code with the salon logo in the middle). */
    public function qr(Request $request, CustomerQrService $qr): Response
    {
        return $qr->response($request->user(), $request, max(CustomerQrService::MIN_SIZE, (int) $request->query('size', CustomerQrService::DEFAULT_SIZE)));
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $this->authorize('update', $request->user());

        return $this->ok(new UserResource($this->auth->updateProfile($request->user(), $request->validated())), 'Profile updated.');
    }

    public function updatePhoto(UpdateProfilePhotoRequest $request): JsonResponse
    {
        return $this->ok(new UserResource($this->auth->updatePhoto($request->user(), $request->file('photo'))), 'Profile photo updated.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->changePassword($request->user(), $request->input('current_password'), $request->input('password'), $request->input('device_token'));

        return $this->ok(null, 'Password changed.');
    }

    /** Deletes the signed-in customer's account (Google Play requires in-app account deletion). */
    public function deleteAccount(DeleteAccountRequest $request, AccountDeletionService $deletion): JsonResponse
    {
        $deletion->deleteWithPassword($request->user(), $request->input('password'));

        return $this->ok(null, 'Your account has been deleted.');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->sendResetCode($request->input('email'));

        return $this->ok(null, 'If an account exists for that email, we sent a password reset code.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword($request->only('email', 'token', 'password', 'password_confirmation'));

        return $this->ok(null, 'Your password has been reset. Please sign in.');
    }

    private function tokenPayload(array $result): array
    {
        return [
            'user' => (new UserResource($result['user']))->resolve(request()),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ];
    }
}
