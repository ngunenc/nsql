<?php

require_once __DIR__ . '/vendor/autoload.php';

use Nsql\Database\nsql;

// Debug modu açık olarak veritabanı bağlantısı
$db = new nsql(debug: true);


// SELECT tek satır örneği
$kullanici = $db->get_row(
    "SELECT * FROM kullanicilar WHERE id = :id",
    ['id' => 1]
);
$db->debug();

// SELECT çoklu satır örneği
$kullanicilar = $db->get_results(
    "SELECT * FROM kullanicilar"
    );
$db->debug();

echo "<h2>Query Builder Örnekleri</h2>";

// Basit SELECT örneği
$aktifKullanicilar = $db->table('kullanicilar')
    ->select('id', 'tam_isim', 'eposta')
    ->orderBy('tam_isim', 'ASC')
    ->get();
$db->debug();

// Tek kayıt getirme örneği
$tekKullanici = $db->table('kullanicilar')
    ->where('tam_isim', 'Necip GÜNENÇ')
    ->first();
$db->debug();

// Tüm kullanıcıları getir
$sorgu = "SELECT * FROM kullanicilar";
$kullanicilar = $db->get_results($sorgu, []);
$db->debug();

// Generator ile büyük veri setleri için
$buyuk = $db->get_yield($sorgu, []);
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