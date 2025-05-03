<?

require_once 'pdo.php';

$db = new nsql('localhost', 'etiyop', 'root', '', 'utf8mb4');

$stmt = $db->get_results("SELECT * FROM kullanicilar");


$veri=$db->get_row("select * from kullanicilar where id = 1");

$db->debug();

// Tek satır döndürme (İsim al)
$ad = $db->get_row("SELECT tam_isim FROM kullanicilar WHERE id = :id", ['id' => 1]);
echo $ad->tam_isim ?? 'Kullanıcı bulunamadı';

$db->debug();