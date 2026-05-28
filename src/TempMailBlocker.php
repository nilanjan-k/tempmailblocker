<?php

declare(strict_types=1);

namespace NilanjanK\TempMailBlocker;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Core singleton service for detecting and blocking disposable email domains.
 *
 * Responsible for loading the domain list from the configured storage driver,
 * checking email addresses against it, and refreshing the list from the
 * upstream remote source.
 */
class TempMailBlocker
{
    /**
     * In-memory cache of loaded blocked domains.
     *
     * Populated lazily on first access and reset whenever updateDomains() runs.
     *
     * @var array<int, string>|null
     */
    private ?array $domains = null;

    /**
     * Create a new TempMailBlocker instance.
     *
     * The optional $httpClient parameter allows injecting a pre-configured
     * or mocked GuzzleHttp client for testing without network access.
     *
     * @param ClientInterface|null $httpClient HTTP client for remote fetches.
     */
    public function __construct(
        private readonly ?ClientInterface $httpClient = null
    ) {}

    /**
     * Determine whether the given email address belongs to a disposable domain.
     *
     * Evaluation order:
     *   1. Whitelist — immediately returns false (allowed).
     *   2. Blacklist — immediately returns true (blocked).
     *   3. Loaded domain list — returns true when matched.
     *
     * @param string $email The email address to evaluate.
     * @return bool True when the domain is disposable, false otherwise.
     */
    public function isDisposable(string $email): bool
    {
        $domain    = $this->extractDomain($email);
        $whitelist = (array) config('tempmailblocker.whitelist', []);
        $blacklist = (array) config('tempmailblocker.blacklist', []);

        if (in_array($domain, $whitelist, strict: true)) {
            return false;
        }

        if (in_array($domain, $blacklist, strict: true)) {
            return true;
        }

        return in_array($domain, $this->loadDomains(), strict: true);
    }

    /**
     * Determine whether the given email address does NOT belong to a disposable domain.
     *
     * Convenience inverse of isDisposable().
     *
     * @param string $email The email address to evaluate.
     * @return bool True when the domain is legitimate, false when disposable.
     */
    public function isIndisposable(string $email): bool
    {
        return !$this->isDisposable($email);
    }

    /**
     * Fetch the latest domain list from the configured remote source and persist it.
     *
     * The upstream file is plain text with one domain per line. Comment lines
     * (beginning with "#") and blank lines are silently discarded before saving.
     * On success the in-memory cache is reset so subsequent calls use the new list.
     * When the "cache" storage driver is active the cache entry is also busted.
     *
     * @return bool True on success, false on any failure.
     */
    public function updateDomains(): bool
    {
        try {
            $client  = $this->httpClient ?? new Client(['timeout' => 30]);
            $url     = (string) config('tempmailblocker.source_url');
            $body    = (string) $client->get($url)->getBody();

            $domains = array_values(
                array_filter(
                    array_map('trim', explode("\n", $body)),
                    static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')
                )
            );

            $path      = (string) config('tempmailblocker.domains_path');
            $directory = dirname($path);

            if (!is_dir($directory)) {
                mkdir($directory, 0755, recursive: true);
            }

            file_put_contents($path, json_encode($domains, JSON_PRETTY_PRINT));

            if (config('tempmailblocker.storage') === 'cache') {
                Cache::forget((string) config('tempmailblocker.cache_key'));
            }

            $this->domains = null;

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Return the total count of blocked domains currently loaded.
     *
     * @return int Number of domains in the active list.
     */
    public function count(): int
    {
        return count($this->loadDomains());
    }

    /**
     * Extract the domain portion from an email address and return it in lowercase.
     *
     * When the value contains no "@" sign the entire string is treated as the
     * domain — this mirrors PHP's own filter_var e-mail behaviour.
     *
     * @param string $email The full email address.
     * @return string Lowercase domain string.
     */
    public function extractDomain(string $email): string
    {
        $parts = explode('@', $email);

        return strtolower(end($parts));
    }

    /**
     * Load the domain list from the configured storage driver.
     *
     * Results are memoised in $this->domains for the lifetime of this instance.
     *
     * @return array<int, string> Flat array of blocked domain strings.
     */
    private function loadDomains(): array
    {
        if ($this->domains !== null) {
            return $this->domains;
        }

        $storage = (string) config('tempmailblocker.storage', 'file');

        $this->domains = match ($storage) {
            'cache' => $this->loadFromCache(),
            default => $this->loadFromFile(),
        };

        return $this->domains;
    }

    /**
     * Load domains from the configured Laravel cache store.
     *
     * Falls back to loadFromFile() on a cache miss so a warm cache is never
     * required for the package to function correctly.
     *
     * @return array<int, string>
     */
    private function loadFromCache(): array
    {
        $cacheKey = (string) config('tempmailblocker.cache_key', 'tempmailblocker_domains');
        $cacheTtl = (int) config('tempmailblocker.cache_ttl', 1440);

        return Cache::remember(
            $cacheKey,
            $cacheTtl * 60,
            fn (): array => $this->loadFromFile()
        );
    }

    /**
     * Load domains from the JSON file on disk.
     *
     * If the configured domains_path does not exist yet the method falls back
     * to the bundled seed file at resources/domains.json so the package works
     * out of the box without running tempmailblocker:update first.
     *
     * @return array<int, string>
     */
    private function loadFromFile(): array
    {
        $configured = (string) config('tempmailblocker.domains_path');
        $fallback   = __DIR__ . '/../resources/domains.json';

        $path = match (true) {
            file_exists($configured) => $configured,
            file_exists($fallback)   => $fallback,
            default                  => null,
        };

        if ($path === null) {
            return [];
        }

        $content = file_get_contents($path);

        return json_decode((string) $content, associative: true) ?? [];
    }
}
