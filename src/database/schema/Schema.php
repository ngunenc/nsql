<?php

namespace nsql\database\schema;

/**
 * Beklenen veritabanı şeması (tablo tanımları kümesi). SchemaValidator ile canlı veritabanıyla karşılaştırılır.
 *
 *     $schema = new Schema();
 *     $schema->table('users', function (TableDefinition $t) {
 *         $t->integer('id');
 *         $t->string('email', 255);
 *     });
 */
final class Schema
{
    /** @var array<string, TableDefinition> küçük harfli tablo adı => tanım */
    private array $tables = [];

    /**
     * @param callable(TableDefinition): void $define
     */
    public function table(string $name, callable $define): TableDefinition
    {
        $key = strtolower($name);
        if (isset($this->tables[$key])) {
            throw new \InvalidArgumentException("Tablo iki kez tanımlandı: {$name}");
        }

        $table = new TableDefinition($name);
        $define($table);

        return $this->tables[$key] = $table;
    }

    /**
     * @return array<string, TableDefinition>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /**
     * Şema dosyasını yükler. Dosya bir Schema örneği veya Schema alan bir callable döndürmelidir.
     */
    public static function from_file(string $path): self
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Şema dosyası bulunamadı: {$path}");
        }

        $result = (static fn (string $file): mixed => require $file)($path);
        if ($result instanceof self) {
            return $result;
        }
        if (is_callable($result)) {
            $schema = new self();
            $result($schema);

            return $schema;
        }

        throw new \RuntimeException("Şema dosyası Schema örneği veya callable(Schema) döndürmeli: {$path}");
    }
}
