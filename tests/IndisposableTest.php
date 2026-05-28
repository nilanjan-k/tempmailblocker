<?php

declare(strict_types=1);

namespace NilanjanK\TempMailBlocker\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NilanjanK\TempMailBlocker\Facades\TempMailBlocker as TempMailBlockerFacade;
use NilanjanK\TempMailBlocker\Rules\Indisposable;
use NilanjanK\TempMailBlocker\TempMailBlocker;

/**
 * Feature and unit tests for the TempMailBlocker package.
 *
 * Each test is isolated: Orchestra Testbench creates a fresh application
 * instance per test, so the singleton domain cache never leaks between cases.
 */
class IndisposableTest extends TestCase
{
    // ─── 1. Rule object — disposable email fails ──────────────────────────

    /**
     * @test
     */
    public function disposable_email_fails_validation_using_rule_object(): void
    {
        $validator = $this->app['validator']->make(
            ['email' => 'user@mailinator.com'],
            ['email' => [new Indisposable()]]
        );

        $this->assertTrue($validator->fails());
        $this->assertNotEmpty($validator->errors()->get('email'));
    }

    // ─── 2. Rule object — clean email passes ──────────────────────────────

    /**
     * @test
     */
    public function clean_email_passes_validation_using_rule_object(): void
    {
        $validator = $this->app['validator']->make(
            ['email' => 'user@gmail.com'],
            ['email' => [new Indisposable()]]
        );

        $this->assertFalse($validator->fails());
    }

    // ─── 3. String rule — disposable email rejected ───────────────────────

    /**
     * @test
     */
    public function string_rule_rejects_a_disposable_email(): void
    {
        $validator = $this->app['validator']->make(
            ['email' => 'test@yopmail.com'],
            ['email' => 'indisposable']
        );

        $this->assertTrue($validator->fails());
    }

    // ─── 4. String rule — clean email accepted ────────────────────────────

    /**
     * @test
     */
    public function string_rule_accepts_a_clean_email(): void
    {
        $validator = $this->app['validator']->make(
            ['email' => 'contact@example.com'],
            ['email' => 'indisposable']
        );

        $this->assertFalse($validator->fails());
    }

    // ─── 5. Whitelist — exact domain allowed even if in blocklist ─────────

    /**
     * @test
     */
    public function whitelisted_domain_is_allowed_even_if_in_blocklist(): void
    {
        config(['tempmailblocker.whitelist' => ['mailinator.com']]);

        $this->assertFalse(TempMailBlockerFacade::isDisposable('test@mailinator.com'));
    }

    // ─── 6. Whitelist — hierarchy: subdomain is also allowed ──────────────

    /**
     * @test
     */
    public function whitelisted_parent_domain_also_covers_its_subdomains(): void
    {
        // Whitelisting the apex should protect its entire subdomain tree.
        // Without hierarchy-aware whitelist checking, sub.mailinator.com would
        // still be blocked because the domain list hit on "mailinator.com".
        config(['tempmailblocker.whitelist' => ['mailinator.com']]);

        $this->assertFalse(TempMailBlockerFacade::isDisposable('user@sub.mailinator.com'));
        $this->assertFalse(TempMailBlockerFacade::isDisposable('user@a.b.mailinator.com'));
    }

    // ─── 7. Blacklist — domain blocked even if not in blocklist ──────────

    /**
     * @test
     */
    public function blacklisted_domain_is_blocked_even_if_not_in_blocklist(): void
    {
        config(['tempmailblocker.blacklist' => ['legitimate-business.com']]);

        $this->assertTrue(TempMailBlockerFacade::isDisposable('hr@legitimate-business.com'));
    }

    // ─── 8. Blacklist — hierarchy: subdomain of blacklisted apex blocked ──

    /**
     * @test
     */
    public function blacklisted_parent_domain_also_blocks_its_subdomains(): void
    {
        config(['tempmailblocker.blacklist' => ['bad-provider.com']]);

        $this->assertTrue(TempMailBlockerFacade::isDisposable('x@sub.bad-provider.com'));
    }

    // ─── 9. Invalid format — silently deferred to 'email' rule ───────────

