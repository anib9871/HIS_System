<?php
// ============================================================
// login.php
// HIS MULTI-TENANT LOGIN
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// ============================================================
// MASTER DATABASE
// ============================================================

require_once __DIR__ . '/config/master_db.php';

$error = '';


// ============================================================
// RAILWAY MYSQL CONNECTION DETAILS
// ============================================================

$db_host = getenv('MYSQLHOST');
$db_port = getenv('MYSQLPORT') ?: 3306;
$db_user = getenv('MYSQLUSER');
$db_pass = getenv('MYSQLPASSWORD');


// ============================================================
// CHECK RAILWAY VARIABLES
// ============================================================

if (empty($db_host) || empty($db_user) || empty($db_pass)) {

    die("
        <div style='
            font-family:Arial,sans-serif;
            padding:30px;
            background:#fff3f3;
            color:#b91c1c;
            min-height:100vh;
        '>

            <h2>Database Configuration Error</h2>

            <p>
                Railway MySQL environment variables nahi mil rahe.
            </p>

            <p>
                Check:
            </p>

            <ul>
                <li>MYSQLHOST</li>
                <li>MYSQLPORT</li>
                <li>MYSQLUSER</li>
                <li>MYSQLPASSWORD</li>
            </ul>

        </div>
    ");
}


// ============================================================
// CHECK ALREADY LOGGED IN
// ============================================================

if (isset($_SESSION['user_id'])) {

    // SUPER ADMIN
    if (
        isset($_SESSION['role_id']) &&
        (int)$_SESSION['role_id'] === 1
    ) {

        header("Location: superadmin/dashboard.php");
        exit;
    }

    // HOSPITAL / STAFF ADMIN
    header("Location: admin/dashboard.php");
    exit;
}


// ============================================================
// LOGIN
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');


    // ========================================================
    // BASIC VALIDATION
    // ========================================================

    if ($username === '' || $password === '') {

        $error = "Please fill in both Username and Password!";

    } else {

        try {

            // ==================================================
            // 1. CHECK MASTER DATABASE
            // ==================================================

            $stmt = $master_pdo->prepare("
                SELECT
                    u.*,
                    r.role_name,
                    o.org_name,
                    o.db_name
                FROM user_credentials u

                LEFT JOIN master_role r
                    ON u.role_id = r.role_id

                LEFT JOIN master_organization o
                    ON u.org_id = o.org_id

                WHERE
                    u.username = ?
                    AND u.status = 1

                LIMIT 1
            ");

            $stmt->execute([$username]);

            $user = $stmt->fetch();


            // ==================================================
            // MASTER USER AUTHENTICATED
            // ==================================================

            if ($user && $password === $user['password']) {

                // ------------------------------------------------
                // COMMON SESSION
                // ------------------------------------------------

                $_SESSION['user_id'] =
                    $user['user_id'];

                $_SESSION['username'] =
                    $user['username'];

                $_SESSION['role_id'] =
                    (int)$user['role_id'];

                $_SESSION['role_name'] =
                    $user['role_name'] ?? 'User';

                $_SESSION['org_id'] =
                    $user['org_id'] ?? null;

                $_SESSION['org_name'] =
                    $user['org_name'] ?? 'Hospital Admin';


                // ==================================================
                // SUPER ADMIN
                // role_id = 1
                // ==================================================

                if ((int)$user['role_id'] === 1) {

                    $_SESSION['user_type'] = 'superadmin';

                    // Superadmin ka tenant DB nahi hota
                    unset($_SESSION['tenant_db_name']);

                    header(
                        "Location: superadmin/dashboard.php"
                    );

                    exit;
                }


                // ==================================================
                // HOSPITAL ADMIN FROM MASTER DB
                // ==================================================

                if (!empty($user['db_name'])) {

                    $_SESSION['tenant_db_name'] =
                        $user['db_name'];

                    $_SESSION['user_type'] =
                        'admin';

                    $_SESSION['is_admin'] = 1;

                    header(
                        "Location: admin/dashboard.php"
                    );

                    exit;

                } else {

                    $error =
                        "Hospital database is not configured for this organization.";
                }
            }


            // ==================================================
            // 2. CHECK TENANT DATABASES FOR STAFF USERS
            // ==================================================

            if (empty($error) && !$user) {

                $authenticated = false;


                // ==================================================
                // GET ACTIVE ORGANIZATIONS
                // ==================================================

                $all_orgs = $master_pdo->query("
                    SELECT
                        org_id,
                        org_name,
                        db_name
                    FROM master_organization
                    WHERE status = 1
                    AND db_name IS NOT NULL
                    AND db_name != ''
                    ORDER BY org_id ASC
                ")->fetchAll();


                // ==================================================
                // CHECK EACH TENANT DATABASE
                // ==================================================

                foreach ($all_orgs as $org) {

                    if (empty($org['db_name'])) {
                        continue;
                    }


                    try {

                        // ==========================================
                        // CONNECT TO TENANT DATABASE
                        // ==========================================

                        $tenant_check_pdo = new PDO(

                            "mysql:host={$db_host};" .
                            "port={$db_port};" .
                            "dbname=" . $org['db_name'] . ";" .
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


                        // ==========================================
                        // CHECK TENANT USERS TABLE
                        // ==========================================

                        $t_stmt = $tenant_check_pdo->prepare("
                            SELECT
                                u.*,
                                r.role_name
                            FROM tenant_users u

                            LEFT JOIN tenant_roles r
                                ON u.role_id = r.role_id

                            WHERE
                                u.username = ?
                                AND u.status = 1

                            LIMIT 1
                        ");

                        $t_stmt->execute([
                            $username
                        ]);

                        $t_user = $t_stmt->fetch();


                        // ==========================================
                        // STAFF AUTHENTICATED
                        // ==========================================

                        if (
                            $t_user &&
                            isset($t_user['password']) &&
                            $t_user['password'] === $password
                        ) {

                            // --------------------------------------
                            // SESSION
                            // --------------------------------------

                            $_SESSION['user_id'] =
                                $t_user['user_id'];

                            $_SESSION['username'] =
                                $t_user['username'];

                            $_SESSION['fullname'] =
                                $t_user['fullname']
                                ?? $t_user['username'];

                            $_SESSION['role_id'] =
                                (int)$t_user['role_id'];

                            $_SESSION['role_name'] =
                                $t_user['role_name']
                                ?? 'Staff';

                            $_SESSION['org_id'] =
                                $org['org_id'];

                            $_SESSION['org_name'] =
                                $org['org_name'];

                            // IMPORTANT
                            // Tenant DB yahi set hoga
                            $_SESSION['tenant_db_name'] =
                                $org['db_name'];

                            $_SESSION['menu_access'] =
                                $t_user['menu_access']
                                ?? '';

                            $_SESSION['is_admin'] =
                                (int)($t_user['is_admin'] ?? 0);

                            $_SESSION['user_type'] =
                                'admin';


                            $authenticated = true;


                            // ======================================
                            // REDIRECT ADMIN DASHBOARD
                            // ======================================

                            header(
                                "Location: admin/dashboard.php"
                            );

                            exit;
                        }


                    } catch (PDOException $e) {

                        // ------------------------------------------
                        // Agar kisi tenant DB mein problem hai
                        // to next organization check karo
                        // ------------------------------------------

                        continue;
                    }
                }


                // ==================================================
                // LOGIN FAILED
                // ==================================================

                if (!$authenticated) {

                    $error =
                        "Invalid Username, Password or Inactive Account!";
                }
            }


        } catch (PDOException $e) {

            $error =
                "Database Error: " .
                $e->getMessage();
        }
    }
}

?>


<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>HIS Cloud Platform - Login</title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <style>

        body {

            background: #0f172a;

            font-family: 'Segoe UI', sans-serif;

            display: flex;

            align-items: center;

            justify-content: center;

            min-height: 100vh;

            margin: 0;

        }


        .login-card {

            width: 100%;

            max-width: 400px;

            background: #ffffff;

            border-radius: 12px;

            box-shadow:
                0 15px 30px rgba(0,0,0,0.25);

            overflow: hidden;

        }


        .card-header {

            background: #0284c7;

            color: white;

            padding: 25px 20px;

            text-align: center;

            border: none;

        }


        .btn-custom {

            background: #0284c7;

            border: none;

            color: white;

            padding: 10px;

            font-weight: 600;

            border-radius: 6px;

        }


        .btn-custom:hover {

            background: #0369a1;

            color: white;

        }

    </style>

</head>


<body>


<div class="login-card">


    <div class="card-header">

        <h4 class="m-0 fw-bold">

            <i class="bi bi-hospital-fill text-white me-2"></i>

            HIS Platform

        </h4>


        <small class="text-white-50">

            Enterprise Multi-Tenant Portal

        </small>

    </div>


    <div class="p-4">


        <?php if (!empty($error)): ?>

            <div class="alert alert-danger py-2 small">

                <?= htmlspecialchars($error) ?>

            </div>

        <?php endif; ?>


        <form method="POST" action="">


            <div class="mb-3">

                <label
                    class="form-label text-secondary small fw-semibold"
                >

                    Username

                </label>


                <input
                    type="text"
                    name="username"
                    class="form-control"
                    placeholder="Enter username"
                    required
                    autofocus
                    autocomplete="username"
                >

            </div>


            <div class="mb-4">

                <label
                    class="form-label text-secondary small fw-semibold"
                >

                    Password

                </label>


                <input
                    type="password"
                    name="password"
                    class="form-control"
                    placeholder="Enter password"
                    required
                    autocomplete="current-password"
                >

            </div>


            <button
                type="submit"
                class="btn btn-custom w-100"
            >

                <i class="bi bi-box-arrow-in-right me-1"></i>

                Sign In

            </button>


        </form>

    </div>

</div>


</body>

</html>
