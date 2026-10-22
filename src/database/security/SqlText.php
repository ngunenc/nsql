<?php

namespace nsql\database\security;

/**
 * SQL metni üzerinde sözdizimsel ön işlem: literal / yorum ayıklama ve üst seviye (subquery dışı) metin.
 *
 * Tam bir SQL ayrıştırıcısı değildir; analizör ve sayfalama gibi "bu anahtar kelime sorgunun kendisinde mi,
 * yoksa bir string / yorum / alt sorgu içinde mi?" sorusunu yanıtlamak için kullanılır.
 */
final class SqlText
{
    /**
     * String literal'lerini `''` ile değiştirir ve yorumları kaldırır.
     *
     * @param bool $hash_comments `#` satır yorumu sayılsın mı (MySQL). PostgreSQL'de `#` bir operatördür.
     */
    public static function strip(string $sql, bool $hash_comments = true): string
    {
        $comment = '--[^\n]*|\/\*.*?\*\/' . ($hash_comments ? '|#[^\n]*' : '');

        return (string) preg_replace_callback(
            '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`|' . $comment . '/s',
            static fn (array $m): string => match ($m[0][0]) {
                "'" => "''",
                '"', '`' => $m[0],
                default => ' ',
            },
            $sql
        );
    }

    /**
     * Yalnızca string literal'lerini `''` ile değiştirir; yorumlar kalır.
     */
    public static function strip_literals(string $sql): string
    {
        return (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'/s', "''", $sql);
    }

    /**
     * strip() sonrası parantez içleri (alt sorgu, fonksiyon argümanı) boşlukla değiştirilmiş metin.
     */
    public static function top_level(string $sql, bool $hash_comments = true): string
    {
        $stripped = self::strip($sql, $hash_comments);
        do {
            $stripped = (string) preg_replace('/\([^()]*\)/', ' ', $stripped, -1, $count);
        } while ($count > 0);

        return $stripped;
    }
}