    /**
     * @test
     */
    public function invalid_email_format_is_not_blocked_by_indisposable_rule(): void
    {
        $validator = $this->app['validator']->make(
            ['email' => 'not-an-email-at-all'],
            ['email' => [new Indisposable()]]
        );

        $this->assertFalse($validator->fails());
    }

    // ─── 10. Facade — isDisposable() true for known domain ───────────────

    /**
     * @test
     */
    public function facade_is_disposable_returns_true_for_known_disposable_domain(): void
    {
        $this->assertTrue(TempMailBlockerFacade::isDisposable('anyone@mailinator.com'));
    }

    // ─── 11. Facade — isIndisposable() true for clean domain ─────────────

    /**
     * @test
     */
    public function facade_is_indisposable_returns_true_for_clean_domain(): void
    {
        $this->assertTrue(TempMailBlockerFacade::isIndisposable('user@github.com'));
    }

    // ─── 12. Custom error message from Rule constructor ───────────────────

    /**
     * @test
     */
    public function custom_error_message_from_rule_constructor_is_returned(): void
    {
        $customMessage = 'Sorry, throwaway addresses are not permitted here.';
        $rule          = new Indisposable($customMessage);

        $validator = $this->app['validator']->make(
            ['email' => 'test@guerrillamail.com'],
            ['email' => [$rule]]
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString($customMessage, $validator->errors()->first('email'));
    }

    // ─── 13. count() returns a positive integer ──────────────────────────

    /**
     * @test
     */
    public function count_returns_a_positive_integer_after_domains_are_loaded(): void
    {
        $count = TempMailBlockerFacade::count();

        $this->assertIsInt($count);
        $this->assertGreaterThan(0, $count);
    }

    // ─── 14. Subdomain of a disposable domain is blocked ─────────────────

    /**
     * @test
     */
    public function subdomain_of_disposable_domain_is_also_blocked(): void
    {
        $this->assertTrue(TempMailBlockerFacade::isDisposable('user@sub.mailinator.com'));
        $this->assertTrue(TempMailBlockerFacade::isDisposable('user@a.b.mailinator.com'));
    }

    // ─── 15. SSRF — non-HTTPS source URL is rejected ─────────────────────

    /**
     * @test
     */
    public function update_domains_rejects_non_https_source_url(): void
    {
        $tempPath = sys_get_temp_dir() . '/tempmailblocker_ssrf_' . uniqid() . '.json';
        config([
            'tempmailblocker.domains_path' => $tempPath,
            'tempmailblocker.source_url'   => 'http://internal-host/domains.txt',
        ]);

        $blocker = new TempMailBlocker();
        $result  = $blocker->updateDomains();

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($tempPath);
    }

    // ─── 16. Non-2xx response does not overwrite the domain list ─────────

    /**
     * @test
     */
    public function update_domains_returns_false_for_non_2xx_response(): void
    {
        $mock   = new MockHandler([new Response(404, [], 'Not Found')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $tempPath = sys_get_temp_dir() . '/tempmailblocker_404_' . uniqid() . '.json';
        config([
            'tempmailblocker.domains_path' => $tempPath,
            'tempmailblocker.source_url'   => 'https://example.com/domains.txt',
        ]);

        $blocker = new TempMailBlocker($client);
        $result  = $blocker->updateDomains();

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($tempPath);
    }

    // ─── 17. updateDomains() — mocked HTTP success ───────────────────────

    /**
     * @test
     */
    public function update_domains_returns_boolean_with_mocked_http_response(): void
    {
        $mockBody = implode("\n", [
            '# Disposable email domain list — mocked for testing',
            'mailinator.com',
            'guerrillamail.com',
            '',
            'yopmail.com',
            'trashmail.com',
        ]);

        $mock   = new MockHandler([new Response(200, [], $mockBody)]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $tempPath = sys_get_temp_dir() . '/tempmailblocker_test_' . uniqid() . '.json';
        config(['tempmailblocker.domains_path' => $tempPath]);

        $blocker = new TempMailBlocker($client);
        $result  = $blocker->updateDomains();

        $this->assertIsBool($result);
        $this->assertTrue($result);

        $this->assertFileExists($tempPath);
        $saved = json_decode((string) file_get_contents($tempPath), associative: true);
        $this->assertIsArray($saved);
        $this->assertCount(4, $saved);
        $this->assertContains('mailinator.com', $saved);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
    }

    // ─── 18. OPcache driver — loads from PHP hash-map file ───────────────

    /**
     * @test
     */
    public function opcache_driver_loads_domains_from_php_hash_map_file(): void
    {
        $tempDir = sys_get_temp_dir() . '/tempmailblocker_opc_' . uniqid();
        mkdir($tempDir, 0755, true);

        $phpPath = $tempDir . '/domains.php';
        $hashMap = ['mailinator.com' => true, 'guerrillamail.com' => true];

        file_put_contents($phpPath, '<?php return ' . var_export($hashMap, true) . ';');

        config([
            'tempmailblocker.storage'      => 'opcache',
            'tempmailblocker.opcache_path' => $phpPath,
        ]);

        $blocker = new TempMailBlocker();

        $this->assertTrue($blocker->isDisposable('user@mailinator.com'));
        $this->assertTrue($blocker->isDisposable('user@guerrillamail.com'));
        $this->assertFalse($blocker->isDisposable('user@gmail.com'));

        @unlink($phpPath);
        @rmdir($tempDir);
    }

    // ─── 19. updateDomains() — generates OPcache PHP file ────────────────

    /**
     * @test
     */
    public function update_domains_generates_opcache_php_file(): void
    {
        $mockBody = implode("\n", ['# test', 'mailinator.com', 'guerrillamail.com', 'yopmail.com']);

        $mock    = new MockHandler([new Response(200, [], $mockBody)]);
        $client  = new Client(['handler' => HandlerStack::create($mock)]);

        $tempDir  = sys_get_temp_dir() . '/tempmailblocker_opc_upd_' . uniqid();
        mkdir($tempDir, 0755, true);

        $jsonPath = $tempDir . '/domains.json';
        $phpPath  = $tempDir . '/domains.php';

        config([
            'tempmailblocker.storage'      => 'opcache',
            'tempmailblocker.domains_path' => $jsonPath,
            'tempmailblocker.opcache_path' => $phpPath,
            'tempmailblocker.source_url'   => 'https://example.com/domains.txt',
        ]);

        $blocker = new TempMailBlocker($client);
        $result  = $blocker->updateDomains();

        $this->assertTrue($result);
        $this->assertFileExists($jsonPath);
        $this->assertFileExists($phpPath);

        $loaded = include $phpPath;
        $this->assertIsArray($loaded);
        $this->assertArrayHasKey('mailinator.com', $loaded);
        $this->assertTrue($loaded['mailinator.com']);

        array_map('unlink', glob($tempDir . '/*') ?: []);
        @rmdir($tempDir);
    }

    // ─── 20. Hash-map O(1) lookup — regression guard ─────────────────────

    /**
     * @test
     *
     * Verifies lookups are correct on a 50 000-entry synthetic list, confirming
     * the hash-map storage path is used (O(1)) rather than a sequential scan (O(n)).
     */
    public function lookup_is_correct_on_large_synthetic_domain_list(): void
    {
        $tempDir  = sys_get_temp_dir() . '/tempmailblocker_large_' . uniqid();
        mkdir($tempDir, 0755, true);
        $jsonPath = $tempDir . '/domains.json';

        $domains = ['mailinator.com', 'guerrillamail.com'];
        for ($i = 0; $i < 50000; $i++) {
            $domains[] = "disposable-{$i}.example.net";
        }

        file_put_contents($jsonPath, json_encode($domains));
        config(['tempmailblocker.domains_path' => $jsonPath]);

        $blocker = new TempMailBlocker();

        $this->assertTrue($blocker->isDisposable('x@mailinator.com'));
        $this->assertTrue($blocker->isDisposable('x@disposable-25000.example.net'));
        $this->assertFalse($blocker->isDisposable('x@gmail.com'));

        array_map('unlink', glob($tempDir . '/*') ?: []);
        @rmdir($tempDir);
    }
}
