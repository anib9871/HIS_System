<?php
// admin/master_doctors.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Doctor & Availability Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

$err = "";
$msg = "";

// --- 1. HANDLE DOCTOR PROFILE CREATE / UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_doctor'])) {
    
    $doc_code          = strtoupper(trim($_POST['doc_code'] ?? ''));
    $registration_no   = strtoupper(trim($_POST['registration_no'] ?? ''));
    $full_name         = ucwords(strtolower(trim($_POST['full_name'] ?? '')));
    $mobile            = trim($_POST['mobile'] ?? '');
    $email             = strtolower(trim($_POST['email'] ?? ''));
    
    $qualification_id  = !empty($_POST['qualification_id']) ? (int)$_POST['qualification_id'] : null;
    $specialization_id = !empty($_POST['specialization_id']) ? (int)$_POST['specialization_id'] : null;
    $department_id     = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    
    $status            = isset($_POST['status']) ? 1 : 0;
    $edit_id           = (int)($_POST['edit_id'] ?? 0);

    // AUTO-GENERATE DOC CODE BASED ON DEPARTMENT IF EMPTY
    if (empty($doc_code)) {
        $dept_prefix = 'DOC'; 
        if ($department_id) {
            try {
                $pref_stmt = $tenant_pdo->prepare("SELECT dept_prefix FROM master_departments WHERE id = ?");
                $pref_stmt->execute([$department_id]);
                $fetch_pref = $pref_stmt->fetchColumn();
                if ($fetch_pref) $dept_prefix = strtoupper($fetch_pref);
            } catch (Exception $e) {}
        }
        $doc_code = $dept_prefix . '-' . rand(1000, 9999);
    }

    if (empty($full_name) || empty($registration_no)) {
        $err = "Registration No. and Full Name are mandatory.";
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_doctors SET doc_code = ?, registration_no = ?, full_name = ?, qualification_id = ?, specialization_id = ?, department_id = ?, mobile = ?, email = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$doc_code, $registration_no, $full_name, $qualification_id, $specialization_id, $department_id, $mobile, $email, $status, $edit_id, $org_id, $center_id]);
                if(function_exists('set_flash_msg')) set_flash_msg("Doctor profile updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_doctors (org_id, center_id, doc_code, registration_no, full_name, qualification_id, specialization_id, department_id, mobile, email, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id, $doc_code, $registration_no, $full_name, $qualification_id, $specialization_id, $department_id, $mobile, $email, $status]);
                if(function_exists('set_flash_msg')) set_flash_msg("New doctor record created successfully.");
            }
            header("Location: master_doctors.php?tab=doctors");
            exit;
        } catch (PDOException $e) {
            die("<h3 style='color:red; text-align:center; padding:20px;'>Doctor Save Error: " . $e->getMessage() . "</h3>");
        }
    }
}

// --- 2. HANDLE AVAILABILITY SCHEDULE ADD (WITH INSERT IGNORE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_schedule'])) {
    $doctor_id    = (int)($_POST['doctor_id'] ?? 0);
    $days_selected= $_POST['days_of_week'] ?? [];
    $shift_name   = trim($_POST['shift_name'] ?? 'Morning');
    $start_time   = $_POST['start_time'] ?? '09:00:00';
    $end_time     = $_POST['end_time'] ?? '13:00:00';
    $consult_time = (int)($_POST['avg_consult_time_mins'] ?? 10);
    $max_tokens   = (int)($_POST['max_tokens'] ?? 50);

    if ($doctor_id > 0 && !empty($days_selected)) {
        try {
            $stmt = $tenant_pdo->prepare("INSERT IGNORE INTO doctor_availability (org_id, center_id, doctor_id, day_of_week, shift_name, start_time, end_time, avg_consult_time_mins, max_tokens) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $count = 0;
            foreach ($days_selected as $day) {
                $stmt->execute([$org_id, $center_id, $doctor_id, $day, $shift_name, $start_time, $end_time, $consult_time, $max_tokens]);
                if ($stmt->rowCount() > 0) {
                    $count++;
                }
            }
            if(function_exists('set_flash_msg')) set_flash_msg("{$count} new availability slot(s) added successfully.");
            header("Location: master_doctors.php?tab=schedules");
            exit;
        } catch (PDOException $e) {
            die("<h3 style='color:red; text-align:center; padding:20px;'>Schedule Save Error: " . $e->getMessage() . "</h3>");
        }
    } else {
        $err = "Please select a Doctor and at least One Day.";
    }
}

