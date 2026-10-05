<?php

namespace nsql\database\schema;

/**
 * Beklenen tablo tanımı.
 *
 *     $t->integer('id');
 *     $t->string('email', 255);
 *     $t->boolean('active')->default(true);
 *     $t->datetime('created_at')->nullable();
 */
final class TableDefinition
{
    /** @var array<string, ColumnDefinition> küçük harfli kolon adı => tanım */
    private array $columns = [];

    public function __construct(public readonly string $name)
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Geçersiz tablo adı: {$name}");
        }
    }

    public function column(string $name, string $type, ?int $length = null, ?int $precision = null, ?int $scale = null): ColumnDefinition
    {
        $key = strtolower($name);
        if (isset($this->columns[$key])) {
            throw new \InvalidArgumentException("Kolon iki kez tanımlandı: {$this->name}.{$name}");
        }

        return $this->columns[$key] = new ColumnDefinition($name, $type, $length, $precision, $scale);
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->column($name, 'integer');
    }

    /**
     * @param int|null $length null = uzunluk karşılaştırılmaz
     */
    public function string(string $name, ?int $length = null): ColumnDefinition
    {
        return $this->column($name, 'string', $length);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->column($name, 'text');
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->column($name, 'boolean');
    }

    /**
     * @param int|null $precision null = precision/scale karşılaştırılmaz
     */
    public function decimal(string $name, ?int $precision = null, ?int $scale = null): ColumnDefinition
    {
        return $this->column($name, 'decimal', null, $precision, $scale);
    }

    public function float(string $name): ColumnDefinition
    {
        return $this->column($name, 'float');
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->column($name, 'date');
    }

    /**
     * DATETIME ve TIMESTAMP aynı aileye eşlenir.
     */
    public function datetime(string $name): ColumnDefinition
    {
        return $this->column($name, 'datetime');
    }

    public function time(string $name): ColumnDefinition
    {
        return $this->column($name, 'time');
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->column($name, 'json');
    }

    public function binary(string $name): ColumnDefinition
    {
        return $this->column($name, 'binary');
    }

    /**
     * @return array<string, ColumnDefinition>
     */
    public function columns(): array
    {
        return $this->columns;
    }
}
