<?php

/**
 * 1.x snake_case sınıf adlarını (ör. nsql\database\query_builder) 2.x PascalCase karşılıklarına bağlar.
 *
 * PHP sınıf adları büyük/küçük harf duyarsızdır; tek kelimelik adlar (nsql, config, model) için yalnızca
 * dosyanın bulunması yeterlidir, çok kelimelikler için class_alias tanımlanır. 3.0'da kaldırılacak.
 */

spl_autoload_register(static function (string $class): void {
    static $map = null;
    if ($map === null) {
        $map = array_change_key_case(require __DIR__ . '/legacy_class_map.php', CASE_LOWER);
    }

    $target = $map[strtolower(ltrim($class, '\\'))] ?? null;
    if ($target === null) {
        return;
    }

    if (! class_exists($target) && ! interface_exists($target) && ! trait_exists($target)) {
        return;
    }

    if (! class_exists($class, false) && ! interface_exists($class, false) && ! trait_exists($class, false)) {
        class_alias($target, $class);
    }
});
