<?php

namespace nsql\database\validation;

use InvalidArgumentException;

/**
 * Validator - Input Validation Sistemi
 *
 * Özellikler:
 * - Validation rules
 * - Custom validators
 * - Type validation
 * - Range validation
 * - Pattern validation
 */
class Validator
{
    /**
     * Değeri validate eder
     *
     * @param mixed $value Validasyon edilecek değer
     * @param array $rules Validation kuralları
     * @return bool
     * @throws InvalidArgumentException Değer bir kurala uymuyorsa
     * @throws \LogicException Kural bilinmiyorsa (yazım hatası) veya kural listesi geçersizse
     */
    public static function validate(mixed $value, array $rules): bool
    {
        $rules = self::normalize_rules($rules);

        // nullable: boş değerde diğer kurallar uygulanmaz (required ayrıca verilmediyse)
        if (array_key_exists('nullable', $rules)) {
            $nullable = (bool) $rules['nullable'];
            unset($rules['nullable']);
            if ($nullable && ($value === null || $value === '') && empty($rules['required'])) {
                return true;
            }
        }

        foreach ($rules as $rule => $constraint) {
            if (!self::apply_rule($value, $rule, $constraint)) {
                throw new InvalidArgumentException("Validation failed for rule: $rule");
            }
        }

        return true;
    }

    /**
     * Kuralları `kural => kısıt` biçimine çevirir: liste öğesi `'email'` → `['email' => true]`,
     * `'min:18'` → `['min' => '18']`, `'in:a,b'` → `['in' => ['a', 'b']]`.
     *
     * @param array<int|string, mixed> $rules
     * @return array<string, mixed>
     */
    private static function normalize_rules(array $rules): array
    {
        $normalized = [];
        foreach ($rules as $rule => $constraint) {
            if (is_string($rule)) {
                $normalized[$rule] = $constraint;
                continue;
            }

            // Liste öğesi: closure/nesne özel kural olamaz (adı yok)
            if (! is_string($constraint) || trim($constraint) === '') {
                throw new \LogicException('Doğrulama kuralı adı string olmalıdır (liste öğesi: ' . get_debug_type($constraint) . ')');
            }

            [$name, $argument] = array_pad(explode(':', trim($constraint), 2), 2, null);
            $normalized[$name] = match (true) {
                $argument === null => true,
                in_array($name, ['in', 'not_in'], true) => explode(',', $argument),
                default => $argument,
            };
        }

        return $normalized;
    }

    /**
     * Validation kuralını uygular
     */
    private static function apply_rule(mixed $value, string $rule, mixed $constraint): bool
    {
        return match ($rule) {
            'required' => $constraint === false || self::validate_required($value),
            'type' => self::validate_type($value, $constraint),
            'min' => self::validate_min($value, $constraint),
            'max' => self::validate_max($value, $constraint),
            'min_length' => self::validate_min_length($value, $constraint),
            'max_length' => self::validate_max_length($value, $constraint),
            'pattern' => self::validate_pattern($value, $constraint),
            'in' => self::validate_in($value, $constraint),
            'not_in' => self::validate_not_in($value, $constraint),
            'email' => self::validate_email($value),
            'url' => self::validate_url($value, $constraint),
            'numeric' => self::validate_numeric($value),
            'integer' => self::validate_integer($value),
            'float' => self::validate_float($value),
            'boolean' => self::validate_boolean($value),
            'array' => self::validate_array($value),
            'string' => self::validate_string($value),
            default => self::validate_custom($value, $rule, $constraint),
        };
    }

    /**
     * Required validation
     */
    private static function validate_required(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * Type validation
     */
    private static function validate_type(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float' => is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'numeric' => is_numeric($value),
            default => false,
        };
    }

    /**
     * Min value validation
     */
    private static function validate_min(mixed $value, mixed $min): bool
    {
        if (!is_numeric($value) || !is_numeric($min)) {
            return false;
        }
        return (float)$value >= (float)$min;
    }

