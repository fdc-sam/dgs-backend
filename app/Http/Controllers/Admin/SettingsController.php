<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\FileUploadService;
use App\Services\SettingsService;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(SettingsService $settings): View
    {
        return view('admin.settings.edit', [
            'settings' => $settings->all(),
            'logoUrl' => $settings->logoUrl(),
            'paymentQrUrl' => Fmt::url($settings->get('payment_gcash_qr') ?: null),
        ]);
    }

    public function update(Request $request, SettingsService $settings, FileUploadService $uploads, AuditLogger $audit): RedirectResponse
    {
        // "+63 917 123 4567" → "09171234567"
        $number = preg_replace('/[\s\-().]/', '', (string) $request->input('payment_gcash_number'));
        $request->merge(['payment_gcash_number' => preg_match('/^\+?639\d{9}$/', $number) ? '0'.substr($number, -10) : ($number ?: null)]);

        $data = $request->validate([
            'salon_name' => ['required', 'string', 'max:120'],
            'salon_phone' => ['nullable', 'string', 'max:40'],
            'salon_email' => ['nullable', 'email', 'max:190'],
            'salon_address' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'currency_symbol' => ['required', 'string', 'max:4'],
            'booking_minimum_notice_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'booking_maximum_days' => ['required', 'integer', 'min:1', 'max:365'],
            'booking_slot_interval_minutes' => ['required', 'integer', 'in:10,15,20,30,45,60'],
            'cancellation_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'auto_confirm_appointments' => ['boolean'],
            'auto_approve_reviews' => ['boolean'],
            'reminder_offsets' => ['nullable', 'string', 'max:100', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
            'cancellation_policy' => ['nullable', 'string', 'max:5000'],
            'terms_and_conditions' => ['nullable', 'string', 'max:20000'],
            'privacy_policy' => ['nullable', 'string', 'max:20000'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('salon.uploads.max_kilobytes')],
            'payment_gcash_enabled' => ['boolean'],
            'payment_gcash_account_name' => ['nullable', 'required_if_accepted:payment_gcash_enabled', 'string', 'max:120'],
            'payment_gcash_number' => ['nullable', 'regex:/^09\d{9}$/'],
            'payment_qr' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('salon.uploads.max_kilobytes')],
            'payment_deposit_type' => ['sometimes', 'required', 'in:fixed,percent,full'],
            'payment_deposit_value' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'payment_window_minutes' => ['sometimes', 'required', 'integer', 'min:15', 'max:1440'],
            'payment_policy' => ['nullable', 'string', 'max:2000'],
            'website_indexing' => ['boolean'],
            'business_city' => ['nullable', 'string', 'max:80'],
            'business_province' => ['nullable', 'string', 'max:80'],
            'facebook_url' => ['nullable', 'url:https', 'max:255', 'regex:#^https://(www\.|m\.|web\.)?facebook\.com/#'],
            'website_description' => ['nullable', 'string', 'max:300'],
            'google_site_verification' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ], [
            'facebook_url.regex' => 'Enter the Facebook page link, e.g. https://www.facebook.com/yourpage.',
            'google_site_verification.regex' => 'Paste only the code from the content="…" part of the Google meta tag.',
            'reminder_offsets.regex' => 'Enter hours as numbers separated by commas, e.g. 24, 2.',
            'payment_gcash_number.regex' => 'Enter an 11-digit mobile number, e.g. 09171234567.',
            'payment_gcash_account_name.required_if_accepted' => 'Enter the GCash account name customers will see.',
        ]);
        $this->validatePayments($request, $settings, $data);

        $old = $settings->all();

        // Reminder offsets are entered in hours for readability and stored in minutes.
        $hours = array_filter(array_map('intval', explode(',', (string) ($data['reminder_offsets'] ?? ''))));
        $data['reminder_offsets_minutes'] = array_values(array_map(fn ($h) => $h * 60, $hours));
        unset($data['reminder_offsets']);

        if ($request->hasFile('logo')) {
            $data['salon_logo'] = $uploads->storeImage($request->file('logo'), 'logo', $old['salon_logo'] ?: null);
        }
        unset($data['logo']);

        if ($request->hasFile('payment_qr')) {
            $data['payment_gcash_qr'] = $uploads->storeImage($request->file('payment_qr'), 'payments', $old['payment_gcash_qr'] ?: null, lossless: true);
        }
        unset($data['payment_qr']);
        if (array_key_exists('payment_deposit_value', $data) && $data['payment_deposit_value'] === null) {
            $data['payment_deposit_value'] = $old['payment_deposit_value']; // "full" hides the field
        }

        $settings->update($data);
        $audit->log('settings.updated', 'settings', array_intersect_key($old, $data), $data);

        return back()->with('success', 'Settings saved.');
    }

    /** Rules that span several payment fields. */
    private function validatePayments(Request $request, SettingsService $settings, array $data): void
    {
        $validator = validator([], []);
        $validator->after(function (Validator $v) use ($request, $settings, $data) {
            $type = $data['payment_deposit_type'] ?? null;
            if (in_array($type, ['fixed', 'percent'], true) && empty($data['payment_deposit_value'])) {
                $v->errors()->add('payment_deposit_value', 'Enter the deposit amount or percentage.');
            }
            if ($type === 'percent' && (int) ($data['payment_deposit_value'] ?? 0) > 100) {
                $v->errors()->add('payment_deposit_value', 'A percentage cannot be more than 100.');
            }
            $hasQr = $request->hasFile('payment_qr') || $settings->get('payment_gcash_qr');
            if (! empty($data['payment_gcash_enabled']) && ! $hasQr && empty($data['payment_gcash_number'])) {
                $v->errors()->add('payment_qr', 'Upload the GCash QR code or enter the GCash number so customers know where to pay.');
            }
        });
        $validator->validate();
    }
}
