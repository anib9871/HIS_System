<?php
// admin/opd_doctor.php
$page_title = "Doctor Desk";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$org_id    = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$today     = date('Y-m-d');
$err = '';
$success = '';

/* ==========================================================
   DOCTOR DESK FIELDS
   These fields are stored against the OPD visit itself.
   IMPORTANT: Do not use MySQL 8-only "ADD COLUMN IF NOT EXISTS"
   because some installations run older MySQL/MariaDB versions.
   We check the column first and add it only when missing.
   ========================================================== */
function ensureVisitColumn(PDO $pdo, string $column, string $definition, ?string $after = null): void {
    $check = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'opd_visits'
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $check->execute([$column]);
    if ($check->fetch(PDO::FETCH_ASSOC)) {
        return;
    }

    $sql = "ALTER TABLE opd_visits ADD COLUMN `{$column}` {$definition}";
    if ($after) {
        $sql .= " AFTER `{$after}`";
    }
    $pdo->exec($sql);
}

try {
    ensureVisitColumn($tenant_pdo, 'symptoms', 'TEXT NULL', 'remark');
    ensureVisitColumn($tenant_pdo, 'vitals_json', 'TEXT NULL', 'symptoms');
    ensureVisitColumn($tenant_pdo, 'diagnosis', 'TEXT NULL', 'vitals_json');
    ensureVisitColumn($tenant_pdo, 'prescription', 'TEXT NULL', 'diagnosis');
    ensureVisitColumn($tenant_pdo, 'prescription_json', 'LONGTEXT NULL', 'prescription');
    ensureVisitColumn($tenant_pdo, 'doctor_notes', 'TEXT NULL', 'prescription_json');
    ensureVisitColumn($tenant_pdo, 'follow_up_date', 'DATE NULL', 'doctor_notes');
    ensureVisitColumn($tenant_pdo, 'consultation_started_at', 'DATETIME NULL', 'follow_up_date');
    ensureVisitColumn($tenant_pdo, 'consultation_completed_at', 'DATETIME NULL', 'consultation_started_at');
} catch (Exception $e) {
    $err = 'DOCTOR DESK DATABASE SETUP ERROR: ' . $e->getMessage();
}

/* ==========================================================
   DOCTOR MASTER
   ========================================================== */
