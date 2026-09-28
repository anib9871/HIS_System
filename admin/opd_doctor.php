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
    ensureVisitColumn($tenant_pdo, 'doctor_notes', 'TEXT NULL', 'prescription');
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
                $prescription = trim($_POST['prescription_text'] ?? '');

                if ($action === 'complete') {
                    $status = 'Completed';
                    $tenant_pdo->prepare("UPDATE opd_visits SET doctor_id = ?, symptoms = ?, vitals_json = ?, diagnosis = ?, prescription = ?, doctor_notes = ?, follow_up_date = ?, consultation_completed_at = NOW(), status = ? WHERE visit_id = ? AND org_id = ? AND center_id = ?")
                        ->execute([$save_doctor_id, $symptoms, $vitals_json, $diagnosis, $prescription, $doctor_notes, $follow_up, $status, $visit_id, $org_id, $center_id]);
                    try {
                        $tenant_pdo->prepare("UPDATE opd_queue_tracking SET status = 'Completed', out_time = NOW() WHERE visit_id = ? AND org_id = ? AND center_id = ?")
                            ->execute([$visit_id, $org_id, $center_id]);
                    } catch (Exception $e) {}
                    $success = 'CONSULTATION COMPLETED.';
                } else {
                    $tenant_pdo->prepare("UPDATE opd_visits SET doctor_id = ?, symptoms = ?, vitals_json = ?, diagnosis = ?, prescription = ?, doctor_notes = ?, follow_up_date = ? WHERE visit_id = ? AND org_id = ? AND center_id = ?")
                        ->execute([$save_doctor_id, $symptoms, $vitals_json, $diagnosis, $prescription, $doctor_notes, $follow_up, $visit_id, $org_id, $center_id]);
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
            $selected_vitals = [];
            if (!empty($selected['vitals_json'])) {
                $decoded = json_decode($selected['vitals_json'], true);
                if (is_array($decoded)) $selected_vitals = $decoded;
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
                            <div class="mb-3"><label class="form-label small fw-bold">CHIEF COMPLAINT / SYMPTOMS</label><textarea name="symptoms" rows="3" class="form-control" placeholder="ENTER PATIENT COMPLAINTS / SYMPTOMS..."><?= htmlspecialchars($selected['symptoms'] ?? '') ?></textarea></div>

                            <div class="mb-3"><label class="form-label small fw-bold">DIAGNOSIS</label><textarea name="diagnosis" rows="3" class="form-control" placeholder="ENTER DIAGNOSIS..."><?= htmlspecialchars($selected['diagnosis'] ?? '') ?></textarea></div>

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="dd-section-title">PRESCRIPTION</div>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addMedicine()"><i class="bi bi-plus-lg me-1"></i>ADD MEDICINE</button>
                            </div>
                            <div id="medicineList" class="mb-3">
                                <?php
                                $rx_lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($selected['prescription'] ?? ''))));
                                if ($rx_lines) foreach ($rx_lines as $line):
                                ?>
                                    <div class="dd-medicine-row"><input type="text" class="form-control form-control-sm medicine-input" value="<?= htmlspecialchars($line) ?>" placeholder="MEDICINE / DOSE / FREQUENCY / DURATION"></div>
                                <?php endforeach; ?>
                            </div>
                            <textarea id="prescription_text" name="prescription_text" class="d-none"><?= htmlspecialchars($selected['prescription'] ?? '') ?></textarea>

                            <div class="row g-2 mb-3">
                                <div class="col-md-6"><label class="form-label small fw-bold">DOCTOR NOTES</label><textarea name="doctor_notes" rows="3" class="form-control" placeholder="ADVICE / NOTES..."><?= htmlspecialchars($selected['doctor_notes'] ?? '') ?></textarea></div>
                                <div class="col-md-6"><label class="form-label small fw-bold">FOLLOW-UP DATE</label><input type="date" name="follow_up_date" class="form-control" value="<?= htmlspecialchars($selected['follow_up_date'] ?? '') ?>"></div>
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
function addMedicine(value='') {
    const wrap = document.getElementById('medicineList');
    if (!wrap) return;
    const row = document.createElement('div');
    row.className = 'dd-medicine-row d-flex gap-2';
    row.innerHTML = '<input type="text" class="form-control form-control-sm medicine-input" value="' + escapeHtml(value) + '" placeholder="MEDICINE / DOSE / FREQUENCY / DURATION">' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" onclick="this.parentElement.remove(); syncPrescription();"><i class="bi bi-trash"></i></button>';
    wrap.appendChild(row);
    row.querySelector('input').focus();
}
function escapeHtml(v) {
    return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function syncPrescription(){
    const target = document.getElementById('prescription_text');
    if (!target) return true;
    const values = Array.from(document.querySelectorAll('.medicine-input')).map(x => x.value.trim()).filter(Boolean);
    target.value = values.join('\n');
    return true;
}
document.addEventListener('input', function(e){
    if (e.target.classList.contains('medicine-input')) syncPrescription();
});

document.addEventListener('DOMContentLoaded', function(){
    const q = document.getElementById('queueSearch');
    if (q) q.addEventListener('input', function(){
        const term = this.value.toLowerCase().trim();
        document.querySelectorAll('.dd-queue-row').forEach(row => {
            row.style.display = (!term || (row.dataset.search || '').includes(term)) ? '' : 'none';
        });
    });
    syncPrescription();
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
