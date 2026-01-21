<?php

namespace nsql\database\security;

class encryption
{
    private string $key;
    private string $cipher = 'aes-256-gcm';
    private int $tag_length = 16;

    public function __construct(?string $key = null)
    {
        if ($key === null) {
            // Config'den şifreleme anahtarını al veya yeni oluştur
            $key = $this->get_or_generate_key();
        }
        $this->key = $key;
    }

    /**
     * Veriyi şifrele
     */
    public function encrypt(string $data): string
    {
        $iv = random_bytes(16);

        // Şifreleme işlemi
        $ciphertext = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            $this->tag_length
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Şifreleme hatası: ' . openssl_error_string());
        }

        // IV, tag ve şifrelenmiş veriyi birleştir
        $encrypted = base64_encode($iv . $tag . $ciphertext);

        return $encrypted;
    }

    /**
     * Şifrelenmiş veriyi çöz
     */
    public function decrypt(string $encrypted_data): string
    {
        $decoded = base64_decode($encrypted_data);

        // IV ve tag'i ayır
        $iv = substr($decoded, 0, 16);
        $tag = substr($decoded, 16, $this->tag_length);
        $ciphertext = substr($decoded, 16 + $this->tag_length);

        // Şifre çözme işlemi
        $decrypted = openssl_decrypt(
            $ciphertext,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Şifre çözme hatası: ' . openssl_error_string());
        }

        return $decrypted;
    }

    /**
     * Şifreleme anahtarını al veya oluştur
     * 
     * Key Manager kullanarak güvenli key yönetimi yapar
     */
    private function get_or_generate_key(): string
    {
        return key_manager::get_key();
    }

    /**
     * Key rotation yapar (yeni key oluşturur)
     * 
     * @return array Rotation bilgileri
     */
    public function rotate_key(): array
    {
        $rotation_info = key_manager::rotate_key($this->key);
        $this->key = $rotation_info['new_key'];
        
        return $rotation_info;
    }

    /**
     * Mevcut key'in geçerli olup olmadığını kontrol eder
     * 
     * @return bool Key geçerli ise true
     */
    public function is_key_valid(): bool
    {
        try {
            // Test encryption/decryption yaparak key'in geçerli olduğunu kontrol et
            $test_data = 'test_key_validation_' . time();
            $encrypted = $this->encrypt($test_data);
            $decrypted = $this->decrypt($encrypted);
            
            return $decrypted === $test_data;
        } catch (\Exception $e) {
            return false;
        }
    }
}
