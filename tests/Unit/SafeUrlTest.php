<?php

namespace Tests\Unit;

use App\Support\SafeUrl;
use PHPUnit\Framework\TestCase;

/**
 * The SSRF guard — security audit 2026-10-26, finding 4. IP literals only, so the test does no DNS.
 */
class SafeUrlTest extends TestCase
{
    public function test_a_public_address_is_routable(): void
    {
        $this->assertTrue(SafeUrl::isPubliclyRoutable('https://93.184.216.34/'));
        $this->assertTrue(SafeUrl::isPubliclyRoutable('http://8.8.8.8:8080/path'));
    }

    public function test_loopback_private_and_link_local_are_refused(): void
    {
        foreach ([
            'http://127.0.0.1/',
            'http://127.0.0.1:6379/',
            'http://10.0.0.5/',
            'http://192.168.1.1/',
            'http://172.16.0.1/',
            'http://169.254.169.254/latest/meta-data/',
            'http://100.64.0.1/',
            'http://[::1]/',
        ] as $url) {
            $this->assertFalse(SafeUrl::isPubliclyRoutable($url), $url.' should be refused');
        }
    }

    public function test_a_non_http_scheme_is_refused(): void
    {
        $this->assertFalse(SafeUrl::isPubliclyRoutable('file:///etc/passwd'));
        $this->assertFalse(SafeUrl::isPubliclyRoutable('gopher://127.0.0.1/'));
        $this->assertFalse(SafeUrl::isPubliclyRoutable('ftp://8.8.8.8/'));
        $this->assertFalse(SafeUrl::isPubliclyRoutable(''));
        $this->assertFalse(SafeUrl::isPubliclyRoutable(null));
    }
}
