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

    // ─── 5. Whitelist — domain allowed even if in blocklist ───────────────

    /**
     * @test
     */
    public function whitelisted_domain_is_allowed_even_if_in_blocklist(): void
    {
        // mailinator.com is in the seed domains.json, so without whitelist it would block.
        config(['tempmailblocker.whitelist' => ['mailinator.com']]);

        $isDisposable = TempMailBlockerFacade::isDisposable('test@mailinator.com');

        $this->assertFalse($isDisposable);
    }

    // ─── 6. Blacklist — domain blocked even if not in blocklist ──────────

    /**
     * @test
     */
    public function blacklisted_domain_is_blocked_even_if_not_in_blocklist(): void
    {
        // legitimate-business.com is not in the seed list but explicitly blacklisted.
        config(['tempmailblocker.blacklist' => ['legitimate-business.com']]);

        $isDisposable = TempMailBlockerFacade::isDisposable('hr@legitimate-business.com');

        $this->assertTrue($isDisposable);
    }

    // ─── 7. Invalid format — silently deferred to 'email' rule ───────────

    /**
     * @test
     */
    public function invalid_email_format_is_not_blocked_by_indisposable_rule(): void
    {
        $validator = $this->app['validator']->make(
            ['email' => 'not-an-email-at-all'],
            ['email' => [new Indisposable()]]
        );

        // The Indisposable rule passes silently; only 'email' rule would fail.
        // Since we are only applying 'indisposable' here, no errors are expected.
        $this->assertFalse($validator->fails());
    }

    // ─── 8. Facade — isDisposable() true for known domain ────────────────

    /**
     * @test
     */
    public function facade_is_disposable_returns_true_for_known_disposable_domain(): void
    {
        $this->assertTrue(TempMailBlockerFacade::isDisposable('anyone@mailinator.com'));
    }

    // ─── 9. Facade — isIndisposable() true for clean domain ──────────────

    /**
     * @test
     */
    public function facade_is_indisposable_returns_true_for_clean_domain(): void
    {
        $this->assertTrue(TempMailBlockerFacade::isIndisposable('user@github.com'));
    }

    // ─── 10. Custom message returned from Rule constructor ────────────────

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

    // ─── 11. count() returns a positive integer ───────────────────────────

    /**
     * @test
     */
    public function count_returns_a_positive_integer_after_domains_are_loaded(): void
    {
        $count = TempMailBlockerFacade::count();

        $this->assertIsInt($count);
        $this->assertGreaterThan(0, $count);
    }

    // ─── 12. updateDomains() returns a boolean (mocked HTTP) ─────────────

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

        $mock    = new MockHandler([new Response(200, [], $mockBody)]);
        $client  = new Client(['handler' => HandlerStack::create($mock)]);

        // Write to a temp path so the test never pollutes the real storage dir.
        $tempPath = sys_get_temp_dir() . '/tempmailblocker_test_' . uniqid() . '.json';
        config(['tempmailblocker.domains_path' => $tempPath]);

        $blocker = new TempMailBlocker($client);
        $result  = $blocker->updateDomains();

        $this->assertIsBool($result);
        $this->assertTrue($result);

        // Verify the persisted JSON is valid and contains exactly the four domains.
        $this->assertFileExists($tempPath);
        $saved = json_decode((string) file_get_contents($tempPath), associative: true);
        $this->assertIsArray($saved);
        $this->assertCount(4, $saved);
        $this->assertContains('mailinator.com', $saved);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
    }
}