    /**
     * Max value validation
     */
    private static function validate_max(mixed $value, mixed $max): bool
    {
        if (!is_numeric($value) || !is_numeric($max)) {
            return false;
        }
        return (float)$value <= (float)$max;
    }

    /**
     * Min length validation
     */
    private static function validate_min_length(mixed $value, int $min): bool
    {
        if (is_string($value)) {
            return mb_strlen($value, 'UTF-8') >= $min;
        }
        if (is_array($value)) {
            return count($value) >= $min;
        }
        return false;
    }

    /**
     * Max length validation
     */
    private static function validate_max_length(mixed $value, int $max): bool
    {
        if (is_string($value)) {
            return mb_strlen($value, 'UTF-8') <= $max;
        }
        if (is_array($value)) {
            return count($value) <= $max;
        }
        return false;
    }

    /**
     * Pattern validation (regex)
     */
    private static function validate_pattern(mixed $value, string $pattern): bool
    {
        if (!is_string($value)) {
            return false;
        }
        return (bool)preg_match($pattern, $value);
    }

    /**
     * In array validation
     */
    private static function validate_in(mixed $value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }

    /**
     * Not in array validation
     */
    private static function validate_not_in(mixed $value, array $disallowed): bool
    {
        return !in_array($value, $disallowed, true);
    }

    /**
     * Email validation
     */
    private static function validate_email(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * URL validation
     */
    private static function validate_url(mixed $value, mixed $schemes = true): bool
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // Varsayılan http/https: `javascript://…%0A…` gibi değerler FILTER_VALIDATE_URL'den geçer (XSS)
        $allowed = is_array($schemes) ? $schemes : ['http', 'https'];
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, array_map(static fn ($s) => strtolower((string) $s), $allowed), true);
    }

    /**
     * Numeric validation
     */
    private static function validate_numeric(mixed $value): bool
    {
        return is_numeric($value);
    }

    /**
     * Integer validation
     */
    private static function validate_integer(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    /**
     * Float validation
     */
    private static function validate_float(mixed $value): bool
    {
        return is_float($value) || (is_numeric($value) && strpos((string)$value, '.') !== false);
    }

    /**
     * Boolean validation
     */
    private static function validate_boolean(mixed $value): bool
    {
        return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false', 'yes', 'no'], true);
    }

    /**
     * Array validation
     */
    private static function validate_array(mixed $value): bool
    {
        return is_array($value);
    }

    /**
     * String validation
     */
    private static function validate_string(mixed $value): bool
    {
        return is_string($value);
    }

    /**
     * Custom validator (callable)
     */
    private static function validate_custom(mixed $value, string $rule, mixed $constraint): bool
    {
        // Yalnızca closure / invokable nesne: `'format' => 'strlen'` gibi string callable'lar çağrılmaz
        if (is_object($constraint) && is_callable($constraint)) {
            return (bool)$constraint($value);
        }

        // Yazım hatası veya desteklenmeyen kural sessizce geçmez. LogicException: validate_many()
        // bunu alan hatası olarak yutmaz (InvalidArgumentException onun alt sınıfıdır, yakalanır)
        throw new \LogicException("Bilinmeyen doğrulama kuralı: {$rule}");
    }

    /**
     * Birden fazla değeri validate eder
     */
    public static function validate_many(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $field_rules) {
            $value = $data[$field] ?? null;

            try {
                self::validate($value, $field_rules);
            } catch (InvalidArgumentException $e) {
                $errors[$field] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * SQL identifier validation (tablo, sütun adları)
     */
    public static function validate_sql_identifier(string $identifier): bool
    {
        // SQL identifier pattern: harf/rakam/underscore, maksimum 64 karakter
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $identifier) === 1;
    }

    /**
     * SQL parametre validation
     */
    public static function validate_sql_param(mixed $param): bool
    {
        // Array ve object'leri reddet (SQL injection riski)
        if (is_array($param) || is_object($param)) {
            return false;
        }

        // Resource'ları reddet
        if (is_resource($param)) {
            return false;
        }

        return true;
    }
}
