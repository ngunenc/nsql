<?php

namespace nsql\database\security;

class sensitive_data_filter
{
    private array $sensitive_fields = [
        'password',
        'credit_card',
        'ssn',
        'tc_kimlik',
        'email',
        'phone',
        'address',
    ];

    /**
     * Hassas verileri maskeler
     */
    public function filter(mixed $data): mixed
    {
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
    private function filter_array(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $this->sensitive_fields)) {
                $data[$key] = $this->mask($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->filter_array($value);
            } elseif (is_object($value)) {
                $data[$key] = $this->filter_object($value);
            }
        }

        return $data;
    }

    /**
     * Nesne içindeki hassas verileri maskeler
     */
    private function filter_object(object $data): object
    {
        $vars = get_object_vars($data);
        foreach ($vars as $key => $value) {
            if (in_array(strtolower($key), $this->sensitive_fields)) {
                $data->$key = $this->mask($value);
            } elseif (is_array($value)) {
                $data->$key = $this->filter_array($value);
            } elseif (is_object($value)) {
                $data->$key = $this->filter_object($value);
            }
        }

        return $data;
    }

    /**
     * Veriyi maskele
     */
    private function mask(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        $length = strlen((string)$value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        // İlk 2 ve son 2 karakteri göster, arasını maskele
        return substr((string)$value, 0, 2) . str_repeat('*', $length - 4) . substr((string)$value, -2);
    }

    /**
     * Hassas alan listesine yeni alan ekler
     */
    public function add_sensitive_field(string $field_name): void
    {
        if (! in_array(strtolower($field_name), $this->sensitive_fields)) {
            $this->sensitive_fields[] = strtolower($field_name);
        }
    }
}
