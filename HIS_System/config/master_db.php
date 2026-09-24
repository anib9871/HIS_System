<?php
// config/master_db.php
$db_host = '127.0.0.1';
$db_port = '3306';
$db_name = 'his_master_db';
$db_user = 'root';
$db_pass = 'Mysql123@'; // MySQL Workbench ka password agar hai toh yahan daalein

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