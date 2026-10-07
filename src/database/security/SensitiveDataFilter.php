<?php

namespace nsql\database\security;

use nsql\database\Config;

/**
 * Hassas verileri log / debug çıktısından önce maskeleyen tek kaynak.
 *
 * Anahtar adı (büyük/küçük harf duyarsız, baştaki `:` yok sayılır) listedeki bir ifadeyi
 * içeriyorsa değer `********` ile değiştirilir. Liste `SENSITIVE_KEYS` config'i ile
 * genişletilebilir (virgülle ayrılmış metin veya dizi).
 */
class SensitiveDataFilter
{
    public const MASK = '********';

    /** Placeholder'dan hemen önceki `kolon <op>` (IN listesindeki önceki placeholder'lar dahil) */
    private const COMPARED_COLUMN = '/[`"]?(\w+)[`"]?\s*(?:=|<>|!=|<=|>=|<|>|\bNOT\s+LIKE|\bLIKE|\bNOT\s+IN\s*\(|\bIN\s*\()(?:\s*(?:\?|:\w+)\s*,)*\s*$/i';

    /** @var list<string> */
    public const DEFAULT_KEYS = [
        'password',
        'passwd',
        'pass',
        'pwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'private_key',
        'access_key',
        'secret_key',
        'encryption_key',
        'authorization',
        'auth_',
        'credential',
        'cookie',
        'session',
        'credit_card',
        'card_number',
        'cvv',
        'iban',
        'ssn',
        'tc_kimlik',
    ];

    /** @var list<string> */
    private array $sensitive_fields;

    /**
     * @param list<string>|null $fields Null ise varsayılan + SENSITIVE_KEYS kullanılır
     */
    public function __construct(?array $fields = null)
    {
        $this->sensitive_fields = $fields !== null
            ? array_values(array_unique(array_map('strtolower', $fields)))
            : self::configured_keys();
    }

    /**
     * Varsayılan anahtarlar + SENSITIVE_KEYS config'i.
     *
     * @return list<string>
     */
    public static function configured_keys(): array
    {
        $keys = self::DEFAULT_KEYS;
        $extra = Config::get('sensitive_keys');
        if (is_string($extra) && trim($extra) !== '') {
            $extra = explode(',', $extra);
        }
        if (is_array($extra)) {
            foreach ($extra as $key) {
                if (is_string($key) && trim($key) !== '') {
                    $keys[] = strtolower(trim($key));
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Statik kısayol: diziyi varsayılan + yapılandırılmış anahtarlarla maskeler.
     */
    public static function mask_array(array $data): array
    {
        return (new self())->filter_array($data);
    }

    /**
     * Sorgu parametrelerini maskeler: isimli anahtarların yanı sıra, SQL'de hassas bir kolona
     * bağlanan placeholder'ların (`?`, `:ad`) değerleri de maskelenir. Kolon eşlemesi
     * `INSERT ... (kolonlar) VALUES (...)` ve `kolon <op> placeholder` (IN listeleri dahil) için yapılır.
     */
    public static function mask_params(string $sql, array $params): array
    {
        $filter = new self();
        $masked = $filter->filter_array($params);
        if ($params === []) {
            return $masked;
        }

        $positional = array_is_list($params);
        $index = 0;
        foreach (self::placeholder_columns($sql) as [$placeholder, $column]) {
            if ($placeholder === '?') {
                $key = $index++;
                if (! $positional) {
                    continue;
                }
            } else {
                $key = array_key_exists($placeholder, $masked) ? $placeholder : substr($placeholder, 1);
            }

            if ($column === null || ! array_key_exists($key, $masked) || ! $filter->is_sensitive($column)) {
                continue;
            }

            $masked[$key] = is_array($masked[$key]) && array_key_exists('value', $masked[$key])
                ? ['value' => self::MASK] + $masked[$key]
                : self::MASK;
        }

        return $masked;
    }

    /**
     * SQL'deki placeholder'ları sırasıyla ve bağlandıkları kolon adıyla döndürür (kolon bilinmiyorsa null).
     * String literal'ler ve yorumlar atlanır.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private static function placeholder_columns(string $sql): array
    {
        $masked_sql = (string) preg_replace_callback(
            '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.)*"|--[^\n]*|\/\*.*?\*\//s',
            static fn (array $m) => $m[0][0] === '"' ? $m[0] : str_repeat(' ', strlen($m[0])),
            $sql
        );

        $insert_columns = [];
        $values_start = null;
        if (preg_match('/\bINSERT\b.*?\bINTO\s+\S+?\s*\(([^)]*)\)\s*VALUES\s*/is', $masked_sql, $m, PREG_OFFSET_CAPTURE)) {
            $insert_columns = array_map(
                static fn (string $c) => trim($c, " \t\n\r`\"[]"),
                explode(',', $m[1][0])
            );
            $values_start = $m[0][1] + strlen($m[0][0]);
        }

        preg_match_all('/(?<![:\w])(\?|:[A-Za-z_]\w*)/', $masked_sql, $matches, PREG_OFFSET_CAPTURE);

        $result = [];
        foreach ($matches[1] as [$placeholder, $offset]) {
            $column = null;
            if ($values_start !== null && $offset >= $values_start) {
                $column = self::insert_value_column($masked_sql, $values_start, $offset, $insert_columns);
            }
            if ($column === null && preg_match(self::COMPARED_COLUMN, substr($masked_sql, 0, $offset), $cm)) {
                $column = $cm[1];
            }
            $result[] = [$placeholder, $column];
        }

        return $result;
    }

    /**
     * VALUES listesindeki konumdan kolon adını bulur; VALUES bölümünün dışındaysa null.
     *
     * @param list<string> $columns
     */
    private static function insert_value_column(string $sql, int $start, int $offset, array $columns): ?string
    {
        $depth = 0;
        $position = 0;
        for ($i = $start; $i < $offset; $i++) {
            $char = $sql[$i];
            if ($char === '(') {
                if ($depth === 0) {
                    $position = 0;
                }
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 1) {
                $position++;
            } elseif ($depth === 0 && $char !== ',' && ! ctype_space($char)) {
                return null;
            }
        }

        return $depth >= 1 ? ($columns[$position] ?? null) : null;
    }

    public function is_sensitive(int|string $key): bool
    {
        if (is_int($key)) {
            return false;
        }
        $normalized = strtolower(ltrim($key, ':'));
        foreach ($this->sensitive_fields as $field) {
            if (str_contains($normalized, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hassas verileri maskeler
     */
    public function filter(mixed $data): mixed
    {
        if (is_array($data)) {
            return $this->filter_array($data);
        }
        if (is_object($data)) {
            return $this->filter_object(clone $data);
        }

        return $data;
    }

    /**
     * Dizi içindeki hassas verileri maskeler
     */
    public function filter_array(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->is_sensitive($key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->filter_array($value);
            } elseif (is_object($value)) {
                $data[$key] = $this->filter_object(clone $value);
            }
        }

        return $data;
    }

    private function filter_object(object $data): object
    {
        foreach (get_object_vars($data) as $key => $value) {
            if ($this->is_sensitive($key)) {
                $data->$key = self::MASK;
            } elseif (is_array($value)) {
                $data->$key = $this->filter_array($value);
            } elseif (is_object($value)) {
                $data->$key = $this->filter_object(clone $value);
            }
        }

        return $data;
    }

    /**
     * Hassas alan listesine yeni alan ekler
     */
    public function add_sensitive_field(string $field_name): void
    {
        $field = strtolower($field_name);
        if (! in_array($field, $this->sensitive_fields, true)) {
            $this->sensitive_fields[] = $field;
        }
    }
}
