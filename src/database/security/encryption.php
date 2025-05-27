<?php

namespace nsql\database\security;

class encryption {
    private string $key;
    private string $cipher = 'aes-256-gcm';
    private int $tagLength = 16;

    public function __construct(?string $key = null) {
        if ($key === null) {
            // Config'den şifreleme anahtarını al veya yeni oluştur
            $key = $this->getOrGenerateKey();
        }
        $this->key = $key;
    }

    /**
     * Veriyi şifrele
     */
    public function encrypt(string $data): string {
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
            $this->tagLength
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
    public function decrypt(string $encryptedData): string {
        $decoded = base64_decode($encryptedData);
        
        // IV ve tag'i ayır
        $iv = substr($decoded, 0, 16);
        $tag = substr($decoded, 16, $this->tagLength);
        $ciphertext = substr($decoded, 16 + $this->tagLength);

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
     */
    private function getOrGenerateKey(): string {
        // Config'den anahtarı almaya çalış
        $key = \nsql\database\Config::get('ENCRYPTION_KEY');
        
        if (empty($key)) {
            // Yeni anahtar oluştur
            $key = base64_encode(random_bytes(32));
            
            // TODO: Anahtarı güvenli bir şekilde sakla
            // Örneğin: özel bir dosyada veya environment variable'da
        }
        
        return $key;
    }
}
