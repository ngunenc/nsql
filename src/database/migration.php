<?php

namespace nsql\database;

interface migration
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
}
