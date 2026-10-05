<?php

namespace nsql\database\schema;

use nsql\database\Nsql;

/**
 * Schema tanımını canlı veritabanıyla karşılaştırır.
 *
 * Karşılaştırılanlar: tablo ve kolon varlığı, tip ailesi, nullable, (tanımlıysa) varsayılan değer,
 * string uzunluğu ve decimal precision/scale. Şemada olmayan kolonlar uyarıdır; $strict ile hata olur.
 * İndeksler, foreign key'ler ve auto increment karşılaştırılmaz.
 */
final class SchemaValidator
{
    /** Beklenen tipin kabul ettiği canlı tip aileleri (MariaDB JSON'u LONGTEXT olarak raporlar). */
    private const COMPATIBLE = [
        'json' => ['json', 'text'],
    ];

    private SchemaInspector $inspector;

    public function __construct(Nsql $db, private bool $strict = false)
    {
        $this->inspector = new SchemaInspector($db);
    }

    public function validate(Schema $schema): SchemaReport
    {
        $differences = [];
        $tables = [];
        foreach ($schema->tables() as $table) {
            $tables[] = $table->name;
            array_push($differences, ...$this->compare_table($table));
        }

        return new SchemaReport($differences, $tables);
    }

    /**
     * @return list<SchemaDifference>
     */
    private function compare_table(TableDefinition $table): array
    {
        $actual = $this->inspector->columns($table->name);
        if ($actual === null) {
            return [new SchemaDifference(SchemaDifference::MISSING_TABLE, $table->name, null, $table->name, null)];
        }

        $differences = [];
        foreach ($table->columns() as $key => $expected) {
            if (! isset($actual[$key])) {
                $differences[] = new SchemaDifference(SchemaDifference::MISSING_COLUMN, $table->name, $expected->name, $expected->type, null);

                continue;
            }
            array_push($differences, ...$this->compare_column($table->name, $expected, $actual[$key]));
        }

        foreach ($actual as $key => $column) {
            if (! isset($table->columns()[$key])) {
                $differences[] = new SchemaDifference(
                    SchemaDifference::EXTRA_COLUMN,
                    $table->name,
                    $column->name,
                    null,
                    $column->raw_type,
                    $this->strict ? SchemaDifference::ERROR : SchemaDifference::WARNING
                );
            }
        }

        return $differences;
    }

    /**
     * @return list<SchemaDifference>
     */
    private function compare_column(string $table, ColumnDefinition $expected, ColumnInfo $actual): array
    {
        $diff = static fn (string $kind, mixed $e, mixed $a): SchemaDifference => new SchemaDifference($kind, $table, $expected->name, $e, $a);
        $differences = [];

        if (! in_array($actual->type, self::COMPATIBLE[$expected->type] ?? [$expected->type], true)) {
            return [$diff(SchemaDifference::TYPE_MISMATCH, $expected->type, $actual->raw_type)];
        }

        if ($expected->is_nullable() !== $actual->nullable) {
            $differences[] = $diff(SchemaDifference::NULLABLE_MISMATCH, $expected->is_nullable(), $actual->nullable);
        }

        if ($expected->length !== null && $actual->length !== null && $expected->length !== $actual->length) {
            $differences[] = $diff(SchemaDifference::LENGTH_MISMATCH, $expected->length, $actual->length);
        }

        if (
            $expected->precision !== null
            && ($expected->precision !== $actual->precision || ($expected->scale ?? 0) !== ($actual->scale ?? 0))
        ) {
            $differences[] = $diff(
                SchemaDifference::PRECISION_MISMATCH,
                $expected->precision . ',' . ($expected->scale ?? 0),
                $actual->precision === null ? null : $actual->precision . ',' . ($actual->scale ?? 0)
            );
        }

        if ($expected->has_default() && ! self::defaults_match($expected->get_default(), $actual->default, $expected->type)) {
            $differences[] = $diff(SchemaDifference::DEFAULT_MISMATCH, $expected->get_default(), $actual->default);
        }

        return $differences;
    }

    /**
     * Varsayılan değerleri sürücü farklarını yok sayarak karşılaştırır
     * (true ↔ 1/'t', 0 ↔ '0.00', now() ↔ CURRENT_TIMESTAMP).
     */
    public static function defaults_match(mixed $expected, ?string $actual, string $type = ''): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === null && $actual === null;
        }

        if ($type === 'boolean' || is_bool($expected)) {
            $truthy = static fn (string $v): ?bool => match (strtolower(trim($v))) {
                '1', 'true', 't', 'yes', 'y', 'on' => true,
                '0', 'false', 'f', 'no', 'n', 'off' => false,
                default => null,
            };
            $e = is_bool($expected) ? $expected : $truthy((string) $expected);
            $a = $truthy($actual);
            if ($e !== null && $a !== null) {
                return $e === $a;
            }
        }

        $expected = (string) $expected;
        if (is_numeric($expected) && is_numeric($actual)) {
            return (float) $expected === (float) $actual;
        }

        return SchemaInspector::normalize_default($expected) === $actual;
    }
}
