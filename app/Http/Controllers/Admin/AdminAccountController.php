<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
