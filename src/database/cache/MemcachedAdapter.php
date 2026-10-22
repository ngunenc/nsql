<?php

namespace nsql\database\cache;

/**
 * Memcached Cache Adapter
 *
 * Memcached kullanarak distributed cache sağlar. Memcached önekle silmeyi desteklemediğinden
 * anahtarlar `{prefix}{nesil}:{anahtar}` biçiminde yazılır; clear() yalnızca nesil token'ını
 * yeniler, böylece sunucudaki diğer uygulamaların verisi silinmez (flush kullanılmaz). Eski
 * nesildeki kayıtlar okunmaz ve TTL / LRU ile düşer.
 *
 * Tüm değerler (tag listeleri dahil) SafeSerializer JSON string'i olarak yazılır.
 *
 * GÜVENLİK: php-memcached bir öğeyi okurken öğenin bayrağına göre çözer; "PHP serialized" bayraklı
 * bir öğe sınıf kısıtı olmadan unserialize edilir (object injection). Bu, eklenti seviyesinde olur ve
 * kütüphaneden kapatılamaz. Memcached'e güvenilmeyen hiçbir istemci yazamamalıdır: SASL kimlik
 * doğrulaması veya ağ yalıtımı (yalnızca uygulama sunucularından erişim) zorunludur. Paylaşılan /
 * kimlik doğrulamasız bir cache sunucusunda Redis (AUTH + string değerler) tercih edin.
 */
class MemcachedAdapter implements CacheAdapterInterface
{
    private const MAX_KEY_LENGTH = 250;
    /** \Memcached::GET_EXTENDED (eklenti yüklü değilken de sabit kullanılabilsin) */
    private const GET_EXTENDED = 2;
    /** Eşzamanlı tag güncellemesinde cas() deneme sayısı */
    private const CAS_ATTEMPTS = 5;

    /** @var \Memcached|null */
    private ?object $memcached = null;
    private array $servers;
    private int $default_ttl;
    private string $prefix;
    private bool $connected = false;

    /**
     * @param \Memcached|null $client Önceden yapılandırılmış istemci (paylaşılan bağlantı); verilirse $servers kullanılmaz
     */
    public function __construct(
        array $servers = [['127.0.0.1', 11211]],
        int $default_ttl = 3600,
        string $prefix = 'nsql_',
        ?object $client = null
    ) {
        if ($prefix === '') {
            throw new \InvalidArgumentException('Memcached anahtar öneki boş olamaz.');
        }

        $this->servers = $servers;
        $this->default_ttl = $default_ttl;
        $this->prefix = $prefix;

        if ($client !== null) {
            $this->memcached = $client;
            $this->connected = true;
        }
    }

