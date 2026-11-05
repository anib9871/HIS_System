<?php
// login.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/master_db.php';


$error = '';

/**
 * Select the first page this account is allowed to open after login.
 * Full admins retain Dashboard as their landing page.
 */
function get_default_admin_page(bool $isFullAdmin, $menuAccess): string
{
    if ($isFullAdmin) {
        return 'admin/dashboard.php';
    }

    if (is_string($menuAccess)) {
        $decoded = json_decode($menuAccess, true);
        $menuAccess = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($menuAccess)) {
        $menuAccess = [];
    }

    // Dashboard is the preferred landing page when explicitly permitted.
    if (in_array('dashboard', $menuAccess, true)) {
        return 'admin/dashboard.php';
    }

    // Prefer clinical workspaces for staff; Doctor Desk comes first for doctors.
    $pageByPermission = [
        'opd_doctor' => 'admin/opd_doctor.php',
        'opd_appt' => 'admin/opd_appointments.php',
        'opd_reg' => 'admin/opd_bills.php',
        'opd_visit_list' => 'admin/opd_visit_list.php',
        'opd_collection' => 'admin/opd_daily_collection.php',
        'ipd_doc' => 'admin/ipd_doctor_desk.php',
        'ipd_adm' => 'admin/ipd_admission.php',
        'ipd_nurse' => 'admin/ipd_nurse_station.php',
        'ipd_bill' => 'admin/ipd_billing.php',
        'ipd_discharge' => 'admin/ipd_discharge_summary.php',
        'masters_org' => 'admin/org_profile.php',
        'masters_users' => 'admin/users.php',
        'masters_roles' => 'admin/master_roles.php',
        'masters_patients' => 'admin/patient_master.php',
        'masters_doctors' => 'admin/master_doctors.php',
        'masters_departments' => 'admin/master_departments.php',
        'masters_centers' => 'admin/master_centers.php',
        'rx_templates' => 'admin/prescription_template_master.php',
        'rx_medicines' => 'admin/master_medicines.php',
        'rx_symptoms' => 'admin/master_symptoms.php',
        'rx_diagnoses' => 'admin/master_diagnoses.php',
        'rx_investigations' => 'admin/master_investigations.php',
        'rx_vitals' => 'admin/master_vitals.php',
        'rx_advices' => 'admin/master_advices.php',
        'rx_units' => 'admin/master_units.php',
        'rx_frequency' => 'admin/frequency_master.php',
        'rx_meals' => 'admin/master_meals.php',
        'rx_durations' => 'admin/master_durations.php',
        'fac_buildings' => 'admin/master_buildings.php',
        'fac_blocks' => 'admin/master_blocks.php',
        'fac_floors' => 'admin/master_floors.php',
        'fac_room_categories' => 'admin/master_room_categories.php',
        'fac_rooms' => 'admin/master_rooms.php',
        'fac_bed' => 'admin/master_beds.php',
        'masters_states' => 'admin/master_states.php',
        'masters_city' => 'admin/master_city.php',
        'masters_financial_year' => 'admin/master_financial_year.php',
        'masters_qualifications' => 'admin/master_qualifications.php',
        'masters_specializations' => 'admin/master_specializations.php',
        'masters_days' => 'admin/master_days.php',
        'masters_payment_modes' => 'admin/master_payment_modes.php',
        'masters_insurance_categories' => 'admin/master_insurance_categories.php',
        'masters_doctor_services' => 'admin/master_doctor_services.php',
        'masters_doctor_tariff' => 'admin/master_doctor_tariff.php',
    ];

    foreach ($pageByPermission as $permission => $page) {
        if (in_array($permission, $menuAccess, true)) {
            return $page;
        }
    }

    // No page permission was assigned. Do not send the user to a forbidden page.
    return 'no-access.php';
}

/**
 * Save an allotted center in the session when the account has one.
 * Center information is optional: no center assignment means the topbar
 * should not display a Center section.
 */
