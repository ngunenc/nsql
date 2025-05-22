<?php

namespace Nsql\Database;

use PDO;
use PDOException;
use RuntimeException;

class ConnectionPool {
    private static array $pool = [];
    private static array $readPool = [];  // Sadece okuma için bağlantılar
    private static array $inUse = [];
    private static array $readInUse = []; // Okuma bağlantıları için kullanımda olanlar
    private static int $maxConnections = 10;
    private static int $minConnections = 2;
    private static int $connectionTimeout = 30;
    private static array $config = [];
    private static ?string $logFile = null;
    private static int $healthCheckInterval = 60; // saniye
    private static int $lastHealthCheck = 0;
    
    public static function initialize(array $config, int $min = 2, int $max = 10): void {
        self::$config = $config;
        self::$maxConnections = $max;
        self::$minConnections = $min;
        
        // Minimum bağlantıları oluştur
        for ($i = 0; $i < self::$minConnections; $i++) {
            self::addConnection();
        }
    }
    
    private static function addConnection(string $type = 'write'): ?PDO {
        if ($type === 'write' && count(self::$pool) + count(self::$inUse) >= self::$maxConnections) {
            return null;
        }

        if ($type === 'read' && count(self::$readPool) + count(self::$readInUse) >= self::$maxConnections) {
            return null;
        }
        
        try {
            $pdo = new PDO(
                self::$config['dsn'],
                self::$config['username'],
                self::$config['password'],
                self::$config['options']
            );
            
            $connection = [
                'pdo' => $pdo,
                'created_at' => time(),
                'last_used' => time()
            ];
            
            if ($type === 'write') {
                self::$pool[] = $connection;
            } else {
                self::$readPool[] = $connection;
            }
            
            return $pdo;
            
        } catch (PDOException $e) {
            throw new RuntimeException("Bağlantı havuzu bağlantı oluşturamadı: " . $e->getMessage());
        }
    }
    
    public static function getConnection(string $type = 'write'): PDO {
        $pool = $type === 'write' ? self::$pool : self::$readPool;
        $inUse = $type === 'write' ? self::$inUse : self::$readInUse;

        // Önce havuzdan kullanılabilir bağlantı ara
        foreach ($pool as $key => $connection) {
            // Bağlantının geçerliliğini kontrol et
            if (self::isConnectionValid($connection['pdo'])) {
                $connection['last_used'] = time();
                unset($pool[$key]);
                $inUse[] = $connection;
                return $connection['pdo'];
            }
        }
        
        // Kullanılabilir bağlantı yoksa ve limit aşılmamışsa yeni bağlantı oluştur
        $pdo = self::addConnection($type);
        if ($pdo) {
            return $pdo;
        }
        
        // Havuz doluysa en eski bağlantıyı bekle
        $startTime = time();
        while (time() - $startTime < self::$connectionTimeout) {
            usleep(100000); // 100ms bekle
            
            // Tekrar kontrol et
            foreach ($pool as $key => $connection) {
                if (self::isConnectionValid($connection['pdo'])) {
                    $connection['last_used'] = time();
                    unset($pool[$key]);
                    $inUse[] = $connection;
                    return $connection['pdo'];
                }
            }
        }
        
        throw new RuntimeException("Bağlantı havuzu zaman aşımına uğradı");
    }
    
    public static function releaseConnection(PDO $pdo): void {
        foreach (self::$inUse as $key => $connection) {
            if ($connection['pdo'] === $pdo) {
                $connection['last_used'] = time();
                self::$pool[] = $connection;
                unset(self::$inUse[$key]);
                return;
            }
        }

        foreach (self::$readInUse as $key => $connection) {
            if ($connection['pdo'] === $pdo) {
                $connection['last_used'] = time();
                self::$readPool[] = $connection;
                unset(self::$readInUse[$key]);
                return;
            }
        }
    }
    
