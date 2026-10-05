<?php

namespace nsql\database\schema;

use nsql\database\Nsql;
use PDO;

/**
 * Canlı tablo yapısını okur: MySQL/MariaDB ve PostgreSQL'de information_schema, SQLite'ta PRAGMA table_info.
 *
 * Sorgular doğrudan PDO üzerinden çalışır: query cache ve okuma replica'sı devre dışıdır, böylece aynı süreçte
 * migration'dan hemen sonra güncel yapı görülür.
 */
final class SchemaInspector
{
    private const INTEGER_TYPES = [
        'int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint',
        'int2', 'int4', 'int8', 'serial', 'bigserial', 'smallserial',
    ];

    public function __construct(private Nsql $db)
    {
    }

    /**
     * @return array<string, ColumnInfo>|null küçük harfli kolon adı => bilgi; tablo yoksa null
     */
    public function columns(string $table): ?array
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Geçersiz tablo adı: {$table}");
        }

        $driver = $this->db->get_driver_name();
        $rows = match ($driver) {
            'pgsql' => $this->fetch(
                'SELECT column_name AS name, data_type AS data_type, udt_name AS column_type, is_nullable AS nullable,
                        column_default AS col_default, character_maximum_length AS max_length,
                        numeric_precision AS num_precision, numeric_scale AS num_scale
                 FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = :t
                 ORDER BY ordinal_position',
                ['t' => $table]
            ),
            'sqlite' => $this->sqlite_rows($table),
            default => $this->fetch(
                'SELECT COLUMN_NAME AS name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable,
                        COLUMN_DEFAULT AS col_default, CHARACTER_MAXIMUM_LENGTH AS max_length,
                        NUMERIC_PRECISION AS num_precision, NUMERIC_SCALE AS num_scale
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                 ORDER BY ORDINAL_POSITION',
                ['t' => $table]
            ),
        };

        if ($rows === []) {
            return null;
        }

        $columns = [];
        foreach ($rows as $row) {
            $info = $this->to_column_info($row, $driver);
            $columns[strtolower($info->name)] = $info;
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetch(string $sql, array $params = []): array
    {
        $this->db->ensure_connection();
        $pdo = $this->db->get_pdo();
        if ($pdo === null) {
            throw new \RuntimeException('Veritabanı bağlantısı yok.');
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return array_map(static fn (array $row): array => array_change_key_case($row, CASE_LOWER), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sqlite_rows(string $table): array
    {
        $rows = [];
        foreach ($this->fetch('PRAGMA table_info(' . $this->db->quote_identifier($table) . ')') as $row) {
            $declared = strtolower(trim((string) $row['type']));
            $base = trim((string) preg_replace('/\(.*$/', '', $declared));
            $args = preg_match('/\(\s*(\d+)\s*(?:,\s*(\d+)\s*)?\)/', $declared, $m) ? $m : [];
            $is_numeric = in_array($base, ['decimal', 'numeric'], true);
            $rows[] = [
                'name' => $row['name'],
                'data_type' => $base,
                'column_type' => $declared,
                'nullable' => ((int) $row['notnull'] === 1 || (int) $row['pk'] > 0) ? 'NO' : 'YES',
                'col_default' => $row['dflt_value'],
                'max_length' => ! $is_numeric && isset($args[1]) ? (int) $args[1] : null,
                'num_precision' => $is_numeric && isset($args[1]) ? (int) $args[1] : null,
                'num_scale' => $is_numeric && isset($args[2]) && $args[2] !== '' ? (int) $args[2] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function to_column_info(array $row, string $driver): ColumnInfo
    {
        $data_type = strtolower((string) $row['data_type']);
        $column_type = strtolower((string) ($row['column_type'] ?? $data_type));
        $type = self::type_family($data_type, $column_type, $driver);

        return new ColumnInfo(
            (string) $row['name'],
            $column_type !== '' ? $column_type : $data_type,
            $type,
            strtoupper((string) $row['nullable']) === 'YES',
            self::normalize_default($row['col_default'] === null ? null : (string) $row['col_default']),
            $type === 'string' && $row['max_length'] !== null ? (int) $row['max_length'] : null,
            $type === 'decimal' && $row['num_precision'] !== null ? (int) $row['num_precision'] : null,
            $type === 'decimal' && $row['num_scale'] !== null ? (int) $row['num_scale'] : null
        );
    }

    /**
     * Sürücüye özel tip adını tip ailesine eşler; tanınmayan tipler ham adıyla döner.
     */
    public static function type_family(string $data_type, string $column_type = '', string $driver = 'mysql'): string
    {
        $t = strtolower(trim($data_type));
        $full = strtolower(trim($column_type));

        if ($driver !== 'pgsql' && $driver !== 'sqlite' && $t === 'tinyint' && preg_match('/^tinyint\(1\)/', $full)) {
            return 'boolean';
        }

        return match (true) {
            in_array($t, ['bool', 'boolean'], true) => 'boolean',
            in_array($t, self::INTEGER_TYPES, true) => 'integer',
            in_array($t, ['varchar', 'char', 'character varying', 'character', 'nvarchar', 'nchar', 'varchar2', 'bpchar'], true) => 'string',
            in_array($t, ['text', 'tinytext', 'mediumtext', 'longtext', 'clob'], true) => 'text',
            in_array($t, ['decimal', 'numeric'], true) => 'decimal',
            in_array($t, ['float', 'double', 'real', 'double precision', 'float4', 'float8'], true) => 'float',
            $t === 'date' => 'date',
            in_array($t, ['datetime', 'timestamp', 'timestamp without time zone', 'timestamp with time zone', 'timestamptz'], true) => 'datetime',
            in_array($t, ['time', 'time without time zone', 'time with time zone', 'timetz'], true) => 'time',
            in_array($t, ['json', 'jsonb'], true) => 'json',
            in_array($t, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bytea'], true) => 'binary',
            default => $t,
        };
    }

    /**
     * Sürücülerin döndürdüğü varsayılan değer ifadesini karşılaştırılabilir hale getirir:
     * `'abc'::character varying` → `abc`, `'it''s'` → `it's`, `NULL` → null, `current_timestamp()` → `CURRENT_TIMESTAMP`.
     */
    public static function normalize_default(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $v = trim($value);
        $v = (string) preg_replace('/::[a-z_ ]+(\[\])?$/i', '', $v);
        if (preg_match('/^\((.*)\)$/s', $v, $m)) {
            $v = trim($m[1]);
        }
        if (strcasecmp($v, 'null') === 0) {
            return null;
        }
        if (preg_match("/^'(.*)'$/s", $v, $m)) {
            return str_replace("''", "'", $m[1]);
        }
        if (preg_match('/^(current_timestamp|now|localtimestamp)(\(\s*\d*\s*\))?$/i', $v)) {
            return 'CURRENT_TIMESTAMP';
        }

        return $v;
    }
}
