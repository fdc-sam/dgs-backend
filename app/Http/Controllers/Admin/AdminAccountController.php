<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Super admins: the admin portal logins (admins and super admins), and which of them are test accounts. */
class AdminAccountController extends Controller
{
    public function index(): View
    {
        return view('admin.admin-accounts.index', [
            'admins' => User::query()->whereIn('role', [UserRole::SuperAdmin->value, UserRole::Admin->value])
                ->orderByDesc('role')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.admin-accounts.create');
    }

    /** A new admin portal / admin app login, ready to sign in (same rules as `php artisan dgs:create-admin`). */
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in([UserRole::Admin->value, UserRole::SuperAdmin->value])],
            'account_type' => ['required', Rule::in(['live', 'test'])],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $admin = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'status' => UserStatus::Active,
        ]);
        $admin->forceFill(['is_test' => $data['account_type'] === 'test', 'email_verified_at' => now()])->save();
        $audit->log('admin.created', $admin, null, ['role' => $data['role'], 'is_test' => $admin->is_test]);

        return redirect()->route('admin-accounts.index')
            ->with('success', "{$admin->name} can now sign in with {$admin->email}.");
    }

    public function updateAccountType(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        abort_unless($user->isManager(), 404);
        $data = $request->validate(['account_type' => ['required', Rule::in(['live', 'test'])]]);
        $test = $data['account_type'] === 'test';
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot change your own account type. Ask another super admin.');
        }
        if ($user->is_test === $test) {
            return back();
        }

        $user->setTestAccount($test);
        $audit->log('admin.account_type', $user, ['is_test' => ! $test], ['is_test' => $test]);

        return back()->with('success', "{$user->name} is now a ".($test ? 'test' : 'live').' account.');
    }
}
