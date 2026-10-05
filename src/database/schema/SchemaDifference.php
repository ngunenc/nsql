<?php

namespace nsql\database\schema;

/**
 * Şema tanımı ile canlı tablo arasındaki tek bir fark.
 */
final class SchemaDifference
{
    public const MISSING_TABLE = 'missing_table';
    public const MISSING_COLUMN = 'missing_column';
    public const EXTRA_COLUMN = 'extra_column';
    public const TYPE_MISMATCH = 'type_mismatch';
    public const NULLABLE_MISMATCH = 'nullable_mismatch';
    public const DEFAULT_MISMATCH = 'default_mismatch';
    public const LENGTH_MISMATCH = 'length_mismatch';
    public const PRECISION_MISMATCH = 'precision_mismatch';

    public const ERROR = 'error';
    public const WARNING = 'warning';

    public function __construct(
        public readonly string $kind,
        public readonly string $table,
        public readonly ?string $column,
        public readonly mixed $expected,
        public readonly mixed $actual,
        public readonly string $severity = self::ERROR
    ) {
    }

    public function is_error(): bool
    {
        return $this->severity === self::ERROR;
    }

    public function message(): string
    {
        $target = $this->column === null ? $this->table : "{$this->table}.{$this->column}";

        return match ($this->kind) {
            self::MISSING_TABLE => "{$target}: tablo yok",
            self::MISSING_COLUMN => "{$target}: kolon yok",
            self::EXTRA_COLUMN => "{$target}: şemada tanımlı olmayan kolon",
            default => sprintf(
                '%s: %s beklenen %s, mevcut %s',
                $target,
                substr($this->kind, 0, -strlen('_mismatch')),
                self::export($this->expected),
                self::export($this->actual)
            ),
        };
    }

    /**
     * @return array{kind: string, severity: string, table: string, column: string|null, expected: mixed, actual: mixed, message: string}
     */
    public function to_array(): array
    {
        return [
            'kind' => $this->kind,
            'severity' => $this->severity,
            'table' => $this->table,
            'column' => $this->column,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'message' => $this->message(),
        ];
    }

    private static function export(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
