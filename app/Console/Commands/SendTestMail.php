<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Check that the server can send email (password reset codes depend on it). Run on the server after setting MAIL_*. */
class SendTestMail extends Command
{
    protected $signature = 'dgs:test-mail {email : Where to send the test message}';

    protected $description = 'Send a test email with the configured mailer';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            $this->error("MAIL_MAILER is \"{$mailer}\": emails are not delivered. Set the SMTP settings in .env (see docs/DEPLOYMENT.md).");

            return self::FAILURE;
        }

        try {
            Mail::raw('This is a test email from '.config('app.name').'. Password reset codes will arrive the same way.', function ($message) {
                $message->to($this->argument('email'))->subject('Test email from '.config('app.name'));
            });
        } catch (Throwable $e) {
            $this->error('Sending failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent with the \"{$mailer}\" mailer from ".config('mail.from.address').'. Check the inbox (and spam folder).');

        return self::SUCCESS;
    }
}