// --- 3. STATUS TOGGLE & DELETE ACTIONS ---
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $current = (int)($_GET['st'] ?? 1);
    $new_st = ($current === 1) ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_doctors SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$new_st, $id, $org_id, $center_id]);
    if(function_exists('set_flash_msg')) set_flash_msg("Doctor status updated successfully.");
    header("Location: master_doctors.php?tab=doctors");
    exit;
}

if (isset($_GET['del_sch'])) {
    $sch_id = (int)$_GET['del_sch'];
    $tenant_pdo->prepare("DELETE FROM doctor_availability WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$sch_id, $org_id, $center_id]);
    if(function_exists('set_flash_msg')) set_flash_msg("Schedule slot deleted successfully.");
    header("Location: master_doctors.php?tab=schedules");
    exit;
}

// --- 4. SAFE FETCH DATA FOR DROPDOWNS ---
$qualifications_list = []; $specializations_list = []; $departments_list = [];

try { $qualifications_list = $tenant_pdo->query("SELECT id, qualification_name FROM master_qualifications WHERE status = 1 ORDER BY qualification_name ASC")->fetchAll() ?: []; } catch (Exception $e) {}
try { $specializations_list = $tenant_pdo->query("SELECT id, specialization_name FROM master_specializations WHERE status = 1 ORDER BY specialization_name ASC")->fetchAll() ?: []; } catch (Exception $e) {}
try { $departments_list = $tenant_pdo->query("SELECT id, dept_name, dept_prefix FROM master_departments WHERE status = 1 ORDER BY dept_name ASC")->fetchAll() ?: []; } catch (Exception $e) {}

// PROPER FETCH FROM MASTER_DAYS TABLE
$master_days = [];
try {
    $day_stmt = $tenant_pdo->prepare("SELECT day_name FROM master_days WHERE status = 1 AND org_id = ? AND center_id = ? ORDER BY id ASC");
    $day_stmt->execute([$org_id, $center_id]);
    $fetched_days = $day_stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($fetched_days)) {
        $master_days = $fetched_days;
    }
} catch (Exception $e) {}

if (empty($master_days)) {
    $master_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
}

