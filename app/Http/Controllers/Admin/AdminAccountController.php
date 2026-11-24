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
            'admins' => User::query()->whereIn('role', [UserRole::SuperAdmin->value, UserRole::Admin->value, UserRole::Cashier->value])
                ->orderByDesc('role')->orderBy('name')->paginate(self::PER_PAGE),
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
            'role' => ['required', Rule::in([UserRole::Admin->value, UserRole::SuperAdmin->value, UserRole::Cashier->value])],
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

    /** One admin portal / admin app login: name, email, role, status, live or test, and a new password if needed. */
    public function edit(Request $request, User $user): View
    {
        $this->assertAdminAccount($user);

        return view('admin.admin-accounts.edit', ['admin' => $user, 'self' => $user->is($request->user())]);
    }

    /**
     * Saves the account. Your own role, status and account type stay as they are (another super admin changes those),
     * and the last active super admin keeps that role. A new password, or turning the account off, signs them out of
     * the admin app.
     */
    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $this->assertAdminAccount($user);
        $self = $user->is($request->user());
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => [$self ? 'nullable' : 'required', Rule::in([UserRole::Admin->value, UserRole::SuperAdmin->value, UserRole::Cashier->value])],
            'status' => [$self ? 'nullable' : 'required', Rule::in(UserStatus::values())],
            'account_type' => [$self ? 'nullable' : 'required', Rule::in(['live', 'test'])],
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $role = $self ? $user->role : UserRole::from($data['role']);
        $status = $self ? $user->status : UserStatus::from($data['status']);
        $test = $self ? $user->is_test : $data['account_type'] === 'test';

        $stillSuperAdmin = $role === UserRole::SuperAdmin && $status === UserStatus::Active;
        if ($user->role === UserRole::SuperAdmin && $user->isActive() && ! $stillSuperAdmin && ! $this->otherActiveSuperAdmin($user)) {
            return back()->withInput()->with('error', "{$user->name} is the only active super admin, so they must stay an active super admin.");
        }

        $old = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'status' => $user->status->value, 'is_test' => $user->is_test];
        $user->fill(['name' => $data['name'], 'email' => $data['email'], 'role' => $role, 'status' => $status]);
        $passwordChanged = filled($data['password'] ?? null);
        if ($passwordChanged) {
            $user->password = $data['password'];
        }
        $user->save();
        if ($user->is_test !== $test) {
            $user->setTestAccount($test);
        }
        // Signed in on the admin app with the old password, or no longer allowed in: sign them out there.
        if (! $self && ($passwordChanged || $status !== UserStatus::Active)) {
            $user->tokens()->delete();
        }

        $new = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'status' => $user->status->value, 'is_test' => $user->is_test];
        $changed = array_keys(array_diff_assoc(array_map('strval', $new), array_map('strval', $old)));
        if ($changed || $passwordChanged) {
            $audit->log('admin.updated', $user, array_intersect_key($old, array_flip($changed)),
                array_intersect_key($new, array_flip($changed)) + ($passwordChanged ? ['password' => 'changed'] : []));
        }

        return redirect()->route('admin-accounts.index')->with('success', "Saved {$user->name}'s account.");
    }

    public function updateAccountType(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        abort_unless($user->isManager() || $user->isCashier(), 404);
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

    /** Only admin portal logins (super admins, admins, cashiers) are edited here; customers and stylists have their own pages. */
    private function assertAdminAccount(User $user): void
    {
        abort_unless($user->isManager() || $user->isCashier(), 404);
    }

    private function otherActiveSuperAdmin(User $user): bool
    {
        return User::query()->whereKeyNot($user->id)->where('role', UserRole::SuperAdmin->value)
            ->where('status', UserStatus::Active->value)->exists();
    }
}
