<?php

namespace nsql\database\schema;

/**
 * Beklenen kolon tanımı. Tip bir aileyi ifade eder (integer, string, text, boolean, decimal, float,
 * date, datetime, time, json, binary); sürücüye özel tip adları SchemaInspector'da bu ailelere eşlenir.
 *
 * Varsayılan olarak kolon NOT NULL kabul edilir; varsayılan değer yalnızca default() çağrılmışsa karşılaştırılır.
 */
final class ColumnDefinition
{
    public const TYPES = ['integer', 'string', 'text', 'boolean', 'decimal', 'float', 'date', 'datetime', 'time', 'json', 'binary'];

    private bool $nullable = false;
    private bool $has_default = false;
    private mixed $default = null;

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null
    ) {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Geçersiz kolon adı: {$name}");
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Bilinmeyen kolon tipi: {$type}");
        }
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Beklenen varsayılan değer. null = varsayılan yok veya NULL. Sabit ifadeler için string verin
     * (ör. 'CURRENT_TIMESTAMP').
     */
    public function default(mixed $value): self
    {
        if (! is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('Varsayılan değer skaler veya null olmalı.');
        }
        $this->has_default = true;
        $this->default = $value;

        return $this;
    }

    public function is_nullable(): bool
    {
        return $this->nullable;
    }

    public function has_default(): bool
    {
        return $this->has_default;
    }

    public function get_default(): mixed
    {
        return $this->default;
    }
}
