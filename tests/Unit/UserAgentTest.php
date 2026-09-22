<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use Beacon\Transport\UserAgent;

/*
 * Unit tests for {@see UserAgent}.
 *
 * The shape asserted here is the one an upstream's bot protection was
 * asked for — product, version, contact, deployment — so these tests
 * are deliberately literal about the punctuation. home_url() comes
 * from the wp-mocks WordPress stub group and answers
 * https://example.test/.
 */

it('builds the documented shape', function () {
    expect(UserAgent::forApp('Tamar', '1.2.3'))
        ->toBe('Tamar/1.2.3 (rest@aa-bristol.org; https://example.test)');
});

it('leaves out the slash when the version is missing', function () {
    // Better a product with no version than "Tamar/" or an invented one.
    expect(UserAgent::forApp('Tamar'))
        ->toBe('Tamar (rest@aa-bristol.org; https://example.test)');
});

it('falls back to Beacon for an empty app name', function () {
    expect(UserAgent::forApp('', '1.0'))->toStartWith('Beacon/1.0');
});

it('strips header-breaking characters', function () {
    // A newline here would be header injection; a bracket or
    // semicolon would close the comment early and leave the
    // contact details dangling outside it.
    expect(UserAgent::forApp("Tamar", "1.2.3\r\n(evil);"))
        ->toBe('Tamar/1.2.3 evil (rest@aa-bristol.org; https://example.test)');
});

it('uses the role address as the contact', function () {
    expect(UserAgent::CONTACT)->toBe('rest@aa-bristol.org');
});
