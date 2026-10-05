<?php

namespace nsql\database\schema;

/**
 * Canlı veritabanından okunan kolon bilgisi.
 */
final class ColumnInfo
{
    /**
     * @param string $type Tip ailesi (ColumnDefinition::TYPES) veya tanınmayan tipler için ham tip adı
     * @param string|null $default Normalize edilmiş varsayılan değer (tırnak ve tip dönüşümü atılmış); null = yok/NULL
     */
    public function __construct(
        public readonly string $name,
        public readonly string $raw_type,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly ?string $default,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null
    ) {
    }
}
