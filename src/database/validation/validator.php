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
class validator
{
    /**
     * Değeri validate eder
     * 
     * @param mixed $value Validasyon edilecek değer
     * @param array $rules Validation kuralları
     * @return bool
     * @throws InvalidArgumentException
     */
    public static function validate(mixed $value, array $rules): bool
    {
        foreach ($rules as $rule => $constraint) {
            if (!self::apply_rule($value, $rule, $constraint)) {
                throw new InvalidArgumentException("Validation failed for rule: $rule");
            }
        }
        
        return true;
    }

    /**
     * Validation kuralını uygular
     */
    private static function apply_rule(mixed $value, string $rule, mixed $constraint): bool
    {
        return match ($rule) {
            'required' => self::validate_required($value),
            'type' => self::validate_type($value, $constraint),
            'min' => self::validate_min($value, $constraint),
            'max' => self::validate_max($value, $constraint),
            'min_length' => self::validate_min_length($value, $constraint),
            'max_length' => self::validate_max_length($value, $constraint),
            'pattern' => self::validate_pattern($value, $constraint),
            'in' => self::validate_in($value, $constraint),
            'not_in' => self::validate_not_in($value, $constraint),
            'email' => self::validate_email($value),
            'url' => self::validate_url($value),
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
    private static function validate_url(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
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
        if (is_callable($constraint)) {
            return (bool)$constraint($value);
        }
        return true; // Bilinmeyen kural varsayılan olarak geçer
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
