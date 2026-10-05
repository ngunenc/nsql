<?php

namespace nsql\database\traits;

use nsql\database\security\sensitive_data_filter;
use RuntimeException;
use Throwable;

trait debug_trait
{
    /**
     * Hata loglar
     */
    private function log_error(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] $message" . PHP_EOL;
        $path = $this->resolve_log_path($this->log_file);
        $this->ensure_log_directory(dirname($path));
        file_put_contents($path, $log_message, FILE_APPEND | LOCK_EX);
    }

    /**
     * Son çağrılan metodu kaydeder
     */
    private function set_last_called_method(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $this->last_called_method = $trace[1]['function'] ?? 'unknown';
    }

    /**
     * Debug çıktısı oluşturur
     */    public function debug(): void
    {
        if (! $this->debug_mode) {
            echo '<div style="color:red;font-weight:bold;">Debug modu kapalı! Detaylı sorgu ve hata bilgisi için nsql nesnesini debug modda başlatın.</div>';

            return;
        }

        try {
            $params = sensitive_data_filter::mask_array($this->last_params);
            $query = $this->interpolate_query($this->last_query, $params);
            $params_json = json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $query = $this->last_query . ' [Parametre dönüştürme hatası]';
            $params_json = 'Parametreler görüntülenemedi: ' . $e->getMessage();
        }

        $debug_message = sprintf(
            "Çalıştırılan Metod: %s\nSQL Sorgusu: %s\nParametreler: %s\n%s",
            $this->last_called_method,
            $query,
            $params_json,
            $this->last_error ? "Hata: {$this->last_error}\n" : ''
        );

        $this->log_error($debug_message);

        echo <<<HTML
        <style>
            .nsql-debug {
                font-family: monospace;
                background: #f9f9f9;
                border: 1px solid #ccc;
                padding: 16px;
                margin: 16px 0;
                border-radius: 8px;
                max-width: 100%;
                overflow-x: auto;
            }
            .method-header {
                background: #4a90e2;
                color: white;
                padding: 12px 16px;
                border-radius: 6px;
                margin-bottom: 16px;
                font-size: 18px;
                font-weight: bold;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .nsql-debug h4 {
                margin: 0 0 8px;
                font-size: 16px;
                color: #333;
            }
            .nsql-debug pre {
                background: #fff;
                border: 1px solid #ddd;
                padding: 10px;
                margin: 8px 0;
                border-radius: 5px;
                overflow-x: auto;
                white-space: pre-wrap;
                word-wrap: break-word;
            }
            .nsql-debug .method-info {
                background: #e8f5e9;
                border: 1px solid #c8e6c9;
                color: #2e7d32;
                padding: 10px;
                margin: 8px 0;
                border-radius: 5px;
                font-weight: bold;
            }
            .nsql-debug table {
                border-collapse: collapse;
                width: 100%;
                margin: 8px 0;
                background: #fff;
            }
            .nsql-debug table th,
            .nsql-debug table td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
                font-size: 13px;
                word-break: break-word;
            }
            .nsql-debug table th {
                background: #f5f5f5;
                font-weight: bold;
            }
            .nsql-debug .error {
                background: #ffecec;
                border: 1px solid #f5aca6;
                color: #cc0033;
                padding: 10px;
                margin: 8px 0;
                border-radius: 5px;
            }
            .nsql-debug .info {
                background: #e7f6ff;
                border: 1px solid #b3e5fc;
                color: #0288d1;
                padding: 10px;
                margin: 8px 0;
                border-radius: 5px;
            }
            .nsql-debug .query-section {
                margin: 16px 0;
            }
            .nsql-debug .no-results {
                font-style: italic;
                color: #666;
            }
        </style>
        <div class="nsql-debug">
HTML;

        if ($this->last_error) {
            echo "<div class='error'>⚠️ <strong>Hata:</strong> " . htmlspecialchars($this->last_error) . "</div>";
        }

        echo "<div class='query-section'>";
        echo "<h4>🔍 SQL Sorgusu:</h4>";
        echo "<pre>" . htmlspecialchars($query) . "</pre>";

        echo "<h4>📋 Parametreler:</h4>";
        echo "<pre>" . htmlspecialchars($params_json) . "</pre>";
        echo "</div>";

        if (! empty($this->last_results)) {
            echo "<div class='query-section'>";
            echo "<h4>📊 Sonuç Verisi:</h4>";

            $results = is_array($this->last_results)
                ? (new sensitive_data_filter())->filter($this->last_results)
                : $this->last_results;
            if (is_array($results) && count($results) > 0) {
                $first_row = is_object($results[0]) ? (array)$results[0] : $results[0];

                echo "<table><thead><tr>";
                foreach ($first_row as $key => $_) {
                    echo "<th>" . htmlspecialchars((string)$key) . "</th>";
                }
                echo "</tr></thead><tbody>";

                foreach ($results as $row) {
                    echo "<tr>";
                    foreach ((array)$row as $value) {
                        $display_value = is_null($value) ? '-' :
                                    (is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value);
                        echo "<td>" . htmlspecialchars($display_value) . "</td>";
                    }
                    echo "</tr>";
                }
                echo "</tbody></table>";

                echo "<div class='info'>✓ Toplam " . count($results) . " kayıt bulundu.</div>";
            } else {
                echo "<div class='info'>ℹ️ Sonuç bulunamadı.</div>";
            }
            echo "</div>";
        } else {
            if ($this->last_query) {
                echo "<div class='info'>ℹ️ Bu sorgu herhangi bir sonuç döndürmedi veya sonuçlar henüz alınmadı.</div>";
            }
        }

        echo "</div>";
    }

    /**
     * Sorgu ve parametreleri birleştirir
     */
    private function interpolate_query(string $query, array $params): string
    {
        if (! $this->debug_mode) {
            throw new RuntimeException("interpolate_query yalnızca debug modunda kullanılabilir.");
        }

        foreach ($params as $key => $value) {
            if (is_array($value) && isset($value['value'])) {
                $actual_value = $value['value'];
            } else {
                $actual_value = $value;
            }

            if ($actual_value === null) {
                $escaped = 'NULL';
            } else {
                $text = is_bool($actual_value) ? ($actual_value ? '1' : '0') : (string) $actual_value;
                $escaped = $this->pdo !== null
                    ? (string) $this->pdo->quote($text)
                    : "'" . str_replace("'", "''", $text) . "'";
            }

            if (is_string($key)) {
                $name = preg_quote(ltrim($key, ':'), '/');
                $query = (string) preg_replace_callback(
                    '/:' . $name . '(?![A-Za-z0-9_])/',
                    static fn () => $escaped,
                    $query
                );
            } else {
                $query = (string) preg_replace_callback('/\?/', static fn () => $escaped, $query, 1);
            }
        }

        return $query;
    }

    /**
     * Debug çıktısını render eder
     */    private function render_debug_output(string $query, string $params_json): void
    {
        if (! defined('NSQL_TEMPLATE')) {
            define('NSQL_TEMPLATE', true);
        }

        $template_data = [
            'method' => $this->last_called_method,
            'query' => $query,
            'params' => $params_json,
            'error' => $this->last_error,
            'results' => json_encode(
                $this->last_results ?? [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ];
        extract($template_data);

        include __DIR__ . '/../templates/debug_template.php';
    }

    /**
     * Debug hatalarını işler
     */
    private function handle_debug_error(Throwable $e): void
    {
        $this->last_error = $e->getMessage();
        $query = $this->last_query . ' [Parametre dönüştürme hatası]';
        $params_json = 'Parametreler görüntülenemedi: ' . $e->getMessage();
        $this->render_debug_output($query, $params_json);
    }
}
