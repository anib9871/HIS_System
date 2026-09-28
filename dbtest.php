<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT') ?: 3306;
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$db   = getenv('MASTER_DB_NAME') ?: 'his_master_db';

echo "<h2>Database Test</h2>";

echo "<pre>";
echo "Host: " . htmlspecialchars($host) . "\n";
echo "Port: " . htmlspecialchars($port) . "\n";
echo "User: " . htmlspecialchars($user) . "\n";
echo "Database: " . htmlspecialchars($db) . "\n";
echo "</pre>";

try {

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    echo "<h2 style='color:green'>MASTER DATABASE CONNECTED</h2>";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    echo "<pre>";
    print_r($tables);
    echo "</pre>";

} catch (Throwable $e) {

    echo "<h2 style='color:red'>DATABASE ERROR</h2>";

    echo "<pre>";
    echo htmlspecialchars($e->getMessage());
    echo "</pre>";
}
