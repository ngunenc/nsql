<?php

namespace nsql\database\cache;

use JsonException;

/**
 * Güvenli cache payload serileştirme.
 *
 * Yeni format: nsql:j1:{json}
 * Eski PHP serialize okuması yalnızca allowed_classes=false ile (object injection yok).
 */
class safe_serializer
{
    public const PREFIX = 'nsql:j1:';

    /**
     * Değeri güvenli JSON payload'a çevirir.
     *
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return self::PREFIX . json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * Payload'ı çözer. Bozuk / güvensiz içerikte null döner.
     */
    public static function decode(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, self::PREFIX)) {
            $json = substr($raw, strlen(self::PREFIX));

            try {
                return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                return null;
            }
        }

        // Legacy PHP serialize — sınıflar asla instantiate edilmez
        $value = unserialize($raw, ['allowed_classes' => false]);

        if ($value === false && $raw !== 'b:0;') {
            return null;
        }

        // Object / incomplete class → cache miss (yeniden yazılsın)
        if (is_object($value)) {
            return null;
        }

        if (is_array($value) && self::array_contains_object($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Ham string yeni güvenli formatta mı?
     */
    public static function is_safe_payload(string $raw): bool
    {
        return str_starts_with($raw, self::PREFIX);
    }

    /**
     * @param array<mixed> $data
     */
    private static function array_contains_object(array $data): bool
    {
        foreach ($data as $item) {
            if (is_object($item)) {
                return true;
            }
            if (is_array($item) && self::array_contains_object($item)) {
                return true;
            }
        }

        return false;
    }
}
