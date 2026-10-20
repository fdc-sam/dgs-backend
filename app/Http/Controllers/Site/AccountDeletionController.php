<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletionService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Public /delete-account page: the web link Google Play requires for deleting an account without the app. */
class AccountDeletionController extends Controller
{
    public function create(SettingsService $settings): View
    {
        return view('site.delete-account', ['s' => $settings->all(), 'logoUrl' => $settings->logoUrl()]);
    }

    public function store(Request $request, AccountDeletionService $deletion): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Please confirm that you want to delete your account.']);

        $deletion->deleteByCredentials($data['email'], $data['password']);

        return redirect()->route('account-deletion')->with('deleted', true);
    }
}
