<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('admin.profile', ['user' => $request->user()]);
    }

    public function update(Request $request, FileUploadService $uploads): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('users', 'phone')->ignore($user->id)],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('salon.uploads.max_kilobytes')],
        ]);

        if ($request->hasFile('photo')) {
            $data['profile_photo'] = $uploads->storeImage($request->file('photo'), 'staff', $user->profile_photo);
        }
        unset($data['photo']);
        $user->update([...$data, 'email' => strtolower($data['email'])]);

        return back()->with('success', 'Account updated.');
    }

    public function password(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $request->user()->update(['password' => $request->input('password')]);
        // Other browsers are signed out by `auth.session`; this ends admin-app sessions too.
        $request->user()->signOutOtherDevices();
        $request->session()->regenerate();

        return back()->with('success', 'Password changed.');
    }
}
