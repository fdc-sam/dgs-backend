<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\PaymentService;
use App\Services\SettingsService;
use App\Support\Fmt;
use App\Support\PrivacyPolicy;
use Illuminate\Http\JsonResponse;

class SettingsController extends ApiController
{
    private const PUBLIC_KEYS = [
        'salon_name', 'salon_phone', 'salon_email', 'salon_address', 'currency', 'currency_symbol', 'timezone',
        'booking_minimum_notice_hours', 'booking_maximum_days', 'booking_slot_interval_minutes', 'cancellation_hours',
        'cancellation_policy', 'terms_and_conditions', 'privacy_policy',
    ];

    public function __invoke(SettingsService $settings, PaymentService $payments): JsonResponse
    {
        $all = $settings->all();
        $gcash = $payments->isEnabled();

        return $this->ok([
            ...array_intersect_key($all, array_flip(self::PUBLIC_KEYS)),
            'privacy_policy' => PrivacyPolicy::text($all),
            'logo_url' => $settings->logoUrl(),
            // The salon's GCash account (shown on every payment screen) and the salon-wide deposit rule for services
            // without their own QR (booking review preview). Per-service QRs come with each appointment.
            'payment_gcash_enabled' => $gcash,
            'payment_gcash_account_name' => $all['payment_gcash_account_name'] ?: null,
            'payment_gcash_number' => $all['payment_gcash_number'] ?: null,
            'payment_gcash_qr_url' => $gcash ? Fmt::url($all['payment_gcash_qr'] ?: null) : null,
            'payment_deposit_type' => $all['payment_deposit_type'],
            'payment_deposit_value' => $all['payment_deposit_value'],
            'payment_window_minutes' => $payments->windowMinutes(),
            'payment_policy' => $all['payment_policy'],
        ]);
    }
}
