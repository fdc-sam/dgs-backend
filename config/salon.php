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
        // The GCash reward a referrer earns when the person they referred gets one of these services. Shown in the app
        // under the customer's loyalty card (whose perks come from each service's loyalty prices); the rewards also
        // pre-fill the amount when a referral is completed (App\Services\LoyaltyProgram).
        'referral_rewards' => ['value' => '[{"service":"Haircut","amount":20},{"service":"Rebond","amount":200},{"service":"Manicure","amount":50},{"service":"Brazilian botox","amount":200}]', 'type' => 'json'],
        // Loyalty card through a friend (App\Services\LoyaltyCardRequestService): a customer scans the QR of a friend who
        // has an active card, pays gcash_amount to the salon by GCash in the app and endorser_cash in cash to the friend;
        // the admin checks the receipt and approves. The card price is the two together. The QR is optional (e.g. a
        // fixed-amount GCash QR for the salon's share); without it the salon GCash QR / number is shown.
        'loyalty_endorsement_enabled' => ['value' => '1', 'type' => 'boolean'],
        'loyalty_endorsement_gcash_amount' => ['value' => '100', 'type' => 'integer'],
        'loyalty_endorsement_endorser_cash' => ['value' => '100', 'type' => 'integer'],
        'loyalty_endorsement_gcash_qr' => ['value' => '', 'type' => 'string'],
    ],

    'uploads' => [
        'max_kilobytes' => 4096,
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_dimension' => 1600,
    ],

    // Loyalty cards the salon sells, printed "CARD NO. LC-0000353": the prefix plus a zero-padded number.
    // The salon links a card in the admin (typed with or without the prefix, spaces or dashes; only the digits are
    // stored). A card is good for validity_months from the day it is linked or renewed; the admin can renew it once it
    // has expired, or up to renewal_window_days before.
    'loyalty' => [
        'prefix' => env('LOYALTY_PREFIX', 'LC-'),
        'min_digits' => (int) env('LOYALTY_MIN_DIGITS', 7),
        'max_digits' => (int) env('LOYALTY_MAX_DIGITS', 7),
        'validity_months' => (int) env('LOYALTY_VALIDITY_MONTHS', 12),
        'renewal_window_days' => (int) env('LOYALTY_RENEWAL_WINDOW_DAYS', 30),
    ],
];
