<?php
// config/tenant_db.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$tenant_host = '127.0.0.1';
$tenant_port = '3306';
$tenant_user = 'root';
$tenant_pass = 'Mysql123@';

// Session se active hospital ka tenant DB uthega
$active_tenant_db = $_SESSION['tenant_db_name'] ?? 'his_tenant_demo';

try {
    $tenant_pdo = new PDO(
        "mysql:host={$tenant_host};port={$tenant_port};dbname={$active_tenant_db};charset=utf8mb4",
        $tenant_user,
        $tenant_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ]
    );
} catch (PDOException $e) {
    die("<div style='font-family:sans-serif;padding:20px;color:red;background:#ffe6e6;border:1px solid red;border-radius:6px;'>
        <h4>Tenant Database Error</h4>
        <p>Database <b>{$active_tenant_db}</b> se connect nahi ho saka. Workbench me database check karein: " . htmlspecialchars($e->getMessage()) . "</p>
    </div>");
}