<?php
// admin/opd_appointments.php
$page_title = "OPD Appointments & Live Queue";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php'; 

$msg = "";
$err = "";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// Keep Appointment -> Patient Master linked.
try {
    $tenant_pdo->exec("ALTER TABLE opd_appointments ADD COLUMN IF NOT EXISTS patient_id INT NULL AFTER center_id");
} catch (Exception $e) {}

function getAppointmentFY($visit_date = null) {
    $visit_date = $visit_date ?: date('Y-m-d');
    $ts = strtotime($visit_date);
    $m = (int)date('m', $ts);
    $y = (int)date('y', $ts);
    return ($m >= 4)
        ? sprintf("%02d%02d", $y, $y + 1)
        : sprintf("%02d%02d", $y - 1, $y);
}

function generateAppointmentUHID($tenant_pdo, $center_id, $fy_code) {
    $org_prefix = 'MAX';
    try {
        $v = $tenant_pdo->query("SELECT mnemonic FROM org_profile LIMIT 1")->fetchColumn();
        if (!empty($v)) $org_prefix = strtoupper(trim($v));
    } catch (Exception $e) {}

    $center_code = 'LKO';
    try {
        $s = $tenant_pdo->prepare("SELECT center_code FROM master_centers WHERE center_id = ?");
        $s->execute([$center_id]);
        $v = $s->fetchColumn();
        if (!empty($v)) $center_code = strtoupper(trim($v));
    } catch (Exception $e) {}

    $base = "{$org_prefix}-{$center_code}-{$fy_code}-";
    $s = $tenant_pdo->prepare("SELECT uhid FROM opd_patients WHERE uhid LIKE ? ORDER BY patient_id DESC LIMIT 1 FOR UPDATE");
    $s->execute([$base . '%']);
    $last = $s->fetchColumn();

    $next = $last ? ((int)str_replace($base, '', $last) + 1) : 1;
    return $base . $next;
}
$center_id = (int)($_SESSION['center_id'] ?? 1);
$today_date = date('Y-m-d');

// ==============================================================
 // PATIENT LOOKUP AJAX
 // Mobile number is the single lookup key for Patient Master.
 // ==============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_lookup') {
    header('Content-Type: application/json; charset=utf-8');

    $mobile_lookup = preg_replace('/\\D+/', '', $_GET['mobile'] ?? '');

    if (strlen($mobile_lookup) < 10) {
        echo json_encode(['found' => false]);
        exit;
    }

    try {
        $stmt = $tenant_pdo->prepare("
            SELECT patient_id, uhid, fullname, gender, age, mobile
            FROM opd_patients
            WHERE org_id = ?
              AND center_id = ?
              AND (mobile = ? OR mobile LIKE ?)
            ORDER BY patient_id DESC
            LIMIT 1
        ");
        $stmt->execute([$org_id, $center_id, $mobile_lookup, $mobile_lookup . ',%']);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(
            $patient
                ? ['found' => true, 'patient' => $patient]
                : ['found' => false]
        );
    } catch (Exception $e) {
        echo json_encode(['found' => false]);
    }
    exit;
}

