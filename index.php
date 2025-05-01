<?

require_once 'pdo.php';

$db = new nsql('localhost', 'etiyop', 'root', '', 'utf8mb4');

$stmt = $db->get_results("SELECT * FROM kullanicilar");


$db->debug();

$veri=$db->get_row("select * from kullanicilar where id =:id", ['id' => 1]);

$db->debug();