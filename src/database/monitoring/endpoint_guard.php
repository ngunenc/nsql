<?php

namespace nsql\database\monitoring;

/**
 * Monitoring endpoint koruması (health / metrics).
 *
 * Auth: Authorization Bearer, X-NSQL-Monitoring-Token veya ?token=
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
        if (! self::is_enabled()) {
            self::respond(404, [
                'status' => 'error',
                'message' => 'Monitoring endpoint disabled',
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        }

        $configured = self::get_configured_token();
        if ($configured === null || $configured === '') {
            self::respond(403, [
                'status' => 'error',
                'message' => 'Monitoring token not configured',
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        }

        $provided = self::extract_request_token();
        if ($provided === null || ! hash_equals($configured, $provided)) {
            self::respond(401, [
                'status' => 'error',
                'message' => 'Unauthorized',
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        }
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

        if (isset($_GET['token']) && is_string($_GET['token']) && $_GET['token'] !== '') {
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
