<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    |
    | Controls how the blocked-domain list is stored between requests.
    | "file"  — reads the JSON file on every cold start (zero cache deps).
    | "cache" — stores the list in your configured Laravel cache store, keyed
    |           by `cache_key` and expired after `cache_ttl` minutes.
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
