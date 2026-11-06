<?php

namespace Tests\Unit;

use App\Support\LocalDatabase;
use PHPUnit\Framework\TestCase;

/** Demo seeding and table wiping are only allowed on a database on this machine (DatabaseSeeder, AppServiceProvider). */
class LocalDatabaseTest extends TestCase
{
    public function test_only_sqlite_and_localhost_count_as_local(): void
    {
        $this->assertTrue(LocalDatabase::isLocalConfig(['driver' => 'sqlite', 'database' => '/tmp/local.sqlite']));
        $this->assertTrue(LocalDatabase::isLocalConfig(['driver' => 'mysql', 'host' => '127.0.0.1']));
        $this->assertTrue(LocalDatabase::isLocalConfig(['driver' => 'pgsql', 'host' => 'localhost']));

        // The live Supabase database, by host or by DB_URL (which wins over the host).
        $this->assertFalse(LocalDatabase::isLocalConfig(['driver' => 'pgsql', 'host' => 'aws-0-ap-southeast-1.pooler.supabase.com']));
        $this->assertFalse(LocalDatabase::isLocalConfig(['driver' => 'pgsql', 'host' => '127.0.0.1',
            'url' => 'postgresql://user:secret@db.example.supabase.co:5432/postgres']));
        $this->assertFalse(LocalDatabase::isLocalConfig(['driver' => 'pgsql']));
    }
}
