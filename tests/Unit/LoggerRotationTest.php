<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\database\logging\Logger;
use PHPUnit\Framework\TestCase;

class LoggerRotationTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_logrot_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->file = $this->dir . DIRECTORY_SEPARATOR . 'app.log';

        Config::set('log_rotation_interval', 3600);
        Config::set('log_max_size', 10 * 1024 * 1024);
        Config::set('log_compress', false);
    }

    protected function tearDown(): void
    {
        Config::set('log_rotation_interval', null);
        Config::set('log_max_size', null);
        Config::set('log_compress', null);

        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * @return list<string>
     */
    private function rotated_files(): array
    {
        return glob($this->file . '.*') ?: [];
    }

    private function write_entry(int $age_seconds, bool $structured): void
    {
        $time = date('c', time() - $age_seconds);
        $line = $structured
            ? json_encode(['timestamp' => $time, 'level' => 'INFO', 'message' => 'eski'], JSON_UNESCAPED_SLASHES)
            : "[{$time}] [INFO] eski";

        file_put_contents($this->file, $line . PHP_EOL);
    }

    public function test_new_logger_rotates_file_older_than_interval(): void
    {
        $this->write_entry(7200, true);

        (new Logger($this->file, Logger::DEBUG))->info('yeni');

        $this->assertCount(1, $this->rotated_files());
        $this->assertStringContainsString('eski', (string) file_get_contents($this->rotated_files()[0]));
        $content = (string) file_get_contents($this->file);
        $this->assertStringContainsString('yeni', $content);
        $this->assertStringNotContainsString('eski', $content);
    }

    public function test_text_format_old_file_is_rotated(): void
    {
        $this->write_entry(7200, false);

        (new Logger($this->file, Logger::DEBUG, false))->info('yeni');

        $this->assertCount(1, $this->rotated_files());
    }

    public function test_fresh_file_is_not_rotated(): void
    {
        $this->write_entry(60, true);

        $logger = new Logger($this->file, Logger::DEBUG);
        $logger->info('bir');
        $logger->info('iki');

        $this->assertSame([], $this->rotated_files());
        $this->assertCount(3, file($this->file, FILE_IGNORE_NEW_LINES) ?: []);
    }

    public function test_size_limit_is_read_fresh_in_long_running_logger(): void
    {
        Config::set('log_max_size', 4096);
        $logger = new Logger($this->file, Logger::DEBUG);
        $logger->info('ilk');
        $logger->info('ikinci');
        $this->assertSame([], $this->rotated_files());

        file_put_contents($this->file, str_repeat('x', 5000) . PHP_EOL, FILE_APPEND);
        $logger->info('son');

        $this->assertCount(1, $this->rotated_files());
    }

    public function test_text_format_keeps_entry_on_single_line(): void
    {
        $logger = new Logger($this->file, Logger::DEBUG, false);
        $logger->error("Sorgu hatası\n[2026-01-01 00:00:00] [INFO] sahte satır", ['sql' => "SELECT 1\nFROM t"]);

        $lines = array_values(array_filter(explode(PHP_EOL, (string) file_get_contents($this->file))));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Sorgu hatası\n[2026-01-01 00:00:00] [INFO] sahte satır', $lines[0]);
    }
}
