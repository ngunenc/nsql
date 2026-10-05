<?php

namespace nsql\database\orm;

/*
 * @deprecated 1.13.1 ModelNotFoundException kullanın; bu takma ad 2.0.0'da kaldırılacak.
 */
class_alias(ModelNotFoundException::class, __NAMESPACE__ . '\\model_not_found_exception');
