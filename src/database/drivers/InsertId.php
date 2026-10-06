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

        return preg_match('/^-?[1-9][0-9]*$|^0$/', $id) === 1 && (string) (int) $id === $id ? (int) $id : $id;
    }
}
