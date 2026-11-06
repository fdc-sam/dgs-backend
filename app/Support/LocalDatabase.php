<?php

namespace App\Support;

use Illuminate\Support\ConfigurationUrlParser;

/**
 * Whether a database connection is on this machine (SQLite, or a server at localhost). A developer's .env can point
 * at the live database while saying APP_ENV=local, so "local environment" alone must never decide that demo data may
 * be seeded or tables wiped.
 */
final class LocalDatabase
{
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    public static function isLocal(?string $connection = null): bool
    {
        $name = $connection ?? config('database.default');

        return self::isLocalConfig((array) config("database.connections.{$name}", []));
    }

    /** @param array<string, mixed> $config a connection's config; a `url` (e.g. DB_URL) wins over `host` */
    public static function isLocalConfig(array $config): bool
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        if (($config['driver'] ?? null) === 'sqlite') {
            return true;
        }

        return in_array(strtolower(trim((string) ($config['host'] ?? ''), '[]')), self::LOCAL_HOSTS, true);
    }

    /** The host name to mention in messages ("db.example.supabase.co"), or the driver for SQLite. */
    public static function describe(?string $connection = null): string
    {
        $name = $connection ?? config('database.default');
        $config = (new ConfigurationUrlParser)->parseConfiguration((array) config("database.connections.{$name}", []));

        return ($config['driver'] ?? null) === 'sqlite' ? 'sqlite' : (string) ($config['host'] ?? 'unknown host');
    }
}
