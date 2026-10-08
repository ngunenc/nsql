<?php

namespace nsql\database\orm;

use nsql\database\exceptions\DatabaseException;

/**
 * Optimistic locking: model yüklendikten sonra satır başka bir işlem tarafından değiştirildi
 * veya silindi (sürüm kolonu eşleşmedi).
 */
class StaleModelException extends DatabaseException
{
    public function __construct(
        public readonly string $model,
        public readonly int|string $id,
        public readonly mixed $expected_version
    ) {
        parent::__construct(
            "{$model} #{$id} başka bir işlem tarafından değiştirildi (beklenen sürüm: " . var_export($expected_version, true) . '); kaydı yeniden yükleyin.',
            0,
            null,
            ['model' => $model, 'id' => $id, 'expected_version' => $expected_version]
        );
    }
}
