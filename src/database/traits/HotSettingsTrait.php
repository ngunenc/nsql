<?php

namespace nsql\database\traits;

use nsql\database\Config;

/**
 * Her sorguda okunan ayarlar için örnek bazlı önbellek. Değerler Config::revision()
 * değişince (Config::set(), refresh(), set_project_root()) yeniden okunur.
 */
trait HotSettingsTrait
{
    /** @var array<string, mixed> */
    private array $hot_settings = [];
    private int $hot_settings_revision = -1;

    private function setting(string $key, mixed $default = null): mixed
    {
        $revision = Config::revision();
        if ($revision !== $this->hot_settings_revision) {
            $this->hot_settings = [];
            $this->hot_settings_revision = $revision;
        }

        if (! array_key_exists($key, $this->hot_settings)) {
            $this->hot_settings[$key] = Config::get($key, $default);
        }

        return $this->hot_settings[$key];
    }
}
