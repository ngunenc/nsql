<?php

require_once __DIR__ . '/vendor/autoload.php';

use nsql\database\Config;
use nsql\database\nsql;

try {

    // Config tabanlı güvenli bağlantı (credentials .env/Config üzerinden)
    $db = new nsql();

    // SELECT tek satır örneği
    $kullanici = $db->get_row(
        "SELECT * FROM kullanicilar WHERE id = :id",
        ['id' => 1]
    );
    if (Config::get('DEBUG_MODE', false)) {
        $db->debug();
    }

    // SELECT çoklu satır örneği
    $kullanicilar = $db->get_results(
        "SELECT * FROM sayfalar"
    );
    if (Config::get('DEBUG_MODE', false)) {
        $db->debug();
    }

    echo "<h2>Query Builder Örnekleri</h2>";

    // Basit SELECT örneği
    $aktifKullanicilar = $db->table('kullanicilar')
        ->select('id', 'tam_isim', 'eposta')
        ->order_by('tam_isim', 'ASC')
        ->get();
    if (Config::get('DEBUG_MODE', false)) {
        $db->debug();
    }

    // Tek kayıt getirme örneği
    $tekKullanici = $db->table('kullanicilar')
        ->where('tam_isim', '=', 'Necip GÜNENÇ')
        ->first();
    if (Config::get('DEBUG_MODE', false)) {
        $db->debug();
    }

    // Tüm kullanıcıları getir
    $sorgu = "SELECT * FROM sayfalar";
    $kullanicilar = $db->get_results($sorgu, []);
    if (Config::get('DEBUG_MODE', false)) {
        $db->debug();
    }

    // Generator ile büyük veri setleri için
    $buyuk = $db->get_yield($sorgu, []);
    foreach ($buyuk as $row) {
        // Büyük veri setleri işlenirken çıktı verilmez; gerektiğinde burada kullanılır
    }
    if (Config::get('DEBUG_MODE', false)) {
        $db->debug();
    }

    //Tek Satır Veri getirme
    $sorgu = "select * from kullanicilar where id = :id";
    $veri = $db->get_row($sorgu, ['id' => 1]);
    if (Config::get('DEBUG_MODE', false)) {
        $db->debug();
    }

    // Tek satır döndürme (İsim al) - XSS güvenli çıktı
    $ad = $db->get_row("SELECT tam_isim FROM kullanicilar WHERE id = :id", ['id' => 1]);
    echo isset($ad->tam_isim) ? nsql::escapeHtml($ad->tam_isim) : 'Kullanıcı bulunamadı';
    if (Config::get('DEBUG_MODE', false)) {
        $db->debug();
    }

} catch (Exception $e) {
    // Hata mesajını kullanıcıya güvenli ve genel bir formatta göster
    $generic = 'Bir hata oluştu.';
    echo nsql::escapeHtml($generic);
}
