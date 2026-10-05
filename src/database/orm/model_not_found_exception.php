<?php

namespace nsql\database\orm;

class model_not_found_exception extends \RuntimeException
{
    public function __construct(
        public readonly string $model,
        public readonly int|string $id
    ) {
        parent::__construct("{$model} bulunamadı: #{$id}");
    }
}
