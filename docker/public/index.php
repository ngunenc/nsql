<?php

/**
 * Docker geliştirme ortamının web kökü (docker/nginx.conf).
 *
 * Proje kökü web'e açılmaz: .env, storage/keys, storage/logs ve tests/*.php dışarıdan erişilemez.
 * Monitoring örnekleri: /health.php ve /metrics.php (NSQL_MONITORING_TOKEN gerekir).
 */

header('Content-Type: text/plain; charset=utf-8');
echo "nsql geliştirme ortamı\n";
