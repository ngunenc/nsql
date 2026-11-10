<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\database\exceptions\ConnectionException;
use nsql\database\exceptions\ErrorCodes;
use nsql\database\Nsql;
use nsql\security\SecurityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArrayLogger;

/**
 * Güvenlik sağlamlaştırma: kimlik bilgisi sızıntısı (dump / stack trace), log maskesi,
 * tanımlayıcı doğrulaması, production kimlik bilgisi denetimi.
 */
class SecurityHardeningTest extends TestCase
{
    private const SECRET = 'hunter2-cok-gizli';

    private string $path;

    /** @var list<Nsql> */
    private array $connections = [];

    /** @var array<string, string|false> */
    private array $saved_env = [];

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite gerekli');
        }
        foreach (['ENV', 'APP_ENV'] as $key) {
            $this->saved_env[$key] = getenv($key);
        }
        // Önce yükleme: ilk Config erişimi ortamı ENV değişkeninden yeniden okur
        Config::set_project_root(dirname(__DIR__, 2));
        Config::refresh();
        Config::set_environment('testing');
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_hardening_' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            (fn () => $this->disconnect())->call($db);
        }
        $this->connections = [];
        foreach ($this->saved_env as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        Config::set_project_root(dirname(__DIR__, 2));
        Config::refresh();
        Config::set_environment('testing');
        @unlink($this->path);
    }

    private function sqlite(): Nsql
    {
        $db = new Nsql(db: $this->path, user: 'app_user', pass: self::SECRET, driver: 'sqlite');
        $db->set_read_replica(['host' => 'replica', 'pass' => self::SECRET]);
        $this->connections[] = $db;

        return $db;
    }

    // --- B8: parola dump / stack trace çıktısına düşmez ---

    public function test_var_dump_and_print_r_do_not_expose_password(): void
    {
        $db = $this->sqlite();

        ob_start();
        var_dump($db);
        $dump = (string) ob_get_clean();
        $printed = print_r($db, true);

        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringNotContainsString(self::SECRET, $printed);
        // Maske: alan görünür kalır, değer gizlenir
        $this->assertStringContainsString('********', $printed);
        $this->assertStringContainsString('app_user', $printed);
    }

    public function test_debug_info_masks_password_in_dsn(): void
    {
        $db = $this->sqlite();
        (function (): void {
            $this->dsn = 'pgsql:host=db;dbname=app;password=' . SecurityHardeningTest::secret() . ';sslmode=require';
        })->call($db);

        $info = $db->__debugInfo();

        $this->assertSame('pgsql:host=db;dbname=app;password=********;sslmode=require', $info['dsn']);
        $this->assertStringNotContainsString(self::SECRET, print_r($db, true));
    }

    public static function secret(): string
    {
        return self::SECRET;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sensitive_parameters(): array
    {
        return [
            'constructor pass' => ['__construct', 'pass'],
            'connect dsn' => ['connect', 'dsn'],
            'connect password' => ['connect', 'password'],
        ];
    }

    #[DataProvider('sensitive_parameters')]
    public function test_credential_parameters_are_marked_sensitive(string $method, string $parameter): void
    {
        foreach ((new \ReflectionMethod(Nsql::class, $method))->getParameters() as $candidate) {
            if ($candidate->getName() === $parameter) {
                $this->assertNotEmpty(
                    $candidate->getAttributes(\SensitiveParameter::class),
                    "{$method}(\${$parameter}) stack trace'te maskelenmeli"
                );

                return;
            }
        }

        $this->fail("{$method}(\${$parameter}) parametresi bulunamadı");
    }

    // --- D-err: handle_exception / safe_execute log'u sürücü mesajındaki veriyi maskeler ---

    public function test_handle_exception_logs_masked_driver_message(): void
    {
        $logger = new ArrayLogger();
        $db = $this->sqlite()->set_logger($logger);

        $db->handle_exception(new \PDOException("SQLSTATE[23000]: Duplicate entry 'ali@example.com' for key 'users.email'"));

        $this->assertCount(1, $logger->records);
        $this->assertStringNotContainsString('ali@example.com', $logger->records[0]['message']);
        $this->assertStringContainsString("Duplicate entry '***'", $logger->records[0]['message']);
        $this->assertStringContainsString("'users.email'", $logger->records[0]['message']);
    }

    public function test_safe_execute_logs_masked_driver_message(): void
    {
        $logger = new ArrayLogger();
        $db = $this->sqlite()->set_logger($logger)->set_throw_on_error(false);

        $result = $db->safe_execute(function (): never {
            throw new \PDOException("SQLSTATE[23000]: Duplicate entry 'ali@example.com' for key 'users.email'");
        });

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $messages = implode("\n", array_column($logger->records, 'message'));
        $this->assertStringNotContainsString('ali@example.com', $messages);
        $this->assertStringContainsString("Duplicate entry '***'", $messages);
        // Çağıran için orijinal hata korunur
        $this->assertStringContainsString('ali@example.com', (string) $db->get_last_exception()?->getMessage());
    }

    // --- D-id: `$` satır sonundan önce de eşleşir; tanımlayıcı sondaki "\n" ile geçmemeli ---

    /**
     * @return array<string, array{string}>
     */
    public static function identifiers_with_trailing_newline(): array
    {
        return [
            'tablo' => ["users\n"],
            'şema.tablo' => ["app.users\n"],
            'yalnızca rakam + satır sonu' => ["123\n"],
        ];
    }

    #[DataProvider('identifiers_with_trailing_newline')]
    public function test_identifier_with_trailing_newline_is_rejected(string $identifier): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sqlite()->quote_identifier($identifier);
    }

    public function test_valid_identifiers_still_quote(): void
    {
        $db = $this->sqlite();

        $this->assertSame('"users"', $db->quote_identifier('users'));
        $this->assertSame('"app"."users_2"', $db->quote_identifier('app.users_2'));
    }

    // --- D-cfg: production'da güvensiz kimlik bilgisi ---

    /**
     * @return array<string, array{string, string}>
     */
    public static function insecure_credentials(): array
    {
        return [
            'boş kullanıcı' => ['', 'x'],
            'boş kullanıcı ve parola' => ['', ''],
            'root + boş parola' => ['root', ''],
            'ROOT + boş parola' => ['Root', ''],
        ];
    }

    #[DataProvider('insecure_credentials')]
    public function test_production_rejects_insecure_credentials(string $user, string $pass): void
    {
        Config::set_environment('production');

        try {
            Config::assert_production_credentials($user, $pass);
            $this->fail('ConnectionException bekleniyordu');
        } catch (ConnectionException $e) {
            $this->assertSame(ErrorCodes::CONFIGURATION_ERROR, $e->getCode());
            $this->assertStringContainsString('DB_USER', $e->getMessage());
        }
    }

    public function test_production_accepts_real_credentials_and_passwordless_non_root(): void
    {
        Config::set_environment('production');

        Config::assert_production_credentials('app', 'gizli');
        Config::assert_production_credentials('root', 'gizli');
        // Parolasız kimlik doğrulama (PostgreSQL peer/trust, soket) geçerli bir kurulumdur
        Config::assert_production_credentials('app', '');

        $this->addToAssertionCount(3);
    }

    #[DataProvider('insecure_credentials')]
    public function test_non_production_keeps_accepting_any_credentials(string $user, string $pass): void
    {
        foreach (['development', 'testing', 'staging'] as $environment) {
            Config::set_environment($environment);
            Config::assert_production_credentials($user, $pass);
        }

        $this->addToAssertionCount(3);
    }

    public function test_insecure_credentials_can_be_allowed_explicitly(): void
    {
        Config::set_environment('production');
        Config::set('db_allow_insecure_credentials', true);

        Config::assert_production_credentials('root', '');

        $this->addToAssertionCount(1);
    }

    public function test_app_env_production_is_honoured_when_env_is_not_set(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_hardening_root_' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);

        try {
            putenv('ENV');
            putenv('APP_ENV=production');
            (function (): void {
                self::$environment = 'production';
                self::$environment_explicit = false;
            })->bindTo(null, Config::class)();
            Config::set_project_root($root);
            Config::refresh();

            $this->expectException(ConnectionException::class);
            Config::assert_production_credentials('root', '');
        } finally {
            @rmdir($root);
        }
    }

    /**
     * ENV hiç verilmediğinde ortam varsayılan olarak "production" sayılır; bu örtük durum
     * yerel geliştirme kurulumlarını (root + boş parola) kırmamalıdır.
     */
    public function test_implicit_production_default_does_not_reject_credentials(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_hardening_root_' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);

        try {
            putenv('ENV');
            putenv('APP_ENV');
            (function (): void {
                self::$environment = 'production';
                self::$environment_explicit = false;
            })->bindTo(null, Config::class)();
            Config::set_project_root($root);
            Config::refresh();

            $this->assertTrue(Config::is_production());
            Config::assert_production_credentials('root', '');
            $this->addToAssertionCount(1);
        } finally {
            @rmdir($root);
        }
    }

    public function test_constructor_enforces_production_credentials_except_sqlite(): void
    {
        Config::set_environment('production');

        // SQLite kimlik bilgisi kullanmaz
        $db = new Nsql(db: $this->path, user: 'root', pass: '', driver: 'sqlite');
        $this->connections[] = $db;
        $this->assertSame('sqlite', $db->get_driver_name());

        $this->expectException(ConnectionException::class);
        $this->expectExceptionCode(ErrorCodes::CONFIGURATION_ERROR);
        new Nsql(host: '127.0.0.1', db: 'app', user: 'root', pass: '', driver: 'mysql');
    }

    // --- D-sec: yanıltıcı adlı yardımcılar kullanımdan kaldırıldı olarak işaretli ---

    #[DataProvider('deprecated_helpers')]
    public function test_misleading_sql_helpers_are_marked_deprecated(string $method): void
    {
        $doc = (string) (new \ReflectionMethod(SecurityManager::class, $method))->getDocComment();

        $this->assertStringContainsString('@deprecated', $doc);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deprecated_helpers(): array
    {
        return [
            'sanitize_sql_params' => ['sanitize_sql_params'],
            'prepare_safe_query' => ['prepare_safe_query'],
        ];
    }
}
