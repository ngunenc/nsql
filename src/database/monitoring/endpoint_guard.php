<?php

namespace nsql\database\monitoring;

/**
 * Monitoring endpoint koruması (health / metrics).
 *
 * Auth: Authorization Bearer veya X-NSQL-Monitoring-Token başlığı.
 * `?token=` yalnızca NSQL_MONITORING_ALLOW_QUERY_TOKEN=true ile kabul edilir (URL'deki
 * gizli bilgi erişim loglarına, proxy loglarına, tarayıcı geçmişine ve Referer'a sızar).
 * Env: NSQL_MONITORING_TOKEN (zorunlu), NSQL_MONITORING_ENABLED (false ile kapat)
 */
class endpoint_guard
{
    public const HEADER_TOKEN = 'X-NSQL-Monitoring-Token';

    /**
     * Endpoint'i korur; yetkisizse JSON yanıt yazıp script'i sonlandırır.
     */
    public static function protect(): void
    {
        $denied = self::authorize();
        if ($denied !== null) {
            self::respond($denied['status'], $denied['body']);
        }
    }

    /**
     * İsteği doğrular; yetkiliyse null, değilse yanıt durum kodu ve gövdesini döndürür.
     *
     * @return array{status: int, body: array<string, string>}|null
     */
    public static function authorize(): ?array
    {
        if (! self::is_enabled()) {
            return self::denial(404, 'Monitoring endpoint disabled');
        }

        $configured = self::get_configured_token();
        if ($configured === null || $configured === '') {
            return self::denial(403, 'Monitoring token not configured');
        }

        $provided = self::extract_request_token();
        if ($provided === null || ! hash_equals($configured, $provided)) {
            return self::denial(401, 'Unauthorized');
        }

        return null;
    }

    /**
     * @return array{status: int, body: array<string, string>}
     */
    private static function denial(int $status, string $message): array
    {
        return [
            'status' => $status,
            'body' => [
                'status' => 'error',
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s'),
            ],
        ];
    }

    public static function allows_query_token(): bool
    {
        $raw = self::env('NSQL_MONITORING_ALLOW_QUERY_TOKEN');
        if ($raw === null) {
            return false;
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'on', 'yes'], true);
    }

    public static function is_enabled(): bool
    {
        $raw = self::env('NSQL_MONITORING_ENABLED');
        if ($raw === null || $raw === '') {
            return true;
        }

        return ! in_array(strtolower($raw), ['0', 'false', 'off', 'no'], true);
    }

    public static function get_configured_token(): ?string
    {
        $token = self::env('NSQL_MONITORING_TOKEN');
        if ($token === null || $token === '') {
            return null;
        }

        return $token;
    }

    public static function extract_request_token(): ?string
    {
        $headerToken = self::get_header(self::HEADER_TOKEN);
        if ($headerToken !== null && $headerToken !== '') {
            return $headerToken;
        }

        $auth = self::get_header('Authorization');
        if ($auth !== null && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) {
            return $m[1];
        }

        if (
            self::allows_query_token()
            && isset($_GET['token']) && is_string($_GET['token']) && $_GET['token'] !== ''
        ) {
            return $_GET['token'];
        }

        return null;
    }

    /**
     * İstemciye generic hata döner; detayı error_log'a yazar.
     *
     * @param \Throwable $e
     * @param int $status
     * @return never
     */
    public static function fail_closed(\Throwable $e, int $status = 503): void
    {
        error_log(sprintf(
            '[nsql monitoring] %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        self::respond($status, [
            'status' => 'error',
            'message' => 'Internal monitoring error',
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return never
     */
    public static function respond(int $status, array $payload): void
    {
        if (! headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json');
        }

        echo json_encode($payload, JSON_PRETTY_PRINT);
        exit;
    }

    private static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    private static function get_header(string $name): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey])) {
            return trim($_SERVER[$serverKey]);
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strcasecmp((string) $key, $name) === 0 && is_string($value)) {
                        return trim($value);
                    }
                }
            }
        }

        return null;
    }
}
