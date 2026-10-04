<?php

namespace nsql\database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Süreç içi (per-process) bağlantı havuzu.
 *
 * - Her DSN + kullanıcı + seçenek kombinasyonu ayrı bir havuz anahtarı alır; farklı
 *   veritabanlarına bağlanan `nsql` örnekleri birbirinin bağlantısını almaz.
 * - State PHP sürecine aittir (PHP-FPM worker'ları arasında paylaşılmaz); bu nedenle
 *   süreçler arası dosya kilidi kullanılmaz.
 * - Bağlantılar ihtiyaç anında açılır; kullanımdaki bir bağlantı havuz tarafından asla kapatılmaz.
 */
class connection_pool
{
    /**
     * @var array<string, array{
     *     config: array{dsn: string, username: string, password: string, options: array<int|string, mixed>},
     *     min: int,
     *     max: int,
     *     connections: array<int, PDO>,
     *     in_use: array<int, true>,
     *     idle_since: array<int, int>
     * }>
     */
    private static array $pools = [];

    private static ?string $default_key = null;

    /** @var array<int, string> spl_object_id(PDO) => havuz anahtarı */
    private static array $owners = [];

    /** @var array<string, int> */
    private static array $stats = [
        'created_connections' => 0,
        'closed_connections' => 0,
        'discarded_connections' => 0,
        'connection_errors' => 0,
        'health_checks' => 0,
        'failed_health_checks' => 0,
        'peak_connections' => 0,
    ];

    /**
     * Havuzu kaydeder (bağlantı açmaz) ve havuz anahtarını döndürür.
     *
     * Aynı yapılandırma ile tekrar çağrılması mevcut havuzu döndürür.
     *
     * @param int|null $min_connections Boşta tutulacak minimum bağlantı sayısı (önceden bağlantı açılmaz)
     */
    public static function initialize(array $config, ?int $min_connections = null, ?int $max_connections = null): string
    {
        self::validate_configuration($config);

        $key = self::pool_key($config);

        if (! isset(self::$pools[$key])) {
            $max = max(1, $max_connections ?? (int) config::get('max_connections', config::max_connections));
            $min = max(0, min($max, $min_connections ?? (int) config::get('min_connections', config::min_connections)));

            self::$pools[$key] = [
                'config' => [
                    'dsn' => (string) $config['dsn'],
                    'username' => (string) $config['username'],
                    'password' => (string) $config['password'],
                    'options' => (array) $config['options'],
                ],
                'min' => $min,
                'max' => $max,
                'connections' => [],
                'in_use' => [],
                'idle_since' => [],
            ];
        }

        self::$default_key ??= $key;

        return $key;
    }

    /**
     * Havuzdan bir bağlantı alır; boşta geçerli bağlantı yoksa yenisini açar.
     *
     * @param string|null $pool_key initialize() dönüşü; null ise ilk kaydedilen havuz
     * @throws RuntimeException Havuz kayıtlı değilse, havuz doluysa veya bağlantı açılamazsa
     */
    public static function get_connection(?string $pool_key = null): PDO
    {
        $key = self::resolve_key($pool_key);

        self::prune_idle($key);

        foreach (self::$pools[$key]['connections'] as $id => $conn) {
            if (isset(self::$pools[$key]['in_use'][$id])) {
                continue;
            }

            $idle_since = self::$pools[$key]['idle_since'][$id] ?? 0;
            if (self::needs_health_check($idle_since) && ! self::is_connection_valid($conn)) {
                self::$stats['failed_health_checks']++;
                self::remove($key, $id);

                continue;
            }

            self::$pools[$key]['in_use'][$id] = true;
            unset(self::$pools[$key]['idle_since'][$id]);

            return $conn;
        }

        $total = count(self::$pools[$key]['connections']);
        if ($total >= self::$pools[$key]['max']) {
            throw new RuntimeException(
                'Kullanılabilir bağlantı yok (havuz dolu). Aktif: ' . count(self::$pools[$key]['in_use']) .
                ', Maksimum: ' . self::$pools[$key]['max']
            );
        }

        return self::create_connection($key);
    }

    /**
     * Bağlantıyı havuza geri bırakır. Açık transaction kalmışsa geri alınır.
     */
    public static function release_connection(PDO $connection): void
    {
        $id = spl_object_id($connection);
        $key = self::$owners[$id] ?? null;

        if ($key === null || ! isset(self::$pools[$key]['in_use'][$id])) {
            return;
        }

        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        } catch (PDOException $e) {
            self::$stats['discarded_connections']++;
            self::remove($key, $id);

            return;
        }

        unset(self::$pools[$key]['in_use'][$id]);
        self::$pools[$key]['idle_since'][$id] = time();
    }

    /**
     * Bozuk/kopmuş bir bağlantıyı havuzdan tamamen çıkarır (tekrar dağıtılmaz).
     */
    public static function discard_connection(PDO $connection): void
    {
        $id = spl_object_id($connection);
        $key = self::$owners[$id] ?? null;

        if ($key === null) {
            return;
        }

        self::$stats['discarded_connections']++;
        self::remove($key, $id);
    }

    /**
     * Tüm havuzlardaki bağlantı referanslarını bırakır. Havuz kayıtları korunur.
     */
    public static function close_all(): void
    {
        foreach (self::$pools as $key => $pool) {
            foreach (array_keys($pool['connections']) as $id) {
                self::remove($key, $id);
            }
        }
    }

    /**
     * Havuz istatistiklerini döndürür.
     *
     * @param string|null $pool_key Belirtilirse yalnızca o havuz; null ise tüm havuzların toplamı
     */
    public static function get_stats(?string $pool_key = null): array
    {
        $keys = $pool_key === null ? array_keys(self::$pools) : [$pool_key];

        $total = 0;
        $active = 0;
        $max = 0;
        $pools = [];

        foreach ($keys as $key) {
            if (! isset(self::$pools[$key])) {
                continue;
            }

            $pool = self::$pools[$key];
            $pool_total = count($pool['connections']);
            $pool_active = count($pool['in_use']);

            $total += $pool_total;
            $active += $pool_active;
            $max += $pool['max'];

            $pools[substr($key, 0, 12)] = [
                'total_connections' => $pool_total,
                'active_connections' => $pool_active,
                'idle_connections' => $pool_total - $pool_active,
                'min_connections' => $pool['min'],
                'max_connections' => $pool['max'],
            ];
        }

        return array_merge(self::$stats, [
            'pool_count' => count($pools),
            'total_connections' => $total,
            'active_connections' => $active,
            'idle_connections' => $total - $active,
            'max_connections' => $max,
            'pools' => $pools,
        ]);
    }

    private static function pool_key(array $config): string
    {
        $options = (array) $config['options'];
        ksort($options);

        return hash('sha256', implode("\0", [
            (string) $config['dsn'],
            (string) $config['username'],
            (string) $config['password'],
            serialize($options),
        ]));
    }

    private static function resolve_key(?string $pool_key): string
    {
        $key = $pool_key ?? self::$default_key;

        if ($key === null || ! isset(self::$pools[$key])) {
            throw new RuntimeException('Connection pool başlatılmamış');
        }

        return $key;
    }

    private static function create_connection(string $key): PDO
    {
        $config = self::$pools[$key]['config'];

        try {
            $conn = new PDO(
                $config['dsn'],
                $config['username'],
                $config['password'],
                self::build_options($config['options'])
            );
        } catch (PDOException $e) {
            self::$stats['connection_errors']++;

            throw new RuntimeException('Veritabanı bağlantısı oluşturulamadı: ' . $e->getMessage(), 0, $e);
        }

        if ($conn->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        $id = spl_object_id($conn);
        self::$pools[$key]['connections'][$id] = $conn;
        self::$pools[$key]['in_use'][$id] = true;
        self::$owners[$id] = $key;

        self::$stats['created_connections']++;
        self::$stats['peak_connections'] = max(self::$stats['peak_connections'], self::count_all());

        return $conn;
    }

    /**
     * @param array<int|string, mixed> $options
     * @return array<int, mixed>
     */
    private static function build_options(array $options): array
    {
        $final = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => 0,
            PDO::ATTR_TIMEOUT => (int) config::get('connection_timeout', config::connection_timeout),
            PDO::ATTR_PERSISTENT => (int) (bool) config::get('persistent_connection', config::persistent_connection),
        ];

        foreach ($options as $key => $value) {
            if (is_string($key)) {
                if (str_starts_with($key, 'ATTR_') && defined('\\PDO::' . $key)) {
                    $final[constant('\\PDO::' . $key)] = $value;
                }

                continue;
            }

            $final[$key] = $value;
        }

        return $final;
    }

    /**
     * Boşta kalma süresini aşan bağlantıları (minimum boşta sayısını koruyarak) kapatır.
     * Kullanımdaki bağlantılara dokunmaz.
     */
    private static function prune_idle(string $key): void
    {
        $idle_timeout = (int) config::get('connection_idle_timeout', config::connection_idle_timeout);
        $now = time();
        $idle_count = count(self::$pools[$key]['idle_since']);

        foreach (self::$pools[$key]['idle_since'] as $id => $since) {
            if ($idle_count <= self::$pools[$key]['min']) {
                return;
            }

            if (($now - $since) > $idle_timeout) {
                self::remove($key, $id);
                $idle_count--;
            }
        }
    }

    private static function needs_health_check(int $idle_since): bool
    {
        $interval = (int) config::get('health_check_interval', config::health_check_interval);

        return (time() - $idle_since) >= $interval;
    }

    private static function is_connection_valid(PDO $connection): bool
    {
        self::$stats['health_checks']++;

        try {
            return @$connection->query('SELECT 1') !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    private static function remove(string $key, int $id): void
    {
        if (! isset(self::$pools[$key]['connections'][$id])) {
            return;
        }

        unset(
            self::$pools[$key]['connections'][$id],
            self::$pools[$key]['in_use'][$id],
            self::$pools[$key]['idle_since'][$id],
            self::$owners[$id]
        );

        self::$stats['closed_connections']++;
    }

    private static function count_all(): int
    {
        $total = 0;
        foreach (self::$pools as $pool) {
            $total += count($pool['connections']);
        }

        return $total;
    }

    private static function validate_configuration(array $config): void
    {
        foreach (['dsn', 'username', 'password', 'options'] as $key) {
            if (! array_key_exists($key, $config)) {
                throw new \InvalidArgumentException("Eksik yapılandırma parametresi: $key");
            }
        }
    }
}
