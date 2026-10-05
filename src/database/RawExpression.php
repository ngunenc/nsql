<?php

namespace nsql\database;

/**
 * QueryBuilder::raw() ile oluşturulan, doğrulanmadan SQL'e eklenen ifade.
 * Değerler `:ad` placeholder'ı ve $bindings ile bağlanmalıdır.
 */
final class RawExpression
{
    /**
     * @param array<string, mixed> $bindings
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings = []
    ) {
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
