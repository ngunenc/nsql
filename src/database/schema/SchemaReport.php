<?php

namespace nsql\database\schema;

/**
 * SchemaValidator sonucu.
 */
final class SchemaReport
{
    /**
     * @param list<SchemaDifference> $differences
     * @param list<string> $tables Kontrol edilen tablolar
     */
    public function __construct(
        private array $differences,
        public readonly array $tables
    ) {
    }

    /**
     * Hata seviyesinde fark yoksa true (uyarılar geçerliliği bozmaz).
     */
    public function is_valid(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return list<SchemaDifference>
     */
    public function differences(): array
    {
        return $this->differences;
    }

    /**
     * @return list<SchemaDifference>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->differences, static fn (SchemaDifference $d): bool => $d->is_error()));
    }

    /**
     * @return list<SchemaDifference>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->differences, static fn (SchemaDifference $d): bool => ! $d->is_error()));
    }

    /**
     * @return array{valid: bool, tables: list<string>, differences: list<array<string, mixed>>}
     */
    public function to_array(): array
    {
        return [
            'valid' => $this->is_valid(),
            'tables' => $this->tables,
            'differences' => array_map(static fn (SchemaDifference $d): array => $d->to_array(), $this->differences),
        ];
    }

    /**
     * İnsan tarafından okunabilir rapor (CLI çıktısı).
     */
    public function format(): string
    {
        $lines = [sprintf('%d tablo kontrol edildi: %d hata, %d uyarı', count($this->tables), count($this->errors()), count($this->warnings()))];
        foreach ($this->differences as $difference) {
            $lines[] = sprintf('  [%s] %s', $difference->is_error() ? 'HATA' : 'UYARI', $difference->message());
        }

        return implode(PHP_EOL, $lines);
    }
}
