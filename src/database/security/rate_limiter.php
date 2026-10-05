<?php

namespace nsql\database\security;

/*
 * @deprecated 1.13.0 \nsql\security\rate_limiter kullanın; bu takma ad 2.0.0'da kaldırılacak.
 */
class_alias(\nsql\security\rate_limiter::class, __NAMESPACE__ . '\\rate_limiter');
