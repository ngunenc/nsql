<?

require_once 'pdo.php';

$db = new nsql('localhost', 'etiyop', 'root', '', 'utf8mb4');

$sorgu="select * from kullanicilar";
$kullanicilar = $db->get_results( $sorgu);


$db->debug();

$veri=$db->get_row("select * from kullanicilar where id = 1");

$db->debug();

// Tek satır döndürme (İsim al)
$ad = $db->get_row("SELECT tam_isim FROM kullanicilar WHERE id = :id", ['id' => 1]);
echo $ad->tam_isim ?? 'Kullanıcı bulunamadı';

$db->debug();