<?php
// config/master_db.php
$db_host = 'mysql.railway.internal'; // Railway ka private network host
$db_port = '3306';
$db_name = 'railway'; // Railway par default DB ka naam aksar 'railway' hota hai (Variables tab mein MYSQL_DATABASE dekh lena)
$db_user = 'root';
$db_pass = 'tAkeZegypoAxZNlmmMBnDHHKFdbWwmIe'; // Apna Railway Variables tab se copy kiya hua password yahan paste karo

try {
    $master_pdo = new PDO(
        "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ]
    );
} catch (PDOException $e) {
    die("Master DB Connection Failed: " . $e->getMessage());
}
