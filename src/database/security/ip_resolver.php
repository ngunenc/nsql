<?php

namespace nsql\database\security;

/*
 * @deprecated 1.13.0 \nsql\security\ip_resolver kullanın; bu takma ad 2.0.0'da kaldırılacak.
 */
class_alias(\nsql\security\ip_resolver::class, __NAMESPACE__ . '\\ip_resolver');
