<?php

namespace Tests\Unit;

use nsql\database\config;
use nsql\security\ip_resolver;
use nsql\security\security_manager;
use PHPUnit\Framework\TestCase;

class IpResolverTest extends TestCase
{
    private array $server_backup;

    protected function setUp(): void
    {
        $this->server_backup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server_backup;
        config::set('TRUSTED_PROXIES', '');
    }

    public function test_untrusted_peer_cannot_spoof_forwarded_headers(): void
    {
        $resolver = new ip_resolver([], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_X_REAL_IP' => '1.2.3.4',
            'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
        ]);

        $this->assertSame('203.0.113.10', $resolver->client_ip());
    }

    public function test_trusted_proxy_forwarded_for_is_used(): void
    {
        $resolver = new ip_resolver(['10.0.0.0/8'], [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ]);

        $this->assertSame('198.51.100.7', $resolver->client_ip());
    }

    public function test_chain_is_walked_from_right_and_spoofed_left_entries_ignored(): void
    {
        $resolver = new ip_resolver(['10.0.0.0/8', '192.168.1.1'], [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, 192.168.1.1',
        ]);

        $this->assertSame('198.51.100.7', $resolver->client_ip());
    }

    public function test_all_trusted_chain_returns_leftmost(): void
    {
        $resolver = new ip_resolver(['10.0.0.0/8'], [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '10.1.1.1, 10.2.2.2',
        ]);

        $this->assertSame('10.1.1.1', $resolver->client_ip());
    }

    public function test_invalid_chain_entry_stops_walk(): void
    {
        $resolver = new ip_resolver(['10.0.0.0/8'], [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7, not-an-ip',
        ]);

        $this->assertSame('10.0.0.5', $resolver->client_ip());
    }

    public function test_real_ip_and_cloudflare_headers_from_trusted_proxy(): void
    {
        $this->assertSame('198.51.100.8', (new ip_resolver(['10.0.0.5'], [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_REAL_IP' => '198.51.100.8',
        ]))->client_ip());

        $this->assertSame('198.51.100.9', (new ip_resolver(['173.245.48.0/20'], [
            'REMOTE_ADDR' => '173.245.48.1',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.9',
        ]))->client_ip());
    }

    public function test_ipv6_ranges_and_bracketed_ports(): void
    {
        $resolver = new ip_resolver(['2001:db8::/32'], [
            'REMOTE_ADDR' => '2001:db8::1',
            'HTTP_X_FORWARDED_FOR' => '[2001:4860:4860::8888]:443',
        ]);

        $this->assertSame('2001:4860:4860::8888', $resolver->client_ip());
    }

    public function test_ipv4_with_port_in_chain(): void
    {
        $resolver = new ip_resolver(['10.0.0.5'], [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7:51234',
        ]);

        $this->assertSame('198.51.100.7', $resolver->client_ip());
    }

    public function test_wildcard_trusts_only_immediate_peer(): void
    {
        $resolver = new ip_resolver(['*'], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7',
        ]);

        $this->assertSame('198.51.100.7', $resolver->client_ip());
    }

    public function test_missing_or_invalid_remote_addr_is_unknown(): void
    {
        $this->assertSame('unknown', (new ip_resolver([], []))->client_ip());
        $this->assertSame('unknown', (new ip_resolver([], ['REMOTE_ADDR' => 'garbage']))->client_ip());
    }

    /**
     * @dataProvider range_provider
     */
    public function test_ip_in_range(string $ip, string $range, bool $expected): void
    {
        $this->assertSame($expected, ip_resolver::ip_in_range($ip, $range));
    }

    public static function range_provider(): array
    {
        return [
            ['10.1.2.3', '10.0.0.0/8', true],
            ['11.0.0.1', '10.0.0.0/8', false],
            ['172.31.255.255', '172.16.0.0/12', true],
            ['172.32.0.0', '172.16.0.0/12', false],
            ['192.168.1.1', '192.168.1.1', true],
            ['192.168.1.2', '192.168.1.1/32', false],
            ['1.2.3.4', '0.0.0.0/0', true],
            ['2001:db8::abcd', '2001:db8::/32', true],
            ['2001:db9::1', '2001:db8::/32', false],
            ['::1', '::1', true],
            ['10.0.0.1', '2001:db8::/32', false],
            ['10.0.0.1', '10.0.0.0/33', false],
            ['10.0.0.1', '10.0.0.0/abc', false],
            ['not-ip', '10.0.0.0/8', false],
        ];
    }

    public function test_forwarded_proto_requires_trusted_proxy(): void
    {
        $headers = ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->assertFalse((new ip_resolver([], $headers))->is_https());
        $this->assertTrue((new ip_resolver(['203.0.113.10'], $headers))->is_https());

        $ssl = ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_SSL' => 'on'];
        $this->assertFalse((new ip_resolver([], $ssl))->is_https());
        $this->assertTrue((new ip_resolver(['203.0.113.0/24'], $ssl))->is_https());
    }

    public function test_trusted_proxy_can_report_plain_http(): void
    {
        $resolver = new ip_resolver(['10.0.0.5'], [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'SERVER_PORT' => '443',
        ]);

        $this->assertFalse($resolver->is_https());
    }

    public function test_direct_https_detection(): void
    {
        $this->assertTrue((new ip_resolver([], ['HTTPS' => 'on']))->is_https());
        $this->assertFalse((new ip_resolver([], ['HTTPS' => 'off']))->is_https());
        $this->assertTrue((new ip_resolver([], ['SERVER_PORT' => '443']))->is_https());
        $this->assertFalse((new ip_resolver([], ['SERVER_PORT' => '80']))->is_https());
    }

    public function test_security_manager_uses_trusted_proxies_config(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
        unset($_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CF_CONNECTING_IP']);

        config::set('TRUSTED_PROXIES', '');
        $this->assertSame('10.0.0.5', security_manager::get_client_ip());

        config::set('TRUSTED_PROXIES', '127.0.0.1, 10.0.0.0/8');
        $this->assertSame('198.51.100.7', security_manager::get_client_ip());
    }
}
