<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard stop before any test trait runs (RefreshDatabase wipes the database during setUp):
     * tests may only use a LOCAL database whose name ends in "_test" — never Supabase/production.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.connections.'.$app['config']->get('database.default'));
        $host = (string) ($connection['host'] ?? '');
        $database = (string) ($connection['database'] ?? '');

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || ! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against [{$host}/{$database}]: tests must use a local *_test database.");
        }

        return $app;
    }
}
