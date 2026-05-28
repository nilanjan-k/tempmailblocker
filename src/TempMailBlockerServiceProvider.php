<?php

declare(strict_types=1);

namespace NilanjanK\TempMailBlocker;

use Illuminate\Support\ServiceProvider;
use NilanjanK\TempMailBlocker\Console\UpdateDomains;
use NilanjanK\TempMailBlocker\Validation\IndisposableValidator;

/**
 * Laravel service provider for the TempMailBlocker package.
 *
 * Handles container bindings, asset publishing, validator extension
 * registration, and Artisan command registration.
 */
class TempMailBlockerServiceProvider extends ServiceProvider
{
    /**
     * Register package bindings into the service container.
     *
     * Merges the default package configuration so application config always
     * has a complete set of keys even when the user has not published it.
     * Binds the TempMailBlocker class as a shared singleton.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/tempmailblocker.php',
            'tempmailblocker'
        );

        $this->app->singleton(
            'tempmailblocker',
            static fn (): TempMailBlocker => new TempMailBlocker()
        );
    }

    /**
     * Bootstrap package services after all providers are registered.
     *
     * Publishes config and the seed domains file, registers the
     * "indisposable" validator extension with its custom error message
     * replacer, and conditionally registers the Artisan command.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/tempmailblocker.php' => config_path('tempmailblocker.php'),
        ], 'tempmailblocker-config');

        $this->publishes([
            __DIR__ . '/../resources/domains.json' => storage_path('tempmailblocker/domains.json'),
        ], 'tempmailblocker-domains');

        $this->app['validator']->extend(
            'indisposable',
            IndisposableValidator::class . '@validate'
        );

        $this->app['validator']->replacer(
            'indisposable',
            static fn (string $message, string $attribute, string $rule, array $parameters): string =>
                (string) config('tempmailblocker.message', 'Disposable or temporary email addresses are not allowed.')
        );

        if ($this->app->runningInConsole()) {
            $this->commands([UpdateDomains::class]);
        }
    }
}
