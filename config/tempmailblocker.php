<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    |
    | Controls how the blocked-domain list is loaded into memory.
    |
    | "file"    — Reads and JSON-decodes the domains file on each PHP-FPM worker
    |             cold start. Simple, zero infrastructure dependencies.
    |
    | "cache"   — Stores the list in your configured Laravel cache store (Redis,
    |             Memcached, etc.), shared across all workers. Best for
    |             horizontally-scaled fleets where you want one warm-up per
    |             cache server rather than per worker process.
    |
    | "opcache" — Writes a PHP file that returns the domain hash map as a
    |             literal array. OPcache compiles it to bytecode on the first
    |             include and serves it from shared memory on every subsequent
    |             request — no JSON parsing, no file I/O after the first hit.
    |             Fastest option for PHP-FPM + OPcache environments.
    |             Requires running `php artisan tempmailblocker:update` to
    |             generate the PHP file at `opcache_path`.
    |
    */
    'storage' => 'file',

    /*
    |--------------------------------------------------------------------------
    | Cache Key
    |--------------------------------------------------------------------------
    |
    | The key under which the domain list is stored when using the "cache"
    | storage driver. Change this if it conflicts with an existing key.
    |
    */
    'cache_key' => 'tempmailblocker_domains',

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (minutes)
    |--------------------------------------------------------------------------
    |
    | How long the domain list is cached when the "cache" storage driver is
    | active. Defaults to 1 440 minutes (24 hours).
    |
    */
    'cache_ttl' => 1440,

    /*
    |--------------------------------------------------------------------------
    | Domains File Path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the JSON file that holds the blocked-domain list on
    | disk. After running `php artisan tempmailblocker:update` the fetched
    | list is written here. If the file does not exist yet the package falls
    | back to the bundled seed list inside the package's resources directory.
    |
    */
    'domains_path' => storage_path('tempmailblocker/domains.json'),

    /*
    |--------------------------------------------------------------------------
    | OPcache PHP File Path
    |--------------------------------------------------------------------------
    |
    | Path to the auto-generated PHP file used by the "opcache" storage driver.
    | This file is created automatically by `php artisan tempmailblocker:update`
    | when storage is set to "opcache". It exports the domain list as a PHP
    | hash map so OPcache can compile and serve it from shared memory.
    |
    | Only relevant when `storage` is set to "opcache".
    |
    */
    'opcache_path' => storage_path('tempmailblocker/domains.php'),

    /*
    |--------------------------------------------------------------------------
    | Remote Source URL
    |--------------------------------------------------------------------------
    |
    | The plain-text URL from which `tempmailblocker:update` fetches the
    | latest list of disposable email domains. Each line is one domain;
    | lines beginning with "#" are treated as comments and discarded.
    |
    */
    'source_url' => 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/master/disposable_email_blocklist.conf',

    /*
    |--------------------------------------------------------------------------
    | Validation Message
    |--------------------------------------------------------------------------
    |
    | The error message returned when a disposable email address is detected.
    | This message is used by both the "indisposable" string rule and the
    | Indisposable Rule object (unless overridden via the Rule constructor).
    |
    */
    'message' => 'Disposable or temporary email addresses are not allowed.',

    /*
    |--------------------------------------------------------------------------
    | Whitelist
    |--------------------------------------------------------------------------
    |
    | Domains listed here are always considered legitimate, even if they appear
    | in the blocked-domain list. Useful for overriding false positives.
    |
    | Example: ['mycompany-temp.com']
    |
    */
    'whitelist' => [],

    /*
    |--------------------------------------------------------------------------
    | Blacklist
    |--------------------------------------------------------------------------
    |
    | Domains listed here are always considered disposable, even if they do
    | NOT appear in the blocked-domain list. Useful for blocking domains that
    | have not yet been added to the upstream source.
    |
    | Example: ['sketchy-domain.com']
    |
    */
    'blacklist' => [],

];
