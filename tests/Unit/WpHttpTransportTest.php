<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use Beacon\Transport\Interfaces\HttpTransport;
use Beacon\Transport\Interfaces\TransportException;
use BleedingDeacons\WpMocks\Doubles\FakeWpHttp;
use Beacon\Transport\WpHttpTransport;

/*
 * Unit tests for {@see WpHttpTransport}.
 *
 * These drive the WP HTTP API shims (see tests/bootstrap.php) via
 * {@see FakeWpHttp}, asserting on both what the transport *returns* and
 * what it *sends* — cookie continuity, header merging and request
 * shaping are only observable on the outbound side.
 *
 * Scope note: this exercises the transport's own logic against a
 * scripted backend. It does not prove behaviour against a live
 * WordPress or the real upstream panel — the redirect-following and
 * Set-Cookie parsing depend on WP's actual Requests library, which a
 * future integration test (wp_mock / a WP test harness) should cover.
 */

beforeEach(function () {
    FakeWpHttp::reset();
});

it('implements the http transport contract', function () {
    expect(new WpHttpTransport())->toBeInstanceOf(HttpTransport::class);
});

it('returns status, body and lower-cased headers', function () {
    FakeWpHttp::pushResponse(
        200,
        '<html>ok</html>',
        ['Content-Type' => 'text/html; charset=utf-8', 'X-Upstream' => 'pbx'],
    );

    $result = (new WpHttpTransport())->request('GET', 'https://pbx.example.com/huntgroup');

    expect($result['status'])->toBe(200)
        ->and($result['body'])->toBe('<html>ok</html>');
    // Keys lower-cased per the HttpTransport contract.
    expect($result['headers'])->toHaveKey('content-type')
        ->toHaveKey('x-upstream')
        ->not->toHaveKey('Content-Type')
        ->and($result['headers']['content-type'])->toBe('text/html; charset=utf-8');
});

it('joins multi-value response headers into a string', function () {
    FakeWpHttp::pushResponse(
        200,
        '',
        ['set-cookie' => ['a=1', 'b=2']],
    );

    $result = (new WpHttpTransport())->request('GET', 'https://pbx.example.com/');

    expect($result['headers']['set-cookie'])->toBe('a=1, b=2');
});

it('reads a getAll-style header dictionary', function () {
    // WP hands back a case-insensitive dictionary object on success;
    // the transport must read it via getAll().
    $dict = new class {
        /** @return array<string,string> */
        public function getAll(): array
        {
            return ['Content-Type' => 'text/html'];
        }
    };
    FakeWpHttp::pushResponse(200, 'body', $dict);

    $result = (new WpHttpTransport())->request('GET', 'https://pbx.example.com/');

    expect($result['headers']['content-type'])->toBe('text/html');
});

it('returns 4xx and 5xx responses rather than throwing', function () {
    FakeWpHttp::pushResponse(404, 'not found');
    FakeWpHttp::pushResponse(503, 'unavailable');

    $transport = new WpHttpTransport();

    $notFound = $transport->request('GET', 'https://pbx.example.com/missing');
    expect($notFound['status'])->toBe(404)
        ->and($notFound['body'])->toBe('not found');

    $down = $transport->request('GET', 'https://pbx.example.com/down');
    expect($down['status'])->toBe(503);
});

