<?php

// ============================================================
// MASTER DATABASE CONNECTION
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');


$db_host = getenv('MYSQLHOST');
$db_port = getenv('MYSQLPORT') ?: 3306;
$db_name = getenv('MASTER_DB_NAME') ?: 'his_master_db';
$db_user = getenv('MYSQLUSER');
$db_pass = getenv('MYSQLPASSWORD');


// ============================================================
// CHECK VARIABLES
// ============================================================

if (empty($db_host)) {
    die("ERROR: MYSQLHOST is missing.");
}

if (empty($db_user)) {
    die("ERROR: MYSQLUSER is missing.");
}

if (empty($db_pass)) {
    die("ERROR: MYSQLPASSWORD is missing.");
}

if (empty($db_name)) {
    die("ERROR: MASTER_DB_NAME is missing.");
}


// ============================================================
// CONNECT
// ============================================================

try {

    $master_pdo = new PDO(

        "mysql:host={$db_host};" .
        "port={$db_port};" .
        "dbname={$db_name};" .
        "charset=utf8mb4",

        $db_user,
        $db_pass,

        [

            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES =>
                false

        ]

    );

} catch (PDOException $e) {

    die(
        "<div style='
            font-family:Arial;
            padding:30px;
            background:#fff3f3;
            color:#b91c1c;
            min-height:100vh;
        '>

            <h2>Master Database Connection Failed</h2>

            <p>
                <b>Database:</b>
                " . htmlspecialchars($db_name) . "
            </p>

            <p>
                <b>Host:</b>
                " . htmlspecialchars($db_host) . "
            </p>

            <p>
                <b>Error:</b><br>
                " . htmlspecialchars($e->getMessage()) . "
            </p>

        </div>"
    );

}
