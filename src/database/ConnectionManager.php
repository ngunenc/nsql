<?php

namespace nsql\database;

use InvalidArgumentException;

/**
 * Aynı süreçte birden fazla isimlendirilmiş veritabanı bağlantısını yönetir.
 *
 * Bağlantı ayarı iki yoldan gelir:
 * - ConnectionManager::add('reporting', ['host' => ..., 'db' => ..., ...])
 * - ortam değişkenleri: DB_REPORTING_HOST, DB_REPORTING_NAME, DB_REPORTING_USER, DB_REPORTING_PASS,
 *   DB_REPORTING_PORT, DB_REPORTING_DRIVER, DB_REPORTING_CHARSET (tanımlı olmayanlar DB_* değerlerinden gelir)
 *
 * 'default' bağlantısı ayar verilmediyse DB_* değerleriyle açılır. Her isim için tek nsql örneği
 * tutulur; transaction, hata durumu ve statement cache'i bağlantılar arasında paylaşılmaz.
 */
final class ConnectionManager
{
    public const DEFAULT = 'default';

    private const CONFIG_KEYS = ['host', 'db', 'user', 'pass', 'port', 'driver', 'charset', 'debug', 'read'];

    /** @var array<string, array<string, mixed>> */
    private static array $configs = [];

    /** @var array<string, Nsql> */
    private static array $connections = [];

    /**
     * Bağlantı ayarı ekler. Anahtarlar: host, db, user, pass, port, driver, charset, debug ve
     * read (replica ayarı, bkz. Nsql::set_read_replica). Açık bağlantı varsa kapatılır.
     *
     * @param array<string, mixed> $config
     */
    public static function add(string $name, array $config): void
    {
        $unknown = array_diff(array_keys($config), self::CONFIG_KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Bilinmeyen bağlantı ayarı: ' . implode(', ', $unknown));
        }

        self::purge($name);
        self::$configs[self::normalize($name)] = $config;
    }

    /**
     * Hazır bir nsql örneğini isimle kaydeder.
     */
    public static function set(string $name, Nsql $connection): void
    {
        self::$connections[self::normalize($name)] = $connection;
    }

    public static function get(string $name = self::DEFAULT): Nsql
    {
        $key = self::normalize($name);

        return self::$connections[$key] ??= self::create($key);
    }

    public static function has(string $name): bool
    {
        $key = self::normalize($name);

        return isset(self::$configs[$key]) || isset(self::$connections[$key])
            || $key === self::DEFAULT || Config::has('db_' . $key . '_host') || Config::has('db_' . $key . '_name');
    }

    /**
     * Açık bağlantı(lar)ı bırakır; ayarlar korunur. null = hepsi.
     */
    public static function purge(?string $name = null): void
    {
        $keys = $name === null ? array_keys(self::$connections) : [self::normalize($name)];
        foreach ($keys as $key) {
            unset(self::$connections[$key]);
        }
    }

    /**
     * Tüm bağlantıları ve ayarları sıfırlar (testler için).
     */
    public static function reset(): void
    {
        self::$connections = [];
        self::$configs = [];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::$connections);
    }

    private static function create(string $key): Nsql
    {
        $config = self::$configs[$key] ?? self::config_from_env($key);

        $connection = new Nsql(
            host: isset($config['host']) ? (string) $config['host'] : null,
            db: isset($config['db']) ? (string) $config['db'] : null,
            user: isset($config['user']) ? (string) $config['user'] : null,
            pass: isset($config['pass']) ? (string) $config['pass'] : null,
            charset: isset($config['charset']) ? (string) $config['charset'] : null,
            debug: isset($config['debug']) ? (bool) $config['debug'] : null,
            driver: isset($config['driver']) ? (string) $config['driver'] : null,
            port: isset($config['port']) ? (int) $config['port'] : null
        );

        if (array_key_exists('read', $config)) {
            $connection->set_read_replica(is_array($config['read']) ? $config['read'] : null);
        }

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private static function config_from_env(string $key): array
    {
        if ($key === self::DEFAULT) {
            return [];
        }

        $map = ['host' => 'HOST', 'db' => 'NAME', 'user' => 'USER', 'pass' => 'PASS', 'port' => 'PORT', 'driver' => 'DRIVER', 'charset' => 'CHARSET'];
        $config = [];
        foreach ($map as $option => $suffix) {
            $env_key = 'DB_' . strtoupper($key) . '_' . $suffix;
            if (Config::has($env_key)) {
                $config[$option] = Config::get($env_key);
            }
        }

        if ($config === []) {
            $prefix = 'DB_' . strtoupper($key);
            throw new InvalidArgumentException(
                "Tanımsız bağlantı: '{$key}'. connection_manager::add() ile ekleyin veya {$prefix}_HOST / {$prefix}_NAME tanımlayın."
            );
        }

        return $config;
    }

    private static function normalize(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name === '' || ! preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException("Geçersiz bağlantı adı: '{$name}'");
        }

        return $name;
    }
}
