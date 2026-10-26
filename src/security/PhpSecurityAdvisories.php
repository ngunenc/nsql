<?php

namespace nsql\security;

/**
 * Çalışan PHP sürümünü bilinen PHP çekirdek açıklarına karşı denetler.
 *
 * Kütüphane PHP yorumlayıcısındaki açıkları kapatamaz; yalnızca yamasız sürümde çalıştığını
 * bildirebilir. Sürüm karşılaştırması sınırlıdır: Debian / Ubuntu / RHEL paketleri yamayı sürüm
 * numarasını değiştirmeden geri taşır (backport), bu yüzden "outdated" sonucu dağıtım paketinde
 * yanlış alarm olabilir. Kesin bilgi için dağıtımın güvenlik duyurusuna bakın.
 */
final class PhpSecurityAdvisories
{
    /** Listenin son güncellendiği PHP güvenlik sürümü tarihi */
    public const LAST_UPDATED = '2026-10-21';

    /**
     * Dal başına bilinen kritik / yüksek açıkların tamamının kapandığı en düşük sürüm.
     * PHP2Shell: CVE-2026-109618, CVE-2026-109619 (kimlik doğrulamasız RCE, CVSS 9.2).
     *
     * @var array<string, string>
     */
    public const MIN_SAFE_VERSIONS = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /**
     * CVE-2025-14180: emülasyonlu prepare açıkken PDO_pgsql quoting'de NULL pointer (DoS).
     *
     * @var array<string, string>
     */
    private const PDO_PGSQL_QUOTE_FIXED = [
        '8.1' => '8.1.34',
        '8.2' => '8.2.30',
        '8.3' => '8.3.29',
        '8.4' => '8.4.16',
        '8.5' => '8.5.1',
    ];

    /**
     * @return array{status: 'ok'|'outdated'|'unsupported', branch: string, minimum: string|null}
     *         unsupported: dal güvenlik desteği almıyor (8.1 ve öncesi; PHP2Shell yaması yok)
     */
    public static function status(string $version = PHP_VERSION): array
    {
        $version = self::numeric($version);
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        $known = array_keys(self::MIN_SAFE_VERSIONS);

        if (version_compare($branch, $known[0], '<')) {
            return ['status' => 'unsupported', 'branch' => $branch, 'minimum' => self::MIN_SAFE_VERSIONS[end($known)]];
        }
        $minimum = self::MIN_SAFE_VERSIONS[$branch] ?? null;
        if ($minimum === null) {
            // Listeden yeni dal: bu listenin yazıldığı tarihte yayımlanmamıştı
            return ['status' => 'ok', 'branch' => $branch, 'minimum' => null];
        }

        return [
            'status' => version_compare($version, $minimum, '<') ? 'outdated' : 'ok',
            'branch' => $branch,
            'minimum' => $minimum,
        ];
    }

    /**
     * PDO_pgsql emülasyonlu prepare açığı (CVE-2025-14180) bu sürümde kapalı mı?
     */
    public static function pdo_pgsql_quote_fixed(string $version = PHP_VERSION): bool
    {
        $version = self::numeric($version);
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        $fixed = self::PDO_PGSQL_QUOTE_FIXED[$branch] ?? null;
        if ($fixed === null) {
            return version_compare($branch, '8.1', '>');
        }

        return version_compare($version, $fixed, '>=');
    }

    /**
     * "8.3.6-1ubuntu1" / "8.4.0RC1" → "8.3.6" / "8.4.0"
     */
    private static function numeric(string $version): string
    {
        return preg_match('/^\d+\.\d+(?:\.\d+)?/', $version, $m) === 1 ? $m[0] : '0.0.0';
    }
}
