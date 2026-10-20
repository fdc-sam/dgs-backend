<?php

namespace App\Support;

/**
 * The privacy policy shown in the app and at /privacy (Google Play requires a public URL). The owner's text from
 * Settings wins; until they write one, a default that matches what the app actually collects is used.
 */
final class PrivacyPolicy
{
    public const UPDATED = '20 October 2026';

    /** @param  array<string, mixed>  $s  all settings */
    public static function text(array $s): string
    {
        $custom = trim((string) ($s['privacy_policy'] ?? ''));

        return $custom !== '' ? $custom : self::default($s);
    }

    /** @param  array<string, mixed>  $s */
    private static function default(array $s): string
    {
        $name = $s['salon_name'] ?: 'DGS Hair Salon & Spa';
        $deleteUrl = url('/delete-account');
        $contact = collect([
            filled($s['salon_email'] ?? null) ? 'Email: '.$s['salon_email'] : null,
            filled($s['salon_phone'] ?? null) ? 'Phone: '.$s['salon_phone'] : null,
            filled($s['salon_address'] ?? null) ? 'Address: '.$s['salon_address'] : null,
        ])->filter()->join("\n") ?: 'Please contact us at any of our branches.';
        $updated = self::UPDATED;

        return <<<TEXT
        Last updated: {$updated}

        {$name} ("we", "us") runs the {$name} mobile app and this website. This policy explains what personal information we collect, why we collect it, and the choices you have. We handle personal information in line with the Philippine Data Privacy Act of 2012 (Republic Act No. 10173).

        INFORMATION WE COLLECT
        • Account details: your name, email address, mobile number and password. Passwords are stored only in hashed form.
        • Profile details you choose to add: profile photo, date of birth, gender, address and emergency contact.
        • Bookings: the services, branch, staff, dates and times you book, cancel or reschedule, and any notes you add.
        • Payments: photos of GCash payment receipts you upload for booking deposits, and their status. We do not collect card or bank account details.
        • Reviews (with any photos) and favorites you add.
        • Device information: a push notification token, so we can send you booking updates.

        HOW WE USE IT
        • To create and secure your account and sign you in.
        • To book, confirm, remind you of and manage your appointments.
        • To verify deposits and keep accounting records.
        • To send booking updates and salon notifications. You can turn notifications off in your phone's settings.
        • To identify you at the salon with your personal QR code.
        We do not sell your personal information, and we do not use it for advertising.

        WHO WE SHARE IT WITH
        • Our salon staff, who need your booking details to serve you.
        • Service providers that run the app for us: Amazon Web Services (server hosting), Supabase (database, file storage and notifications) and Google Firebase Cloud Messaging (push notifications). They process data only to provide these services.
        • Government authorities, when the law requires it.

        SECURITY
        All data is sent over encrypted (HTTPS) connections. Payment receipts are stored privately and only authorised salon staff can view them.

        KEEPING AND DELETING YOUR DATA
        We keep your information for as long as you have an account. You can delete your account at any time:
        • In the app: Profile → Settings → Delete account.
        • On the web: {$deleteUrl}
        When you delete your account we immediately cancel your upcoming appointments and delete your profile details, photo, favorites, reviews, notifications and device tokens, and remove your name, email address, mobile number and QR code from our records. Records of past appointments and payments are kept without your name or contact details, for accounting and tax purposes.

        YOUR RIGHTS
        You may ask to access, correct or delete your personal information, or object to how we use it. Most details can be changed in the app under Profile → Edit profile.

        CHILDREN
        The app is not intended for children under 13, and we do not knowingly collect their personal information.

        CHANGES
        If we change this policy we will update the date above and, for important changes, let you know in the app.

        CONTACT US
        {$name}
        {$contact}
        TEXT;
    }
}