    private static function isConnectionValid(PDO $pdo): bool {
        try {
            return $pdo->query('SELECT 1')->fetchColumn() === '1';
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public static function cleanup(): void {
        $now = time();
        
        // 30 dakikadan eski bağlantıları temizle
        foreach (self::$pool as $key => $connection) {
            if ($now - $connection['last_used'] > 1800) { // 30 dakika
                unset(self::$pool[$key]);
            }
        }

        foreach (self::$readPool as $key => $connection) {
            if ($now - $connection['last_used'] > 1800) { // 30 dakika
                unset(self::$readPool[$key]);
            }
        }
        
        // Minimum bağlantı sayısını koru
        self::maintainMinConnections();
    }
    
    public static function getStats(): array {
        return [
            'pool_size' => count(self::$pool),
            'in_use' => count(self::$inUse),
            'total' => count(self::$pool) + count(self::$inUse),
            'max' => self::$maxConnections
        ];
    }

    /**
     * Read-Write split için yazma bağlantısı al
     */
    public static function getMasterConnection(): PDO {
        return self::getConnection('write');
    }

    /**
     * Read-Write split için okuma bağlantısı al
     */
    public static function getSlaveConnection(): PDO {
        return self::getConnection('read');
    }

    /**
     * Bağlantı sağlığını kontrol et
     */
    public static function healthCheck(): array {
        $now = time();
        $results = [
            'healthy' => 0,
            'unhealthy' => 0,
            'total' => 0
        ];

        // Health check sıklığını kontrol et
        if ($now - self::$lastHealthCheck < self::$healthCheckInterval) {
            return $results;
        }

        self::$lastHealthCheck = $now;

        // Havuzdaki tüm bağlantıları kontrol et
        foreach (self::$pool as $key => $connection) {
            $results['total']++;
            if (!self::isConnectionValid($connection['pdo'])) {
                $results['unhealthy']++;
                unset(self::$pool[$key]);
                self::logConnectionError($connection);
            } else {
                $results['healthy']++;
            }
        }

        // Read pool kontrolü
        foreach (self::$readPool as $key => $connection) {
            $results['total']++;
            if (!self::isConnectionValid($connection['pdo'])) {
                $results['unhealthy']++;
                unset(self::$readPool[$key]);
                self::logConnectionError($connection);
            } else {
                $results['healthy']++;
            }
        }

        // Eksik bağlantıları tamamla
        self::maintainMinConnections();

        return $results;
    }

    /**
     * Bağlantı hatalarını logla
     */
    private static function logConnectionError(array $connection): void {
        if (self::$logFile === null) {
            return;
        }

        $message = sprintf(
            "[%s] Connection error: Created at %s, Last used %s\n",
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', $connection['created_at']),
            date('Y-m-d H:i:s', $connection['last_used'])
        );

        file_put_contents(self::$logFile, $message, FILE_APPEND);
    }

    /**
     * Minimum bağlantı sayısını koru
     */
    private static function maintainMinConnections(): void {
        $totalWrite = count(self::$pool) + count(self::$inUse);
        $totalRead = count(self::$readPool) + count(self::$readInUse);

        while ($totalWrite < self::$minConnections) {
            self::addConnection('write');
            $totalWrite++;
        }

        while ($totalRead < self::$minConnections) {
            self::addConnection('read');
            $totalRead++;
        }
    }

    /**
     * Gelişmiş istatistikler
     */
    public static function getDetailedStats(): array {
        $now = time();
        $stats = [
            'write_pool' => [
                'available' => count(self::$pool),
                'in_use' => count(self::$inUse),
                'total' => count(self::$pool) + count(self::$inUse)
            ],
            'read_pool' => [
                'available' => count(self::$readPool),
                'in_use' => count(self::$readInUse),
                'total' => count(self::$readPool) + count(self::$readInUse)
            ],
            'health' => [
                'last_check' => date('Y-m-d H:i:s', self::$lastHealthCheck),
                'next_check' => date('Y-m-d H:i:s', self::$lastHealthCheck + self::$healthCheckInterval)
            ],
            'limits' => [
                'max' => self::$maxConnections,
                'min' => self::$minConnections
            ]
        ];

        // Bağlantı yaşlarını hesapla
        $stats['connection_age'] = [
            'oldest' => null,
            'newest' => null,
            'average' => 0
        ];

        $ages = [];
        foreach ([self::$pool, self::$readPool] as $pool) {
            foreach ($pool as $conn) {
                $age = $now - $conn['created_at'];
                $ages[] = $age;
            }
        }

        if (!empty($ages)) {
            $stats['connection_age'] = [
                'oldest' => max($ages),
                'newest' => min($ages),
                'average' => array_sum($ages) / count($ages)
            ];
        }

        return $stats;
    }
}
