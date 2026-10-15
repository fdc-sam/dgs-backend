<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores the send-push Edge Function URL and the shared webhook secret in Supabase Vault,
 * where the notifications trigger reads them. Re-run to rotate the secret.
 */
class ConfigureSupabasePush extends Command
{
    protected $signature = 'dgs:supabase-push {--rotate : Generate a new webhook secret}';

    protected $description = 'Store the push Edge Function URL and webhook secret in Supabase Vault';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql' || ! DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'vault') AS v")->v) {
            $this->error('The default database connection is not a Supabase Postgres database.');

            return self::FAILURE;
        }

        $url = rtrim((string) config('services.supabase.url'), '/');
        if ($url === '') {
            $this->error('Set SUPABASE_URL in .env first.');

            return self::FAILURE;
        }

        $secret = (string) config('services.supabase.push_webhook_secret');
        if ($secret === '' || $this->option('rotate')) {
            $secret = Str::random(48);
            $this->writeEnv('SUPABASE_PUSH_WEBHOOK_SECRET', $secret);
            $this->warn('Generated a new SUPABASE_PUSH_WEBHOOK_SECRET in .env — set the same value as the Edge Function secret DGS_PUSH_WEBHOOK_SECRET.');
        }

        $this->upsertSecret('dgs_push_function_url', $url.'/functions/v1/send-push', 'send-push Edge Function URL');
        $this->upsertSecret('dgs_push_webhook_secret', $secret, 'Shared secret sent by the notifications trigger');

        $this->info('Vault secrets stored. New notification rows will now be pushed through the send-push function.');

        return self::SUCCESS;
    }

    private function upsertSecret(string $name, string $value, string $description): void
    {
        $id = DB::selectOne('SELECT id FROM vault.secrets WHERE name = ?', [$name])?->id;

        $id
            ? DB::select('SELECT vault.update_secret(?, ?, ?, ?)', [$id, $value, $name, $description])
            : DB::select('SELECT vault.create_secret(?, ?, ?)', [$value, $name, $description]);
    }

    private function writeEnv(string $key, string $value): void
    {
        $path = base_path('.env');
        $env = file_get_contents($path);
        $line = "{$key}={$value}";
        $env = preg_match("/^{$key}=.*$/m", $env)
            ? preg_replace("/^{$key}=.*$/m", $line, $env)
            : rtrim($env).PHP_EOL.$line.PHP_EOL;
        file_put_contents($path, $env);
    }
}
