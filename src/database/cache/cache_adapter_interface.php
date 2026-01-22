<?php

namespace nsql\database\cache;

/**
 * Cache Adapter Interface
 * 
 * Tüm cache adapter'ları bu interface'i implement etmelidir
 */
interface cache_adapter_interface
{
    /**
     * Cache'den değer alır
     *
     * @param string $key Cache key
     * @return mixed|null Cache değeri veya null
     */
    public function get(string $key): mixed;

    /**
     * Cache'e değer yazar
     *
     * @param string $key Cache key
     * @param mixed $value Cache değeri
     * @param int|null $ttl Time to live (saniye, null ise varsayılan TTL)
     * @param array $tags Cache tags (opsiyonel)
     * @return bool Başarılı ise true
     */
    public function set(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool;

    /**
     * Cache'den değer siler
     *
     * @param string $key Cache key
     * @return bool Başarılı ise true
     */
    public function delete(string $key): bool;

    /**
     * Tüm cache'i temizler
     *
     * @return bool Başarılı ise true
     */
    public function clear(): bool;

    /**
     * Tag'e göre cache'leri temizler
     *
     * @param string|array $tags Tag veya tag'ler
     * @return bool Başarılı ise true
     */
    public function invalidate_by_tag($tags): bool;

    /**
     * Cache'in mevcut olup olmadığını kontrol eder
     *
     * @param string $key Cache key
     * @return bool Mevcut ise true
     */
    public function has(string $key): bool;

    /**
     * Adapter'ın kullanılabilir olup olmadığını kontrol eder
     *
     * @return bool Kullanılabilir ise true
     */
    public function is_available(): bool;

    /**
     * Adapter adını döndürür
     *
     * @return string Adapter adı
     */
    public function get_name(): string;
}
