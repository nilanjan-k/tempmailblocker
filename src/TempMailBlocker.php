<?php

declare(strict_types=1);

namespace NilanjanK\TempMailBlocker;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Throwable;

/**
 * Core singleton service for detecting and blocking disposable email domains.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  PERFORMANCE DESIGN NOTES                                               │
 * │                                                                         │
 * │  1. Hash-map storage: domains are kept as ['domain' => true] rather     │
 * │     than a sequential array. This makes every lookup O(1) via isset()   │
 * │     instead of O(n) via in_array(), regardless of how many domains      │
 * │     are in the list (~100 000 in the real upstream source).             │
 * │                                                                         │
 * │  2. Inline hierarchy traversal: subdomain checks use strpos/substr on   │
 * │     the domain string directly – no intermediate arrays allocated.      │
 * │                                                                         │
 * │  3. Whitelist/blacklist hash maps are built once per process lifetime   │
 * │     from config and cached in instance properties, so config() is       │
 * │     never called more than once per driver across all lookups.          │
 * │                                                                         │
 * │  4. OPcache storage driver: exports the hash map as a PHP return        │
 * │     statement. After the first request, OPcache compiles and holds the  │
 * │     PHP bytecode in shared memory, so subsequent includes are served    │
 * │     at near-zero cost with no disk I/O and no JSON parsing.             │
 * │                                                                         │
 * │  Recommended storage drivers by environment:                            │
 * │    PHP-FPM + OPcache  →  'opcache'  (fastest: shared memory)           │
 * │    Octane / Swoole    →  'file'     (in-process singleton is reused)    │
 * │    Distributed fleet  →  'cache'    (Redis/Memcached shared state)      │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
class TempMailBlocker
{
    /**
     * Maximum bytes read from the HTTP response body (10 MB).
     *
     * @var int
     */
    private const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;

    /**
     * Maximum size (bytes) accepted when reading the domains file from disk (20 MB).
     *
     * @var int
     */
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

    /**
     * Maximum JSON nesting depth accepted when decoding the domains array.
     *
     * @var int
     */
    private const JSON_DEPTH = 3;

    /**
     * In-memory hash map of blocked domains: ['domain.com' => true].
     *
     * Using a hash map instead of a sequential array turns every lookup from
     * O(n) (in_array) to O(1) (isset). Populated lazily on first access.
     *
     * @var array<string, true>|null
     */
    private ?array $domains = null;

    /**
     * Cached whitelist hash map: ['domain.com' => true].
     *
     * Built once from config on first access and reused for all subsequent
     * calls within the same process lifetime.
     *
     * @var array<string, true>|null
     */
    private ?array $whitelistMap = null;

    /**
     * Cached blacklist hash map: ['domain.com' => true].
     *
     * @var array<string, true>|null
     */
    private ?array $blacklistMap = null;

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
     * All lookups are O(1) hash-map operations. The subdomain hierarchy is
     * traversed inline using strpos/substr (no array allocation) to prevent
     * bypass attacks such as user@anything.mailinator.com.
     *
     * Evaluation order:
     *   1. O(1) exact whitelist match  — immediately returns false.
     *   2. O(1) per-level hierarchy check against blacklist and domain list,
     *      from most-specific label down to the apex domain.
     *
     * @param string $email The email address to evaluate.
     * @return bool True when the domain is disposable, false otherwise.
     */
    public function isDisposable(string $email): bool
    {
        $domain    = $this->extractDomain($email);
        $domains   = $this->loadDomains();
        $whitelist = $this->whitelist();
        $blacklist = $this->blacklist();

        // Inline hierarchy traversal: walk from the full subdomain down to the
        // apex, stopping before bare TLDs. Each isset() is O(1) on the hash map.
        //
        // Whitelist is checked at EVERY level of the hierarchy so that adding
        // "mailinator.com" to the whitelist also allows "sub.mailinator.com".
        // This mirrors the blocklist/domain-list hierarchy behaviour and matches
        // the intuitive expectation: whitelisting a domain covers its subtree.
        $candidate = $domain;
        while (true) {
            if (isset($whitelist[$candidate])) {
                return false;
            }

            if (isset($blacklist[$candidate]) || isset($domains[$candidate])) {
                return true;
            }

            $dot = strpos($candidate, '.');
            if ($dot === false) {
                break;
            }

            $parent = substr($candidate, $dot + 1);

            // Stop before bare TLDs (no dot remaining means it is a TLD like "com").
            if (!str_contains($parent, '.')) {
                break;
            }

            $candidate = $parent;
        }

        return false;
    }

    /**
     * Determine whether the given email address does NOT belong to a disposable domain.
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
     * Writes the canonical JSON file (human-readable, source of truth) and, when
     * the 'opcache' driver is active, also writes the pre-built PHP hash-map file
     * for shared-memory serving by OPcache.
     *
     * Security guarantees:
     *   - SSRF/MITM prevention   : only HTTPS source URLs accepted.
     *   - TLS verification       : always enforced.
     *   - Redirect restriction   : only HTTPS targets followed.
     *   - Response safety        : only 2xx accepted; body capped at MAX_RESPONSE_BYTES.
     *   - Data integrity         : each line validated before being saved.
     *   - Deduplication          : upstream duplicates removed before writing.
     *   - Atomic write           : temp file + rename prevents partial reads.
     *   - Exclusive lock (LOCK_EX): prevents concurrent write interleaving.
     *
     * @return bool True on success, false on any failure.
     */
    public function updateDomains(): bool
    {
        try {
            $url = (string) config('tempmailblocker.source_url');

            if (!$this->isHttpsUrl($url)) {
                return false;
            }

            $client = $this->httpClient ?? new Client([
                'timeout'         => 30,
                'connect_timeout' => 10,
                'verify'          => true,
                'allow_redirects' => [
                    'max'       => 3,
                    'strict'    => true,
                    'protocols' => ['https'],
                ],
            ]);

            $response   = $client->get($url);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                return false;
            }

            $stream = $response->getBody();
            $stream->rewind();
            $content = $stream->read(self::MAX_RESPONSE_BYTES);

            // Single-pass parse: normalize to lowercase, validate, deduplicate.
            $seen    = [];
            $domains = [];

            foreach (explode("\n", $content) as $raw) {
                $line = trim($raw);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $normalized = strtolower($line);

                if (isset($seen[$normalized]) || !$this->isValidDomain($normalized)) {
                    continue;
                }

                $seen[$normalized] = true;
                $domains[]         = $normalized;
            }

            unset($seen);

            $path = (string) config('tempmailblocker.domains_path');

            if ($path === '') {
                return false;
            }

            $directory = dirname($path);

            if (!is_dir($directory)) {
                mkdir($directory, 0755, recursive: true);
            }

            // Write the canonical JSON file atomically.
            $encoded = json_encode($domains, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $tmpJson = $directory . DIRECTORY_SEPARATOR
                . basename($path) . '.tmp.' . bin2hex(random_bytes(8));

            if (file_put_contents($tmpJson, $encoded, LOCK_EX) === false) {
                return false;
            }

            if (!rename($tmpJson, $path)) {
                @unlink($tmpJson);
                return false;
            }

            $storage = (string) config('tempmailblocker.storage');

            // Write the pre-built OPcache PHP file when that driver is active.
            if ($storage === 'opcache') {
                $this->generateOpcacheFile($domains);
            }

            // Bust the Laravel cache entry when the cache driver is active.
            if ($storage === 'cache') {
                Cache::forget((string) config('tempmailblocker.cache_key'));
            }

            // Reset all in-process caches so the next lookup picks up the new list.
            $this->domains     = null;
            $this->whitelistMap = null;
            $this->blacklistMap = null;

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
     * Extract the lowercase domain portion from an email address.
     *
     * Domains are already stored in lowercase, so normalizing here ensures
     * every lookup hits the hash-map key on the first try.
     *
     * @param string $email The full email address.
     * @return string Lowercase domain string.
     */
    public function extractDomain(string $email): string
    {
        $parts = explode('@', $email);

        return strtolower(end($parts));
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Return the cached whitelist hash map, building it from config on first call.
     *
     * Calling config() once per process lifetime instead of once per email check
     * avoids repeated config repository lookups on high-traffic endpoints.
     *
     * @return array<string, true>
     */
    private function whitelist(): array
    {
        if ($this->whitelistMap !== null) {
            return $this->whitelistMap;
        }

        $this->whitelistMap = [];

        foreach ((array) config('tempmailblocker.whitelist', []) as $domain) {
            if (is_string($domain) && $domain !== '') {
                $this->whitelistMap[strtolower($domain)] = true;
            }
        }

        return $this->whitelistMap;
    }

    /**
     * Return the cached blacklist hash map, building it from config on first call.
     *
     * @return array<string, true>
     */
    private function blacklist(): array
    {
        if ($this->blacklistMap !== null) {
            return $this->blacklistMap;
        }

        $this->blacklistMap = [];

        foreach ((array) config('tempmailblocker.blacklist', []) as $domain) {
            if (is_string($domain) && $domain !== '') {
                $this->blacklistMap[strtolower($domain)] = true;
            }
        }

        return $this->blacklistMap;
    }

    /**
     * Load the domain hash map from the configured storage driver.
     *
     * Results are memoised in $this->domains for the lifetime of this instance.
     * In long-running runtimes (Octane/Swoole) the singleton is reused across
     * requests, so the file/cache is read at most once for the life of the worker.
     *
     * @return array<string, true> Hash map of blocked domains.
     */
    private function loadDomains(): array
    {
        if ($this->domains !== null) {
            return $this->domains;
        }

        $storage = (string) config('tempmailblocker.storage', 'file');

        $this->domains = match ($storage) {
            'cache'   => $this->loadFromCache(),
            'opcache' => $this->loadFromOpcache(),
            default   => $this->loadFromFile(),
        };

        return $this->domains;
    }

    /**
     * Load domains from the Laravel cache store.
     *
     * The cached value is re-validated and normalized to a hash map on each
     * retrieval to defend against cache-poisoning on shared stores (Redis).
     *
     * @return array<string, true>
     */
    private function loadFromCache(): array
    {
        $cacheKey = (string) config('tempmailblocker.cache_key', 'tempmailblocker_domains');
        $cacheTtl = (int) config('tempmailblocker.cache_ttl', 1440);

        $cached = Cache::remember(
            $cacheKey,
            $cacheTtl * 60,
            fn (): array => $this->loadFromFile()
        );

        // SECURITY: Validate and normalize the cached value.
        // Handles both the current hash-map format and any legacy sequential arrays.
        if (!is_array($cached)) {
            return $this->loadFromFile();
        }

        $map = [];

        foreach ($cached as $key => $value) {
            if (is_string($key) && $key !== '') {
                // Hash-map format (current): key is the domain.
                $map[$key] = true;
            } elseif (is_int($key) && is_string($value) && $value !== '') {
                // Sequential array format (legacy / poisoned cache): value is the domain.
                $map[strtolower($value)] = true;
            }
        }

        return $map;
    }

    /**
     * Load domains from the pre-built OPcache PHP file.
     *
     * After the first request, OPcache compiles the PHP file to bytecode and
     * holds it in shared memory. Subsequent includes are served from shared
     * memory with no disk I/O and no JSON parsing — the fastest possible load.
     *
     * Falls back to loadFromFile() when the OPcache file does not exist yet
     * (e.g. before the first tempmailblocker:update run).
     *
     * @return array<string, true>
     */
    private function loadFromOpcache(): array
    {
        $path = (string) config('tempmailblocker.opcache_path', '');

        if ($path === '' || !file_exists($path)) {
            return $this->loadFromFile();
        }

        try {
            $data = include $path;
        } catch (Throwable) {
            return $this->loadFromFile();
        }

        if (!is_array($data)) {
            return $this->loadFromFile();
        }

        // Normalize in case the file was hand-edited or written in a different format.
        $map = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $key !== '') {
                $map[$key] = true;
            }
        }

        return $map;
    }

    /**
     * Load domains from the JSON file on disk and return them as a hash map.
     *
     * All entries are normalized to lowercase at load time so that every
     * subsequent lookup can be a direct isset() without a strtolower() call.
     *
     * Falls back to the bundled seed file when the configured path does not
     * exist so the package works immediately after installation.
     *
     * @return array<string, true>
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

        $fileSize = @filesize($path);

        if ($fileSize === false || $fileSize > self::MAX_FILE_BYTES) {
            return [];
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            return [];
        }

        try {
            $decoded = json_decode(
                $content,
                associative: true,
                depth: self::JSON_DEPTH,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        // Single-pass: build hash map, normalize to lowercase, drop non-strings.
        $map = [];

        foreach ($decoded as $entry) {
            if (is_string($entry) && $entry !== '') {
                $map[strtolower($entry)] = true;
            }
        }

        return $map;
    }

    /**
     * Write the pre-built OPcache PHP file for the 'opcache' storage driver.
     *
     * Generates a PHP file that returns the domain hash map as a literal array.
     * OPcache compiles this to bytecode on the first include and serves it from
     * shared memory on all subsequent includes — zero JSON parsing, zero file I/O.
     *
     * The file is written atomically and the OPcache entry for the old version is
     * invalidated so workers pick up the new list without a restart.
     *
     * @param array<int, string> $domains Normalized, deduplicated domain list.
     * @return bool True on success, false on any failure.
     */
    private function generateOpcacheFile(array $domains): bool
    {
        $path = (string) config('tempmailblocker.opcache_path', '');

        if ($path === '') {
            return false;
        }

        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, recursive: true);
        }

        // Build the hash map and export as a PHP return statement.
        $hashMap  = array_fill_keys($domains, true);
        $exported = var_export($hashMap, true);
        $content  = "<?php\n// Auto-generated by tempmailblocker — do not edit manually.\ndeclare(strict_types=1);\nreturn {$exported};\n";

        $tmpPath = $directory . DIRECTORY_SEPARATOR
            . basename($path) . '.tmp.' . bin2hex(random_bytes(8));

        if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            return false;
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return false;
        }

        // Invalidate the OPcache entry so workers serve the new version immediately.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, force: true);
        }

        return true;
    }

    /**
     * Return true when the given URL uses the HTTPS scheme.
     *
     * @param string $url The URL to validate.
     * @return bool
     */
    private function isHttpsUrl(string $url): bool
    {
        return $url !== '' && str_starts_with(strtolower($url), 'https://');
    }

    /**
     * Return true when the given string is a syntactically valid domain name.
     *
     * Validates RFC-1123 label format: dot-separated labels, each 1–63
     * alphanumeric-or-hyphen characters, not starting or ending with a hyphen,
     * TLD of 2–63 alphabetic characters, total length ≤ 253 characters.
     * Punycode (xn--) internationalized labels pass naturally.
     *
     * @param string $domain The candidate domain string.
     * @return bool
     */
    private function isValidDomain(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
            $domain
        );
    }
}
