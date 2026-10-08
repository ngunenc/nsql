<?php

namespace nsql\security;

use nsql\database\Config;

/**
 * Redis destekli token bucket (#111). Algoritma RateLimiter (veritabanı) ile aynıdır:
 *
 * - Kova kapasitesi RATE_LIMIT_MAX_REQUESTS; kova RATE_LIMIT_WINDOW saniyede tamamen dolar.
 * - RATE_LIMIT_BURST aynı saniye içinde izin verilen en fazla istek sayısıdır.
 *
 * Her kontrol tek bir Lua betiğiyle atomik çalışır (kilit, transaction veya ek gidiş-dönüş yok).
 * Saat enjekte edilmezse Redis sunucusunun saati (TIME) kullanılır; uygulama sunucuları arasındaki
 * saat farkı sonucu etkilemez. Kayıtlar pencerenin iki katı TTL ile kendiliğinden silinir.
 *
 * Redis'e ulaşılamazsa RuntimeException fırlatılır; isteği reddetmek (fail-closed) veya geçirmek
 * (fail-open) çağıranın kararıdır.
 */
class RedisRateLimiter implements RateLimiterInterface
{
    /** Eski şemalardaki FLOAT kolonun yuvarlama hatası (RateLimiter ile aynı tolerans) */
    private const TOKEN_EPSILON = '0.000001';

    private const SCRIPT = <<<'LUA'
local capacity = tonumber(ARGV[1])
local rate = tonumber(ARGV[2])
local burst_limit = tonumber(ARGV[3])
local now = tonumber(ARGV[4])
local ttl = tonumber(ARGV[5])
local epsilon = tonumber(ARGV[6])
if now == nil then
    now = tonumber(redis.call('TIME')[1])
end

local data = redis.call('HMGET', KEYS[1], 'tokens', 'last_update', 'burst_count', 'burst_start')
local tokens = tonumber(data[1])
local last = tonumber(data[2])
local burst_count = tonumber(data[3]) or 0
local burst_start = tonumber(data[4]) or 0
if tokens == nil or last == nil then
    tokens = capacity
    last = now
end

tokens = math.min(capacity, tokens + math.max(0, now - last) * rate)
if burst_start ~= now then
    burst_count = 0
end

local allowed = 0
if tokens >= 1 - epsilon and burst_count < burst_limit then
    tokens = math.max(0, tokens - 1)
    burst_count = burst_count + 1
    allowed = 1
end

redis.call('HSET', KEYS[1], 'tokens', tostring(tokens), 'last_update', now, 'burst_count', burst_count, 'burst_start', now)
redis.call('EXPIRE', KEYS[1], ttl)

return allowed
LUA;

    /** @var \Redis */
    private object $redis;
    private string $prefix;
    private int $capacity;
    private int $window;
    private int $burst_limit;
    /** @var (callable(): int)|null */
    private $clock;

    /**
     * @param \Redis|null $client Bağlı phpredis istemcisi; null ise REDIS_* ayarlarıyla bağlanılır
     * @param callable(): int|null $clock Unix zamanı (saniye); null ise Redis sunucu saati
     * @param array{prefix?: string, max_requests?: int, window?: int, burst?: int} $options Config değerlerini ezer
     */
    public function __construct(?object $client = null, ?callable $clock = null, array $options = [])
    {
        $this->redis = $client ?? self::connect();
        $this->clock = $clock;

        $this->prefix = (string) ($options['prefix'] ?? Config::get('RATE_LIMIT_REDIS_PREFIX', 'nsql_rl_'));
        if ($this->prefix === '') {
            throw new \InvalidArgumentException('Redis rate limit anahtar öneki boş olamaz.');
        }
        $this->capacity = max(1, (int) ($options['max_requests'] ?? Config::get('RATE_LIMIT_MAX_REQUESTS', Config::rate_limit_max_requests)));
        $this->window = max(1, (int) ($options['window'] ?? Config::get('RATE_LIMIT_WINDOW', Config::rate_limit_window)));
        $this->burst_limit = max(1, (int) ($options['burst'] ?? Config::get('RATE_LIMIT_BURST', Config::rate_limit_burst)));
    }

    /**
     * @return \Redis
     */
    private static function connect(): object
    {
        if (! extension_loaded('redis')) {
            throw new \RuntimeException('RedisRateLimiter için phpredis eklentisi gerekli.');
        }

        $redis = new \Redis();
        $connected = @$redis->connect(
            (string) Config::get('redis_host', '127.0.0.1'),
            (int) Config::get('redis_port', 6379),
            (float) Config::get('redis_timeout', 2)
        );
        if (! $connected) {
            throw new \RuntimeException('Redis bağlantısı kurulamadı (rate limit).');
        }

        $password = Config::get('redis_password');
        if ($password !== null && $password !== '') {
            $redis->auth((string) $password);
        }
        $redis->select((int) Config::get('redis_database', 0));

        return $redis;
    }

    public function check_rate_limit(string $identifier, string $request_type = 'default'): bool
    {
        $now = $this->clock !== null ? (string) (int) ($this->clock)() : '';

        try {
            $result = $this->redis->eval(self::SCRIPT, [
                $this->key($identifier, $request_type),
                (string) $this->capacity,
                sprintf('%.12F', $this->refill_rate()),
                (string) $this->burst_limit,
                $now,
                (string) ($this->window * 2),
                self::TOKEN_EPSILON,
            ], 1);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Redis rate limit kontrolü başarısız: ' . $e->getMessage(), 0, $e);
        }

        if ($result === false) {
            throw new \RuntimeException('Redis rate limit kontrolü başarısız: ' . (string) $this->redis->getLastError());
        }

        return (int) $result === 1;
    }

    public function refill_rate(): float
    {
        return $this->capacity / $this->window;
    }

    /**
     * Redis kayıtları TTL ile (pencerenin iki katı) kendiliğinden silinir; temizlenecek bir şey yoktur.
     */
    public function purge(?int $older_than_seconds = null): int
    {
        return 0;
    }

    /**
     * @internal
     */
    public function key(string $identifier, string $request_type = 'default'): string
    {
        // Çok uzun kimlikler (ör. tam User-Agent) özetlenir; kısa olanlar okunabilir kalır
        $id = strlen($identifier) <= 128 ? rawurlencode($identifier) : 'h_' . hash('sha256', $identifier);

        return $this->prefix . rawurlencode($request_type) . ':' . $id;
    }
}
