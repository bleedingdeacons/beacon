<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use Beacon\Transport\UserAgent;
use BleedingDeacons\WpMocks\TestCase;

/**
 * Unit tests for {@see UserAgent}.
 *
 * The shape asserted here is the one an upstream's bot protection was
 * asked for — product, version, contact, deployment — so these tests
 * are deliberately literal about the punctuation. home_url() comes
 * from the wp-mocks WordPress stub group and answers
 * https://example.test/.
 */
final class UserAgentTest extends TestCase
{
    public function test_it_builds_the_documented_shape(): void
    {
        self::assertSame(
            'Tamar/1.2.3 (rest@aa-bristol.org; https://example.test)',
            UserAgent::forApp('Tamar', '1.2.3'),
        );
    }

    public function test_a_missing_version_leaves_out_the_slash(): void
    {
        // Better a product with no version than "Tamar/" or an invented one.
        self::assertSame(
            'Tamar (rest@aa-bristol.org; https://example.test)',
            UserAgent::forApp('Tamar'),
        );
    }

    public function test_an_empty_app_name_falls_back_to_beacon(): void
    {
        self::assertStringStartsWith('Beacon/1.0', UserAgent::forApp('', '1.0'));
    }

    public function test_header_breaking_characters_are_stripped(): void
    {
        // A newline here would be header injection; a bracket or
        // semicolon would close the comment early and leave the
        // contact details dangling outside it.
        self::assertSame(
            'Tamar/1.2.3 evil (rest@aa-bristol.org; https://example.test)',
            UserAgent::forApp("Tamar", "1.2.3\r\n(evil);"),
        );
    }

    public function test_the_contact_is_the_role_address(): void
    {
        self::assertSame('rest@aa-bristol.org', UserAgent::CONTACT);
    }
}
