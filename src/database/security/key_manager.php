<?php

namespace nsql\database\security;

use RuntimeException;

/**
 * Encryption Key Manager
 * 
 * Güvenli key yönetimi, rotation ve storage sağlar
 */
class key_manager
{
    private const KEY_VERSION_PREFIX = 'v';
    private const KEY_STORAGE_FILE = 'storage/keys/encryption.key';
    private const KEY_MIN_LENGTH = 32;
    private const KEY_MAX_LENGTH = 64;

    /**
     * Mevcut encryption key'i alır
     * 
     * Öncelik sırası:
     * 1. Environment variable (ENCRYPTION_KEY)
     * 2. Config dosyası (encryption_key)
     * 3. Güvenli key storage dosyası
     * 4. Yeni key oluştur ve sakla
     * 
     * @return string Encryption key
     */
    public static function get_key(): string
    {
        // 1. Environment variable'dan al
        $key = getenv('ENCRYPTION_KEY');
        if (!empty($key)) {
            return self::validate_key($key);
        }

        // 2. Config'den al
        $key = \nsql\database\config::get('encryption_key');
        if (!empty($key)) {
            return self::validate_key($key);
        }

        // 3. Güvenli storage dosyasından al
        $key = self::load_key_from_storage();
        if (!empty($key)) {
            return self::validate_key($key);
        }

        // 4. Yeni key oluştur ve sakla
        $key = self::generate_key();
        self::save_key_to_storage($key);

        return $key;
    }

    /**
     * Yeni encryption key oluşturur
     * 
     * @return string Yeni encryption key
     */
    public static function generate_key(): string
    {
        // 32 byte (256 bit) güçlü key oluştur
        $key = base64_encode(random_bytes(32));
        
        return self::validate_key($key);
    }

    /**
     * Key'i güvenli storage'a kaydeder
     * 
     * @param string $key Encryption key
     * @return bool Başarılı ise true
     */
    public static function save_key_to_storage(string $key): bool
    {
        $key = self::validate_key($key);
        $storage_path = self::get_storage_path();

        // Storage dizinini oluştur
        $storage_dir = dirname($storage_path);
        if (!is_dir($storage_dir)) {
            if (!mkdir($storage_dir, 0700, true)) {
                throw new RuntimeException("Key storage dizini oluşturulamadı: {$storage_dir}");
            }
        }

        // Dosya izinlerini kontrol et
        if (file_exists($storage_path)) {
            $current_perms = fileperms($storage_path);
            if (($current_perms & 0777) !== 0600) {
                chmod($storage_path, 0600);
            }
        }

        // Key'i dosyaya yaz (base64 encoded)
        $result = file_put_contents(
            $storage_path,
            base64_encode($key),
            LOCK_EX
        );

        if ($result === false) {
            throw new RuntimeException("Key storage dosyasına yazılamadı: {$storage_path}");
        }

        // Dosya izinlerini güvenli hale getir
        chmod($storage_path, 0600);

        return true;
    }

    /**
     * Key'i güvenli storage'dan yükler
     * 
     * @return string|null Key bulunursa key, bulunamazsa null
     */
    public static function load_key_from_storage(): ?string
    {
        $storage_path = self::get_storage_path();

        if (!file_exists($storage_path)) {
            return null;
        }

        // Dosya izinlerini kontrol et
        $perms = fileperms($storage_path);
        if (($perms & 0777) !== 0600) {
            // Güvenlik uyarısı: dosya izinleri güvenli değil
            trigger_error(
                "Key storage dosyası güvenli izinlere sahip değil: {$storage_path}",
                E_USER_WARNING
            );
        }

        $content = file_get_contents($storage_path);
        if ($content === false) {
            return null;
        }

        $key = base64_decode($content, true);
        if ($key === false) {
            return null;
        }

        return self::validate_key($key);
    }

    /**
     * Key rotation yapar (yeni key oluşturur ve eski key'i arşivler)
     * 
     * @param string|null $old_key Eski key (opsiyonel, otomatik yüklenir)
     * @return array ['new_key' => string, 'old_key' => string, 'rotation_date' => string]
     */
    public static function rotate_key(?string $old_key = null): array
    {
        if ($old_key === null) {
            $old_key = self::get_key();
        }

        // Yeni key oluştur
        $new_key = self::generate_key();

        // Eski key'i arşivle
        self::archive_key($old_key);

        // Yeni key'i kaydet
        self::save_key_to_storage($new_key);

        return [
            'new_key' => $new_key,
            'old_key' => $old_key,
            'rotation_date' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Eski key'i arşivler (geçmiş için saklar)
     * 
     * @param string $key Eski key
     * @return bool Başarılı ise true
     */
    private static function archive_key(string $key): bool
    {
        $archive_dir = dirname(self::get_storage_path()) . '/archive';
        if (!is_dir($archive_dir)) {
            mkdir($archive_dir, 0700, true);
        }

        $archive_file = $archive_dir . '/key_' . date('Y-m-d_His') . '.key';
        
        return file_put_contents(
            $archive_file,
            base64_encode($key),
            LOCK_EX
        ) !== false;
    }

    /**
     * Key'in geçerli olup olmadığını kontrol eder
     * 
     * @param string $key Kontrol edilecek key
     * @return string Geçerli key
     * @throws RuntimeException Key geçersiz ise
     */
    private static function validate_key(string $key): string
    {
        $key = trim($key);
        
        if (empty($key)) {
            throw new RuntimeException('Encryption key boş olamaz');
        }

        $decoded = base64_decode($key, true);
        if ($decoded === false) {
            throw new RuntimeException('Encryption key geçersiz format (base64 bekleniyor)');
        }

        $length = strlen($decoded);
        if ($length < self::KEY_MIN_LENGTH || $length > self::KEY_MAX_LENGTH) {
            throw new RuntimeException(
                sprintf(
                    'Encryption key uzunluğu geçersiz: %d byte (beklenen: %d-%d byte)',
                    $length,
                    self::KEY_MIN_LENGTH,
                    self::KEY_MAX_LENGTH
                )
            );
        }

        return $key;
    }

    /**
     * Key storage dosya yolunu döndürür
     * 
     * @return string Storage dosya yolu
     */
    private static function get_storage_path(): string
    {
        $project_root = \nsql\database\config::get('project_root', dirname(__DIR__, 3));
        $storage_file = \nsql\database\config::get('encryption_key_storage', self::KEY_STORAGE_FILE);

        // Mutlak yol ise direkt kullan
        if (preg_match('/^[A-Za-z]:\\\\|^\//', $storage_file)) {
            return $storage_file;
        }

        // Göreli yol ise project root'a göre oluştur
        return rtrim($project_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storage_file;
    }

    /**
     * Key storage dosyasının var olup olmadığını kontrol eder
     * 
     * @return bool Key storage dosyası varsa true
     */
    public static function key_exists(): bool
    {
        $storage_path = self::get_storage_path();
        return file_exists($storage_path) && is_readable($storage_path);
    }

    /**
     * Key storage dosyasını siler (dikkatli kullanılmalı!)
     * 
     * @return bool Başarılı ise true
     */
    public static function delete_key_storage(): bool
    {
        $storage_path = self::get_storage_path();
        
        if (file_exists($storage_path)) {
            return unlink($storage_path);
        }

        return true;
    }
}
