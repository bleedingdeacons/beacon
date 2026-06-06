<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use Beacon\Transport\Interfaces\HttpTransport;
use Beacon\Transport\Interfaces\TransportException;
use PHPUnit\Framework\TestCase;
use Beacon\Tests\Support\FakeWpHttp;
use Beacon\Transport\WpHttpTransport;

/**
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
final class WpHttpTransportTest extends TestCase
{
    protected function setUp(): void
    {
        FakeWpHttp::reset();
    }

    public function test_it_implements_the_http_transport_contract(): void
    {
        self::assertInstanceOf(HttpTransport::class, new WpHttpTransport());
    }

    public function test_it_returns_status_body_and_lowercased_headers(): void
    {
        FakeWpHttp::pushResponse(
            200,
            '<html>ok</html>',
            ['Content-Type' => 'text/html; charset=utf-8', 'X-Upstream' => 'pbx'],
        );

        $result = (new WpHttpTransport())->request('GET', 'https://pbx.example.com/huntgroup');

        self::assertSame(200, $result['status']);
        self::assertSame('<html>ok</html>', $result['body']);
        // Keys lower-cased per the HttpTransport contract.
        self::assertArrayHasKey('content-type', $result['headers']);
        self::assertArrayHasKey('x-upstream', $result['headers']);
        self::assertArrayNotHasKey('Content-Type', $result['headers']);
        self::assertSame('text/html; charset=utf-8', $result['headers']['content-type']);
    }

    public function test_multivalue_response_headers_are_joined_into_a_string(): void
    {
        FakeWpHttp::pushResponse(
            200,
            '',
            ['set-cookie' => ['a=1', 'b=2']],
        );

        $result = (new WpHttpTransport())->request('GET', 'https://pbx.example.com/');

        self::assertSame('a=1, b=2', $result['headers']['set-cookie']);
    }

    public function test_it_reads_a_getAll_style_header_dictionary(): void
    {
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

        self::assertSame('text/html', $result['headers']['content-type']);
    }

    public function test_4xx_and_5xx_are_returned_not_thrown(): void
    {
        FakeWpHttp::pushResponse(404, 'not found');
        FakeWpHttp::pushResponse(503, 'unavailable');

        $transport = new WpHttpTransport();

        $notFound = $transport->request('GET', 'https://pbx.example.com/missing');
        self::assertSame(404, $notFound['status']);
        self::assertSame('not found', $notFound['body']);

        $down = $transport->request('GET', 'https://pbx.example.com/down');
        self::assertSame(503, $down['status']);
    }

    public function test_a_network_failure_throws_transport_exception(): void
    {
        FakeWpHttp::push(new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('timed out');

        (new WpHttpTransport())->request('GET', 'https://pbx.example.com/huntgroup');
    }

    public function test_it_captures_a_session_cookie_and_replays_it_on_the_next_request(): void
    {
        // Login sets the cookie…
        FakeWpHttp::pushResponse(302, '', ['location' => '/dashboard'], [
            new \WP_Http_Cookie(['name' => 'PHPSESSID', 'value' => 'abc123']),
        ]);
        // …the follow-up GET carries no new cookie.
        FakeWpHttp::pushResponse(200, '<form>edit</form>');

        $transport = new WpHttpTransport();

        $login = $transport->request('POST', 'https://pbx.example.com/login/', [], 'user=u&pass=p');
        self::assertSame(302, $login['status']);
        self::assertSame('abc123', $transport->cookies()['PHPSESSID']);

        $transport->request('GET', 'https://pbx.example.com/huntgroup?huntgroup=1');

        // The cookie must have been replayed on the second outbound call.
        $cookiesSent = FakeWpHttp::sentArgs(1)['cookies'];
        $names = array_map(static fn (\WP_Http_Cookie $c) => $c->name, $cookiesSent);
        self::assertContains('PHPSESSID', $names);
        $session = array_values(array_filter(
            $cookiesSent,
            static fn (\WP_Http_Cookie $c) => $c->name === 'PHPSESSID'
        ));
        self::assertSame('abc123', $session[0]->value);
    }

    public function test_the_first_request_sends_an_empty_cookie_jar(): void
    {
        FakeWpHttp::pushResponse(200, '');

        (new WpHttpTransport())->request('GET', 'https://pbx.example.com/');

        self::assertSame([], FakeWpHttp::sentArgs(0)['cookies']);
    }

    public function test_a_later_set_cookie_of_the_same_name_overrides_the_earlier_one(): void
    {
        FakeWpHttp::pushResponse(200, '', [], [new \WP_Http_Cookie(['name' => 'SID', 'value' => 'first'])]);
        FakeWpHttp::pushResponse(200, '', [], [new \WP_Http_Cookie(['name' => 'SID', 'value' => 'second'])]);
        FakeWpHttp::pushResponse(200, '');

        $transport = new WpHttpTransport();
        $transport->request('GET', 'https://pbx.example.com/a'); // gets SID=first
        $transport->request('GET', 'https://pbx.example.com/b'); // sends first, gets SID=second
        $transport->request('GET', 'https://pbx.example.com/c'); // sends second

        self::assertSame(['SID' => 'second'], $transport->cookies());

        $secondCall = FakeWpHttp::sentArgs(1)['cookies'];
        self::assertSame('first', $secondCall[0]->value);

        $thirdCall = FakeWpHttp::sentArgs(2)['cookies'];
        self::assertSame('second', $thirdCall[0]->value);
    }

    public function test_a_cookie_with_an_empty_name_is_ignored(): void
    {
        FakeWpHttp::pushResponse(200, '', [], [
            new \WP_Http_Cookie(['name' => '', 'value' => 'junk']),
            new \WP_Http_Cookie(['name' => 'SID', 'value' => 'ok']),
        ]);

        $transport = new WpHttpTransport();
        $transport->request('GET', 'https://pbx.example.com/');

        self::assertSame(['SID' => 'ok'], $transport->cookies());
    }

    public function test_it_attaches_a_body_on_post_but_omits_it_on_an_empty_get(): void
    {
        FakeWpHttp::pushResponse(200, '');
        FakeWpHttp::pushResponse(200, '');

        $transport = new WpHttpTransport();
        $transport->request('POST', 'https://pbx.example.com/update', [], 'a=1&b=2');
        $transport->request('GET', 'https://pbx.example.com/page');

        self::assertSame('a=1&b=2', FakeWpHttp::sentArgs(0)['body']);
        self::assertArrayNotHasKey('body', FakeWpHttp::sentArgs(1));
    }

    public function test_caller_headers_take_precedence_over_defaults(): void
    {
        FakeWpHttp::pushResponse(200, '');

        (new WpHttpTransport())->request(
            'POST',
            'https://pbx.example.com/update',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'a=1',
        );

        $headers = FakeWpHttp::sentArgs(0)['headers'];
        self::assertSame('application/x-www-form-urlencoded', $headers['Content-Type']);
        // Default Accept is still supplied.
        self::assertArrayHasKey('Accept', $headers);
    }

    public function test_method_is_upper_cased(): void
    {
        FakeWpHttp::pushResponse(200, '');

        (new WpHttpTransport())->request('post', 'https://pbx.example.com/update', [], 'a=1');

        self::assertSame('POST', FakeWpHttp::sentArgs(0)['method']);
    }

    public function test_tls_verification_and_timeout_are_passed_through(): void
    {
        FakeWpHttp::pushResponse(200, '');

        (new WpHttpTransport(verifyTls: false, timeoutSeconds: 42))
            ->request('GET', 'https://pbx.example.com/');

        $args = FakeWpHttp::sentArgs(0);
        self::assertFalse($args['sslverify']);
        self::assertSame(42, $args['timeout']);
    }

    public function test_redirects_are_followed_by_default_and_configurable(): void
    {
        FakeWpHttp::pushResponse(200, '');
        FakeWpHttp::pushResponse(200, '');

        (new WpHttpTransport())->request('GET', 'https://pbx.example.com/');
        self::assertSame(5, FakeWpHttp::sentArgs(0)['redirection']);

        (new WpHttpTransport(maxRedirects: 0))->request('GET', 'https://pbx.example.com/');
        self::assertSame(0, FakeWpHttp::sentArgs(1)['redirection']);
    }
}
