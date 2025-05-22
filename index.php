<?php

require_once __DIR__ . '/vendor/autoload.php';

use Nsql\Database\nsql;

// Veritabanı bağlantısı
$db = new nsql(debug: true);

//Çoklu Satır Veri getirme
$sorgu = "select * from sayfalar";
$kullanicilar = $db->get_results($sorgu, [], true);

$db->debug();

$buyuk = $db->get_yield($sorgu, [], true);
foreach ($buyuk as $row) {
    
}
$db->debug();

//Tek Satır Veri getirme
$sorgu = "select * from kullanicilar where id = :id";
$veri = $db->get_row($sorgu, ['id' => 1]);

$db->debug();

// Tek satır döndürme (İsim al)
$ad = $db->get_row("SELECT tam_isim FROM kullanicilar WHERE id = :id", ['id' => 1]);
echo $ad->tam_isim ?? 'Kullanıcı bulunamadı';

$db->debug();