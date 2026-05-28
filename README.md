# nilanjan-k/tempmailblocker

A Laravel package to detect and block disposable/temporary email addresses during validation.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/nilanjan-k/tempmailblocker.svg?style=flat-square)](https://packagist.org/packages/nilanjan-k/tempmailblocker)
[![Laravel](https://img.shields.io/badge/Laravel-10.x%20%7C%2011.x%20%7C%2012.x%20%7C%2013.x-orange.svg?style=flat-square)](https://laravel.com)
[![PHP Version Require](https://img.shields.io/packagist/php-v/nilanjan-k/tempmailblocker.svg?style=flat-square)](https://packagist.org/packages/nilanjan-k/tempmailblocker)
[![License](https://img.shields.io/packagist/l/nilanjan-k/tempmailblocker.svg?style=flat-square)](LICENSE)
[![Tests](https://img.shields.io/github/actions/workflow/status/nilanjan-k/tempmailblocker/tests.yml?label=tests&style=flat-square)](https://github.com/nilanjan-k/tempmailblocker/actions)

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.1 or higher |
| Laravel | 10.x, 11.x, 12.x, or 13.x |
| GuzzleHttp | 7.x |

---

## Installation

Install the package via Composer:

```bash
composer require nilanjan-k/tempmailblocker
```

Laravel's auto-discovery will register the service provider and facade automatically.

After installation, seed the domain list from the upstream source:

```bash
php artisan tempmailblocker:update
```

This command writes the domain list to `storage/tempmailblocker/domains.json`. Until you run it, the package uses the small seed list bundled inside the package itself.

---

## Configuration

Publish the configuration file to customise behaviour:

```bash
php artisan vendor:publish --tag=tempmailblocker-config
```

This creates `config/tempmailblocker.php`. Every key is documented below.

| Key | Default | Description |
|---|---|---|
| `storage` | `"file"` | Storage driver: `"file"` reads and JSON-decodes the domain file on each cold start; `"cache"` shares the list via Laravel's cache store (Redis/Memcached); `"opcache"` loads a pre-built PHP hash-map from OPcache shared memory — fastest option for PHP-FPM deployments. |
| `cache_key` | `"tempmailblocker_domains"` | Cache key used when `storage` is `"cache"`. |
| `cache_ttl` | `1440` | Cache lifetime in **minutes** (default: 24 hours). Only used when `storage` is `"cache"`. |
| `domains_path` | `storage_path('tempmailblocker/domains.json')` | Absolute path to the JSON domain list on disk. Written by `tempmailblocker:update`; falls back to the bundled seed list if absent. |
| `opcache_path` | `storage_path('tempmailblocker/domains.php')` | Path to the auto-generated PHP hash-map file used by the `"opcache"` driver. Created automatically by `tempmailblocker:update` when `storage` is `"opcache"`. |
| `source_url` | *(disposable-email-domains GitHub raw URL)* | Remote source from which `tempmailblocker:update` fetches the latest list. Must be an HTTPS URL. |
| `message` | `"Disposable or temporary email addresses are not allowed."` | Default validation error message. |
| `whitelist` | `[]` | Array of domains that are **always allowed**, even if they appear in the blocked list. Subdomains are also covered (whitelisting `example.com` also allows `sub.example.com`). |
| `blacklist` | `[]` | Array of domains that are **always blocked**, even if they do not appear in the blocked list. Subdomains are also covered. |

### Choosing a storage driver

| Driver | Best for | How it works |
|---|---|---|
| `"file"` | Octane / Swoole, single-server setups | Reads `domains_path` once per worker process; the singleton holds it in memory for the worker's lifetime. |
| `"cache"` | Horizontally-scaled fleets | Stores the domain list in Laravel's configured cache store (Redis, Memcached). One warm-up per cache server instead of per worker. |
| `"opcache"` | PHP-FPM + OPcache deployments | `tempmailblocker:update` writes a PHP file containing the hash map as a literal `return` statement. OPcache compiles it to bytecode on the first request and serves it from shared memory on every subsequent one — no JSON parsing, no disk I/O after the initial compile. |

To use the `opcache` driver:

1. Set `'storage' => 'opcache'` in `config/tempmailblocker.php`.
2. Run `php artisan tempmailblocker:update` — this writes both `domains.json` and `domains.php`.
3. Schedule daily updates so the PHP file stays fresh (see [Scheduling automatic updates](#scheduling-automatic-updates)).

---

## Usage

### As a string rule

```php
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'indisposable'],
        ];
    }
}
```

### As a Rule object

```php
use NilanjanK\TempMailBlocker\Rules\Indisposable;

$request->validate([
    'email' => ['required', 'email', new Indisposable()],
]);
```

### With a custom error message

```php
use NilanjanK\TempMailBlocker\Rules\Indisposable;

$request->validate([
    'email' => [
        'required',
        'email',
        new Indisposable('Sorry, throwaway email addresses are not accepted.'),
    ],
]);
```

### Via the Facade

```php
use TempMailBlocker;

if (TempMailBlocker::isDisposable($email)) {
    // handle rejection
}

if (TempMailBlocker::isIndisposable($email)) {
    // proceed normally
}
```

Or with the full facade class path:

```php
use NilanjanK\TempMailBlocker\Facades\TempMailBlocker;

TempMailBlocker::isDisposable('user@mailinator.com'); // true
TempMailBlocker::isIndisposable('user@gmail.com');    // true
TempMailBlocker::count();                             // e.g. 19 000+
```

---

## Updating the Domain List

Run this Artisan command to download the latest list from the configured `source_url`:

```bash
php artisan tempmailblocker:update
```

### Scheduling automatic updates

**Laravel 11+ (`routes/console.php`):**

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('tempmailblocker:update')->daily();
```

**Laravel 10 and below (`app/Console/Kernel.php`):**

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('tempmailblocker:update')->daily();
}
```

---

## Whitelisting & Blacklisting

### Whitelist — always allow a domain

Add domains to `whitelist` in `config/tempmailblocker.php` to allow them even if they appear in the blocked list:

```php
'whitelist' => [
    'my-internal-tool.com',
    'trusted-partner.org',
],
```

### Blacklist — always block a domain

Add domains to `blacklist` to block them even if they are absent from the downloaded list:

```php
'blacklist' => [
    'new-sketchy-domain.io',
    'suspicious-provider.net',
],
```

Whitelist takes precedence over blacklist. A domain present in both will be **allowed**.

---

## Testing

```bash
composer test
```

Or directly:

```bash
./vendor/bin/phpunit
```

---

## Contributing

Contributions are welcome. Please open an issue first to discuss what you would like to change, then submit a pull request against the `main` branch. Make sure all tests pass and follow PSR-12 coding standards.

---

## License

MIT © Nilanjan K
