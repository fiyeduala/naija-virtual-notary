<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything that could be a real database.
     *
     * Tests wipe and rebuild the schema. phpunit.xml and phpunit.mysql.xml
     * already force a throwaway database, but a config file can be edited or
     * the wrong one passed on the command line, and the cost of that mistake is
     * the live data. So the check is made once more here, after configuration
     * has loaded and before RefreshDatabase touches anything: SQLite must be in
     * memory, and any other database must be named *_testing.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $safe = $connection === 'sqlite'
            ? $database === ':memory:'
            : str_ends_with($database, '_testing');

        if (! $safe) {
            throw new RuntimeException(
                "Refusing to run tests against [{$connection}] database [{$database}]. "
                . 'Use phpunit.xml (in-memory SQLite) or phpunit.mysql.xml (a *_testing database).'
            );
        }

        return parent::setUpTraits();
    }
}
