<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_mail_command_refuses_the_log_mailer(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('dgs:test-mail', ['email' => 'owner@example.com'])
            ->expectsOutputToContain('emails are not delivered')
            ->assertFailed();
    }

    public function test_test_mail_command_sends_with_a_real_mailer(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $this->artisan('dgs:test-mail', ['email' => 'owner@example.com'])->assertSuccessful();
    }

    public function test_reset_email_shows_the_code_without_a_dead_app_link(): void
    {
        $user = User::factory()->create(['name' => 'Jane']);

        $html = (string) (new ResetPasswordCode('123456'))->toMail($user)->render();

        $this->assertStringContainsString('123456', $html);
        $this->assertStringNotContainsString('dgssalon://', $html);
    }
}
