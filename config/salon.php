<?php

/*
| Default business settings. The admin can override every value at /settings;
| these defaults are only used until a row exists in the `settings` table.
| Contact details are intentionally blank: official DGS data must be entered by the admin.
*/

return [
    'settings' => [
        'salon_name' => ['value' => 'DGS Hair Salon & Spa', 'type' => 'string'],
        'salon_phone' => ['value' => '', 'type' => 'string'],
        'salon_email' => ['value' => '', 'type' => 'string'],
        'salon_address' => ['value' => '', 'type' => 'string'],
        'salon_logo' => ['value' => '', 'type' => 'string'],
        'currency' => ['value' => 'PHP', 'type' => 'string'],
        'currency_symbol' => ['value' => '₱', 'type' => 'string'],
        'timezone' => ['value' => 'Asia/Manila', 'type' => 'string'],
        'booking_minimum_notice_hours' => ['value' => '2', 'type' => 'integer'],
        'booking_maximum_days' => ['value' => '60', 'type' => 'integer'],
        'booking_slot_interval_minutes' => ['value' => '30', 'type' => 'integer'],
        'cancellation_hours' => ['value' => '24', 'type' => 'integer'],
        'auto_confirm_appointments' => ['value' => '0', 'type' => 'boolean'],
        'auto_approve_reviews' => ['value' => '0', 'type' => 'boolean'],
        // Minutes before start_at at which a reminder is sent (24 h and 2 h).
        'reminder_offsets_minutes' => ['value' => '[1440,120]', 'type' => 'json'],
        'cancellation_policy' => ['value' => 'Appointments may be cancelled or rescheduled up to the configured number of hours before the start time.', 'type' => 'text'],

        // GCash deposit (manual: the customer pays the salon's GCash and uploads the receipt). See docs/PAYMENTS.md.
        'payment_gcash_enabled' => ['value' => '0', 'type' => 'boolean'],
        'payment_gcash_account_name' => ['value' => '', 'type' => 'string'],
        'payment_gcash_number' => ['value' => '', 'type' => 'string'],
        'payment_gcash_qr' => ['value' => '', 'type' => 'string'],
        // fixed = payment_deposit_value pesos | percent = payment_deposit_value % of the total | full = whole total.
        'payment_deposit_type' => ['value' => 'fixed', 'type' => 'string'],
        'payment_deposit_value' => ['value' => '200', 'type' => 'integer'],
        // How long a booking is held for the customer to pay before the slot is released.
        'payment_window_minutes' => ['value' => '30', 'type' => 'integer'],
        'payment_policy' => ['value' => 'The deposit is deducted from your total at the salon.', 'type' => 'text'],
        // Public website & Google. Indexing stays off until real business data replaces the samples.
        'website_indexing' => ['value' => '0', 'type' => 'boolean'],
        'business_city' => ['value' => '', 'type' => 'string'],
        'business_province' => ['value' => '', 'type' => 'string'],
        'facebook_url' => ['value' => '', 'type' => 'string'],
        'website_description' => ['value' => '', 'type' => 'text'],
        'google_site_verification' => ['value' => '', 'type' => 'string'],
        'terms_and_conditions' => ['value' => '', 'type' => 'text'],
        'privacy_policy' => ['value' => '', 'type' => 'text'],
    ],

    'uploads' => [
        'max_kilobytes' => 4096,
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_dimension' => 1600,
    ],

    // Loyalty cards the salon sells: the number printed on the card (digits only, spaces and dashes ignored).
    'loyalty' => [
        'min_digits' => (int) env('LOYALTY_MIN_DIGITS', 8),
        'max_digits' => (int) env('LOYALTY_MAX_DIGITS', 20),
    ],
];