    /**
     * Memcached bağlantısını oluşturur
     */
    private function connect(): bool
    {
        if ($this->connected && $this->memcached !== null) {
            return true;
        }

        if (! extension_loaded('memcached')) {
            return false;
        }

        try {
            $memcached = new \Memcached();
            $memcached->addServers($this->servers);

            // Consistency hash kullan (daha iyi dağıtım)
            $memcached->setOption(\Memcached::OPT_DISTRIBUTION, \Memcached::DISTRIBUTION_CONSISTENT);
            $memcached->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);

            // addServers() bağlantı kurmaz; ulaşılamayan sunucular 255.255.255 sürümü döndürür
            $versions = $memcached->getVersion();
            $reachable = is_array($versions) && array_filter($versions, fn ($v) => $v !== false && $v !== '255.255.255') !== [];
            if (! $reachable) {
                return false;
            }

            $this->memcached = $memcached;
            $this->connected = true;
            return true;
        } catch (\Exception $e) {
            $this->memcached = null;
            $this->connected = false;
            return false;
        }
    }

    /**
     * @return \Memcached
     */
    private function client(): object
    {
        assert($this->memcached !== null);

        return $this->memcached;
    }

    private function namespace_key(): string
    {
        return $this->prefix . 'ns';
    }

    /**
     * Geçerli nesil token'ı. Token silinirse (eviction) yenisi üretilir; eski kayıtlar böylece
     * yeniden görünür hale gelmez.
     */
    private function generation(): string
    {
        $memcached = $this->client();
        $generation = $memcached->get($this->namespace_key());
        if (is_string($generation) && $generation !== '') {
            return $generation;
        }

        $generation = bin2hex(random_bytes(4));
        if (! $memcached->add($this->namespace_key(), $generation, 0)) {
            $existing = $memcached->get($this->namespace_key());
            if (is_string($existing) && $existing !== '') {
                return $existing;
            }
        }

        return $generation;
    }

    private function key(string $key, ?string $generation = null): string
    {
        $base = $this->prefix . ($generation ?? $this->generation()) . ':';
        $full = $base . $key;

        // Memcached anahtarları en fazla 250 byte olabilir ve boşluk/kontrol karakteri içeremez
        if (strlen($full) > self::MAX_KEY_LENGTH || preg_match('/[\s\x00-\x1f\x7f]/', $key)) {
            return $base . 'h:' . hash('sha256', $key);
        }

        return $full;
    }

    private function tag_key(string $tag, string $generation): string
    {
        return $this->key('tag:' . $tag, $generation);
    }

    public function get(string $key): mixed
    {
        if (! $this->connect()) {
            return null;
        }

        try {
            $value = $this->client()->get($this->key($key));

            return is_string($value) ? SafeSerializer::decode($value) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool
    {
        if (! $this->connect()) {
            return false;
        }

        try {
            $memcached = $this->client();
            // Memcached göreli TTL üst sınırı 30 gün
            $ttl = min($ttl ?? $this->default_ttl, 2592000);
            $generation = $this->generation();
            $full_key = $this->key($key, $generation);

            $result = $memcached->set($full_key, SafeSerializer::encode($value), $ttl);

            if ($result) {
                foreach ($tags as $tag) {
                    $this->add_to_tag($this->tag_key((string) $tag, $generation), $full_key, $ttl);
                }
            }

            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Tag listesine anahtar ekler. Oku-değiştir-yaz cas() ile yapılır: eşzamanlı iki set() birbirinin
     * eklediği anahtarı ezmez (aksi halde invalidate_by_tag() o kaydı bulamaz ve bayat veri kalır).
     */
    private function add_to_tag(string $tag_key, string $full_key, int $ttl): void
    {
        $memcached = $this->client();
        for ($attempt = 0; $attempt < self::CAS_ATTEMPTS; $attempt++) {
            $item = $memcached->get($tag_key, null, self::GET_EXTENDED);
            if (! is_array($item) || ! array_key_exists('value', $item)) {
                if ($memcached->add($tag_key, SafeSerializer::encode([$full_key]), $ttl)) {
                    return;
                }
                continue;
            }

            $keys = self::tag_keys($item['value']);
            if (in_array($full_key, $keys, true)) {
                return;
            }
            $keys[] = $full_key;
            if ($memcached->cas($item['cas'], $tag_key, SafeSerializer::encode($keys), $ttl)) {
                return;
            }
        }
    }

    /**
     * Tag listesini çözer: JSON string (2.5.1+) veya eski PHP dizisi biçimi.
     *
     * @return list<string>
     */
    private static function tag_keys(mixed $stored): array
    {
        $keys = is_string($stored) ? SafeSerializer::decode($stored) : $stored;

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    public function delete(string $key): bool
    {
        if (! $this->connect()) {
            return false;
        }

        try {
            return $this->client()->delete($this->key($key));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Bu adaptörün kayıtlarını geçersiz kılar (nesil token'ı yenilenir; flush kullanılmaz).
     */
    public function clear(): bool
    {
        if (! $this->connect()) {
            return false;
        }

        try {
            return $this->client()->set($this->namespace_key(), bin2hex(random_bytes(4)), 0);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function invalidate_by_tag($tags): bool
    {
        if (! $this->connect()) {
            return false;
        }

        $keys_to_delete = [];

        try {
            $memcached = $this->client();
            $generation = $this->generation();
            foreach ((array) $tags as $tag) {
                $tag_key = $this->tag_key((string) $tag, $generation);
                $stored = $memcached->get($tag_key);

                if ($stored !== false) {
                    $keys_to_delete = array_merge($keys_to_delete, self::tag_keys($stored));
                    $memcached->delete($tag_key);
                }
            }

            foreach (array_unique($keys_to_delete) as $key) {
                $memcached->delete($key);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function has(string $key): bool
    {
        if (! $this->connect()) {
            return false;
        }

        try {
            // Değerler her zaman SafeSerializer string'i olarak yazılır; false = kayıt yok
            return is_string($this->client()->get($this->key($key)));
        } catch (\Exception $e) {
            return false;
        }
    }

    public function is_available(): bool
    {
        return $this->connect();
    }

    public function get_name(): string
    {
        return 'memcached';
    }
}
