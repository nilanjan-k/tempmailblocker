<?php

declare(strict_types=1);

namespace NilanjanK\TempMailBlocker\Console;

use Illuminate\Console\Command;
use NilanjanK\TempMailBlocker\Facades\TempMailBlocker as TempMailBlockerFacade;

/**
 * Artisan command that fetches the latest disposable-email domain list from
 * the configured remote source and saves it to local storage.
 *
 * Run this command periodically (e.g. daily via the scheduler) to keep the
 * domain list up to date.
 *
 * Usage:
 *   php artisan tempmailblocker:update
 */
class UpdateDomains extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'tempmailblocker:update';

    /**
     * The console command description shown in `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Fetch and cache the latest disposable email domain list';

    /**
     * Execute the console command.
     *
     * Calls TempMailBlocker::updateDomains() and reports the outcome.
     * On success the total count of blocked domains is printed.
     * On failure a diagnostic message is printed and FAILURE is returned.
     *
     * @return int Command exit code — self::SUCCESS or self::FAILURE.
     */
    public function handle(): int
    {
        $this->info('Fetching the latest disposable email domain list…');

        $success = TempMailBlockerFacade::updateDomains();

        if (!$success) {
            $this->error(
                'Failed to update the domain list. Please verify the source URL in your config '
                . '(tempmailblocker.source_url) and check your internet connection.'
            );

            return self::FAILURE;
        }

        $count = TempMailBlockerFacade::count();

        $this->info("Domain list updated successfully. {$count} blocked domains are now active.");

        return self::SUCCESS;
    }
}
