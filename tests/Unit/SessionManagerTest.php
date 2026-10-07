<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\security\SessionManager;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class SessionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
    }

    private function prepare_session_ini(): void
    {
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.save_path', sys_get_temp_dir());
    }

    #[RunInSeparateProcess]
    public function test_existing_session_data_is_preserved_and_id_regenerated(): void
    {
        $this->prepare_session_ini();
        session_start();
        $_SESSION['user_id'] = 42;
        $id = session_id();

        (new SessionManager(['send_headers' => false]))->start();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertNotSame($id, session_id());
        $this->assertSame(42, $_SESSION['user_id']);
        $this->assertArrayHasKey('_fingerprint', $_SESSION);
        session_destroy();
    }

    #[RunInSeparateProcess]
    public function test_planted_session_id_is_not_kept(): void
    {
        $this->prepare_session_ini();
        session_id('attackerchosenid0123456789abcdef');

        (new SessionManager(['send_headers' => false]))->start();

        $this->assertNotSame('attackerchosenid0123456789abcdef', session_id());
        session_destroy();
    }

    #[RunInSeparateProcess]
    public function test_established_session_keeps_id_on_next_start(): void
    {
        $this->prepare_session_ini();
        (new SessionManager(['send_headers' => false]))->start();
        $id = session_id();
        session_write_close();

        session_id($id);
        (new SessionManager(['send_headers' => false]))->start();

        $this->assertSame($id, session_id());
        session_destroy();
    }

    #[RunInSeparateProcess]
    public function test_validate_regenerates_id_after_interval(): void
    {
        $this->prepare_session_ini();
        $manager = new SessionManager(['send_headers' => false, 'regenerate_interval' => 60]);
        $manager->start();
        $id = session_id();

        $manager->validate();
        $this->assertSame($id, session_id());

        $_SESSION['_regenerated_at'] = time() - 61;
        $manager->validate();
        $this->assertNotSame($id, session_id());
        session_destroy();
    }

    #[RunInSeparateProcess]
    public function test_ip_change_does_not_kill_session_by_default(): void
    {
        $this->prepare_session_ini();
        $manager = new SessionManager(['send_headers' => false]);
        $manager->start();

        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $manager->validate();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        session_destroy();
    }

    #[RunInSeparateProcess]
    public function test_fingerprint_ip_prefix_tolerates_same_subnet(): void
    {
        $this->prepare_session_ini();
        $manager = new SessionManager(['send_headers' => false, 'fingerprint_ip' => 'prefix']);
        $manager->start();

        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $manager->validate();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());

        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $this->expectExceptionMessage('Session hijacking');
        $manager->validate();
    }

    #[RunInSeparateProcess]
    public function test_accept_language_change_does_not_kill_session_by_default(): void
    {
        $this->prepare_session_ini();
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'tr-TR,tr;q=0.9';
        $manager = new SessionManager(['send_headers' => false]);
        $manager->start();

        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.8';
        $manager->validate();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        session_destroy();
    }

    #[RunInSeparateProcess]
    public function test_accept_language_can_be_added_to_fingerprint_fields(): void
    {
        $this->prepare_session_ini();
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'tr-TR,tr;q=0.9';
        $manager = new SessionManager([
            'send_headers' => false,
            'fingerprint_fields' => ['HTTP_USER_AGENT', 'HTTP_ACCEPT_LANGUAGE'],
        ]);
        $manager->start();

        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.8';
        $this->expectExceptionMessage('Session hijacking');
        $manager->validate();
    }

    #[RunInSeparateProcess]
    public function test_user_agent_change_still_kills_session(): void
    {
        $this->prepare_session_ini();
        $manager = new SessionManager(['send_headers' => false]);
        $manager->start();

        $_SERVER['HTTP_USER_AGENT'] = 'other-agent';
        $this->expectExceptionMessage('Session hijacking');
        $manager->validate();
    }

    public function test_http_request_gets_no_hsts_and_no_xss_header(): void
    {
        $headers = (new SessionManager(['hsts' => true]))->security_headers();

        $this->assertArrayNotHasKey('Strict-Transport-Security', $headers);
        $this->assertArrayNotHasKey('X-XSS-Protection', $headers);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    public function test_hsts_only_on_https_and_opt_in(): void
    {
        $_SERVER['HTTPS'] = 'on';

        $this->assertArrayNotHasKey('Strict-Transport-Security', (new SessionManager())->security_headers());
        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            (new SessionManager(['hsts' => true]))->security_headers()['Strict-Transport-Security']
        );
        $this->assertSame(
            'max-age=600',
            (new SessionManager(['hsts' => 'max-age=600']))->security_headers()['Strict-Transport-Security']
        );
    }

    public function test_secure_flag_follows_https_unless_explicit(): void
    {
        $this->assertFalse((new SessionManager())->is_secure());
        $this->assertTrue((new SessionManager(['secure' => true]))->is_secure());

        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue((new SessionManager())->is_secure());
        $this->assertFalse((new SessionManager(['secure' => false]))->is_secure());
    }

    public function test_ip_prefix(): void
    {
        $this->assertSame('203.0.113.0/24', SessionManager::ip_prefix('203.0.113.77'));
        $this->assertSame('2001:db8:1:2::/64', SessionManager::ip_prefix('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
    }

    public function test_csrf_token_expires_and_rotates(): void
    {
        $_SESSION = [];
        Config::set('csrf_token_ttl', 100);

        $token = SessionManager::get_csrf_token();
        $this->assertTrue(SessionManager::validate_csrf_token($token));
        $this->assertSame($token, SessionManager::get_csrf_token());

        $_SESSION['csrf_token_time'] = time() - 101;
        $this->assertFalse(SessionManager::validate_csrf_token($token));
        $fresh = SessionManager::get_csrf_token();
        $this->assertNotSame($token, $fresh);
        $this->assertTrue(SessionManager::validate_csrf_token($fresh));

        $rotated = SessionManager::rotate_csrf_token();
        $this->assertFalse(SessionManager::validate_csrf_token($fresh));
        $this->assertTrue(SessionManager::validate_csrf_token($rotated));

        Config::set('csrf_token_ttl', null);
        $_SESSION = [];
    }

    public function test_consumed_csrf_token_is_single_use(): void
    {
        $_SESSION = [];

        $token = SessionManager::get_csrf_token();
        $this->assertTrue(SessionManager::validate_csrf_token($token));
        $this->assertTrue(SessionManager::validate_csrf_token($token), 'varsayılan: TTL boyunca tekrar kullanılabilir');

        $this->assertTrue(SessionManager::validate_csrf_token($token, consume: true));
        $this->assertFalse(SessionManager::validate_csrf_token($token, consume: true));
        $this->assertFalse(SessionManager::validate_csrf_token($token));

        $next = SessionManager::get_csrf_token();
        $this->assertNotSame($token, $next);
        $this->assertTrue(SessionManager::validate_csrf_token($next));

        $_SESSION = [];
    }

    public function test_failed_consume_does_not_rotate(): void
    {
        $_SESSION = [];
        $token = SessionManager::get_csrf_token();

        $this->assertFalse(SessionManager::validate_csrf_token('yanlis', consume: true));
        $this->assertFalse(SessionManager::validate_csrf_token(['dizi'], consume: true));
        $this->assertSame($token, SessionManager::get_csrf_token());

        $_SESSION = [];
    }
}
