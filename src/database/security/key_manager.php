<?php

namespace nsql\database\security;

/*
 * @deprecated 1.13.0 \nsql\security\key_manager kullanın; bu takma ad 2.0.0'da kaldırılacak.
 */
class_alias(\nsql\security\key_manager::class, __NAMESPACE__ . '\\key_manager');
