<?php

namespace nsql\database;

/**
 * query_builder::raw() ile oluşturulan, doğrulanmadan SQL'e eklenen ifade.
 * Değerler `:ad` placeholder'ı ve $bindings ile bağlanmalıdır.
 */
final class raw_expression
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
