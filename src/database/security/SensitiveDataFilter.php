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