function set_login_center(PDO $pdo, array $account): void
{
    unset($_SESSION['center_id'], $_SESSION['center_name']);

    $centerId = (int)($account['center_id'] ?? 0);
    $centerName = trim((string)($account['center_name'] ?? ''));

    if ($centerId > 0 && $centerName === '') {
        try {
            $stmt = $pdo->prepare("SELECT center_name FROM master_centers WHERE center_id = ? AND status = 1 LIMIT 1");
            $stmt->execute([$centerId]);
            $centerName = trim((string)($stmt->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            // Center table/assignment may not be configured for this account.
            $centerName = '';
        }
    }

    if ($centerId > 0 && $centerName !== '') {
        $_SESSION['center_id'] = $centerId;
        $_SESSION['center_name'] = $centerName;
    }
}

// Check already logged in
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1) {
        header("Location: superadmin/dashboard.php");
        exit;
    }

    $alreadyLoggedInIsFullAdmin = !empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
    $alreadyLoggedInMenuAccess = $_SESSION['menu_access'] ?? [];
    header("Location: " . get_default_admin_page($alreadyLoggedInIsFullAdmin, $alreadyLoggedInMenuAccess));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = "Please fill in both Username and Password!";
    } else {
        try {
            // 1. Check in Master DB (user_credentials + master_organization)
            $stmt = $master_pdo->prepare("
                SELECT u.*, r.role_name, o.org_name, o.db_name
                FROM user_credentials u
                LEFT JOIN master_role r ON u.role_id = r.role_id
                LEFT JOIN master_organization o ON u.org_id = o.org_id
                WHERE u.username = ? AND u.status = 1
                LIMIT 1
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Keep the existing plain-text password comparison.
            if ($user && $password === (string)$user['password']) {
                session_regenerate_id(true);

                $_SESSION['user_id']        = $user['user_id'];
                $_SESSION['username']       = $user['username'];
                $_SESSION['fullname']       = trim((string)($user['fullname'] ?? ''));
                $_SESSION['role_id']        = (int)$user['role_id'];
                $_SESSION['role_name']      = $user['role_name'] ?? 'User';
                $_SESSION['org_id']         = $user['org_id'] ?? null;
                $_SESSION['org_name']       = $user['org_name'] ?? '';
                $_SESSION['tenant_db_name'] = $user['db_name'] ?? 'his_tenant_demo';
                $_SESSION['menu_access']    = [];
                $_SESSION['login_source']  = 'master';

                // Master DB accounts (except Superadmin) are full Hospital Admins.
                if ((int)$user['role_id'] === 1) {
                    $_SESSION['user_type'] = 'superadmin';
                    $_SESSION['is_admin'] = 1;
                    unset($_SESSION['center_id'], $_SESSION['center_name']);
                    header("Location: superadmin/dashboard.php");
                    exit;
                }

                $_SESSION['user_type'] = 'admin';
                $_SESSION['is_admin']  = 1;

                // Resolve the center only if this master account has a center_id.
                $tenantDbName = trim((string)($_SESSION['tenant_db_name'] ?? ''));
                if ($tenantDbName !== '') {
                    try {
                        $tenantPdo = new PDO(
                            "mysql:host={$db_host};port={$db_port};dbname={$tenantDbName};charset=utf8mb4",
                            $db_user,
                            $db_pass,
                            [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                            ]
                        );
                        set_login_center($tenantPdo, $user);
                    } catch (Throwable $e) {
                        unset($_SESSION['center_id'], $_SESSION['center_name']);
                    }
                } else {
                    unset($_SESSION['center_id'], $_SESSION['center_name']);
                }

                header("Location: admin/dashboard.php");
                exit;
            }

            // 2. If not found in Master DB, check staff users in tenant DBs.
            $authenticated = false;
            $all_orgs = $master_pdo->query(
                "SELECT org_id, org_name, db_name FROM master_organization WHERE status = 1"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($all_orgs as $org) {
                if (empty($org['db_name'])) {
                    continue;
                }

                try {
                    $tenant_check_pdo = new PDO(
                        "mysql:host={$db_host};port={$db_port};dbname={$org['db_name']};charset=utf8mb4",
                        $db_user,
                        $db_pass,
                        [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                        ]
                    );

                    $t_stmt = $tenant_check_pdo->prepare("
                        SELECT u.*, r.role_name
                        FROM tenant_users u
                        LEFT JOIN tenant_roles r ON u.role_id = r.role_id
                        WHERE u.username = ? AND u.status = 1
                        LIMIT 1
                    ");
                    $t_stmt->execute([$username]);
                    $t_user = $t_stmt->fetch(PDO::FETCH_ASSOC);

                    // Keep the existing plain-text password comparison.
                    if ($t_user && (string)$t_user['password'] === $password) {
                        session_regenerate_id(true);

                        $_SESSION['user_id']        = $t_user['user_id'];
                        $_SESSION['username']       = $t_user['username'];
                        $_SESSION['fullname']       = $t_user['fullname'] ?? $t_user['username'];
                        $_SESSION['role_id']        = (int)$t_user['role_id'];
                        $_SESSION['role_name']      = $t_user['role_name'] ?? 'Staff';
                        $_SESSION['org_id']         = $org['org_id'];
                        $_SESSION['org_name']       = $org['org_name'];
                        $_SESSION['tenant_db_name'] = $org['db_name'];
                        $_SESSION['menu_access']    = $t_user['menu_access'] ?? [];
                        $_SESSION['is_admin']       = (int)($t_user['is_admin'] ?? 0);
                        $_SESSION['user_type']      = 'admin';
                        $_SESSION['login_source']  = 'tenant';

                        set_login_center($tenant_check_pdo, $t_user);

                        $authenticated = true;
                        $landingPage = get_default_admin_page(
                            (int)($_SESSION['is_admin'] ?? 0) === 1,
                            $_SESSION['menu_access'] ?? []
                        );
                        header("Location: " . $landingPage);
                        exit;
                    }
                } catch (Throwable $e) {
                    // Continue checking the remaining active tenant databases.
                    continue;
                }
            }

            if (!$authenticated) {
                $error = "Invalid Username, Password or Inactive Account!";
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HIS Cloud Platform - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #0f172a; font-family: 'Segoe UI', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .login-card { width: 100%; max-width: 400px; background: #ffffff; border-radius: 12px; box-shadow: 0 15px 30px rgba(0,0,0,0.25); overflow: hidden; }
        .card-header { background: #0284c7; color: white; padding: 25px 20px; text-align: center; border: none; }
        .btn-custom { background: #0284c7; border: none; color: white; padding: 10px; font-weight: 600; border-radius: 6px; }
        .btn-custom:hover { background: #0369a1; color: white; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="card-header">
        <h4 class="m-0 fw-bold"><i class="bi bi-hospital-fill text-white me-2"></i>HIS Platform</h4>
        <small class="text-white-50">Enterprise Multi-Tenant Portal</small>
    </div>
    <div class="p-4">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label text-secondary small fw-semibold">Username</label>
                <input type="text" name="username" class="form-control" placeholder="Enter username" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label text-secondary small fw-semibold">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Enter password" required>
            </div>
            <button type="submit" class="btn btn-custom w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Sign In</button>
        </form>
    </div>
</div>

</body>
</html>