it('throws a transport exception on a network failure', function () {
    FakeWpHttp::push(new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'));

    (new WpHttpTransport())->request('GET', 'https://pbx.example.com/huntgroup');
})->throws(TransportException::class, 'timed out');

it('captures a session cookie and replays it on the next request', function () {
    // Login sets the cookie…
    FakeWpHttp::pushResponse(302, '', ['location' => '/dashboard'], [
        new \WP_Http_Cookie(['name' => 'PHPSESSID', 'value' => 'abc123']),
    ]);
    // …the follow-up GET carries no new cookie.
    FakeWpHttp::pushResponse(200, '<form>edit</form>');

    $transport = new WpHttpTransport();

    $login = $transport->request('POST', 'https://pbx.example.com/login/', [], 'user=u&pass=p');
    expect($login['status'])->toBe(302)
        ->and($transport->cookies()['PHPSESSID'])->toBe('abc123');

    $transport->request('GET', 'https://pbx.example.com/huntgroup?huntgroup=1');

    // The cookie must have been replayed on the second outbound call.
    $cookiesSent = FakeWpHttp::sentArgs(1)['cookies'];
    $names = array_map(static fn (\WP_Http_Cookie $c) => $c->name, $cookiesSent);
    expect($names)->toContain('PHPSESSID');
    $session = array_values(array_filter(
        $cookiesSent,
        static fn (\WP_Http_Cookie $c) => $c->name === 'PHPSESSID'
    ));
    expect($session[0]->value)->toBe('abc123');
});

it('sends an empty cookie jar on the first request', function () {
    FakeWpHttp::pushResponse(200, '');

    (new WpHttpTransport())->request('GET', 'https://pbx.example.com/');

    expect(FakeWpHttp::sentArgs(0)['cookies'])->toBe([]);
});

it('lets a later set-cookie of the same name override the earlier one', function () {
    FakeWpHttp::pushResponse(200, '', [], [new \WP_Http_Cookie(['name' => 'SID', 'value' => 'first'])]);
    FakeWpHttp::pushResponse(200, '', [], [new \WP_Http_Cookie(['name' => 'SID', 'value' => 'second'])]);
    FakeWpHttp::pushResponse(200, '');

    $transport = new WpHttpTransport();
    $transport->request('GET', 'https://pbx.example.com/a'); // gets SID=first
    $transport->request('GET', 'https://pbx.example.com/b'); // sends first, gets SID=second
    $transport->request('GET', 'https://pbx.example.com/c'); // sends second

    expect($transport->cookies())->toBe(['SID' => 'second']);

    $secondCall = FakeWpHttp::sentArgs(1)['cookies'];
    expect($secondCall[0]->value)->toBe('first');

    $thirdCall = FakeWpHttp::sentArgs(2)['cookies'];
    expect($thirdCall[0]->value)->toBe('second');
});

it('ignores a cookie with an empty name', function () {
    FakeWpHttp::pushResponse(200, '', [], [
        new \WP_Http_Cookie(['name' => '', 'value' => 'junk']),
        new \WP_Http_Cookie(['name' => 'SID', 'value' => 'ok']),
    ]);

    $transport = new WpHttpTransport();
    $transport->request('GET', 'https://pbx.example.com/');

    expect($transport->cookies())->toBe(['SID' => 'ok']);
});

it('attaches a body on POST but omits it on an empty GET', function () {
    FakeWpHttp::pushResponse(200, '');
    FakeWpHttp::pushResponse(200, '');

    $transport = new WpHttpTransport();
    $transport->request('POST', 'https://pbx.example.com/update', [], 'a=1&b=2');
    $transport->request('GET', 'https://pbx.example.com/page');

    expect(FakeWpHttp::sentArgs(0)['body'])->toBe('a=1&b=2')
        ->and(FakeWpHttp::sentArgs(1))->not->toHaveKey('body');
});

it('gives caller headers precedence over the defaults', function () {
    FakeWpHttp::pushResponse(200, '');

    (new WpHttpTransport())->request(
        'POST',
        'https://pbx.example.com/update',
        ['Content-Type' => 'application/x-www-form-urlencoded'],
        'a=1',
    );

    $headers = FakeWpHttp::sentArgs(0)['headers'];
    expect($headers['Content-Type'])->toBe('application/x-www-form-urlencoded');
    // Default Accept is still supplied.
    expect($headers)->toHaveKey('Accept');
});

it('introduces itself as Beacon by default', function () {
    FakeWpHttp::pushResponse(200, '');

    (new WpHttpTransport())->request('GET', 'https://pbx.example.com/');

    // The version is whatever Composer installed, so only its presence is pinned.
    expect(FakeWpHttp::sentArgs(0)['user-agent'])
        ->toMatch('#^Beacon/\S+ \(rest@aa-bristol\.org; https://example\.test\)$#');
});

it('lets a driver user agent override the Beacon default', function () {
    // Tamar owns the conversation with the panel, so the panel
    // should see Tamar rather than the framework underneath it.
    FakeWpHttp::pushResponse(200, '');

    (new WpHttpTransport(userAgent: 'Tamar/1.2.3 (rest@aa-bristol.org; https://example.test)'))
        ->request('GET', 'https://pbx.example.com/');

    expect(FakeWpHttp::sentArgs(0)['user-agent'])
        ->toBe('Tamar/1.2.3 (rest@aa-bristol.org; https://example.test)');
});

it('upper-cases the method', function () {
    FakeWpHttp::pushResponse(200, '');

    (new WpHttpTransport())->request('post', 'https://pbx.example.com/update', [], 'a=1');

    expect(FakeWpHttp::sentArgs(0)['method'])->toBe('POST');
});

it('passes TLS verification and timeout through', function () {
    FakeWpHttp::pushResponse(200, '');

    (new WpHttpTransport(verifyTls: false, timeoutSeconds: 42))
        ->request('GET', 'https://pbx.example.com/');

    $args = FakeWpHttp::sentArgs(0);
    expect($args['sslverify'])->toBeFalse()
        ->and($args['timeout'])->toBe(42);
});

it('follows redirects by default and makes that configurable', function () {
    FakeWpHttp::pushResponse(200, '');
    FakeWpHttp::pushResponse(200, '');

    (new WpHttpTransport())->request('GET', 'https://pbx.example.com/');
    expect(FakeWpHttp::sentArgs(0)['redirection'])->toBe(5);

    (new WpHttpTransport(maxRedirects: 0))->request('GET', 'https://pbx.example.com/');
    expect(FakeWpHttp::sentArgs(1)['redirection'])->toBe(0);
});
