<?php

namespace nsql\database\security;

class sensitive_data_filter {
    private array $sensitiveFields = [
        'password',
        'credit_card',
        'ssn',
        'tc_kimlik',
        'email',
        'phone',
        'address'
    ];

    /**
     * Hassas verileri maskeler
     */
    public function filter($data) {
        if (is_array($data)) {
            return $this->filterArray($data);
        } elseif (is_object($data)) {
            return $this->filterObject($data);
        }
        return $data;
    }

    /**
     * Dizi içindeki hassas verileri maskeler
     */
    private function filterArray(array $data): array {
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $this->sensitiveFields)) {
                $data[$key] = $this->mask($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->filterArray($value);
            } elseif (is_object($value)) {
                $data[$key] = $this->filterObject($value);
            }
        }
        return $data;
    }

    /**
     * Nesne içindeki hassas verileri maskeler
     */
    private function filterObject(object $data): object {
        $vars = get_object_vars($data);
        foreach ($vars as $key => $value) {
            if (in_array(strtolower($key), $this->sensitiveFields)) {
                $data->$key = $this->mask($value);
            } elseif (is_array($value)) {
                $data->$key = $this->filterArray($value);
            } elseif (is_object($value)) {
                $data->$key = $this->filterObject($value);
            }
        }
        return $data;
    }

    /**
     * Veriyi maskele
     */
    private function mask($value): string {
        if (empty($value)) {
            return '';
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        // İlk 2 ve son 2 karakteri göster, arasını maskele
        return substr($value, 0, 2) . str_repeat('*', $length - 4) . substr($value, -2);
    }

    /**
     * Hassas alan listesine yeni alan ekler
     */
    public function addSensitiveField(string $fieldName): void {
        if (!in_array(strtolower($fieldName), $this->sensitiveFields)) {
            $this->sensitiveFields[] = strtolower($fieldName);
        }
    }
}
