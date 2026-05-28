<?php

declare(strict_types=1);

namespace NilanjanK\TempMailBlocker\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade providing static access to the TempMailBlocker singleton.
 *
 * @method static bool isDisposable(string $email)  Returns true if the email domain is disposable.
 * @method static bool isIndisposable(string $email) Returns true if the email domain is NOT disposable.
 * @method static bool updateDomains()              Fetches and saves the latest domain list; returns true on success.
 * @method static int  count()                      Returns the total count of blocked domains currently loaded.
 *
 * @see \NilanjanK\TempMailBlocker\TempMailBlocker
 */
class TempMailBlocker extends Facade
{
    /**
     * Get the registered name of the component in the service container.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'tempmailblocker';
    }
}
