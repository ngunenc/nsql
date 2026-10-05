<?php

namespace nsql\security;

use nsql\database\Config;

/**
 * İstemci IP'sini ve HTTPS durumunu çözer.
 *
 * X-Forwarded-* / X-Real-IP / CF-Connecting-IP başlıkları yalnızca isteği doğrudan
 * gönderen adres (REMOTE_ADDR) TRUSTED_PROXIES listesindeyse dikkate alınır.
 */
final class IpResolver
{
    /** @var array<string> */
    private array $trusted_proxies;
    private bool $trust_immediate_peer;
    /** @var array<string, mixed> */
    private array $server;

    /**
     * @param array<string> $trusted_proxies IP veya CIDR (IPv4/IPv6). '*' isteği gönderen eşe güvenir.
     * @param array<string, mixed>|null $server null ise $_SERVER
     */
    public function __construct(array $trusted_proxies = [], ?array $server = null)
    {
        $this->trust_immediate_peer = in_array('*', $trusted_proxies, true);
        $this->trusted_proxies = array_values(array_filter(
            array_map('trim', $trusted_proxies),
            static fn (string $entry) => $entry !== '' && $entry !== '*'
        ));
        $this->server = $server ?? $_SERVER;
    }

    /**
     * TRUSTED_PROXIES config'inden (virgülle ayrılmış metin veya dizi) oluşturur.
     *
     * @param array<string, mixed>|null $server
     */
    public static function from_config(?array $server = null): self
    {
        $value = Config::get('TRUSTED_PROXIES', '');
        $list = is_array($value) ? $value : explode(',', (string) $value);

        return new self(array_map('strval', $list), $server);
    }

    public function client_ip(): string
    {
        $remote = $this->normalize((string) ($this->server['REMOTE_ADDR'] ?? ''));
        if ($remote === null) {
            return 'unknown';
        }

        if (! $this->trust_immediate_peer && ! $this->is_trusted_proxy($remote)) {
            return $remote;
        }

        $forwarded_for = trim((string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded_for !== '') {
            return $this->resolve_forwarded_chain(explode(',', $forwarded_for), $remote);
        }

        foreach (['HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $header) {
            $ip = $this->normalize((string) ($this->server[$header] ?? ''));
            if ($ip !== null) {
                return $ip;
            }
        }

        return $remote;
    }

    public function is_https(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        if ($this->request_from_trusted_proxy()) {
            $proto = strtolower(trim(explode(',', (string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            if ($proto !== '') {
                return $proto === 'https';
            }

            if (strtolower((string) ($this->server['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
                return true;
            }
        }

        return (int) ($this->server['SERVER_PORT'] ?? 0) === 443;
    }

    public function is_trusted_proxy(string $ip): bool
    {
        foreach ($this->trusted_proxies as $range) {
            if (self::ip_in_range($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * IP'nin tek bir adrese veya CIDR bloğuna uyup uymadığını kontrol eder.
     */
    public static function ip_in_range(string $ip, string $range): bool
    {
        $ip_bin = @inet_pton($ip);
        if ($ip_bin === false) {
            return false;
        }

        if (! str_contains($range, '/')) {
            $range_bin = @inet_pton($range);

            return $range_bin !== false && $range_bin === $ip_bin;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $subnet_bin = @inet_pton($subnet);
        if ($subnet_bin === false || strlen($subnet_bin) !== strlen($ip_bin) || ! ctype_digit($bits)) {
            return false;
        }

        $bits = (int) $bits;
        $max_bits = strlen($ip_bin) * 8;
        if ($bits > $max_bits) {
            return false;
        }

        $full_bytes = intdiv($bits, 8);
        if (substr($ip_bin, 0, $full_bytes) !== substr($subnet_bin, 0, $full_bytes)) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($ip_bin[$full_bytes]) & $mask) === (ord($subnet_bin[$full_bytes]) & $mask);
    }

    public static function is_valid_ip(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function request_from_trusted_proxy(): bool
    {
        $remote = $this->normalize((string) ($this->server['REMOTE_ADDR'] ?? ''));

        return $remote !== null && ($this->trust_immediate_peer || $this->is_trusted_proxy($remote));
    }

    /**
     * Zinciri sağdan sola yürür; güvenilir proxy olmayan ilk adres istemcidir.
     *
     * @param array<string> $chain
     */
    private function resolve_forwarded_chain(array $chain, string $remote): string
    {
        $client = $remote;
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $ip = $this->normalize($chain[$i]);
            if ($ip === null) {
                break;
            }

            $client = $ip;
            if (! $this->is_trusted_proxy($ip)) {
                break;
            }
        }

        return $client;
    }

    /**
     * "[2001:db8::1]:443" ve "203.0.113.5:8080" biçimlerini sadeleştirir; geçersizse null.
     */
    private function normalize(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $value, $m)) {
            $value = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $value, $m)) {
            $value = $m[1];
        }

        return self::is_valid_ip($value) ? $value : null;
    }
}
