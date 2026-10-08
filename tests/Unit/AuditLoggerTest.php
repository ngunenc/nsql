<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\security\AuditLogger;
use PHPUnit\Framework\TestCase;

/**
 * AuditLogger: biçim, maskeleme, önem seviyeleri, log injection ve rotasyon (#116).
 */
class AuditLoggerTest extends TestCase
{
    private string $dir;
    private string $file;
    private mixed $previous_max_size;

    protected function setUp(): void
    {
        $this->previous_max_size = Config::get('log_max_size');
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_audit_' . bin2hex(random_bytes(4));
        $this->file = $this->dir . DIRECTORY_SEPARATOR . 'audit.log';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        Config::set('log_max_size', $this->previous_max_size);
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            unlink($path);
        }
        @rmdir($this->dir);
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->file))));
    }

    public function test_creates_directory_and_writes_single_line_entry(): void
    {
        (new AuditLogger($this->file))->log_security_event('login_failed', 'Hatalı giriş', ['user' => 'ali'], 'warning');

        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[WARNING\] \[login_failed\] Hatalı giriş'
            . ' \| IP: 203\.0\.113\.7 \| UA: PHPUnit \| SID: no_session \| \{"user":"ali"\}$/u',
            $lines[0]
        );
    }

    public function test_sensitive_context_is_masked(): void
    {
        $logger = new AuditLogger($this->file);
        $logger->log_security_event('profile_update', 'Profil', ['password' => 'gizli123', 'api_token' => 'abc', 'name' => 'Ali']);
        $logger->log_sql_injection_attempt('SELECT * FROM users WHERE id = ?', ['password' => 'p@ss'], 'syntax');

        $content = (string) file_get_contents($this->file);
        $this->assertStringNotContainsString('gizli123', $content);
        $this->assertStringNotContainsString('p@ss', $content);
        $this->assertStringNotContainsString('"abc"', $content);
        $this->assertStringContainsString('"name":"Ali"', $content);
        $this->assertStringContainsString('[CRITICAL] [sql_injection_attempt]', $content);
    }

    public function test_helper_events_and_severities(): void
    {
        $logger = new AuditLogger($this->file);
        $logger->log_rate_limit_violation('203.0.113.7', 'api');
        $logger->log_session_event('session_hijacking_attempt');
        $logger->log_session_event('session_start');
        $logger->log_session_event('bilinmeyen');
        $logger->log_sensitive_data_access('users', 'tckn', 'read');
        $logger->log_pool_event('exhausted', ['active' => 15]);

        $lines = $this->lines();
        $this->assertCount(6, $lines);
        $this->assertStringContainsString('[WARNING] [rate_limit_violation] Rate limit aşıldı: 203.0.113.7 (api)', $lines[0]);
        $this->assertStringContainsString('[CRITICAL] [session_hijacking_attempt] Oturum çalma girişimi', $lines[1]);
        $this->assertStringContainsString('[INFO] [session_start] Yeni oturum başlatıldı', $lines[2]);
        $this->assertStringContainsString('[INFO] [bilinmeyen] Oturum olayı', $lines[3]);
        $this->assertStringContainsString('[WARNING] [sensitive_data_access] Hassas veri erişimi: users.tckn (read)', $lines[4]);
        $this->assertStringContainsString('[INFO] [connection_pool_exhausted]', $lines[5]);
        $this->assertStringContainsString('{"active":15}', $lines[5]);
    }

    public function test_newlines_cannot_forge_entries(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = "Mozilla\r\n[2026-01-01 00:00:00] [INFO] [login_ok] sahte";
        $logger = new AuditLogger($this->file);
        $logger->log_rate_limit_violation("1.2.3.4\n[2026-01-01 00:00:00] [INFO] [admin_login] sahte kayıt", 'api');

        $lines = $this->lines();
        $this->assertCount(1, $lines, 'Kullanıcı kontrolündeki değer yeni log satırı üretmemeli');
        $this->assertStringContainsString('\n[2026-01-01 00:00:00] [INFO] [admin_login] sahte kayıt', $lines[0]);
        $this->assertStringContainsString('UA: Mozilla\r\n', $lines[0]);
    }

    public function test_rotates_when_file_exceeds_max_size(): void
    {
        Config::set('log_max_size', 200);
        $logger = new AuditLogger($this->file);
        for ($i = 0; $i < 3; $i++) {
            $logger->log_security_event('event_' . $i, str_repeat('x', 150));
        }

        $rotated = glob($this->file . '.*') ?: [];
        $this->assertNotEmpty($rotated, 'Boyut aşılınca dosya döndürülmeli');
        $this->assertCount(1, $this->lines(), 'Aktif dosya son kaydı içerir');
    }
}
