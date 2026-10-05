<?php

namespace nsql\database\security;

/*
 * @deprecated 1.13.0 \nsql\security\audit_logger kullanın; bu takma ad 2.0.0'da kaldırılacak.
 */
class_alias(\nsql\security\audit_logger::class, __NAMESPACE__ . '\\audit_logger');
