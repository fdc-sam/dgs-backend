<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Bootstrap an admin account in production (demo seeders never run there). */
class CreateAdmin extends Command
{
    protected $signature = 'dgs:create-admin {email} {--name=Administrator} {--role=super_admin : super_admin or admin}
        {--test : A test account: sees only test customers and their bookings}';

    protected $description = 'Create (or promote) an admin portal account';

    public function handle(): int
    {
        $role = UserRole::tryFrom($this->option('role'));
        if (! $role?->isManager()) {
            $this->error('Role must be super_admin or admin.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (min 8 chars, letters and numbers)');
        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', Password::min(8)->letters()->numbers()]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // forceFill: email_verified_at is not mass-assignable, and local strict mode refuses to drop it silently.
        $user = User::firstOrNew(['email' => strtolower($this->argument('email'))]);
        $user->forceFill([
            'name' => $this->option('name'),
            'password' => $password,
            'role' => $role,
            'status' => UserStatus::Active,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'is_test' => (bool) $this->option('test'),
        ])->save();

        $this->info(($this->option('test') ? 'Test ' : '')."{$role->label()} account ready: {$user->email}");

        return self::SUCCESS;
    }
}