// Safe Doctor Query
$doctors = [];
try {
    $doctors = $tenant_pdo->prepare("
        SELECT md.*, 
               dp.dept_name, 
               q.qualification_name, 
               s.specialization_name
        FROM master_doctors md 
        LEFT JOIN master_departments dp ON md.department_id = dp.id 
        LEFT JOIN master_qualifications q ON md.qualification_id = q.id 
        LEFT JOIN master_specializations s ON md.specialization_id = s.id 
        WHERE md.org_id = ? AND md.center_id = ? 
        ORDER BY md.id DESC
    ");
    $doctors->execute([$org_id, $center_id]);
    $doctors = $doctors->fetchAll() ?: [];
} catch (Exception $e) {
    $doctors = $tenant_pdo->query("SELECT * FROM master_doctors ORDER BY id DESC")->fetchAll() ?: [];
}
$active_doctors = array_filter($doctors, fn($d) => (int)$d['status'] === 1);

$schedules = [];
try {
    $schedules = $tenant_pdo->prepare("
        SELECT s.*, d.full_name, d.doc_code 
        FROM doctor_availability s 
        JOIN master_doctors d ON s.doctor_id = d.id 
        WHERE s.org_id = ? AND s.center_id = ?
        ORDER BY d.full_name ASC, FIELD(s.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
    ");
    $schedules->execute([$org_id, $center_id]);
    $schedules = $schedules->fetchAll() ?: [];
} catch (Exception $e) {}

$active_tab = $_GET['tab'] ?? 'doctors';
require_once __DIR__ . '/layout_header.php';
?>

<style>
/* Modern Toggle Switch & Edit Button Styling */
.form-switch .form-check-input { width: 2.4em; height: 1.25em; cursor: pointer; }
.btn-action-edit { background-color: #e0f2fe; color: #0284c7; border: none; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; transition: 0.2s; text-decoration: none; }
.btn-action-edit:hover { background-color: #0284c7; color: #fff; }
</style>

<!-- ERROR MESSAGE DISPLAY -->
<?php if (!empty($err)): ?>
    <div class="alert alert-danger fw-bold py-2 px-3 shadow-sm mb-3">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($err) ?>
    </div>
<?php endif; ?>
<?php if (!empty($msg)): ?>
    <div class="alert alert-success fw-bold py-2 px-3 shadow-sm mb-3">
        <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<ul class="nav nav-pills mb-3 bg-white p-2 rounded-3 border shadow-sm" id="doctorMasterTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'doctors' ? 'active fw-bold' : 'text-secondary' ?> py-2 px-3 small" id="tab-doctors-btn" data-bs-toggle="pill" data-bs-target="#tab-doctors" type="button" role="tab">
            <i class="bi bi-person-badge-fill me-1"></i> Doctor Directory (<?= count($doctors) ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'schedules' ? 'active fw-bold' : 'text-secondary' ?> py-2 px-3 small" id="tab-schedules-btn" data-bs-toggle="pill" data-bs-target="#tab-schedules" type="button" role="tab">
            <i class="bi bi-calendar-week-fill me-1"></i> Availability Schedules (<?= count($schedules) ?>)
        </button>
    </li>
</ul>

<div class="tab-content" id="doctorMasterTabContent">
    <!-- TAB 1: DOCTOR DIRECTORY -->
    <div class="tab-pane fade <?= $active_tab === 'doctors' ? 'show active' : '' ?>" id="tab-doctors" role="tabpanel">
        <div class="card border shadow-sm mb-4">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark" id="docFormTitle"><i class="bi bi-person-plus-fill text-primary me-1"></i> Add / Edit Doctor Profile</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_doctor" value="1">
                    <input type="hidden" name="edit_id" id="doc_edit_id" value="0">

                    <div class="row g-2 mb-2">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Doc Code</label>
                            <input type="text" name="doc_code" id="doc_code" class="form-control form-control-sm font-monospace text-uppercase" placeholder="Auto">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Reg. No. *</label>
                            <input type="text" name="registration_no" id="registration_no" class="form-control form-control-sm font-monospace text-uppercase" placeholder="MCI-45892" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">Full Name *</label>
                            <input type="text" name="full_name" id="full_name" class="form-control form-control-sm fw-semibold text-capitalize" placeholder="Dr. Sameer Khan" required autofocus>
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Department</label>
                            <select name="department_id" id="department_id" class="form-select form-select-sm">
                                <option value="">-- General / None --</option>
                                <?php foreach ($departments_list as $dp): ?>
                                    <option value="<?= $dp['id'] ?>"><?= htmlspecialchars($dp['dept_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Qualification</label>
                            <select name="qualification_id" id="qualification_id" class="form-select form-select-sm">
                                <option value="">-- Optional --</option>
                                <?php foreach ($qualifications_list as $q): ?>
                                    <option value="<?= $q['id'] ?>"><?= htmlspecialchars($q['qualification_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Specialization</label>
                            <select name="specialization_id" id="specialization_id" class="form-select form-select-sm">
                                <option value="">-- Optional --</option>
                                <?php foreach ($specializations_list as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['specialization_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Mobile No.</label>
                            <input type="text" name="mobile" id="mobile" class="form-control form-control-sm" placeholder="9876543210">
                        </div>
                    </div>

                    <div class="row g-2 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">Email Address</label>
                            <input type="email" name="email" id="email" class="form-control form-control-sm text-lowercase" placeholder="doc@hospital.com">
                        </div>
                        <div class="col-md-2 d-flex justify-content-center">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="status" id="doc_status" checked>
                                <label class="form-check-label small" for="doc_status">Active</label>
                            </div>
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button type="reset" class="btn btn-light btn-sm border flex-fill" onclick="resetDocForm()">Reset</button>
                            <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnDocSubmit"><i class="bi bi-check2-circle me-1"></i> Save Doctor</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- DOCTORS LIST -->
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-people-fill text-primary me-1"></i> Registered Doctors</span>
                <input type="text" id="filterDoctors" class="form-control form-control-sm w-auto" placeholder="Search doctor..." style="max-width: 180px;">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 small" id="doctorTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3">Code</th>
                                <th>Doctor Info</th>
                                <th>Department / Spec.</th>
                                <th>Contact</th>
                                <th>Status Toggle</th>
                                <th class="text-center pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($doctors)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No doctors found. Add records from the form.</td></tr>
                            <?php else: ?>
                                <?php foreach ($doctors as $d): ?>
                                    <?php $is_act = (int)$d['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3"><span class="badge bg-light text-primary border font-monospace"><?= htmlspecialchars($d['doc_code'] ?? '') ?></span></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($d['full_name'] ?? '') ?></div>
                                            <span class="text-muted" style="font-size: 0.75rem;">Reg: <?= htmlspecialchars($d['registration_no'] ?? '') ?> <?= !empty($d['qualification_name']) ? " | {$d['qualification_name']}" : "" ?></span>
                                        </td>
                                        <td>
                                            <span class="fw-semibold text-secondary d-block"><?= htmlspecialchars($d['dept_name'] ?? 'General') ?></span>
                                            <span class="badge bg-light text-secondary border"><?= htmlspecialchars($d['specialization_name'] ?? 'General') ?></span>
                                        </td>
                                        <td>
                                            <small class="d-block text-muted"><?= htmlspecialchars($d['mobile'] ?? '-') ?></small>
                                        </td>
                                        <td>
                                            <!-- Modern Toggle Switch -->
                                            <div class="form-check form-switch m-0" title="Toggle Status (Active/Inactive)">
                                                <input class="form-check-input" type="checkbox" role="switch" 
                                                       <?= $is_act ? 'checked' : '' ?> 
                                                       onchange="window.location.href='?toggle_status=<?= $d['id'] ?>&st=<?= $d['status'] ?>'">
                                            </div>
                                        </td>
                                        <td class="text-center pe-3">
                                            <!-- Modern Edit Button -->
                                            <a href="javascript:void(0);" class="btn-action-edit" title="Edit Doctor Profile" onclick='editDoc(<?= htmlspecialchars(json_encode($d), ENT_QUOTES, 'UTF-8') ?>)'>
                                                <i class="bi bi-pencil-fill" style="font-size: 0.85rem;"></i>
                                            </a>
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

    <!-- TAB 2: AVAILABILITY SCHEDULES -->
    <div class="tab-pane fade <?= $active_tab === 'schedules' ? 'show active' : '' ?>" id="tab-schedules" role="tabpanel">
        <div class="row g-3">
            <div class="col-xl-4 col-lg-5">
                <div class="card border shadow-sm">
                    <div class="card-header bg-white py-2 px-3 border-bottom">
                        <span class="fw-bold small text-dark"><i class="bi bi-clock-history text-primary me-1"></i> Map Availability Slot</span>
                    </div>
                    <div class="card-body p-3">
                        <form method="POST" action="">
                            <input type="hidden" name="action_schedule" value="1">
                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary mb-1">Select Doctor *</label>
                                <select name="doctor_id" class="form-select form-select-sm" required>
                                    <option value="">-- Choose Doctor --</option>
                                    <?php foreach ($active_doctors as $ad): ?>
                                        <option value="<?= $ad['id'] ?>"><?= htmlspecialchars($ad['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="p-2 mb-2 bg-light border rounded-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold text-dark m-0">Days of Availability *</label>
                                    <div class="form-check m-0">
                                        <input class="form-check-input" type="checkbox" id="selectAllDays" onchange="toggleAllDays(this)">
                                        <label class="form-check-label small fw-bold text-primary" for="selectAllDays" style="cursor: pointer;">All <?= count($master_days) ?> Days</label>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 pt-1">
                                    <?php foreach ($master_days as $idx => $day): ?>
                                        <div class="form-check form-check-inline m-0">
                                            <input class="form-check-input day-checkbox" type="checkbox" name="days_of_week[]" value="<?= htmlspecialchars($day) ?>" id="day_<?= $idx ?>">
                                            <label class="form-check-label small text-uppercase" for="day_<?= $idx ?>" style="cursor: pointer;"><?= htmlspecialchars($day) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary mb-1">Shift Name</label>
                                <input type="text" name="shift_name" class="form-control form-control-sm text-capitalize" value="Morning">
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary mb-1">Start Time</label>
                                    <input type="time" name="start_time" class="form-control form-control-sm" value="09:00" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary mb-1">End Time</label>
                                    <input type="time" name="end_time" class="form-control form-control-sm" value="13:00" required>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary mb-1">Avg Min/Pt</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="avg_consult_time_mins" class="form-control" value="10">
                                        <span class="input-group-text">min</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold text-secondary mb-1">Max Token Cap</label>
                                    <input type="number" name="max_tokens" class="form-control form-control-sm" value="50">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                                <i class="bi bi-calendar-plus me-1"></i> Save Availability Slot(s)
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xl-8 col-lg-7">
                <div class="card border shadow-sm">
                    <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-dark"><i class="bi bi-calendar3 text-primary me-1"></i> Availability Roster</span>
                        <input type="text" id="filterSchedules" class="form-control form-control-sm w-auto" placeholder="Filter schedules...">
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
                            <table class="table table-hover align-middle mb-0 small" id="scheduleTable">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th class="ps-3">Doctor</th>
                                        <th>Day</th>
                                        <th>Shift & Timings</th>
                                        <th>Tokens</th>
                                        <th class="text-end pe-3">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($schedules)): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">No availability schedules configured yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($schedules as $s): ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($s['full_name'] ?? '') ?></div>
                                                </td>
                                                <td><span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= htmlspecialchars($s['day_of_week']) ?></span></td>
                                                <td>
                                                    <div class="fw-semibold"><?= date('h:i A', strtotime($s['start_time'])) ?> - <?= date('h:i A', strtotime($s['end_time'])) ?></div>
                                                    <small class="text-muted"><?= htmlspecialchars($s['shift_name']) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-dark"><?= $s['max_tokens'] ?> tokens</span>
                                                    <small class="text-muted d-block"><?= $s['avg_consult_time_mins'] ?> min/pt</small>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <a href="?del_sch=<?= $s['id'] ?>" onclick="return confirm('Delete this availability schedule?')" class="btn btn-outline-danger btn-sm py-0 px-2"><i class="bi bi-trash"></i></a>
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
    </div>
</div>

<script>
function toggleAllDays(source) {
    document.querySelectorAll('.day-checkbox').forEach(cb => cb.checked = source.checked);
}

document.getElementById('filterDoctors')?.addEventListener('keyup', function() {
    let q = this.value.toLowerCase();
    document.querySelectorAll('#doctorTable tbody tr').forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
});

document.getElementById('filterSchedules')?.addEventListener('keyup', function() {
    let q = this.value.toLowerCase();
    document.querySelectorAll('#scheduleTable tbody tr').forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
});

function editDoc(d) {
    document.getElementById('doc_edit_id').value = d.id;
    document.getElementById('doc_code').value = d.doc_code || '';
    document.getElementById('registration_no').value = d.registration_no || '';
    document.getElementById('full_name').value = d.full_name || '';
    document.getElementById('department_id').value = d.department_id || '';
    document.getElementById('qualification_id').value = d.qualification_id || '';
    document.getElementById('specialization_id').value = d.specialization_id || '';
    
    document.getElementById('mobile').value = d.mobile || '';
    document.getElementById('email').value = d.email || '';
    document.getElementById('doc_status').checked = (parseInt(d.status) === 1);
    
    document.getElementById('docFormTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Doctor Profile';
    document.getElementById('btnDocSubmit').innerHTML = '<i class="bi bi-check2-circle me-1"></i> Update Doctor';
    document.getElementById('full_name').focus();
    
    const triggerEl = document.querySelector('#tab-doctors-btn');
    bootstrap.Tab.getInstance(triggerEl) || new bootstrap.Tab(triggerEl).show();
}

function resetDocForm() {
    document.getElementById('doc_edit_id').value = '0';
    document.getElementById('doc_code').value = '';
    document.getElementById('registration_no').value = '';
    document.getElementById('full_name').value = '';
    document.getElementById('department_id').value = '';
    document.getElementById('qualification_id').value = '';
    document.getElementById('specialization_id').value = '';
    document.getElementById('mobile').value = '';
    document.getElementById('email').value = '';
    document.getElementById('docFormTitle').innerHTML = '<i class="bi bi-person-plus-fill text-primary me-1"></i> Add / Edit Doctor Profile';
    document.getElementById('btnDocSubmit').innerHTML = '<i class="bi bi-check2-circle me-1"></i> Save Doctor';
    document.getElementById('full_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>