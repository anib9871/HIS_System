<?php
// config/master_db.php

$db_host = getenv('MYSQLHOST');
$db_port = getenv('MYSQLPORT');
$db_name = getenv('MASTER_DB_NAME');
$db_user = getenv('MYSQLUSER');
$db_pass = getenv('MYSQLPASSWORD');

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
