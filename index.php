<?

require_once 'pdo.php';

$db = new pdo('localhost', 'etiyop', 'root', '', 'utf8mb4');

$stmt = $db->query("SELECT * FROM kullanicilar");

$data = $stmt->fetchAll();

$db->debug();
