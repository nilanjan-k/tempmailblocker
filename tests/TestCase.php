<?php

declare(strict_types=1);

namespace NilanjanK\TempMailBlocker\Tests;

use NilanjanK\TempMailBlocker\Facades\TempMailBlocker as TempMailBlockerFacade;
use NilanjanK\TempMailBlocker\TempMailBlockerServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Base test case for the TempMailBlocker package.
 *
 * Bootstraps a minimal Laravel application using Orchestra Testbench,
 * registers the package service provider and facade, and configures the
 * storage driver to use the bundled seed domains.json so tests run without
 * any network access or writable storage.
 */
class TestCase extends OrchestraTestCase
{
    /**
     * Return the service providers to load for the test environment.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            TempMailBlockerServiceProvider::class,
        ];
    }

    /**
     * Return the facade aliases to register for the test environment.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'TempMailBlocker' => TempMailBlockerFacade::class,
        ];
    }

    /**
     * Configure the test application environment.
     *
     * Forces the "file" storage driver and points domains_path at the
     * bundled seed file so every test starts with a known, fixed domain list
     * and never writes to the real storage directory.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('tempmailblocker.storage', 'file');
        $app['config']->set(
            'tempmailblocker.domains_path',
            dirname(__DIR__) . '/resources/domains.json'
        );
    }
}
