<?php

namespace nsql\database\traits;

use PDO;
use InvalidArgumentException;

trait query_parameter_trait {
    /**
     * Parametre tipini belirler
     */
    private function determine_param_type($value): int {
        if (is_int($value)) return PDO::PARAM_INT;
        if (is_bool($value)) return PDO::PARAM_BOOL;
        if (is_null($value)) return PDO::PARAM_NULL;
        return PDO::PARAM_STR;
    }

    /**
     * Parametre tiplerini kontrol eder
     */
    private function validate_param_types(array $params): void {
        foreach ($params as $key => $param) {
            if (is_array($param) && isset($param['value'], $param['type'])) {
                if (!$this->is_valid_param_type($param['value'])) {
                    throw new InvalidArgumentException("Geçersiz parametre değeri: " . gettype($param['value']) . " (key: $key)");
                }
                continue;
            }

            if (!$this->is_valid_param_type($param)) {
                throw new InvalidArgumentException("Geçersiz parametre değeri: " . gettype($param) . " (key: $key)");
            }
        }
    }

    /**
     * Parametre tipinin geçerli olup olmadığını kontrol eder
     */
    private function is_valid_param_type($value): bool {
        return is_scalar($value) || is_null($value);
    }

    /**
     * Parametre adını normalize eder
     */
    private function normalizeParameterName(string $key): string {
        return is_int($key) ? (string)($key + 1) : (strpos($key, ':') === 0 ? $key : ":{$key}");
    }
}
