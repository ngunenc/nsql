<?php

namespace nsql\database\security;

/*
 * @deprecated 1.13.0 \nsql\security\session_manager kullanın; bu takma ad 2.0.0'da kaldırılacak.
 */
class_alias(\nsql\security\session_manager::class, __NAMESPACE__ . '\\session_manager');