$doctors = [];
try {
    $d = $tenant_pdo->prepare("SELECT id, full_name, department_id FROM master_doctors WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY full_name");
    $d->execute([$org_id, $center_id]);
    $doctors = $d->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$doctor_id = (int)($_GET['doctor_id'] ?? ($_POST['doctor_id'] ?? ($_SESSION['doctor_id'] ?? 0)));
$selected_visit_id = (int)($_GET['visit_id'] ?? ($_POST['visit_id'] ?? 0));

/* ==========================================================
   PRESCRIPTION MASTERS
   ========================================================== */
$prescription_templates = [];
$rx_medicines = [];
$rx_routes = [];
$rx_frequencies = [];
$rx_timings = [];
$rx_durations = [];
$rx_instructions = [];
$rx_symptoms = [];
$rx_diagnoses = [];

try {
    $st = $tenant_pdo->prepare("SELECT template_id, template_name, department_id, specialization_id, doctor_id, default_symptoms, default_diagnosis, default_advice, follow_up_days FROM prescription_template_master WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY template_name");
    $st->execute([$org_id, $center_id]);
    $prescription_templates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, medicine_name, generic_name, brand_name, strength, dosage_form FROM master_medicines WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY medicine_name");
    $st->execute([$org_id, $center_id]);
    $rx_medicines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, route_name, route_code FROM master_routes WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY route_name");
    $st->execute([$org_id, $center_id]);
    $rx_routes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, frequency_name, frequency_code FROM master_frequencies WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY frequency_name");
    $st->execute([$org_id, $center_id]);
    $rx_frequencies = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, timing_name, timing_code FROM master_timings WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY timing_name");
    $st->execute([$org_id, $center_id]);
    $rx_timings = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, duration_name, duration_value, duration_unit FROM master_durations WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY id");
    $st->execute([$org_id, $center_id]);
    $rx_durations = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, instruction_name, instruction_code, instruction_text FROM master_prescription_instructions WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY instruction_name");
    $st->execute([$org_id, $center_id]);
    $rx_instructions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, symptom_name, symptom_code FROM master_symptoms WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY symptom_name");
    $st->execute([$org_id, $center_id]);
    $rx_symptoms = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, diagnosis_name, diagnosis_code FROM master_diagnoses WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY diagnosis_name");
    $st->execute([$org_id, $center_id]);
    $rx_diagnoses = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* AJAX: TEMPLATE DETAILS */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_prescription_template') {
    header('Content-Type: application/json; charset=utf-8');
    $template_id = (int)($_GET['template_id'] ?? 0);
    try {
        $st = $tenant_pdo->prepare("SELECT template_id, template_name, default_symptoms, default_diagnosis, default_advice, follow_up_days FROM prescription_template_master WHERE template_id = ? AND org_id = ? AND center_id = ? AND status = 1 LIMIT 1");
        $st->execute([$template_id, $org_id, $center_id]);
        $template = $st->fetch(PDO::FETCH_ASSOC);
        if (!$template) {
            echo json_encode(['ok'=>false,'message'=>'PRESCRIPTION TEMPLATE NOT FOUND.']);
            exit;
        }

        $st = $tenant_pdo->prepare("SELECT item_id, medicine_name, dose, route, frequency, timing, duration, quantity, instructions, sort_order FROM prescription_template_items WHERE template_id = ? ORDER BY sort_order, item_id");
        $st->execute([$template_id]);
        $template['items'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode(['ok'=>true,'template'=>$template], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

/* ==========================================================
   SAVE / START / COMPLETE CONSULTATION
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['doctor_action'] ?? '';
    $visit_id = (int)($_POST['visit_id'] ?? 0);
    $doctor_post = (int)($_POST['doctor_id'] ?? 0);
    if ($doctor_post > 0) $doctor_id = $doctor_post;

    if ($visit_id > 0 && in_array($action, ['start', 'save', 'complete'], true)) {
        try {
            $find = $tenant_pdo->prepare("SELECT visit_id, patient_id, doctor_id, status FROM opd_visits WHERE visit_id = ? AND org_id = ? AND center_id = ? LIMIT 1");
            $find->execute([$visit_id, $org_id, $center_id]);
            $visit_row = $find->fetch(PDO::FETCH_ASSOC);

            if (!$visit_row) {
                throw new Exception('PATIENT VISIT NOT FOUND.');
            }

            $save_doctor_id = $doctor_id > 0 ? $doctor_id : (int)$visit_row['doctor_id'];
            if ($save_doctor_id <= 0) {
                throw new Exception('PLEASE SELECT A DOCTOR.');
            }

            if ($action === 'start') {
                $tenant_pdo->prepare("UPDATE opd_visits SET doctor_id = ?, status = 'Inside Cabin', consultation_started_at = COALESCE(consultation_started_at, NOW()) WHERE visit_id = ? AND org_id = ? AND center_id = ?")
                    ->execute([$save_doctor_id, $visit_id, $org_id, $center_id]);

                try {
                    $tenant_pdo->prepare("UPDATE opd_queue_tracking SET status = 'Inside Cabin', in_time = COALESCE(in_time, NOW()), waiting_since = COALESCE(waiting_since, NOW()) WHERE visit_id = ? AND org_id = ? AND center_id = ?")
                        ->execute([$visit_id, $org_id, $center_id]);
                } catch (Exception $e) {}

                $success = 'CONSULTATION STARTED.';
            } else {
                $symptoms      = trim($_POST['symptoms'] ?? '');
                $diagnosis     = trim($_POST['diagnosis'] ?? '');
                $doctor_notes  = trim($_POST['doctor_notes'] ?? '');
                $follow_up_raw = trim($_POST['follow_up_date'] ?? '');
                $follow_up     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $follow_up_raw) ? $follow_up_raw : null;

                $vitals = [
                    'bp'        => trim($_POST['bp'] ?? ''),
                    'pulse'     => trim($_POST['pulse'] ?? ''),
                    'temperature' => trim($_POST['temperature'] ?? ''),
                    'spo2'      => trim($_POST['spo2'] ?? ''),
                    'weight'    => trim($_POST['weight'] ?? ''),
                    'height'    => trim($_POST['height'] ?? ''),
                ];
                $vitals_json = json_encode($vitals, JSON_UNESCAPED_UNICODE);

                /*
                 * Store structured prescription data as JSON inside opd_visits.prescription.
                 * This keeps the existing column and remains backward-compatible with
                 * older plain-text prescriptions.
                 */
                $rx_items = [];
                $rx_json_raw = trim($_POST['prescription_items_json'] ?? '');
                if ($rx_json_raw !== '') {
                    $decoded_rx = json_decode($rx_json_raw, true);
                    if (is_array($decoded_rx)) {
                        foreach ($decoded_rx as $rx) {
                            $med = trim((string)($rx['medicine_name'] ?? ''));
                            if ($med === '') continue;
                            $rx_items[] = [
                                'medicine_name' => $med,
                                'dose'          => trim((string)($rx['dose'] ?? '')),
                                'route'         => trim((string)($rx['route'] ?? '')),
                                'frequency'     => trim((string)($rx['frequency'] ?? '')),
                                'timing'        => trim((string)($rx['timing'] ?? '')),
                                'duration'      => trim((string)($rx['duration'] ?? '')),
                                'quantity'      => trim((string)($rx['quantity'] ?? '')),
                                'instructions'  => trim((string)($rx['instructions'] ?? '')),
                            ];
                        }
                    }
                }
                $prescription_json = json_encode(['items' => $rx_items], JSON_UNESCAPED_UNICODE);

                $prescription_lines = [];
                foreach ($rx_items as $rx) {
                    $parts = array_filter([
                        $rx['medicine_name'],
                        $rx['dose'],
                        $rx['route'],
                        $rx['frequency'],
                        $rx['timing'],
                        $rx['duration'],
                        $rx['quantity'] !== '' ? 'QTY ' . $rx['quantity'] : '',
                        $rx['instructions']
                    ], fn($v) => trim((string)$v) !== '');
                    $prescription_lines[] = implode(' | ', $parts);
                }
                $prescription = implode("\n", $prescription_lines);

                if ($action === 'complete') {
                    $status = 'Completed';
                    $tenant_pdo->prepare("UPDATE opd_visits SET doctor_id = ?, symptoms = ?, vitals_json = ?, diagnosis = ?, prescription = ?, prescription_json = ?, doctor_notes = ?, follow_up_date = ?, consultation_completed_at = NOW(), status = ? WHERE visit_id = ? AND org_id = ? AND center_id = ?")
                        ->execute([$save_doctor_id, $symptoms, $vitals_json, $diagnosis, $prescription, $prescription_json, $doctor_notes, $follow_up, $status, $visit_id, $org_id, $center_id]);
                    try {
                        $tenant_pdo->prepare("UPDATE opd_queue_tracking SET status = 'Completed', out_time = NOW() WHERE visit_id = ? AND org_id = ? AND center_id = ?")
                            ->execute([$visit_id, $org_id, $center_id]);
                    } catch (Exception $e) {}
                    $success = 'CONSULTATION COMPLETED.';
                } else {
                    $tenant_pdo->prepare("UPDATE opd_visits SET doctor_id = ?, symptoms = ?, vitals_json = ?, diagnosis = ?, prescription = ?, prescription_json = ?, doctor_notes = ?, follow_up_date = ? WHERE visit_id = ? AND org_id = ? AND center_id = ?")
                        ->execute([$save_doctor_id, $symptoms, $vitals_json, $diagnosis, $prescription, $prescription_json, $doctor_notes, $follow_up, $visit_id, $org_id, $center_id]);
                    $success = 'CONSULTATION SAVED.';
                }
            }

            $selected_visit_id = $visit_id;
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
    }
}

/* ==========================================================
   QUEUE / VISITS LIST
   ========================================================== */
$visit_list = [];
try {
    $whereDoctor = '';
    $params = [$org_id, $center_id, $today];
    if ($doctor_id > 0) {
        $whereDoctor = ' AND v.doctor_id = ? ';
        $params[] = $doctor_id;
    }

    $q = $tenant_pdo->prepare(""
        . "SELECT v.visit_id, v.patient_id, v.doctor_id, v.department_id, v.token_no, v.visit_date, v.status, "
        . "v.consultation_started_at, v.consultation_completed_at, "
        . "p.uhid, p.fullname, p.gender, p.age, p.age_unit, p.mobile, "
        . "d.full_name AS doctor_name, dept.dept_name "
        . "FROM opd_visits v "
        . "JOIN patient_master p ON p.patient_id = v.patient_id "
        . "LEFT JOIN master_doctors d ON d.id = v.doctor_id "
        . "LEFT JOIN master_departments dept ON dept.id = v.department_id "
        . "WHERE v.org_id = ? AND v.center_id = ? AND v.visit_date = ? "
        . $whereDoctor
        . "AND v.status IN ('Waiting','Inside Cabin') "
        . "ORDER BY CASE WHEN v.status = 'Inside Cabin' THEN 0 ELSE 1 END, v.token_no ASC"
    );
    $q->execute($params);
    $visit_list = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $err = $err ?: $e->getMessage();
}

/* ==========================================================
   SELECTED PATIENT DETAILS
   ========================================================== */
$selected = null;
$selected_vitals = [];
$selected_rx_items = [];

if ($selected_visit_id > 0) {
    try {
        $s = $tenant_pdo->prepare(""
            . "SELECT v.*, p.uhid, p.fullname, p.gender, p.age, p.age_unit, p.mobile, p.address, "
            . "d.full_name AS doctor_name, dept.dept_name "
            . "FROM opd_visits v "
            . "JOIN patient_master p ON p.patient_id = v.patient_id "
            . "LEFT JOIN master_doctors d ON d.id = v.doctor_id "
            . "LEFT JOIN master_departments dept ON dept.id = v.department_id "
            . "WHERE v.visit_id = ? AND v.org_id = ? AND v.center_id = ? LIMIT 1"
        );
        $s->execute([$selected_visit_id, $org_id, $center_id]);
        $selected = $s->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($selected) {
            if (!empty($selected['vitals_json'])) {
                $decoded = json_decode($selected['vitals_json'], true);
                if (is_array($decoded)) $selected_vitals = $decoded;
            }

            $rxSource = !empty($selected['prescription_json']) ? $selected['prescription_json'] : ($selected['prescription'] ?? '');
            if (!empty($rxSource)) {
                $rxDecoded = json_decode($rxSource, true);
                if (is_array($rxDecoded) && isset($rxDecoded['items']) && is_array($rxDecoded['items'])) {
                    $selected_rx_items = $rxDecoded['items'];
                } else {
                    /* Legacy / human-readable plain-text prescription */
                    $legacyLines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$selected['prescription'])));
                    foreach ($legacyLines as $line) {
                        $selected_rx_items[] = [
                            'medicine_name' => $line,
                            'dose' => '', 'route' => '', 'frequency' => '',
                            'timing' => '', 'duration' => '', 'quantity' => '', 'instructions' => ''
                        ];
                    }
                }
            }
        }
    } catch (Exception $e) {
        $err = $err ?: $e->getMessage();
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<style>
body, .card, .form-label, .form-control, .form-select, .btn, .badge, .table, th, td { text-transform: uppercase; }
input[type="number"], input[type="date"], textarea { text-transform: none; }
.doctor-desk-wrap { min-width: 0; }
.dd-stat { border:1px solid #e5e7eb; border-radius:12px; padding:12px 14px; background:#fff; box-shadow:0 2px 10px rgba(15,23,42,.05); }
.dd-stat .num { font-size:22px; font-weight:800; line-height:1; }
.dd-stat .lbl { font-size:10px; color:#64748b; font-weight:800; margin-top:4px; }
.dd-queue { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; }
.dd-queue-row { display:block; text-decoration:none; color:inherit; border-bottom:1px solid #eef2f7; padding:12px 13px; }
.dd-queue-row:hover { background:#f8fafc; }
.dd-queue-row.active { background:#eff6ff; border-left:4px solid #0d6efd; }
.dd-token { font-size:20px; font-weight:900; width:48px; }
.dd-patient { min-width:0; }
.dd-patient .name { font-weight:800; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dd-patient .meta { font-size:10px; color:#64748b; margin-top:2px; }
.dd-main-card { border:0; border-radius:14px; box-shadow:0 3px 18px rgba(15,23,42,.07); }
.dd-patient-banner { background:linear-gradient(135deg,#eff6ff,#ffffff); border:1px solid #dbeafe; border-radius:12px; padding:14px; }
.dd-patient-banner .patient-name { font-size:20px; font-weight:900; }
.dd-patient-banner .uhid { font-family:monospace; font-size:12px; font-weight:800; color:#2563eb; }
.dd-section-title { font-size:12px; font-weight:900; color:#0f172a; letter-spacing:.3px; }
.dd-vital { border:1px solid #e5e7eb; border-radius:9px; padding:8px; background:#fff; }
.dd-vital label { font-size:9px; color:#64748b; font-weight:800; margin-bottom:3px; }
.dd-vital input { font-weight:800; }
.dd-medicine-row { border:1px solid #e5e7eb; border-radius:9px; padding:9px; margin-bottom:8px; background:#fafafa; }
.dd-medicine-row .form-label { font-size:9px; color:#64748b; font-weight:800; margin-bottom:3px; }
.dd-prescription-box { border:1px solid #e5e7eb; border-radius:10px; padding:10px; background:#f8fafc; }
.dd-template-bar { border:1px solid #dbeafe; background:#eff6ff; border-radius:10px; padding:10px; }
.dd-master-multi { min-height:70px; }
.dd-small { font-size:10px; }
.status-waiting { background:#fff7ed; color:#c2410c; border:1px solid #fdba74; }
.status-inside { background:#eff6ff; color:#1d4ed8; border:1px solid #93c5fd; animation:ddBlink 1s infinite; }
@keyframes ddBlink { 50% { opacity:.45; } }
@media (max-width: 1100px){ .dd-token{font-size:17px;width:40px;} .dd-patient .name{font-size:12px;} }
</style>

<div class="doctor-desk-wrap">
    <?php if ($err): ?><div class="alert alert-danger py-2 px-3 fw-bold"><?= htmlspecialchars($err) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success py-2 px-3 fw-bold"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h5 class="fw-bold mb-0"><i class="bi bi-prescription2 text-primary me-2"></i>DOCTOR DESK</h5>
            <div class="text-muted small">TODAY'S OPD CONSULTATION</div>
        </div>
        <form method="GET" class="d-flex align-items-center gap-2">
            <label class="small fw-bold text-secondary mb-0">DOCTOR</label>
            <select name="doctor_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:190px;">
                <option value="0">ALL DOCTORS</option>
                <?php foreach ($doctors as $doc): ?>
                    <option value="<?= (int)$doc['id'] ?>" <?= $doctor_id == (int)$doc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="dd-stat"><div class="num text-primary"><?= count($visit_list) ?></div><div class="lbl">TODAY'S ACTIVE PATIENTS</div></div></div>
        <div class="col-6 col-md-3"><div class="dd-stat"><div class="num text-warning"><?= count(array_filter($visit_list, fn($v) => $v['status'] === 'Waiting')) ?></div><div class="lbl">WAITING</div></div></div>
        <div class="col-6 col-md-3"><div class="dd-stat"><div class="num text-info"><?= count(array_filter($visit_list, fn($v) => $v['status'] === 'Inside Cabin')) ?></div><div class="lbl">INSIDE CABIN</div></div></div>
        <div class="col-6 col-md-3"><div class="dd-stat"><div class="num text-success"><?= date('d-m-Y') ?></div><div class="lbl">DATE</div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card dd-main-card">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <div class="fw-bold"><i class="bi bi-people-fill me-2 text-primary"></i>PATIENT QUEUE</div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= count($visit_list) ?></span>
                </div>
                <div class="p-2 border-bottom">
                    <input type="text" id="queueSearch" class="form-control form-control-sm" placeholder="SEARCH TOKEN / PATIENT / UHID">
                </div>
                <div class="dd-queue" style="max-height:650px; overflow-y:auto; border:0; border-radius:0;">
                    <?php if (!$visit_list): ?>
                        <div class="text-center py-5 text-muted small fw-bold">NO ACTIVE PATIENTS IN QUEUE</div>
                    <?php else: ?>
                        <?php foreach ($visit_list as $v): ?>
                            <a class="dd-queue-row <?= $selected_visit_id == (int)$v['visit_id'] ? 'active' : '' ?>" href="?visit_id=<?= (int)$v['visit_id'] ?>&doctor_id=<?= (int)$doctor_id ?>" data-search="<?= htmlspecialchars(strtolower(($v['token_no'] ?? '') . ' ' . ($v['fullname'] ?? '') . ' ' . ($v['uhid'] ?? ''))) ?>">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="dd-token text-primary">#<?= (int)$v['token_no'] ?></div>
                                    <div class="dd-patient flex-grow-1">
                                        <div class="name"><?= htmlspecialchars($v['fullname']) ?></div>
                                        <div class="meta"><?= htmlspecialchars($v['uhid']) ?> • <?= (int)$v['age'] ?> <?= htmlspecialchars($v['age_unit'] ?: 'YRS') ?> • <?= htmlspecialchars($v['gender']) ?></div>
                                    </div>
                                    <span class="badge <?= $v['status'] === 'Waiting' ? 'status-waiting' : 'status-inside' ?> rounded-pill">
                                        <?= htmlspecialchars($v['status']) ?>
                                    </span>
                                </div>
                                <div class="small text-muted mt-1 ms-5"><?= htmlspecialchars($v['doctor_name'] ?? 'GENERAL') ?><?= $v['dept_name'] ? ' • ' . htmlspecialchars($v['dept_name']) : '' ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card dd-main-card">
                <?php if (!$selected): ?>
                    <div class="card-body text-center py-5">
                        <i class="bi bi-person-vcard display-4 text-primary"></i>
                        <h5 class="fw-bold mt-3">SELECT A PATIENT</h5>
                        <p class="text-muted mb-0">SELECT A PATIENT FROM THE QUEUE TO START CONSULTATION.</p>
                    </div>
                <?php else: ?>
                    <?php $selected_vitals = $selected_vitals ?? []; ?>
                    <form method="POST">
                        <input type="hidden" name="visit_id" value="<?= (int)$selected['visit_id'] ?>">
                        <input type="hidden" name="doctor_id" value="<?= (int)$doctor_id ?>">

                        <div class="card-body">
                            <div class="dd-patient-banner mb-3">
                                <div class="d-flex justify-content-between gap-3 flex-wrap">
                                    <div>
                                        <div class="patient-name"><?= htmlspecialchars($selected['fullname']) ?></div>
                                        <div class="uhid"><?= htmlspecialchars($selected['uhid']) ?></div>
                                        <div class="small text-muted mt-1"><?= (int)$selected['age'] ?> <?= htmlspecialchars($selected['age_unit'] ?: 'YRS') ?> • <?= htmlspecialchars($selected['gender']) ?> • <?= htmlspecialchars($selected['mobile']) ?></div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold text-primary fs-5">TOKEN #<?= (int)$selected['token_no'] ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($selected['status']) ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="dd-section-title">VITALS</div>
                                <div class="small text-muted">ENTER CURRENT PATIENT VITALS</div>
                            </div>
                            <div class="row g-2 mb-3">
                                <?php foreach ([
                                    ['bp','BP','e.g. 120/80'], ['pulse','PULSE','bpm'], ['temperature','TEMPERATURE','°F'],
                                    ['spo2','SPO2','%'], ['weight','WEIGHT','kg'], ['height','HEIGHT','cm']
                                ] as $vv): ?>
                                <div class="col-6 col-md-2"><div class="dd-vital"><label><?= $vv[1] ?></label><input type="text" name="<?= $vv[0] ?>" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_vitals[$vv[0]] ?? '') ?>" placeholder="<?= htmlspecialchars($vv[2]) ?>"></div></div>
                                <?php endforeach; ?>
                            </div>

                            <div class="dd-section-title mb-2">CLINICAL NOTES</div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">SYMPTOMS / COMPLAINTS</label>
                                    <select id="symptoms_master" class="form-select form-select-sm dd-master-multi" multiple size="5">
                                        <?php foreach ($rx_symptoms as $sm): ?>
                                            <option value="<?= htmlspecialchars($sm['symptom_name'], ENT_QUOTES) ?>">
                                                <?= htmlspecialchars($sm['symptom_name']) ?><?= !empty($sm['symptom_code']) ? ' (' . htmlspecialchars($sm['symptom_code']) . ')' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <textarea name="symptoms" id="symptoms" rows="2" class="form-control form-control-sm mt-2" placeholder="ADDITIONAL SYMPTOMS / COMPLAINTS..."><?= htmlspecialchars($selected['symptoms'] ?? '') ?></textarea>
                                    <div class="form-text dd-small">CTRL / COMMAND + CLICK FOR MULTIPLE MASTER VALUES.</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">DIAGNOSIS</label>
                                    <select id="diagnosis_master" class="form-select form-select-sm dd-master-multi" multiple size="5">
                                        <?php foreach ($rx_diagnoses as $dg): ?>
                                            <option value="<?= htmlspecialchars($dg['diagnosis_name'], ENT_QUOTES) ?>">
                                                <?= htmlspecialchars($dg['diagnosis_name']) ?><?= !empty($dg['diagnosis_code']) ? ' (' . htmlspecialchars($dg['diagnosis_code']) . ')' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <textarea name="diagnosis" id="diagnosis" rows="2" class="form-control form-control-sm mt-2" placeholder="ADDITIONAL DIAGNOSIS..."><?= htmlspecialchars($selected['diagnosis'] ?? '') ?></textarea>
                                    <div class="form-text dd-small">SELECT COMMON DIAGNOSES FROM THE MASTER.</div>
                                </div>
                            </div>

                            <div class="dd-prescription-box mb-3">
                                <div class="dd-template-bar mb-3">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-8">
                                            <label class="form-label small fw-bold">PRESCRIPTION TEMPLATE</label>
                                            <select id="prescription_template_id" class="form-select form-select-sm">
                                                <option value="">SELECT TEMPLATE</option>
                                                <?php foreach ($prescription_templates as $pt): ?>
                                                    <option value="<?= (int)$pt['template_id'] ?>">
                                                        <?= htmlspecialchars($pt['template_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <button type="button" id="applyTemplateBtn" class="btn btn-sm btn-primary w-100 fw-bold" onclick="applyPrescriptionTemplate()">
                                                <i class="bi bi-magic me-1"></i>APPLY TEMPLATE
                                            </button>
                                        </div>
                                    </div>
                                    <div class="dd-small text-muted mt-2">TEMPLATE WILL LOAD DEFAULT SYMPTOMS, DIAGNOSIS, ADVICE, FOLLOW-UP AND MEDICINES.</div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="dd-section-title">PRESCRIPTION</div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addMedicine()">
                                        <i class="bi bi-plus-lg me-1"></i>ADD MEDICINE
                                    </button>
                                </div>

                                <div id="medicineList" class="mb-2">
                                    <?php foreach ($selected_rx_items as $rx): ?>
                                        <div class="dd-medicine-row">
                                            <div class="row g-2 align-items-end">
                                                <div class="col-lg-2">
                                                    <label class="form-label">MEDICINE</label>
                                                    <select name="rx_medicine[]" class="form-select form-select-sm medicine-select">
                                                        <option value="">SELECT MEDICINE</option>
                                                        <?php foreach ($rx_medicines as $m): ?>
                                                            <option value="<?= htmlspecialchars($m['medicine_name'], ENT_QUOTES) ?>" <?= ($rx['medicine_name'] ?? '') === $m['medicine_name'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($m['medicine_name']) ?><?= !empty($m['strength']) ? ' - ' . htmlspecialchars($m['strength']) : '' ?><?= !empty($m['dosage_form']) ? ' - ' . htmlspecialchars($m['dosage_form']) : '' ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-lg-1">
                                                    <label class="form-label">DOSE</label>
                                                    <input type="text" name="rx_dose[]" class="form-control form-control-sm" value="<?= htmlspecialchars($rx['dose'] ?? '') ?>" placeholder="1 TAB">
                                                </div>
                                                <div class="col-lg-1">
                                                    <label class="form-label">ROUTE</label>
                                                    <select name="rx_route[]" class="form-select form-select-sm route-select">
                                                        <option value="">SELECT</option>
                                                        <?php foreach ($rx_routes as $r): ?>
                                                            <option value="<?= htmlspecialchars($r['route_name'], ENT_QUOTES) ?>" <?= ($rx['route'] ?? '') === $r['route_name'] ? 'selected' : '' ?>><?= htmlspecialchars($r['route_name']) ?><?= !empty($r['route_code']) ? ' (' . htmlspecialchars($r['route_code']) . ')' : '' ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-lg-2">
                                                    <label class="form-label">FREQUENCY</label>
                                                    <select name="rx_frequency[]" class="form-select form-select-sm frequency-select">
                                                        <option value="">SELECT</option>
                                                        <?php foreach ($rx_frequencies as $f): ?>
                                                            <option value="<?= htmlspecialchars($f['frequency_name'], ENT_QUOTES) ?>" <?= ($rx['frequency'] ?? '') === $f['frequency_name'] ? 'selected' : '' ?>><?= htmlspecialchars($f['frequency_name']) ?><?= !empty($f['frequency_code']) ? ' (' . htmlspecialchars($f['frequency_code']) . ')' : '' ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-lg-2">
                                                    <label class="form-label">TIMING</label>
                                                    <select name="rx_timing[]" class="form-select form-select-sm timing-select">
                                                        <option value="">SELECT</option>
                                                        <?php foreach ($rx_timings as $tm): ?>
                                                            <option value="<?= htmlspecialchars($tm['timing_name'], ENT_QUOTES) ?>" <?= ($rx['timing'] ?? '') === $tm['timing_name'] ? 'selected' : '' ?>><?= htmlspecialchars($tm['timing_name']) ?><?= !empty($tm['timing_code']) ? ' (' . htmlspecialchars($tm['timing_code']) . ')' : '' ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-lg-1">
                                                    <label class="form-label">DURATION</label>
                                                    <select name="rx_duration[]" class="form-select form-select-sm duration-select">
                                                        <option value="">SELECT</option>
                                                        <?php foreach ($rx_durations as $du): ?>
                                                            <option value="<?= htmlspecialchars($du['duration_name'], ENT_QUOTES) ?>" <?= ($rx['duration'] ?? '') === $du['duration_name'] ? 'selected' : '' ?>><?= htmlspecialchars($du['duration_name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-lg-1">
                                                    <label class="form-label">QTY</label>
                                                    <input type="text" name="rx_quantity[]" class="form-control form-control-sm" value="<?= htmlspecialchars($rx['quantity'] ?? '') ?>" placeholder="10">
                                                </div>
                                                <div class="col-lg-2">
                                                    <label class="form-label">INSTRUCTION</label>
                                                    <div class="d-flex gap-1">
                                                        <select name="rx_instruction[]" class="form-select form-select-sm instruction-select">
                                                            <option value="">SELECT</option>
                                                            <?php foreach ($rx_instructions as $ins): ?>
                                                                <option value="<?= htmlspecialchars($ins['instruction_text'], ENT_QUOTES) ?>" <?= ($rx['instructions'] ?? '') === $ins['instruction_text'] ? 'selected' : '' ?>><?= htmlspecialchars($ins['instruction_name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.dd-medicine-row').remove(); syncPrescription();" title="REMOVE"><i class="bi bi-trash"></i></button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if (!$selected_rx_items): ?>
                                    <div class="text-center text-muted py-3 small" id="emptyMedicineText">
                                        NO MEDICINE ADDED. CLICK <b>ADD MEDICINE</b> OR APPLY A TEMPLATE.
                                    </div>
                                <?php endif; ?>

                                <textarea id="prescription_items_json" name="prescription_items_json" class="d-none"></textarea>

                                <div class="row g-2 mt-2">
                                    <div class="col-12">
                                        <label class="form-label small fw-bold">DEFAULT / ADDITIONAL ADVICE</label>
                                        <select id="instruction_master" class="form-select form-select-sm" multiple size="3">
                                            <?php foreach ($rx_instructions as $ins): ?>
                                                <option value="<?= htmlspecialchars($ins['instruction_text'], ENT_QUOTES) ?>"><?= htmlspecialchars($ins['instruction_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6"><label class="form-label small fw-bold">DOCTOR NOTES</label><textarea name="doctor_notes" id="doctor_notes" rows="3" class="form-control" placeholder="ADVICE / NOTES..."><?= htmlspecialchars($selected['doctor_notes'] ?? '') ?></textarea></div>
                                <div class="col-md-6"><label class="form-label small fw-bold">FOLLOW-UP DATE</label><input type="date" name="follow_up_date" id="follow_up_date" class="form-control" value="<?= htmlspecialchars($selected['follow_up_date'] ?? '') ?>"></div>
                            </div>
                        </div>

                        <div class="card-footer bg-white border-top d-flex flex-wrap gap-2 justify-content-end py-3">
                            <?php if ($selected['status'] === 'Waiting'): ?>
                                <button name="doctor_action" value="start" class="btn btn-primary fw-bold"><i class="bi bi-play-fill me-1"></i>START CONSULTATION</button>
                            <?php else: ?>
                                <button name="doctor_action" value="save" class="btn btn-outline-primary fw-bold"><i class="bi bi-save me-1"></i>SAVE</button>
                                <button name="doctor_action" value="complete" class="btn btn-success fw-bold" onclick="return syncPrescription();"><i class="bi bi-check2-circle me-1"></i>SAVE & COMPLETE</button>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const MEDICINE_OPTIONS = `
    <option value="">SELECT MEDICINE</option>
    <?php foreach ($rx_medicines as $m): ?>
        <option value="<?= htmlspecialchars($m['medicine_name'], ENT_QUOTES) ?>"><?= htmlspecialchars($m['medicine_name']) ?><?= !empty($m['strength']) ? ' - ' . htmlspecialchars($m['strength']) : '' ?><?= !empty($m['dosage_form']) ? ' - ' . htmlspecialchars($m['dosage_form']) : '' ?></option>
    <?php endforeach; ?>
`;

const ROUTE_OPTIONS = `
    <option value="">SELECT</option>
    <?php foreach ($rx_routes as $r): ?>
        <option value="<?= htmlspecialchars($r['route_name'], ENT_QUOTES) ?>"><?= htmlspecialchars($r['route_name']) ?><?= !empty($r['route_code']) ? ' (' . htmlspecialchars($r['route_code']) . ')' : '' ?></option>
    <?php endforeach; ?>
`;

const FREQUENCY_OPTIONS = `
    <option value="">SELECT</option>
    <?php foreach ($rx_frequencies as $f): ?>
        <option value="<?= htmlspecialchars($f['frequency_name'], ENT_QUOTES) ?>"><?= htmlspecialchars($f['frequency_name']) ?><?= !empty($f['frequency_code']) ? ' (' . htmlspecialchars($f['frequency_code']) . ')' : '' ?></option>
    <?php endforeach; ?>
`;

const TIMING_OPTIONS = `
    <option value="">SELECT</option>
    <?php foreach ($rx_timings as $t): ?>
        <option value="<?= htmlspecialchars($t['timing_name'], ENT_QUOTES) ?>"><?= htmlspecialchars($t['timing_name']) ?><?= !empty($t['timing_code']) ? ' (' . htmlspecialchars($t['timing_code']) . ')' : '' ?></option>
    <?php endforeach; ?>
`;

const DURATION_OPTIONS = `
    <option value="">SELECT</option>
    <?php foreach ($rx_durations as $d): ?>
        <option value="<?= htmlspecialchars($d['duration_name'], ENT_QUOTES) ?>"><?= htmlspecialchars($d['duration_name']) ?></option>
    <?php endforeach; ?>
`;

const INSTRUCTION_OPTIONS = `
    <option value="">SELECT</option>
    <?php foreach ($rx_instructions as $ins): ?>
        <option value="<?= htmlspecialchars($ins['instruction_text'], ENT_QUOTES) ?>"><?= htmlspecialchars($ins['instruction_name']) ?></option>
    <?php endforeach; ?>
`;

function escapeHtml(v) {
    return String(v ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function addMedicine(item = {}) {
    const wrap = document.getElementById('medicineList');
    if (!wrap) return;

    const empty = document.getElementById('emptyMedicineText');
    if (empty) empty.remove();

    const row = document.createElement('div');
    row.className = 'dd-medicine-row';

    row.innerHTML = `
        <div class="row g-2 align-items-end">
            <div class="col-lg-2">
                <label class="form-label">MEDICINE</label>
                <select name="rx_medicine[]" class="form-select form-select-sm medicine-select">${MEDICINE_OPTIONS}</select>
            </div>
            <div class="col-lg-1">
                <label class="form-label">DOSE</label>
                <input type="text" name="rx_dose[]" class="form-control form-control-sm" placeholder="1 TAB" value="${escapeHtml(item.dose || '')}">
            </div>
            <div class="col-lg-1">
                <label class="form-label">ROUTE</label>
                <select name="rx_route[]" class="form-select form-select-sm route-select">${ROUTE_OPTIONS}</select>
            </div>
            <div class="col-lg-2">
                <label class="form-label">FREQUENCY</label>
                <select name="rx_frequency[]" class="form-select form-select-sm frequency-select">${FREQUENCY_OPTIONS}</select>
            </div>
            <div class="col-lg-2">
                <label class="form-label">TIMING</label>
                <select name="rx_timing[]" class="form-select form-select-sm timing-select">${TIMING_OPTIONS}</select>
            </div>
            <div class="col-lg-1">
                <label class="form-label">DURATION</label>
                <select name="rx_duration[]" class="form-select form-select-sm duration-select">${DURATION_OPTIONS}</select>
            </div>
            <div class="col-lg-1">
                <label class="form-label">QTY</label>
                <input type="text" name="rx_quantity[]" class="form-control form-control-sm" placeholder="10" value="${escapeHtml(item.quantity || '')}">
            </div>
            <div class="col-lg-2">
                <label class="form-label">INSTRUCTION</label>
                <div class="d-flex gap-1">
                    <select name="rx_instruction[]" class="form-select form-select-sm instruction-select">${INSTRUCTION_OPTIONS}</select>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.dd-medicine-row').remove(); syncPrescription();" title="REMOVE"><i class="bi bi-trash"></i></button>
                </div>
            </div>
        </div>
    `;

    wrap.appendChild(row);

    row.querySelector('.medicine-select').value = item.medicine_name || '';
    row.querySelector('.route-select').value = item.route || '';
    row.querySelector('.frequency-select').value = item.frequency || '';
    row.querySelector('.timing-select').value = item.timing || '';
    row.querySelector('.duration-select').value = item.duration || '';
    row.querySelector('.instruction-select').value = item.instructions || '';
}

function setMultiSelect(id, valuesText) {
    const el = document.getElementById(id);
    if (!el) return;

    const values = String(valuesText || '')
        .split(/\r?\n/)
        .map(v => v.trim())
        .filter(Boolean);

    Array.from(el.options).forEach(opt => {
        opt.selected = values.includes(opt.value);
    });
}

function getMultiSelect(id) {
    const el = document.getElementById(id);
    if (!el) return [];
    return Array.from(el.selectedOptions).map(o => o.value).filter(Boolean);
}

function mergeMasterAndText(selectId, textId) {
    const selected = getMultiSelect(selectId);
    const textEl = document.getElementById(textId);
    const extra = textEl ? textEl.value.trim() : '';
    const lines = extra ? extra.split(/\r?\n/).map(v=>v.trim()).filter(Boolean) : [];
    return [...new Set([...selected, ...lines])].join('\n');
}

function clearMasterSelections() {
    ['symptoms_master','diagnosis_master','instruction_master'].forEach(id => {
        const el = document.getElementById(id);
        if (el) Array.from(el.options).forEach(opt => opt.selected = false);
    });
}

function collectPrescriptionItems() {
    const rows = Array.from(document.querySelectorAll('#medicineList .dd-medicine-row'));
    return rows.map(row => ({
        medicine_name: row.querySelector('.medicine-select')?.value.trim() || '',
        dose: row.querySelector('[name="rx_dose[]"]')?.value.trim() || '',
        route: row.querySelector('.route-select')?.value.trim() || '',
        frequency: row.querySelector('.frequency-select')?.value.trim() || '',
        timing: row.querySelector('.timing-select')?.value.trim() || '',
        duration: row.querySelector('.duration-select')?.value.trim() || '',
        quantity: row.querySelector('[name="rx_quantity[]"]')?.value.trim() || '',
        instructions: row.querySelector('.instruction-select')?.value.trim() || ''
    })).filter(item => item.medicine_name !== '');
}

function syncPrescription() {
    const target = document.getElementById('prescription_items_json');
    if (target) {
        target.value = JSON.stringify(collectPrescriptionItems());
    }
    return true;
}

async function applyPrescriptionTemplate() {
    const templateId = document.getElementById('prescription_template_id')?.value || '';
    if (!templateId) {
        alert('PLEASE SELECT A PRESCRIPTION TEMPLATE.');
        return;
    }

    const btn = document.getElementById('applyTemplateBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>LOADING...';
    }

    try {
        const res = await fetch('opd_doctor.php?ajax=get_prescription_template&template_id=' + encodeURIComponent(templateId), {
            headers: { 'Accept': 'application/json' }
        });
        const data = await res.json();

        if (!data.ok) {
            alert(data.message || 'TEMPLATE COULD NOT BE LOADED.');
            return;
        }

        const t = data.template || {};

        setMultiSelect('symptoms_master', t.default_symptoms || '');
        setMultiSelect('diagnosis_master', t.default_diagnosis || '');

        const symptomsSelected = getMultiSelect('symptoms_master');
        const diagnosisSelected = getMultiSelect('diagnosis_master');
        document.getElementById('symptoms').value = symptomsSelected.join('\n');
        document.getElementById('diagnosis').value = diagnosisSelected.join('\n');

        const defaultAdvice = String(t.default_advice || '')
            .split(/\r?\n/)
            .map(v => v.trim())
            .filter(Boolean);

        setMultiSelect('instruction_master', t.default_advice || '');

        const adviceLines = getMultiSelect('instruction_master');
        const existingAdvice = document.getElementById('doctor_notes');
        if (existingAdvice && adviceLines.length) {
            existingAdvice.value = [...new Set([...adviceLines, ...(existingAdvice.value.trim() ? [existingAdvice.value.trim()] : [])])].join('\n');
        }

        if (t.follow_up_days !== null && t.follow_up_days !== undefined && String(t.follow_up_days) !== '') {
            const days = parseInt(t.follow_up_days, 10);
            if (!Number.isNaN(days)) {
                const d = new Date();
                d.setDate(d.getDate() + days);
                const yyyy = d.getFullYear();
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                document.getElementById('follow_up_date').value = `${yyyy}-${mm}-${dd}`;
            }
        }

        const wrap = document.getElementById('medicineList');
        wrap.innerHTML = '';

        if ((t.items || []).length) {
            t.items.forEach(item => addMedicine(item));
        } else {
            const empty = document.createElement('div');
            empty.id = 'emptyMedicineText';
            empty.className = 'text-center text-muted py-3 small';
            empty.innerHTML = 'NO MEDICINE IN THIS TEMPLATE.';
            wrap.appendChild(empty);
        }

        syncPrescription();
    } catch (e) {
        alert('UNABLE TO LOAD PRESCRIPTION TEMPLATE.');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-magic me-1"></i>APPLY TEMPLATE';
        }
    }
}

document.addEventListener('input', function(e) {
    if (e.target.matches('#symptoms, #diagnosis, #doctor_notes, #follow_up_date, #medicineList input')) {
        syncPrescription();
    }
});

document.addEventListener('change', function(e) {
    if (e.target.matches('#symptoms_master, #diagnosis_master, #instruction_master, #medicineList select')) {
        syncPrescription();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const q = document.getElementById('queueSearch');
    if (q) q.addEventListener('input', function() {
        const term = this.value.toLowerCase().trim();
        document.querySelectorAll('.dd-queue-row').forEach(row => {
            row.style.display = (!term || (row.dataset.search || '').includes(term)) ? '' : 'none';
        });
    });

    syncPrescription();
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
