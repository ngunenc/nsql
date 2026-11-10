<?php

namespace nsql\database\drivers;

/**
 * Eklenen kayıt kimliğini normalize eder: PHP int aralığındaki tam sayılar int, diğerleri
 * (unsigned bigint, UUID vb.) string olarak korunur; değer yoksa 0.
 */
final class InsertId
{
    public static function normalize(mixed $id): int|string
    {
        if (is_int($id)) {
            return $id;
        }
        if ($id === false || $id === null || $id === '') {
            return 0;
        }
        $id = (string) $id;

        if (preg_match('/^-?[1-9][0-9]*$|^0$/D', $id) !== 1) {
            return $id;
        }
        // Aralık dışı değerde (int) dönüşümü PHP 8.5'te uyarı üretir; filter_var sessizce false döner
        $int = filter_var($id, FILTER_VALIDATE_INT);

        return $int === false ? $id : $int;
    }
}
