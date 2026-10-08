<?php

namespace nsql\security;

use nsql\database\Config;

/**
 * AES-256-GCM şifreleme.
 *
 * v2 biçimi: "v2:" . base64(key_id[8] | iv[12] | tag[16] | ciphertext)
 *  - Anahtar, base64 metnin çözülmüş ham 32 byte'ıdır.
 *  - key_id = sha256(ham anahtar)'ın ilk 8 byte'ı; rotation sonrası doğru anahtar seçilir.
 *  - "v2" ve key_id ek doğrulanmış veri (AAD) olarak bağlanır.
 *
 * v1 biçimi (<= 1.5.22): base64(iv[16] | tag[16] | ciphertext), anahtar olarak base64 metnin kendisi.
 * Yalnızca çözme için desteklenir; reencrypt() ile v2'ye taşınabilir. Taşıma bittiyse
 * ENCRYPTION_ALLOW_V1=false ile kapatın (v2.4.0+).
 *
 * Bağlam (v2.4.0+): encrypt($veri, $baglam) bağlamı AAD'ye ekler (ör. 'users.ssn:42'). Değer başka bir
 * kayda / kolona kopyalanırsa farklı bağlamla çözülemez. Boş bağlam önceki biçimle birebir uyumludur.
 */
class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const V2_PREFIX = 'v2:';
    private const KEY_ID_LENGTH = 8;
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const V1_IV_LENGTH = 16;

    /** Base64 anahtar metni (key_manager biçimi) */
    private string $key;
    /** @var array<string> Önceki (arşiv) anahtarlar, base64 metin */
    private array $previous_keys;
    private bool $allow_v1;

    /**
     * @param string|null $key Base64 anahtar; null ise KeyManager::get_key() ve arşiv anahtarları kullanılır
     * @param array<string> $previous_keys Eski verileri çözmek için önceki anahtarlar (base64)
     * @param bool|null $allow_v1 v1 biçimini çözme; null ise ENCRYPTION_ALLOW_V1 (2.x varsayılanı true)
     */
    public function __construct(?string $key = null, array $previous_keys = [], ?bool $allow_v1 = null)
    {
        $this->allow_v1 = $allow_v1 ?? (bool) Config::get('encryption_allow_v1', Config::encryption_allow_v1);

        if ($key === null) {
            $key = KeyManager::get_key();
            $previous_keys = array_merge($previous_keys, KeyManager::get_archived_keys());
        }

        self::raw_key($key);
        $this->key = $key;
        $this->previous_keys = array_values(array_filter(
            array_unique($previous_keys),
            static fn (string $k) => $k !== $key
        ));
    }

    /**
     * @param string $context Doğrulanmış ek veri (AAD); çözerken aynısı verilmelidir
     */
    public function encrypt(string $data, string $context = ''): string
    {
        $raw_key = self::raw_key($this->key);
        $key_id = self::key_id($raw_key);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $data,
            self::CIPHER,
            $raw_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::aad($key_id, $context),
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Şifreleme hatası: ' . openssl_error_string());
        }

        return self::V2_PREFIX . base64_encode($key_id . $iv . $tag . $ciphertext);
    }

    /**
     * @param string $context encrypt() sırasında verilen bağlam
     * @throws \RuntimeException Doğrulama başarısızsa, anahtar bulunamazsa veya v1 kapalıyken v1 verisi gelirse
     */
    public function decrypt(string $encrypted_data, string $context = ''): string
    {
        if (str_starts_with($encrypted_data, self::V2_PREFIX)) {
            return $this->decrypt_v2(substr($encrypted_data, strlen(self::V2_PREFIX)), $context);
        }

        if (! $this->allow_v1) {
            throw new \RuntimeException('Şifre çözme hatası: v1 biçimi devre dışı (ENCRYPTION_ALLOW_V1=false)');
        }
        if ($context !== '') {
            // v1 bağlam desteklemez; bağlam beklenen yerde bağlamsız veri kabul edilmez
            throw new \RuntimeException('Şifre çözme hatası: v1 verisi bağlamla çözülemez');
        }

        return $this->decrypt_v1($encrypted_data);
    }

    /**
     * Veriyi mevcut anahtarla v2 biçiminde yeniden şifreler (v1 veya eski anahtarlı veriyi taşımak için).
     */
    public function reencrypt(string $encrypted_data, string $context = ''): string
    {
        return $this->encrypt($this->decrypt($encrypted_data, $context), $context);
    }

    /**
     * Veri v1 biçimindeyse veya mevcut anahtardan farklı bir anahtarla şifrelenmişse true.
     */
    public function needs_reencrypt(string $encrypted_data): bool
    {
        if (! str_starts_with($encrypted_data, self::V2_PREFIX)) {
            return true;
        }

        $decoded = base64_decode(substr($encrypted_data, strlen(self::V2_PREFIX)), true);
        if ($decoded === false || strlen($decoded) < self::KEY_ID_LENGTH) {
            return true;
        }

        return ! hash_equals(self::key_id(self::raw_key($this->key)), substr($decoded, 0, self::KEY_ID_LENGTH));
    }

    /**
     * Yeni anahtar üretir, eskisini arşivler; eski anahtar bu nesnede çözme için kullanılmaya devam eder.
     *
     * @return array{new_key: string, old_key: string, rotation_date: string}
     */
    public function rotate_key(): array
    {
        $rotation_info = KeyManager::rotate_key($this->key);
        array_unshift($this->previous_keys, $this->key);
        $this->key = $rotation_info['new_key'];

        return $rotation_info;
    }

    public function is_key_valid(): bool
    {
        try {
            $test_data = 'test_key_validation_' . bin2hex(random_bytes(8));

            return $this->decrypt($this->encrypt($test_data)) === $test_data;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function decrypt_v2(string $body, string $context): string
    {
        $decoded = base64_decode($body, true);
        $min_length = self::KEY_ID_LENGTH + self::IV_LENGTH + self::TAG_LENGTH;
        if ($decoded === false || strlen($decoded) < $min_length) {
            throw new \RuntimeException('Şifre çözme hatası: geçersiz v2 verisi');
        }

        $key_id = substr($decoded, 0, self::KEY_ID_LENGTH);
        $iv = substr($decoded, self::KEY_ID_LENGTH, self::IV_LENGTH);
        $tag = substr($decoded, self::KEY_ID_LENGTH + self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($decoded, $min_length);

        foreach ($this->all_keys() as $key) {
            $raw_key = self::raw_key($key);
            if (! hash_equals(self::key_id($raw_key), $key_id)) {
                continue;
            }

            $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $raw_key, OPENSSL_RAW_DATA, $iv, $tag, self::aad($key_id, $context));
            if ($plaintext === false) {
                throw new \RuntimeException('Şifre çözme hatası: doğrulama başarısız (veri değiştirilmiş olabilir)');
            }

            return $plaintext;
        }

        throw new \RuntimeException('Şifre çözme hatası: verinin anahtarı bulunamadı (key_id ' . bin2hex($key_id) . ')');
    }

    private function decrypt_v1(string $body): string
    {
        $decoded = base64_decode($body, true);
        if ($decoded === false || strlen($decoded) < self::V1_IV_LENGTH + self::TAG_LENGTH) {
            throw new \RuntimeException('Şifre çözme hatası: geçersiz veri');
        }

        $iv = substr($decoded, 0, self::V1_IV_LENGTH);
        $tag = substr($decoded, self::V1_IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($decoded, self::V1_IV_LENGTH + self::TAG_LENGTH);

        foreach ($this->all_keys() as $key) {
            $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plaintext !== false) {
                return $plaintext;
            }
        }

        throw new \RuntimeException('Şifre çözme hatası: doğrulama başarısız');
    }

    /**
     * @return array<string>
     */
    private function all_keys(): array
    {
        return array_merge([$this->key], $this->previous_keys);
    }

    /**
     * Base64 anahtarı ham 32 byte'a çevirir. 32 byte'tan uzun anahtarlar HKDF ile 32 byte'a indirgenir.
     */
    private static function raw_key(string $key): string
    {
        $raw = base64_decode(trim($key), true);
        if ($raw === false || strlen($raw) < 32) {
            throw new \InvalidArgumentException('Encryption key en az 32 byte\'lık base64 olmalıdır');
        }

        return strlen($raw) === 32 ? $raw : hash_hkdf('sha256', $raw, 32, 'nsql-encryption-v2');
    }

    private static function key_id(string $raw_key): string
    {
        return substr(hash('sha256', $raw_key, true), 0, self::KEY_ID_LENGTH);
    }

    private static function aad(string $key_id, string $context = ''): string
    {
        // Boş bağlam 2.3 ve öncesiyle aynı AAD'yi üretir
        return 'v2' . $key_id . ($context === '' ? '' : " " . $context);
    }
}