// ==============================================================
// AUTO-SYNC: OPD VISITS TO QUEUE TRACKING
// (Naya parcha banne par apne aap queue list me add ho jayega)
// ==============================================================
try {
    $tenant_pdo->exec("
        INSERT IGNORE INTO opd_queue_tracking (org_id, center_id, visit_id, status)
        SELECT org_id, center_id, visit_id, 'Waiting'
        FROM opd_visits 
        WHERE visit_date = CURDATE() 
        AND visit_id NOT IN (SELECT visit_id FROM opd_queue_tracking)
    ");
} catch (Exception $e) {}

// ==============================================================
// 1. QUEUE TRACKING ACTIONS (Call In, Check Out)
// ==============================================================
if (isset($_GET['q_action']) && isset($_GET['track_id'])) {
    $action = $_GET['q_action'];
    $track_id = (int)$_GET['track_id'];
    
    try {
        if ($action === 'in') {
            $tenant_pdo->prepare("UPDATE opd_queue_tracking SET status = 'Inside Cabin', in_time = NOW() WHERE track_id = ? AND out_time IS NULL")->execute([$track_id]);
            if (function_exists('set_flash_msg')) set_flash_msg("Patient Called In!");
        } elseif ($action === 'out') {
            $tenant_pdo->prepare("UPDATE opd_queue_tracking SET status = 'Completed', out_time = NOW() WHERE track_id = ?")->execute([$track_id]);
            if (function_exists('set_flash_msg')) set_flash_msg("Consultation Completed!");
        } elseif ($action === 'reset') {
            $tenant_pdo->prepare("UPDATE opd_queue_tracking SET status = 'Waiting', in_time = NULL, out_time = NULL WHERE track_id = ?")->execute([$track_id]);
        }
        header("Location: opd_appointments.php#nav-queue");
        exit;
    } catch (Exception $e) { $err = $e->getMessage(); }
}

// ==============================================================
// 2. APPOINTMENT ACTIONS (Save / Update Status)
// ==============================================================
if (isset($_GET['update_status']) && isset($_GET['id'])) {
    $appt_id = (int)$_GET['id'];
    $new_status = $_GET['update_status'];
    if (in_array($new_status, ['Confirmed', 'Cancelled', 'Pending'])) {
        $tenant_pdo->prepare("UPDATE opd_appointments SET status = ? WHERE appointment_id = ? AND org_id = ?")->execute([$new_status, $appt_id, $org_id]);
        header("Location: opd_appointments.php");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_appointment'])) {
    $patient_name = strtoupper(trim($_POST['patient_name'] ?? ''));
    $mobile       = preg_replace('/\D+/', '', $_POST['mobile'] ?? '');
    $gender       = $_POST['gender'] ?? '';
    $age          = (int)($_POST['age'] ?? 0);
    $appt_date    = !empty($_POST['appointment_date']) ? $_POST['appointment_date'] : $today_date;
    $appt_time    = !empty($_POST['appointment_time']) ? $_POST['appointment_time'] : null;
    $department_id= !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $doctor_id    = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;
    $remark       = strtoupper(trim($_POST['remark'] ?? ''));

    if (empty($patient_name) || empty($mobile) || $doctor_id <= 0) {
        $err = "PATIENT NAME, MOBILE, AND DOCTOR ARE MANDATORY.";
    } else {
        try {
            $tenant_pdo->beginTransaction();

            // One patient master record: reuse it whenever the mobile exists.
            $findPatient = $tenant_pdo->prepare("
                SELECT patient_id, uhid
                FROM opd_patients
                WHERE org_id = ?
                  AND center_id = ?
                  AND (mobile = ? OR mobile LIKE ?)
                ORDER BY patient_id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $findPatient->execute([$org_id, $center_id, $mobile, $mobile . ',%']);
            $existingPatient = $findPatient->fetch(PDO::FETCH_ASSOC);

            if ($existingPatient) {
                $patient_id = (int)$existingPatient['patient_id'];
                $uhid = $existingPatient['uhid'];

                $tenant_pdo->prepare("
                    UPDATE opd_patients
                    SET fullname = ?, gender = ?, age = ?, mobile = ?
                    WHERE patient_id = ? AND org_id = ?
                ")->execute([
                    $patient_name, $gender, $age, $mobile,
                    $patient_id, $org_id
                ]);
            } else {
                $uhid = generateAppointmentUHID(
                    $tenant_pdo,
                    $center_id,
                    getAppointmentFY($appt_date)
                );

                $insertPatient = $tenant_pdo->prepare("
                    INSERT INTO opd_patients
                        (org_id, center_id, uhid, fullname, gender, age, mobile, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $insertPatient->execute([
                    $org_id, $center_id, $uhid,
                    $patient_name, $gender, $age, $mobile
                ]);
                $patient_id = (int)$tenant_pdo->lastInsertId();
            }

            $stmt = $tenant_pdo->prepare("
                INSERT INTO opd_appointments
                    (org_id, center_id, patient_id, patient_name, mobile, age, gender,
                     department_id, doctor_id, appointment_date, appointment_time, status, remark)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)
            ");

            $stmt->execute([
                $org_id, $center_id, $patient_id, $patient_name,
                $mobile, $age, $gender, $department_id, $doctor_id,
                $appt_date, $appt_time, $remark
            ]);

            $tenant_pdo->commit();

            if (function_exists('set_flash_msg')) set_flash_msg("APPOINTMENT BOOKED!");
            header("Location: opd_appointments.php");
            exit;
        } catch (Exception $e) {
            if ($tenant_pdo->inTransaction()) $tenant_pdo->rollBack();
            $err = $e->getMessage();
        }
    }
}
// --- FETCH DROPDOWNS ---
$departments = $tenant_pdo->query("SELECT id, dept_name FROM master_departments WHERE org_id = $org_id AND status = 1")->fetchAll();
$doctors = $tenant_pdo->query("SELECT id, full_name, department_id FROM master_doctors WHERE org_id = $org_id AND status = 1")->fetchAll();

// --- FETCH APPOINTMENTS ---
$date_filter = $_GET['date_filter'] ?? $today_date;
$appointments = $tenant_pdo->prepare("
    SELECT a.*, d.full_name as doctor_name, dept.dept_name 
    FROM opd_appointments a LEFT JOIN master_doctors d ON a.doctor_id = d.id LEFT JOIN master_departments dept ON a.department_id = dept.id
    WHERE a.org_id = ? AND a.center_id = ? AND a.appointment_date = ? ORDER BY a.appointment_time ASC, a.appointment_id DESC
");
$appointments->execute([$org_id, $center_id, $date_filter]);
$appointments = $appointments->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH LIVE QUEUE TRACKING ---
$queue_list = $tenant_pdo->prepare("
    SELECT qt.*, v.token_no, p.fullname, p.age, p.gender, p.mobile, d.full_name as doctor_name
    FROM opd_queue_tracking qt
    JOIN opd_visits v ON qt.visit_id = v.visit_id
    JOIN opd_patients p ON v.patient_id = p.patient_id
    LEFT JOIN master_doctors d ON v.doctor_id = d.id
    WHERE qt.org_id = ? AND qt.center_id = ? AND v.visit_date = CURDATE()
    ORDER BY 
        CASE qt.status WHEN 'Inside Cabin' THEN 1 WHEN 'Waiting' THEN 2 ELSE 3 END, 
        v.token_no ASC
");
$queue_list->execute([$org_id, $center_id]);
$queue_list = $queue_list->fetchAll(PDO::FETCH_ASSOC);

// Helper for Financial Year token formatting
function getFYToken($token_int, $date_str) {
    return (string)((int)$token_int);
}

require_once __DIR__ . '/layout_header.php';
?>

<style>
body, .card, .form-label, .form-select, .form-control, .btn, .badge, .table, th, td { text-transform: uppercase; }
input[type="number"], input[type="date"], input[type="time"], input[inputmode="numeric"] { text-transform: none; }
.nav-pills .nav-link { font-weight: 600; color: #475569; border-radius: 8px; }
.nav-pills .nav-link.active { background-color: #0284c7; color: #fff; }
.display-board { background: #0f172a; color: #fff; border-radius: 10px; border: 4px solid #1e293b; }
.display-token { font-size: 2.5rem; font-weight: 900; color: #38bdf8; line-height: 1; }
.display-blink { animation: blinker 1.5s linear infinite; }
.appointment-booking-card .form-label { margin-bottom: 4px; }
.appointment-booking-card .form-control,
.appointment-booking-card .form-select { min-height: 34px; }
.appointment-booking-card .btn { min-height: 34px; }

@keyframes blinker { 50% { opacity: 0; } }
</style>

<?php if (!empty($err)): ?>
    <div class="alert alert-danger py-2 px-3 mb-3 fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<!-- TOP TABS NAVIGATION -->
<ul class="nav nav-pills mb-3 bg-white p-2 rounded-3 shadow-sm border" id="pills-tab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active px-4" id="nav-appt-tab" data-bs-toggle="pill" data-bs-target="#nav-appt" type="button" role="tab"><i class="bi bi-calendar-plus me-2"></i>Appointment Desk</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link px-4" id="nav-queue-tab" data-bs-toggle="pill" data-bs-target="#nav-queue" type="button" role="tab"><i class="bi bi-display me-2"></i>Live Queue Tracking</button>
    </li>
</ul>

<div class="tab-content" id="pills-tabContent">
    
    <!-- ========================================== -->
    <!-- TAB 1: APPOINTMENT DESK -->
    <!-- ========================================== -->
    <div class="tab-pane fade show active" id="nav-appt" role="tabpanel">
        <!-- BOOK NEW APPOINTMENT -->
        <div class="card border-0 shadow-sm rounded-3 mb-3 appointment-booking-card">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-calendar-plus text-primary me-2"></i>Book New Appointment</h6>
            </div>
            <div class="card-body p-3">
                <form method="POST">
                    <input type="hidden" name="book_appointment" value="1">
                    <input type="hidden" name="patient_id" id="appointment_patient_id" value="">
                    <input type="hidden" name="patient_uhid" id="appointment_patient_uhid" value="">

                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold text-secondary mb-1">Date *</label>
                            <input type="date" name="appointment_date" class="form-control form-control-sm text-dark fw-bold" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-semibold text-secondary mb-1">Time</label>
                            <input type="time" name="appointment_time" class="form-control form-control-sm text-dark fw-bold">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Patient Name *</label>
                            <input type="text" name="patient_name" id="appointment_patient_name" class="form-control form-control-sm text-capitalize" placeholder="Patient Name" required>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-semibold text-secondary mb-1">Mobile No *</label>
                            <input type="text" name="mobile" id="appointment_mobile" class="form-control form-control-sm" maxlength="10" placeholder="MOBILE NO" required>
                            <div id="appointment_patient_status" class="small mt-1 text-muted"></div>
                        </div>

                        <div class="col-md-1">
                            <label class="form-label small fw-semibold text-secondary mb-1">Age</label>
                            <input type="number" name="age" id="appointment_age" class="form-control form-control-sm" placeholder="Age">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-semibold text-secondary mb-1">Gender</label>
                            <select name="gender" class="form-select form-select-sm">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Department</label>
                            <select name="department_id" id="department_id" class="form-select form-select-sm" onchange="filterDoctors()">
                                <option value="">-- All --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['dept_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Doctor *</label>
                            <select name="doctor_id" id="doctor_id" class="form-select form-select-sm" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($doctors as $doc): ?>
                                    <option value="<?= $doc['id'] ?>" data-dept="<?= $doc['department_id'] ?>"><?= htmlspecialchars($doc['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">Remarks</label>
                            <input type="text" name="remark" class="form-control form-control-sm" placeholder="Any remarks...">
                        </div>

                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold shadow-sm">
                                <i class="bi bi-calendar-check me-1"></i> Book Appointment
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- APPOINTMENTS LIST -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-calendar2-week text-primary me-2"></i>Appointments Schedule</h6>
                <form method="GET" class="d-flex gap-2">
                    <label class="small fw-semibold text-secondary align-self-center mb-0">Date</label>
                    <input type="date" name="date_filter" class="form-control form-control-sm fw-bold text-primary" value="<?= htmlspecialchars($date_filter) ?>" onchange="this.form.submit()">
                </form>
            </div>

            <div class="table-responsive" style="max-height: 650px;">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-3">Time</th>
                            <th>Patient Name</th>
                            <th>Mobile</th>
                            <th>Department</th>
                            <th>Doctor</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($appointments)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">No appointments found for the selected date.</td></tr>
                        <?php else: ?>
                            <?php foreach ($appointments as $row):
                                $sc = $row['status'] == 'Confirmed' ? 'success' : ($row['status'] == 'Cancelled' ? 'danger' : 'warning');
                            ?>
                                <tr>
                                    <td class="ps-3 fw-bold text-primary">
                                        <?= !empty($row['appointment_time']) ? date('h:i A', strtotime($row['appointment_time'])) : 'N/A' ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['patient_name']) ?></div>
                                    </td>
                                    <td class="text-muted"><?= htmlspecialchars($row['mobile']) ?></td>
                                    <td class="text-secondary"><?= htmlspecialchars($row['dept_name'] ?? 'N/A') ?></td>
                                    <td><div class="fw-semibold text-dark"><?= htmlspecialchars($row['doctor_name'] ?? 'N/A') ?></div></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $sc ?>-subtle text-<?= $sc ?> border border-<?= $sc ?>">
                                            <?= htmlspecialchars($row['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light border py-0 px-2 dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                Action
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size: 0.8rem;">
                                                <?php if($row['status'] !== 'Confirmed'): ?>
                                                    <li><a class="dropdown-item text-success fw-bold" href="?date_filter=<?= urlencode($date_filter) ?>&update_status=Confirmed&id=<?= $row['appointment_id'] ?>">Confirm</a></li>
                                                <?php endif; ?>
                                                <?php if($row['status'] !== 'Cancelled'): ?>
                                                    <li><a class="dropdown-item text-danger fw-bold" href="?date_filter=<?= urlencode($date_filter) ?>&update_status=Cancelled&id=<?= $row['appointment_id'] ?>">Cancel</a></li>
                                                <?php endif; ?>
                                                <?php if($row['status'] !== 'Pending'): ?>
                                                    <li><a class="dropdown-item text-warning fw-bold" href="?date_filter=<?= urlencode($date_filter) ?>&update_status=Pending&id=<?= $row['appointment_id'] ?>">Set Pending</a></li>
                                                <?php endif; ?>
                                            </ul>
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

    <!-- ========================================== -->
    <!-- TAB 2: LIVE QUEUE & TRACKING (TV DISPLAY) -->
    <!-- ========================================== -->
    <div class="tab-pane fade" id="nav-queue" role="tabpanel">
        
        <!-- Live TV Board Banner -->
        <div class="display-board p-3 mb-3 shadow-lg">
            <h5 class="fw-bold text-warning mb-3 border-bottom border-secondary pb-2"><i class="bi bi-broadcast me-2 display-blink text-danger"></i> OPD LIVE STATUS BOARD</h5>
            <div class="row g-3">
                <?php 
                $inside_patients = array_filter($queue_list, fn($q) => $q['status'] === 'Inside Cabin');
                if(empty($inside_patients)): ?>
                    <div class="col-12 text-center text-muted py-3">No patient currently inside the cabin.</div>
                <?php else: ?>
                    <?php foreach($inside_patients as $in_pat): ?>
                    <div class="col-md-4">
                        <div class="bg-dark p-3 rounded border border-secondary text-center">
                            <div class="text-info small fw-bold text-truncate mb-1"><?= htmlspecialchars($in_pat['doctor_name']) ?></div>
                            <div class="display-token"><?= getFYToken($in_pat['token_no'], $today_date) ?></div>
                            <div class="text-light fw-semibold mt-1 text-truncate"><?= htmlspecialchars($in_pat['fullname']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tracking Table -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-clock-history text-primary me-2"></i>Patient Timeline & Tracking</h6>
                <input type="text" id="searchQueue" class="form-control form-control-sm w-25" placeholder="Search Token / Name...">
            </div>
            <div class="table-responsive" style="max-height: 600px;">
                <table class="table table-hover align-middle mb-0 small" id="queueTable">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-3">Token No.</th>
                            <th>Patient Name</th>
                            <th>Consulting Doctor</th>
                            <th class="text-center">In Time</th>
                            <th class="text-center">Out Time</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Desk Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($queue_list)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">Queue is empty today. Generate tokens from Registration desk.</td></tr>
                        <?php else: ?>
                            <?php foreach ($queue_list as $q): 
                                $sBadge = 'bg-secondary';
                                if($q['status'] == 'Waiting') $sBadge = 'bg-warning text-dark border-warning bg-opacity-10 border';
                                elseif($q['status'] == 'Inside Cabin') $sBadge = 'bg-primary text-white display-blink';
                                elseif($q['status'] == 'Completed') $sBadge = 'bg-success text-success border-success bg-opacity-10 border';
                            ?>
                                <tr class="<?= $q['status'] == 'Inside Cabin' ? 'table-primary bg-opacity-10' : '' ?>">
                                    <td class="ps-3 fw-bold fs-6 text-dark"><?= getFYToken($q['token_no'], $today_date) ?></td>
                                    <td class="fw-bold text-secondary"><?= htmlspecialchars($q['fullname']) ?></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($q['doctor_name'] ?? 'General') ?></td>
                                    <td class="text-center text-primary fw-bold font-monospace"><?= !empty($q['in_time']) ? date('h:i A', strtotime($q['in_time'])) : '--:--' ?></td>
                                    <td class="text-center text-success fw-bold font-monospace"><?= !empty($q['out_time']) ? date('h:i A', strtotime($q['out_time'])) : '--:--' ?></td>
                                    <td class="text-center"><span class="badge <?= $sBadge ?> px-2 py-1"><?= htmlspecialchars($q['status']) ?></span></td>
                                    <td class="text-end pe-3">
                                        <?php if($q['status'] == 'Waiting'): ?>
                                            <a href="?q_action=in&track_id=<?= $q['track_id'] ?>" class="btn btn-sm btn-primary py-0 fw-bold shadow-sm">Call In</a>
                                        <?php elseif($q['status'] == 'Inside Cabin'): ?>
                                            <a href="?q_action=out&track_id=<?= $q['track_id'] ?>" class="btn btn-sm btn-success py-0 fw-bold shadow-sm"><i class="bi bi-check2-all"></i> Done</a>
                                        <?php else: ?>
                                            <a href="?q_action=reset&track_id=<?= $q['track_id'] ?>" class="btn btn-sm btn-light border py-0 text-muted" title="Re-queue"><i class="bi bi-arrow-counterclockwise"></i></a>
                                        <?php endif; ?>
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

<script>
async function lookupAppointmentPatient() {
    const mobileEl = document.getElementById('appointment_mobile');
    const nameEl = document.getElementById('appointment_patient_name');
    const ageEl = document.getElementById('appointment_age');
    const genderEl = document.getElementById('appointment_gender');
    const patientIdEl = document.getElementById('appointment_patient_id');
    const uhidEl = document.getElementById('appointment_patient_uhid');
    const statusEl = document.getElementById('appointment_patient_status');

    if (!mobileEl) return;

    const mobile = mobileEl.value.replace(/\D/g, '');
    if (mobile.length < 10) return;

    statusEl.textContent = 'SEARCHING PATIENT...';
    statusEl.className = 'small mt-1 text-muted';

    try {
        const response = await fetch(
            'opd_appointments.php?ajax=patient_lookup&mobile=' + encodeURIComponent(mobile),
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        );
        const data = await response.json();

        if (data.found && data.patient) {
            nameEl.value = (data.patient.fullname || '').toUpperCase();
            ageEl.value = data.patient.age || '';
            genderEl.value = data.patient.gender || '';
            patientIdEl.value = data.patient.patient_id || '';
            uhidEl.value = data.patient.uhid || '';
            mobileEl.value = (data.patient.mobile || mobile).split(',')[0].trim();

            statusEl.textContent = 'EXISTING PATIENT FOUND — ' + (data.patient.uhid || '');
            statusEl.className = 'small mt-1 text-success fw-bold';
        } else {
            patientIdEl.value = '';
            uhidEl.value = '';
            statusEl.textContent = 'NEW PATIENT — WILL BE CREATED ON BOOKING';
            statusEl.className = 'small mt-1 text-primary fw-bold';
        }
    } catch (error) {
        statusEl.textContent = '';
        statusEl.className = 'small mt-1 text-muted';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const mobileEl = document.getElementById('appointment_mobile');
    if (!mobileEl) return;

    mobileEl.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
        if (this.value.length === 10) lookupAppointmentPatient();
    });

    mobileEl.addEventListener('blur', lookupAppointmentPatient);
});

// Keep active tab on reload
document.addEventListener("DOMContentLoaded", function() {
    let hash = window.location.hash;
    if (hash) {
        let triggerEl = document.querySelector('button[data-bs-target="' + hash + '"]');
        if (triggerEl) {
            let tab = new bootstrap.Tab(triggerEl);
            tab.show();
        }
    }
    let tabButtons = document.querySelectorAll('button[data-bs-toggle="pill"]');
    tabButtons.forEach(btn => {
        btn.addEventListener('shown.bs.tab', function(e) {
            window.location.hash = e.target.getAttribute('data-bs-target');
        });
    });
});

function filterDoctors() {
    const deptId = document.getElementById('department_id').value;
    const docSelect = document.getElementById('doctor_id');
    const currentValue = docSelect.value;

    for (let i = 0; i < docSelect.options.length; i++) {
        const opt = docSelect.options[i];
        if (opt.value === "") continue;

        const matches = !deptId || opt.getAttribute('data-dept') === deptId;
        opt.hidden = !matches;
        opt.disabled = !matches;

        if (!matches && String(opt.value) === String(currentValue)) {
            docSelect.value = "";
        }
    }

    // If exactly one doctor matches the selected department, select it automatically.
    const matching = Array.from(docSelect.options).filter(opt => opt.value !== "" && !opt.disabled);
    if (deptId && matching.length === 1) {
        docSelect.value = matching[0].value;
    }
}

document.getElementById('searchQueue')?.addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    document.querySelectorAll('#queueTable tbody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>