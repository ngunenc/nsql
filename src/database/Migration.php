<?php

namespace nsql\database;

interface Migration
{
    /**
     * Migration'ı uygular
     *
     * @return void
     */
    public function up(): void;

    /**
     * Migration'ı geri alır
     *
     * @return void
     */
    public function down(): void;

    /**
     * Migration'ın açıklamasını döndürür
     *
     * @return string
     */
    public function get_description(): string;

    /**
     * Bu migration'ın bağımlı olduğu migration'ları döndürür
     * Migration dosya adlarını (basename) döndürmelidir
     *
     * @return array<string> Bağımlılık Migration dosya adları
     */
    public function get_dependencies(): array;
}
