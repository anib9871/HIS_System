<?php
// config/tenant_db.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// ============================================================
// RAILWAY MYSQL CONNECTION
// ============================================================

$tenant_host = getenv('MYSQLHOST');
$tenant_port = getenv('MYSQLPORT') ?: 3306;
$tenant_user = getenv('MYSQLUSER');
$tenant_pass = getenv('MYSQLPASSWORD');


// ============================================================
// ACTIVE TENANT DATABASE
// ============================================================
// Login ke time $_SESSION['tenant_db_name'] set hoga.
// Example:
// $_SESSION['tenant_db_name'] = 'apollo_hospital_his';
//
// Agar session me DB nahi mila to railway use hoga.
// Railway tumhara current Admin/HIS database hai.

$active_tenant_db = $_SESSION['tenant_db_name'] ?? 'railway';


// ============================================================
// BASIC VALIDATION
// ============================================================

if (empty($tenant_host) || empty($tenant_user) || empty($tenant_pass)) {

    die("
        <div style='font-family:sans-serif;padding:20px;color:red;
        background:#ffe6e6;border:1px solid red;border-radius:6px;'>
            <h4>Database Configuration Error</h4>
            <p>Railway MySQL environment variables nahi mil rahe.</p>
        </div>
    ");
}


// ============================================================
// TENANT DATABASE CONNECTION
// ============================================================

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

    die("
        <div style='font-family:sans-serif;padding:20px;color:red;
        background:#ffe6e6;border:1px solid red;border-radius:6px;'>

            <h4>Tenant Database Error</h4>

            <p>
                Database
                <b>" . htmlspecialchars($active_tenant_db) . "</b>
                se connect nahi ho saka.
            </p>

            <p>
                " . htmlspecialchars($e->getMessage()) . "
            </p>

        </div>
    ");
}
