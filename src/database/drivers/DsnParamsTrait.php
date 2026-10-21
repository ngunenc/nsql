<?php

namespace nsql\database\drivers;

/**
 * MySQL / PostgreSQL DSN'i için ortak ayrıştırma ve birleştirme.
 *
 * Sürücünün tanımadığı parametreler (`sslmode`, `sslrootcert`, `application_name` …) atılmaz;
 * `params` olarak döner ve build_dsn() ile DSN'e geri eklenir. Değerler `;` içeremez: PDO DSN'inde
 * kaçış yoktur, `;` yeni bir parametre başlatır (ör. kiracı adından `;sslmode=disable` enjeksiyonu).
 */
trait DsnParamsTrait
{
    /**
     * `prefix:` sonrası `anahtar=değer;…` çiftleri; anahtarlar küçük harfe çevrilir.
     *
     * @return array<string, string>
     */
    private static function parse_pairs(string $body): array
    {
        $parts = [];
        foreach (explode(';', $body) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (trim($key) !== '') {
                $parts[strtolower(trim($key))] = trim($value);
            }
        }

        return $parts;
    }

    /**
     * `host:port` yazımını ayırır.
     *
     * @return array{0: string, 1: string}
     */
    private static function split_host_port(string $host, string $port): array
    {
        if ($port === '' && preg_match('/^([^:]+):(\d+)$/', $host, $m)) {
            return [$m[1], $m[2]];
        }

        return [$host, $port];
    }

    /**
     * DSN'e eklenecek değeri doğrular.
     *
     * @throws \InvalidArgumentException Değer `;` içeriyorsa
     */
    private static function dsn_value(string $name, mixed $value): string
    {
        $value = (string) $value;
        if (str_contains($value, ';')) {
            throw new \InvalidArgumentException("DSN değeri ';' içeremez: {$name}");
        }

        return $value;
    }

    /**
     * Ek parametreleri `;anahtar=değer` olarak döndürür.
     *
     * @param array<string, mixed> $params
     */
    private static function dsn_params(array $params): string
    {
        $dsn = '';
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key)) {
                throw new \InvalidArgumentException('Geçersiz DSN parametre adı: ' . substr((string) $key, 0, 64));
            }
            $dsn .= ';' . $key . '=' . self::dsn_value((string) $key, is_bool($value) ? ($value ? '1' : '0') : $value);
        }

        return $dsn;
    }
}
