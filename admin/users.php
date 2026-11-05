<?php

// admin/users.php

// Session start karein agar nahi hai

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

$page_title = "Staff Users & Access Control";

require_once __DIR__ . '/../config/tenant_db.php';

// Safe migration: add a direct link from staff login to Doctor Master.

try {

    $hasDoctorId = $tenant_pdo->query("

        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS

        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_users'

          AND COLUMN_NAME = 'doctor_id'

    ")->fetchColumn();

    if (!(int)$hasDoctorId) {

        $tenant_pdo->exec("ALTER TABLE tenant_users ADD COLUMN doctor_id INT NULL DEFAULT NULL");

    }

} catch (PDOException $e) {

    // Keep the page usable if the DB user cannot run schema migrations.

}

// ==============================================================

// 1. AJAX Handler: Check if Username/Email Already Exists

// ==============================================================

if (isset($_GET['check_username'])) {

    header('Content-Type: application/json');

    $username = trim($_GET['check_username']);

    $edit_id = (int)($_GET['edit_id'] ?? 0);

    $stmt = $tenant_pdo->prepare("SELECT COUNT(*) FROM tenant_users WHERE username = ? AND user_id != ?");

    $stmt->execute([$username, $edit_id]);

    $exists = $stmt->fetchColumn() > 0;

    echo json_encode(['exists' => $exists]);

    exit;

}

// Hierarchical Modules / Sub-menus Matrix

$module_hierarchy = [

    'general_masters' => [

        'title' => 'Hospital & General Masters',

        'icon' => 'bi-gear-wide-connected',

        'submenus' => [

            'masters_org' => ['name' => 'Hospital Profile Setup', 'desc' => 'Clinic / Hospital details'],

            'masters_states' => ['name' => 'State Master', 'desc' => 'State list configuration'],

            'masters_city' => ['name' => 'City Master', 'desc' => 'City list configuration'],

            'masters_financial_year' => ['name' => 'Financial Year Master', 'desc' => 'Accounting financial year'],

            'masters_centers' => ['name' => 'Centers & Branches', 'desc' => 'Manage hospital branches/wings'],

            'masters_users' => ['name' => 'Staff & Access Control', 'desc' => 'Manage staff accounts & rights'],

            'masters_roles' => ['name' => 'Role Master', 'desc' => 'Manage tenant roles'],

            'masters_patients' => ['name' => 'Patient Master', 'desc' => 'Patient demographic records'],

            'masters_payment_modes' => ['name' => 'Payment Modes', 'desc' => 'Cash, UPI, Card, Net Banking'],

            'masters_insurance_categories' => ['name' => 'Category & Insurance', 'desc' => 'TPA & patient categories'],

        ]

    ],

    'doctor_masters' => [

        'title' => 'Doctors & Department Masters',

        'icon' => 'bi-person-badge',

        'submenus' => [

            'masters_doctors' => ['name' => 'Doctors & Schedules', 'desc' => 'Doctor profiles & consultation slots'],

            'masters_departments' => ['name' => 'Departments', 'desc' => 'Hospital medical departments'],

            'masters_qualifications' => ['name' => 'Qualifications', 'desc' => 'Degrees & qualifications list'],

            'masters_specializations' => ['name' => 'Specializations', 'desc' => 'Medical specializations list'],

            'masters_days' => ['name' => 'Working Days', 'desc' => 'OPD consultation working days'],

            'masters_doctor_services' => ['name' => 'Doctor Service Master', 'desc' => 'Services mapped to doctors'],

            'masters_doctor_tariff' => ['name' => 'Doctor Tariff Master', 'desc' => 'Doctor consultation charges & rates'],

        ]

    ],

    'prescription_masters' => [

        'title' => 'Prescription Masters (Rx)',

        'icon' => 'bi-prescription2',

        'submenus' => [

            'rx_units' => ['name' => 'Unit Master', 'desc' => 'Dosage units'],

            'rx_frequency' => ['name' => 'Frequency Master', 'desc' => 'Dosing schedule'],

            'rx_meals' => ['name' => 'Meal Master', 'desc' => 'Before/after meal instructions'],

            'rx_durations' => ['name' => 'Duration Master', 'desc' => 'Course duration'],

            'rx_medicines' => ['name' => 'Medicine Master', 'desc' => 'Drug formulations & brands'],

            'rx_symptoms' => ['name' => 'Symptom Master', 'desc' => 'Clinical patient symptoms'],

            'rx_diagnoses' => ['name' => 'Diagnosis Master', 'desc' => 'Clinical diagnosis & ICD list'],

            'rx_investigations' => ['name' => 'Investigation Master', 'desc' => 'Lab, X-Ray & pathology tests'],

            'rx_vitals' => ['name' => 'Vital Master', 'desc' => 'BP, Pulse, Temperature, SpO2'],

            'rx_advices' => ['name' => 'Advices Master', 'desc' => 'Standard doctor advice notes'],

            'rx_templates' => ['name' => 'Prescription Templates', 'desc' => 'Pre-saved Rx diagnosis templates'],

        ]

    ],

    'facility_masters' => [

        'title' => 'Facilities & Infrastructure',

        'icon' => 'bi-buildings',

        'submenus' => [

            'fac_buildings' => ['name' => 'Buildings', 'desc' => 'Hospital premises & buildings'],

            'fac_blocks' => ['name' => 'Blocks', 'desc' => 'Hospital blocks/wings'],

            'fac_floors' => ['name' => 'Floors', 'desc' => 'Floor levels structure'],

            'fac_room_categories' => ['name' => 'Room Categories', 'desc' => 'General, Deluxe, ICU, Private'],

            'fac_rooms' => ['name' => 'Rooms Master', 'desc' => 'Room numbers & bed configurations'],

            'fac_bed' => ['name' => 'Bed Master', 'desc' => 'Bed numbers and room allocation'],

        ]

    ],

    'opd' => [

        'title' => 'OPD Services Module',

        'icon' => 'bi-person-wheelchair',

        'submenus' => [

            'opd_appt' => ['name' => 'Appointments', 'desc' => 'Book & manage patient appointments'],

            'opd_reg' => ['name' => 'OPD Registration & Token', 'desc' => 'Patient entry & token generation'],

            'opd_queue' => ['name' => 'Patient Live Queue', 'desc' => 'Real-time token display screen'],

            'opd_doctor' => ['name' => 'Doctor Desk (Rx)', 'desc' => 'Doctor diagnosis & prescription'],

            'opd_visit_list' => ['name' => 'OPD Visit List', 'desc' => 'Daily patient visits & case sheets'],

            'opd_collection' => ['name' => 'OPD Daily Collection', 'desc' => 'Daily OPD payment collection'],

        ]

    ],

    'ipd' => [

        'title' => 'IPD Services Module',

        'icon' => 'bi-hospital',

        'submenus' => [

            'ipd_adm' => ['name' => 'IPD Admission', 'desc' => 'Admit and manage in-patients'],

            'ipd_doc' => ['name' => 'IPD Doctor Desk', 'desc' => 'In-patient doctor rounds'],

            'ipd_nurse' => ['name' => 'Nurse Station', 'desc' => 'Nursing and patient care'],

            'ipd_bill' => ['name' => 'IPD Billing', 'desc' => 'In-patient billing'],

            'ipd_discharge' => ['name' => 'Discharge Summary', 'desc' => 'Patient discharge and summary'],

        ]

    ],

];

$flat_submenus = [

    'dashboard' => 'Dashboard',

    'masters_org' => 'Hospital Profile Setup',

    'masters_states' => 'State Master',

    'masters_city' => 'City Master',

    'masters_financial_year' => 'Financial Year Master',

    'masters_centers' => 'Centers & Branches',

    'masters_users' => 'Staff & Access Control',

    'masters_roles' => 'Role Master',

    'masters_patients' => 'Patient Master',

    'masters_payment_modes' => 'Payment Modes',

    'masters_insurance_categories' => 'Category & Insurance',

    'masters_doctors' => 'Doctors & Schedules',

    'masters_departments' => 'Departments',

    'masters_qualifications' => 'Qualifications',

    'masters_specializations' => 'Specializations',

    'masters_days' => 'Working Days',

    'masters_doctor_services' => 'Doctor Service Master',

    'masters_doctor_tariff' => 'Doctor Tariff Master',

    'rx_units' => 'Unit Master',

    'rx_frequency' => 'Frequency Master',

    'rx_meals' => 'Meal Master',

    'rx_durations' => 'Duration Master',

    'rx_medicines' => 'Medicine Master',

    'rx_symptoms' => 'Symptom Master',

    'rx_diagnoses' => 'Diagnosis Master',

    'rx_investigations' => 'Investigation Master',

    'rx_vitals' => 'Vital Master',

    'rx_advices' => 'Advices Master',

    'rx_templates' => 'Prescription Templates',

    'fac_buildings' => 'Buildings',

    'fac_blocks' => 'Blocks',

    'fac_floors' => 'Floors',

    'fac_room_categories' => 'Room Categories',

    'fac_rooms' => 'Rooms Master',

    'fac_bed' => 'Bed Master',

    'opd_appt' => 'Appointments',

    'opd_reg' => 'OPD Registration & Token',

    'opd_queue' => 'Patient Live Queue',

    'opd_doctor' => 'Doctor Desk (Rx)',

    'opd_visit_list' => 'OPD Visit List',

    'opd_collection' => 'OPD Daily Collection',

    'ipd_adm' => 'IPD Admission',

    'ipd_doc' => 'IPD Doctor Desk',

    'ipd_nurse' => 'Nurse Station',

    'ipd_bill' => 'IPD Billing',

    'ipd_discharge' => 'Discharge Summary',

];

// 2. Status Toggle via URL

if (isset($_GET['toggle_status'])) {

    $toggle_id = (int)$_GET['toggle_status'];

    $current   = (int)($_GET['current'] ?? 1);

    $new_st    = ($current === 1) ? 0 : 1;

    try {

        $stmt = $tenant_pdo->prepare("UPDATE tenant_users SET status = ? WHERE user_id = ?");

        $stmt->execute([$new_st, $toggle_id]);

        $_SESSION['swal_success'] = "User status update ho gaya!";

    } catch (PDOException $e) {

        $_SESSION['swal_error'] = "Status update failed: " . $e->getMessage();

    }

    header("Location: users.php");

    exit;

}

// 3. Fetch Edit User Data

$edit_data = null;

$user_assigned_menus = [];

if (isset($_GET['edit'])) {

    $edit_id = (int)$_GET['edit'];

    $stmt = $tenant_pdo->prepare("SELECT * FROM tenant_users WHERE user_id = ?");

    $stmt->execute([$edit_id]);

    $edit_data = $stmt->fetch();

    if ($edit_data && !empty($edit_data['menu_access'])) {

        $user_assigned_menus = json_decode($edit_data['menu_access'], true) ?: [];

    }

}

// 4. Create or Update Staff User

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $fullname  = trim($_POST['fullname'] ?? '');

    $username  = trim($_POST['username'] ?? '');

    $password  = trim($_POST['password'] ?? '');

    $role_id   = (int)($_POST['role_id'] ?? 0);

    $doctor_id = (int)($_POST['doctor_id'] ?? 0);

    $center_id = (int)($_POST['center_id'] ?? 1);

    $mobile    = trim($_POST['mobile'] ?? '');

    $is_admin  = isset($_POST['is_admin']) ? 1 : 0;

    $status    = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    // Save only the permissions explicitly selected by the administrator.

    $raw_menus = $_POST['menu_access'] ?? [];

    $selected_menus = json_encode(array_values($raw_menus));

    $role_type_stmt = $tenant_pdo->prepare("SELECT role_type FROM tenant_roles WHERE role_id = ? LIMIT 1");

    $role_type_stmt->execute([$role_id]);

    $selected_role_type = strtolower(trim((string)$role_type_stmt->fetchColumn()));

    if (empty($fullname) || empty($username) || empty($role_id)) {

        $_SESSION['swal_error'] = "Mandatory fields bharna zaroori hai!";

    } elseif ($selected_role_type === 'doctor' && $doctor_id <= 0) {

        $_SESSION['swal_error'] = "Doctor role ke liye Doctor Master se doctor select karna zaroori hai!";

    } else {

        if ($_POST['action'] === 'save_user') {

            if (empty($password)) {

                $_SESSION['swal_error'] = "Password daalna zaroori hai!";

            } else {

                try {

                    $stmt = $tenant_pdo->prepare("

                        INSERT INTO tenant_users

(center_id, role_id, doctor_id, fullname, username, password, mobile, menu_access, is_admin, status)

VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)

                    ");

                    $stmt->execute([$center_id, $role_id, ($selected_role_type === 'doctor' ? $doctor_id : null), $fullname, $username, $password, $mobile, $selected_menus, $is_admin, $status]);

                    $_SESSION['swal_success'] = "Staff user successfully ban gaya!";

                    header("Location: users.php");

                    exit;

                } catch (PDOException $e) {

                    $_SESSION['swal_error'] = "Error: Username pehle se maujood ho sakta hai.";

                }

            }

        } elseif ($_POST['action'] === 'update_user') {

            $user_id = (int)$_POST['user_id'];

            try {

                if (!empty($password)) {

                    $stmt = $tenant_pdo->prepare("

                        UPDATE tenant_users

                        SET center_id = ?, role_id = ?, doctor_id = ?, fullname = ?, username = ?, password = ?, mobile = ?, menu_access = ?, is_admin = ?, status = ?

                        WHERE user_id = ?

                    ");

                    $stmt->execute([$center_id, $role_id, ($selected_role_type === 'doctor' ? $doctor_id : null), $fullname, $username, $password, $mobile, $selected_menus, $is_admin, $status, $user_id]);

                } else {

                    $stmt = $tenant_pdo->prepare("

                        UPDATE tenant_users

                        SET center_id = ?, role_id = ?, doctor_id = ?, fullname = ?, username = ?, mobile = ?, menu_access = ?, is_admin = ?, status = ?

                        WHERE user_id = ?

                    ");

                    $stmt->execute([$center_id, $role_id, ($selected_role_type === 'doctor' ? $doctor_id : null), $fullname, $username, $mobile, $selected_menus, $is_admin, $status, $user_id]);

                }

                $_SESSION['swal_success'] = "User permissions update ho gayi!";

                header("Location: users.php");

                exit;

            } catch (PDOException $e) {

                $_SESSION['swal_error'] = "Update failed: " . $e->getMessage();

            }

        }

    }

}

// Fetch Roles, Centers & All Users

$roles = $tenant_pdo->query("SELECT * FROM tenant_roles WHERE status = 1")->fetchAll();

$centers = $tenant_pdo->query("SELECT * FROM master_centers WHERE status = 1")->fetchAll();

$doctors = [];

try {

    $doctorStmt = $tenant_pdo->query("SELECT id, full_name, center_id FROM master_doctors WHERE status = 1 ORDER BY full_name");

    $doctors = $doctorStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (PDOException $e) {

    $doctors = [];

}

$users = $tenant_pdo->query("

    SELECT u.*, r.role_name, r.role_type, c.center_name

    FROM tenant_users u

    LEFT JOIN tenant_roles r ON u.role_id = r.role_id

    LEFT JOIN master_centers c ON u.center_id = c.center_id

    ORDER BY u.user_id DESC

")->fetchAll();

require_once __DIR__ . '/layout_header.php';

?>

<!-- SweetAlert2 CDN -->

<script src="https\://cdn.jsdelivr.net/npm/sweetalert2\@11"></script>

<style>

.form-switch .form-check-input { width: 2.2em; height: 1.15em; cursor: pointer; }

.btn-action-edit { background-color: #e0f2fe; color: #0284c7; border: none; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; transition: 0.2s; text-decoration: none; }

.btn-action-edit:hover { background-color: #0284c7; color: #fff; }

.badge-axis { font-size: 0.72rem; padding: 2px 6px; margin: 1px; border-radius: 4px; background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; display: inline-block; }

.accordion-module-card {

    border: 1px solid #e2e8f0;

    border-radius: 8px;

    margin-bottom: 10px;

    background: #ffffff;

    overflow: hidden;

}

.accordion-module-header {

    background: #f8fafc;

    padding: 10px 14px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    cursor: pointer;

    user-select: none;

    border-bottom: 1px solid transparent;

    transition: *background* 0.2s;

}

.accordion-module-header:hover {

    background: #f1f5f9;

}

.accordion-module-header.active {

    border-bottom-color: #e2e8f0;

    background: #f0fdf4;

}

.accordion-arrow {

    transition: transform 0.2s ease;

}

.accordion-arrow.rotated {

    transform: rotate(180deg);

}

.sub-menu-row {

    padding: 8px 14px;

    border-bottom: 1px dashed #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: space-between;

}

.sub-menu-row:last-child {

    border-bottom: none;

}

</style>

<div class="container-fluid p-0">

    <div class="row g-4">

        <!-- FORM COLUMN -->

        <div class="col-xl-5 col-lg-5">

            <div class="card border-0 shadow-sm rounded-3">

                <div class="card-header bg-white py-3">

                    <h6 class="mb-0 fw-bold text-dark">

                        <i class="bi <?= $edit_data ? 'bi-pencil-square text-primary' : 'bi-person-plus-fill text-primary' ?> me-2"></i>

                        <?= $edit_data ? 'Edit User & Permissions' : 'Create New User / Doctor' ?>

                    </h6>

                </div>

                <div class="card-body">

                    <form method="POST" action="users.php">

                        <input type="hidden" name="action" value="<?= $edit_data ? 'update_user' : 'save_user' ?>">

                        <input type="hidden" id="edit_id" value="<?= $edit_data ? $edit_data['user_id'] : 0 ?>">

                        <?php if ($edit_data): ?>

                            <input type="hidden" name="user_id" value="<?= $edit_data['user_id'] ?>">

                        <?php endif; ?>

                        <div class="mb-3">

                            <label class="form-label small fw-semibold">Full Name *</label>

                            <input type="text" name="fullname" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['fullname'] ?? '') ?>" required>

                        </div>

                        <div class="row g-2 mb-3">

                            <div class="col-6">

                                <label class="form-label small fw-semibold">Role *</label>

                                <select name="role_id" class="form-select form-select-sm" required>

                                    <option value="">Select Role</option>

                                    <?php foreach ($roles as $r): ?>

                                        <option value="<?= $r['role_id'] ?>" data-role-type="<?= htmlspecialchars($r['role_type'] ?? 'general') ?>" <?= (isset($edit_data['role_id']) && $edit_data['role_id'] == $r['role_id']) ? 'selected' : '' ?>>

                                            <?= htmlspecialchars($r['role_name']) ?><?= !empty($r['role_type']) ? ' (' . htmlspecialchars(ucfirst($r['role_type'])) . ')' : '' ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-6">

                                <label class="form-label small fw-semibold">Branch Center</label>

                                <select name="center_id" class="form-select form-select-sm">

                                    <?php foreach ($centers as $c): ?>

                                        <option value="<?= $c['center_id'] ?>" <?= (isset($edit_data['center_id']) && $edit_data['center_id'] == $c['center_id']) ? 'selected' : '' ?>>

                                            <?= htmlspecialchars($c['center_name']) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                        </div>

                        <div class="mb-3" id="doctorAssignmentWrap" style="display:none;">

                            <label class="form-label small fw-semibold">Link Doctor Master <span class="text-danger">*</span></label>

                            <select name="doctor_id" id="doctor_id" class="form-select form-select-sm">

                                <option value="">Select Doctor</option>

                                <?php foreach ($doctors as $doc): ?>

                                    <option value="<?= (int)$doc['id'] ?>" data-center-id="<?= (int)$doc['center_id'] ?>"

                                        <?= (isset($edit_data['doctor_id']) && (int)$edit_data['doctor_id'] === (int)$doc['id']) ? 'selected' : '' ?>>

                                        <?= htmlspecialchars($doc['full_name']) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <small class="text-muted">Doctor role ko uske Doctor Master profile se link karein.</small>

                        </div>

                        <div class="row g-2 mb-3">

                            <div class="col-6">

                                <label class="form-label small fw-semibold">Username / Email *</label>

                                <input type="text" name="username" id="username" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['username'] ?? '') ?>" required onkeyup="checkUsername()">

                                <span id="username_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> Username already taken!</span>

                            </div>

                            <div class="col-6">

                                <label class="form-label small fw-semibold">

                                    <?= $edit_data ? 'Change Password' : 'Password *' ?>

                                    <?php if($edit_data): ?><span class="text-muted fw-normal" style="font-size: 0.7rem;">(Leave blank to keep same)</span><?php endif; ?>

                                </label>

                                <div class="input-group input-group-sm">

                                    <input

                                        type="password"

                                        name="password"

                                        id="staffPassword"

                                        class="form-control"
                                        value="<?= $edit_data ? htmlspecialchars($edit_data['password'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>"

                                        autocomplete="new-password"

                                        placeholder="<?= $edit_data ? 'Leave blank to keep current password' : 'Enter password' ?>"

                                        <?= $edit_data ? '' : 'required' ?>

                                    >

                                    <button

                                        class="btn btn-outline-secondary"

                                        type="button"

                                        id="toggleStaffPassword"

                                        onclick="toggleStaffPasswordVisibility()"

                                        aria-label="Show password"

                                        title="Show password"

                                    >

                                        <i class="bi bi-eye" id="staffPasswordEyeIcon"></i>

                                    </button>

                                </div>

                            </div>

                        </div>

                        <div class="row g-2 mb-3">

                            <div class="col-6">

                                <label class="form-label small fw-semibold">Mobile No.</label>

                                <input type="text" name="mobile" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['mobile'] ?? '') ?>">

                            </div>

                            <div class="col-6">

                                <label class="form-label small fw-semibold">Status *</label>

                                <select name="status" class="form-select form-select-sm" required>

                                    <option value="1" <?= (!isset($edit_data['status']) || (int)$edit_data['status'] === 1) ? 'selected' : '' ?>>Active</option>

                                    <option value="0" <?= (isset($edit_data['status']) && (int)$edit_data['status'] === 0) ? 'selected' : '' ?>>Inactive</option>

                                </select>

                            </div>

                        </div>

                        <!-- Full Admin Override -->

                        <div class="p-2 mb-3 bg-danger bg-opacity-10 border border-danger-subtle rounded-3 d-flex align-items-center justify-content-between">

                            <div>

                                <span class="small fw-bold text-danger"><i class="bi bi-shield-check me-1"></i> Full Hospital Admin Axis</span>

                                <div class="text-muted" style="font-size: 0.72rem;">Grant all permissions without restrictions</div>

                            </div>

                            <div class="form-check form-switch m-0">

                                <input class="form-check-input" type="checkbox" name="is_admin" value="1" id="is_admin_flag" <?= (!empty($edit_data['is_admin']) && $edit_data['is_admin'] == 1) ? 'checked' : '' ?> onchange="handleAdminToggle(this.checked)">

                            </div>

                        </div>

                        <!-- Hierarchical Menu Permissions -->

                        <div class="mb-3">

                            <div class="d-flex justify-content-between align-items-center mb-2">

                                <label class="form-label small fw-bold text-dark m-0">

                                    <i class="bi bi-diagram-3-fill text-primary me-1"></i> Menu & Sub-Menu Permissions:

                                </label>

                                <div class="form-check form-switch m-0 d-flex align-items-center gap-2">

                                    <label class="form-check-label small fw-semibold text-primary m-0" for="masterToggle" style="font-size: 0.75rem;">Toggle All</label>

                                    <input class="form-check-input" type="checkbox" id="masterToggle" onchange="toggleAllMenus(this.checked)">

                                </div>

                            </div>

                            <div class="p-2 bg-light border rounded-3" style="max-height: 380px; overflow-y: auto;">

                                    <?php $dashboard_checked = $edit_data ? in_array('dashboard', $user_assigned_menus, true) : false; ?>

                                    <div class="sub-menu-row bg-white border rounded-2 mb-2 px-3">

                                        <div>

                                            <div class="fw-semibold text-dark small">Dashboard</div>

                                            <small class="text-muted" style="font-size:0.7rem;">Access to hospital dashboard</small>

                                        </div>

                                        <div class="form-check form-switch m-0">

                                            <input class="form-check-input menu-toggle-switch" type="checkbox" name="menu_access[]" value="dashboard" id="m_dashboard" <?= $dashboard_checked ? 'checked' : '' ?>>

                                        </div>

                                    </div>

                                <?php foreach ($module_hierarchy as $parent_key => $parent_val): ?>

                                    <div class="accordion-module-card">

                                        <div class="accordion-module-header active" onclick="toggleAccordion('<?= $parent_key ?>_body', '<?= $parent_key ?>_arrow', this)">

                                            <div class="d-flex align-items-center gap-2">

                                                <i class="bi <?= $parent_val['icon'] ?> text-primary fs-5"></i>

                                                <span class="fw-bold text-dark small"><?= $parent_val['title'] ?></span>

                                            </div>

                                            <div class="d-flex align-items-center gap-2">

                                                <span class="badge bg-light text-muted border small" style="font-size: 0.68rem;"><?= count($parent_val['submenus']) ?> Menus</span>

                                                <i class="bi bi-chevron-down accordion-arrow rotated fs-6 text-muted" id="<?= $parent_key ?>_arrow"></i>

                                            </div>

                                        </div>

                                        <div id="<?= $parent_key ?>_body" class="accordion-module-body" style="display: block;">

                                            <?php foreach ($parent_val['submenus'] as $sub_key => $sub_val): ?>

                                                <?php

                                                    $checked = false;

                                                    if ($edit_data) {

                                                        $checked = in_array($sub_key, $user_assigned_menus);

                                                    } else {

                                                        $checked = in_array($sub_key, ['opd_reg', 'opd_queue']);

                                                    }

                                                ?>

                                                <div class="sub-menu-row">

                                                    <div>

                                                        <div class="fw-semibold text-dark small" style="line-height: 1.1;"><?= $sub_val['name'] ?></div>

                                                        <small class="text-muted" style="font-size: 0.7rem;"><?= $sub_val['desc'] ?></small>

                                                    </div>

                                                    <div class="form-check form-switch m-0">

                                                        <input class="form-check-input menu-toggle-switch" type="checkbox" name="menu_access[]" value="<?= $sub_key ?>" id="m_<?= $sub_key ?>" <?= $checked ? 'checked' : '' ?>>

                                                    </div>

                                                </div>

                                            <?php endforeach; ?>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        </div>

                        <div class="d-flex gap-2">

                            <button type="submit" class="btn btn-primary btn-sm flex-grow-1 fw-semibold" id="btnSubmit">

                                <i class="bi <?= $edit_data ? 'bi-check-lg' : 'bi-save' ?> me-1"></i> <?= $edit_data ? 'Update Axis & User' : 'Save Staff User' ?>

                            </button>

                            <?php if ($edit_data): ?>

                                <a href="users.php" class="btn btn-light btn-sm border">Cancel</a>

                            <?php else: ?>

                                <button type="reset" class="btn btn-light btn-sm border">Reset</button>

                            <?php endif; ?>

                        </div>

                    </form>

                </div>

            </div>

        </div>

        <!-- DIRECTORY TABLE COLUMN -->

        <div class="col-xl-7 col-lg-7">

            <div class="card border-0 shadow-sm rounded-3">

                <div class="p-3 border-bottom d-flex justify-content-between align-items-center gap-3 bg-white rounded-top-3">

                    <h6 class="fw-bold text-dark m-0"><i class="bi bi-people-fill text-primary me-2"></i>Staff & Access Directory</h6>

                    <!-- Search bar placeholder wapas pehle jaisa -->

                    <input type="text" id="searchUserInput" class="form-control form-control-sm w-50" placeholder="Search by name, role or username...">

                </div>

                <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0" id="userTable">

                        <thead class="table-light">

                            <tr>

                                <th>User</th>

                                <th>Role & Branch</th>

                                <th>Assigned Sub-Menus</th>

                                <th>Status</th>

                                <th class="text-center" style="width: 100px;">Action</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php if (empty($users)): ?>

                                <tr><td colspan="5" class="text-center text-muted py-4">No staff users created yet.</td></tr>

                            <?php else: ?>

                                <?php foreach ($users as $u): ?>

                                    <?php

                                        $u_menus = json_decode($u['menu_access'] ?? '[]', true) ?: [];

                                    ?>

                                    <tr>

                                        <td>

                                            <div class="fw-bold text-dark"><?= htmlspecialchars($u['fullname']) ?></div>

                                            <div class="small text-muted"><code><?= htmlspecialchars($u['username']) ?></code></div>

                                        </td>

                                        <td>

                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= htmlspecialchars($u['role_name'] ?? 'N/A') ?><?= !empty($u['role_type']) ? ' · ' . htmlspecialchars(ucfirst($u['role_type'])) : '' ?></span>

                                            <div class="small text-muted mt-1"><?= htmlspecialchars($u['center_name'] ?? 'Main') ?></div>

                                        </td>

                                        <td>

                                            <?php if ($u['is_admin'] == 1): ?>

                                                <span class="badge bg-danger">Full Admin Axis</span>

                                            <?php else: ?>

                                                <div style="max-width: 250px; max-height: 80px; overflow-y: auto;">

                                                    <?php foreach ($u_menus as $m_key): ?>

                                                        <?php if (isset($flat_submenus[$m_key])): ?>

                                                            <span class="badge-axis"><?= $flat_submenus[$m_key] ?? $m_key ?></span>

                                                        <?php endif; ?>

                                                    <?php endforeach; ?>

                                                </div>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <span class="badge <?= (int)$u['status'] === 1 ? 'bg-success' : 'bg-danger' ?>">

                                                <?= (int)$u['status'] === 1 ? 'Active' : 'Inactive' ?>

                                            </span>

                                        </td>

                                        <td class="text-center">

                                            <div class="d-flex align-items-center justify-content-center gap-2">

                                                <a href="users.php?edit=<?= $u['user_id'] ?>" class="btn-action-edit" title="Edit Axis">

                                                    <i class="bi bi-pencil-fill" style="font-size: 0.85rem;"></i>

                                                </a>

                                                <div class="form-check form-switch m-0" title="Toggle Status">

                                                    <input class="form-check-input" type="checkbox" role="switch"

                                                           <?= (int)$u['status'] === 1 ? 'checked' : '' ?>

                                                           onclick="event.preventDefault(); confirmToggle(<?= $u['user_id'] ?>, <?= $u['status'] ?>)">

                                                </div>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</div>

<script>

let typingTimer;

function checkUsername() {

    clearTimeout(typingTimer);

    let usernameInput = document.getElementById('username').value.trim();

    let editId = document.getElementById('edit_id').value;

    let errorSpan = document.getElementById('username_error');

    let btnSubmit = document.getElementById('btnSubmit');

    if (usernameInput.length === 0) {

        errorSpan.style.display = 'none';

        btnSubmit.disabled = false;

        return;

    }

    typingTimer = setTimeout(function() {

        fetch(`users.php?check_username=${encodeURIComponent(usernameInput)}&edit_id=${editId}`)

        .then(response => response.json())

        .then(data => {

            if (data.exists) {

                errorSpan.style.display = 'block';

                btnSubmit.disabled = true;

            } else {

                errorSpan.style.display = 'none';

                btnSubmit.disabled = false;

            }

        })

        .catch(error => console.error("Error checking username:", error));

    }, 500);

}

function toggleAccordion(bodyId, arrowId, headerEl) {

    const body = document.getElementById(bodyId);

    const arrow = document.getElementById(arrowId);

    if (!body) return;

    if (body.style.display === 'none' || body.style.display === '') {

        body.style.display = 'block';

        if (arrow) arrow.classList.add('rotated');

        if (headerEl) headerEl.classList.add('active');

    } else {

        body.style.display = 'none';

        if (arrow) arrow.classList.remove('rotated');

        if (headerEl) headerEl.classList.remove('active');

    }

}

function toggleAllMenus(checked) {

    let switches = document.querySelectorAll('.menu-toggle-switch');

    switches.forEach(sw => sw.checked = checked);

}

function handleAdminToggle(isAdmin) {

    let switches = document.querySelectorAll('.menu-toggle-switch');

    let masterToggle = document.getElementById('masterToggle');

    if (isAdmin) {

        switches.forEach(sw => { sw.checked = true; sw.disabled = true; });

        if(masterToggle) { masterToggle.checked = true; masterToggle.disabled = true; }

    } else {

        switches.forEach(sw => sw.disabled = false);

        if(masterToggle) { masterToggle.disabled = false; }

    }

}

function updateDoctorAssignment() {

    const roleSelect = document.querySelector('select[name="role_id"]');

    const centerSelect = document.querySelector('select[name="center_id"]');

    const wrap = document.getElementById('doctorAssignmentWrap');

    const doctorSelect = document.getElementById('doctor_id');

    if (!roleSelect || !wrap || !doctorSelect) return;

    const roleOption = roleSelect.options[roleSelect.selectedIndex];

    const isDoctor = roleOption && (roleOption.dataset.roleType || '').toLowerCase() === 'doctor';

    wrap.style.display = isDoctor ? '' : 'none';

    doctorSelect.required = !!isDoctor;

    const centerId = centerSelect ? centerSelect.value : '';

    Array.from(doctorSelect.options).forEach(option => {

        if (!option.value) return;

        const matches = !centerId || option.dataset.centerId === centerId;

        option.hidden = !matches;

        if (!matches && option.selected) doctorSelect.value = '';

    });

}

document.querySelector('select[name="role_id"]')?.addEventListener('change', updateDoctorAssignment);

document.querySelector('select[name="center_id"]')?.addEventListener('change', updateDoctorAssignment);

window.addEventListener('DOMContentLoaded', () => {

    updateDoctorAssignment();

    let adminFlag = document.getElementById('is_admin_flag');

    if(adminFlag && adminFlag.checked) {

        handleAdminToggle(true);

    }

});

function toggleStaffPasswordVisibility() {

    const passwordInput = document.getElementById('staffPassword');

    const eyeIcon = document.getElementById('staffPasswordEyeIcon');

    const toggleButton = document.getElementById('toggleStaffPassword');

    if (!passwordInput || !eyeIcon || !toggleButton) return;

    const showPassword = passwordInput.type === 'password';

    passwordInput.type = showPassword ? 'text' : 'password';

    eyeIcon.classList.toggle('bi-eye', !showPassword);

    eyeIcon.classList.toggle('bi-eye-slash', showPassword);

    const label = showPassword ? 'Hide password' : 'Show password';

    toggleButton.setAttribute('aria-label', label);

    toggleButton.setAttribute('title', label);

}

function confirmToggle(userId, currentStatus) {

    let actionTxt = currentStatus === 1 ? 'Deactivate' : 'Activate';

    Swal.fire({

        title: `Are you sure?`,

        text: `Do you want to ${actionTxt} this user?`,

        icon: 'warning',

        showCancelButton: true,

        confirmButtonColor: currentStatus === 1 ? '#d33' : '#198754',

        cancelButtonColor: '#6c757d',

        confirmButtonText: `Yes, ${actionTxt}!`

    }).then((result) => {

        if (result.isConfirmed) {

            window.location.href = `users.php?toggle_status=${userId}&current=${currentStatus}`;

        }

    });

}

const searchUserInput = document.getElementById('searchUserInput');

if (searchUserInput) searchUserInput.addEventListener('keyup', function() {

    let filter = this.value.toLowerCase();

    let rows = document.querySelectorAll('#userTable tbody tr');

    rows.forEach(row => {

        if(row.cells.length > 1) {

            let text = row.innerText.toLowerCase();

            row.style.display = text.includes(filter) ? '' : 'none';

        }

    });

});

</script>
