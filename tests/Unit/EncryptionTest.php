<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\security\Encryption;
use nsql\security\KeyManager;
use PHPUnit\Framework\TestCase;

class EncryptionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_keys_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        Config::set('encryption_key_storage', $this->dir . DIRECTORY_SEPARATOR . 'encryption.key');
    }

    protected function tearDown(): void
    {
        Config::set('encryption_key_storage', 'storage/keys/encryption.key');
        $this->remove_dir($this->dir);
    }

    private function remove_dir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? $this->remove_dir($path) : unlink($path);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    private static function key(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * <= 1.5.22 encrypt(): base64 metin anahtar olarak, 16 byte IV.
     */
    private static function legacy_encrypt(string $data, string $key): string
    {
        $iv = random_bytes(16);
        $tag = '';
        $ciphertext = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function test_v2_uses_raw_32_byte_key_and_12_byte_iv(): void
    {
        $key = self::key();
        $raw = base64_decode($key, true);
        $payload = (new Encryption($key))->encrypt('gizli veri');

        $this->assertStringStartsWith('v2:', $payload);
        $decoded = base64_decode(substr($payload, 3), true);
        $this->assertSame(8 + 12 + 16 + strlen('gizli veri'), strlen($decoded));

        $key_id = substr($decoded, 0, 8);
        $this->assertSame(substr(hash('sha256', $raw, true), 0, 8), $key_id);

        $plaintext = openssl_decrypt(
            substr($decoded, 36),
            'aes-256-gcm',
            $raw,
            OPENSSL_RAW_DATA,
            substr($decoded, 8, 12),
            substr($decoded, 20, 16),
            'v2' . $key_id
        );
        $this->assertSame('gizli veri', $plaintext);
    }

    public function test_roundtrip_and_unique_ciphertexts(): void
    {
        $encryption = new Encryption(self::key());

        $a = $encryption->encrypt('aynı metin');
        $b = $encryption->encrypt('aynı metin');

        $this->assertNotSame($a, $b);
        $this->assertSame('aynı metin', $encryption->decrypt($a));
        $this->assertSame('', $encryption->decrypt($encryption->encrypt('')));
    }

    public function test_legacy_v1_payload_is_still_decryptable(): void
    {
        $key = self::key();
        $legacy = self::legacy_encrypt('eski kayıt', $key);

        $encryption = new Encryption($key);
        $this->assertSame('eski kayıt', $encryption->decrypt($legacy));
        $this->assertTrue($encryption->needs_reencrypt($legacy));

        $migrated = $encryption->reencrypt($legacy);
        $this->assertStringStartsWith('v2:', $migrated);
        $this->assertFalse($encryption->needs_reencrypt($migrated));
        $this->assertSame('eski kayıt', $encryption->decrypt($migrated));
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $encryption = new Encryption(self::key());
        $decoded = base64_decode(substr($encryption->encrypt('veri'), 3), true);
        $decoded[strlen($decoded) - 1] = chr(ord($decoded[strlen($decoded) - 1]) ^ 1);

        $this->expectException(\RuntimeException::class);
        $encryption->decrypt('v2:' . base64_encode($decoded));
    }

    public function test_tampered_key_id_cannot_be_reused_with_other_key(): void
    {
        $a = new Encryption(self::key());
        $b = new Encryption(self::key());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('anahtarı bulunamadı');
        $b->decrypt($a->encrypt('veri'));
    }

    /**
     * @dataProvider malformed_provider
     */
    public function test_malformed_input_is_rejected(string $payload): void
    {
        $this->expectException(\RuntimeException::class);
        (new Encryption(self::key()))->decrypt($payload);
    }

    public static function malformed_provider(): array
    {
        return [
            'v2 geçersiz base64' => ['v2:@@@not-base64@@@'],
            'v2 çok kısa' => ['v2:' . base64_encode(str_repeat('x', 35))],
            'v1 geçersiz base64' => ['%%%'],
            'v1 çok kısa' => [base64_encode(str_repeat('x', 31))],
            'boş' => [''],
        ];
    }

    public function test_invalid_keys_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Encryption(base64_encode(random_bytes(16)));
    }

    public function test_rotation_keeps_old_data_readable(): void
    {
        $old_key = self::key();
        KeyManager::save_key_to_storage($old_key);

        $encryption = new Encryption($old_key);
        $v2_old = $encryption->encrypt('rotation öncesi');
        $v1_old = self::legacy_encrypt('v1 rotation öncesi', $old_key);

        $info = $encryption->rotate_key();
        $this->assertSame($old_key, $info['old_key']);
        $this->assertNotSame($old_key, $info['new_key']);
        $this->assertSame($info['new_key'], KeyManager::load_key_from_storage());

        $this->assertSame('rotation öncesi', $encryption->decrypt($v2_old));
        $this->assertTrue($encryption->needs_reencrypt($v2_old));

        $fresh = new Encryption($info['new_key'], KeyManager::get_archived_keys());
        $this->assertSame('rotation öncesi', $fresh->decrypt($v2_old));
        $this->assertSame('v1 rotation öncesi', $fresh->decrypt($v1_old));

        $default = new Encryption();
        $this->assertSame('rotation öncesi', $default->decrypt($v2_old));
    }

    public function test_archived_keys_are_newest_first_and_private(): void
    {
        $first = self::key();
        KeyManager::save_key_to_storage($first);
        $second = KeyManager::rotate_key($first)['new_key'];
        KeyManager::rotate_key($second);

        $this->assertSame([$second, $first], KeyManager::get_archived_keys());

        if (PHP_OS_FAMILY !== 'Windows') {
            foreach (glob($this->dir . '/archive/*.key') ?: [] as $file) {
                $this->assertSame(0600, fileperms($file) & 0777);
            }
        }
    }

    public function test_storage_uses_single_base64_and_reads_legacy_double_base64(): void
    {
        $key = self::key();
        KeyManager::save_key_to_storage($key);
        $path = $this->dir . DIRECTORY_SEPARATOR . 'encryption.key';
        $this->assertSame($key, file_get_contents($path));

        file_put_contents($path, base64_encode($key));
        $this->assertSame($key, @KeyManager::load_key_from_storage());
    }

    public function test_is_key_valid(): void
    {
        $this->assertTrue((new Encryption(self::key()))->is_key_valid());
    }

    public function test_context_binds_ciphertext(): void
    {
        $encryption = new Encryption(self::key());
        $secret = $encryption->encrypt('12345678901', 'users.tckn:42');

        $this->assertSame('12345678901', $encryption->decrypt($secret, 'users.tckn:42'));

        foreach (['users.tckn:43', '', 'users.tckn:42 '] as $wrong) {
            try {
                $encryption->decrypt($secret, $wrong);
                $this->fail("Yanlış bağlamla ('{$wrong}') çözülmemeli");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('doğrulama', $e->getMessage());
            }
        }

        $this->assertSame('12345678901', $encryption->decrypt($encryption->reencrypt($secret, 'users.tckn:42'), 'users.tckn:42'));
    }

    public function test_empty_context_is_compatible_with_previous_format(): void
    {
        $key = self::key();
        // 2.3 biçimi: AAD = 'v2' . key_id (bağlamsız)
        $raw = (string) base64_decode($key, true);
        $key_id = substr(hash('sha256', $raw, true), 0, 8);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = (string) openssl_encrypt('2.3 verisi', 'aes-256-gcm', $raw, OPENSSL_RAW_DATA, $iv, $tag, 'v2' . $key_id, 16);
        $old = 'v2:' . base64_encode($key_id . $iv . $tag . $ciphertext);

        $this->assertSame('2.3 verisi', (new Encryption($key))->decrypt($old));
    }

    public function test_v1_can_be_disabled(): void
    {
        $key = self::key();
        $legacy = self::legacy_encrypt('eski kayıt', $key);

        $strict = new Encryption($key, [], allow_v1: false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ENCRYPTION_ALLOW_V1');
        $strict->decrypt($legacy);
    }

    public function test_v1_follows_config_and_rejects_context(): void
    {
        $key = self::key();
        $legacy = self::legacy_encrypt('eski kayıt', $key);

        Config::set('encryption_allow_v1', false);
        try {
            $this->assertFalse((fn () => $this->allow_v1)->call(new Encryption($key)));
        } finally {
            Config::set('encryption_allow_v1', Config::encryption_allow_v1);
        }

        $this->expectException(\RuntimeException::class);
        (new Encryption($key))->decrypt($legacy, 'bir bağlam');
    }
}
