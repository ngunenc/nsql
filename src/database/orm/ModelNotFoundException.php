<?php

namespace nsql\database\orm;

use nsql\database\exceptions\DatabaseException;

class ModelNotFoundException extends DatabaseException
{
    public function __construct(
        public readonly string $model,
        public readonly int|string $id
    ) {
        parent::__construct("{$model} bulunamadı: #{$id}", 0, null, ['model' => $model, 'id' => $id]);
    }
}
