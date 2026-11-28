<?php
// admin/ipd_doctor_desk.php
$page_title = "IPD Ward Rounds (Doctor Desk)";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';
require_once __DIR__ . '/../config/audit_helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();
// Use hospital local time (India) for ward-round timestamps and date displays.
date_default_timezone_set('Asia/Kolkata');

$org_id    = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$selected_ipd_id = (int)($_GET['ipd_id'] ?? ($_POST['ipd_id'] ?? 0));
$today     = date('Y-m-d');
$err       = '';
$success   = '';

/* ==========================================================
   DOCTOR MASTER + SIGNED-IN DOCTOR RESOLUTION
   Mirrors the OPD Doctor Desk doctor selector behaviour.
   ========================================================== */
$doctors = [];
$is_logged_in_doctor = false;
$logged_doctor_id = 0;
$logged_doctor_name = '';
$session_is_admin = ((int)($_SESSION['is_admin'] ?? 0) === 1);

try {
    $doctorStmt = $tenant_pdo->prepare("
        SELECT id, full_name, department_id
        FROM master_doctors
        WHERE org_id = ? AND center_id = ? AND status = 1
        ORDER BY full_name
    ");
    $doctorStmt->execute([$org_id, $center_id]);
    $doctors = $doctorStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($user_id > 0) {
        $userStmt = $tenant_pdo->prepare("
            SELECT tu.fullname, tu.center_id, tu.doctor_id, tu.is_admin,
                   tr.role_name, tr.role_type
            FROM tenant_users tu
            LEFT JOIN tenant_roles tr ON tr.role_id = tu.role_id
            WHERE tu.user_id = ? AND tu.status = 1
            LIMIT 1
        ");
        $userStmt->execute([$user_id]);
        $logged_user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$session_is_admin && $logged_user
            && (int)($logged_user['is_admin'] ?? 0) !== 1
            && strtolower(trim((string)($logged_user['role_type'] ?? 'general'))) === 'doctor') {

            $is_logged_in_doctor = true;
            $logged_doctor_id = (int)($logged_user['doctor_id'] ?? 0);
            $doctorCenterId = (int)($logged_user['center_id'] ?? $center_id);

            if ($logged_doctor_id > 0) {
                $matchStmt = $tenant_pdo->prepare("
                    SELECT id, full_name, department_id
                    FROM master_doctors
                    WHERE id = ? AND org_id = ? AND center_id = ? AND status = 1
                    LIMIT 1
                ");
                $matchStmt->execute([$logged_doctor_id, $org_id, $doctorCenterId]);
                $doctorRecord = $matchStmt->fetch(PDO::FETCH_ASSOC);

                if ($doctorRecord) {
                    $doctors = [$doctorRecord];
                    $logged_doctor_name = trim((string)$doctorRecord['full_name']);
                } else {
                    $doctors = [];
                    $logged_doctor_id = 0;
                    $err = 'THE LINKED DOCTOR MASTER RECORD IS INACTIVE OR DOES NOT MATCH THIS CENTER. PLEASE CHECK STAFF USER MAPPING.';
                }
            } else {
                $doctors = [];
                $err = 'DOCTOR LOGIN IS NOT LINKED TO DOCTOR MASTER. PLEASE EDIT THIS STAFF USER AND SELECT THE CORRECT DOCTOR.';
            }
        }
    }
} catch (Throwable $e) {
    $err = $err ?: 'DOCTOR LOOKUP ERROR: ' . $e->getMessage();
}

$doctor_id = $is_logged_in_doctor
    ? $logged_doctor_id
    : (int)($_GET['doctor_id'] ?? ($_POST['doctor_id'] ?? ($_SESSION['doctor_id'] ?? 0)));

if ($is_logged_in_doctor) {
    $doctor_id = $logged_doctor_id;
} elseif ($doctor_id > 0) {
    $validDoctor = false;
    foreach ($doctors as $doc) {
        if ((int)$doc['id'] === $doctor_id) { $validDoctor = true; break; }
    }
    if (!$validDoctor) $doctor_id = 0;
}

/* ==========================================================
   AJAX MASTERS LOGIC
   ========================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_masters') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $st_sym = $tenant_pdo->prepare("SELECT id, symptom_name FROM master_symptoms WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY symptom_name");
        $st_sym->execute([$org_id, $center_id]);
        $symptoms = $st_sym->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_diag = $tenant_pdo->prepare("SELECT id, diagnosis_name FROM master_diagnoses WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY diagnosis_name");
        $st_diag->execute([$org_id, $center_id]);
        $diagnoses = $st_diag->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_inv = $tenant_pdo->prepare("SELECT id, investigation_name FROM master_investigations WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY investigation_name");
        $st_inv->execute([$org_id, $center_id]);
        $investigations = $st_inv->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_adv = $tenant_pdo->prepare("SELECT id, advice_name FROM master_advices WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY advice_name");
        $st_adv->execute([$org_id, $center_id]);
        $advices = $st_adv->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_vit = $tenant_pdo->prepare("SELECT id, vital_name, vital_key, unit, placeholder, sort_order FROM master_vitals WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY sort_order ASC, vital_name ASC");
        $st_vit->execute([$org_id, $center_id]);
        $vitals = $st_vit->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_freq = $tenant_pdo->prepare("SELECT id, frequency_name FROM frequency_master WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY frequency_name");
        $st_freq->execute([$org_id, $center_id]);
        $frequencies = $st_freq->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_unit = $tenant_pdo->prepare("SELECT id, unit_name FROM master_units WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY unit_name");
        $st_unit->execute([$org_id, $center_id]);
        $units = $st_unit->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_meal = $tenant_pdo->prepare("SELECT id, meal_name FROM master_meals WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY meal_name");
        $st_meal->execute([$org_id, $center_id]);
        $meals = $st_meal->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_dur = $tenant_pdo->prepare("SELECT id, duration_name FROM master_durations WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY duration_name");
        $st_dur->execute([$org_id, $center_id]);
        $durations = $st_dur->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_med = $tenant_pdo->prepare("
            SELECT mm.id, mm.medicine_name, mm.generic_name, mm.strength, mm.frequency_id, mm.unit_id, mm.meal_id, 
                   mm.default_qty, mm.default_duration_id, mm.dosage_form, mm.manufacturer, mm.category, 
                   mu.unit_name, meal.meal_name, md.duration_name, fm.frequency_name, fm.frequency_code, mm.status AS medicine_status
            FROM master_medicines mm
            LEFT JOIN master_units mu ON mu.id = mm.unit_id
            LEFT JOIN master_meals meal ON meal.id = mm.meal_id
            LEFT JOIN master_durations md ON md.id = mm.default_duration_id
            LEFT JOIN frequency_master fm ON fm.id = mm.frequency_id
            WHERE mm.org_id = ? AND mm.center_id = ? AND mm.status = 1
            ORDER BY mm.medicine_name
        ");
        $st_med->execute([$org_id, $center_id]);
        $medicines = $st_med->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'ok' => true,
            'symptoms' => $symptoms,
            'diagnoses' => $diagnoses,
            'investigations' => $investigations,
            'advices' => $advices,
            'vitals' => $vitals,
            'frequencies' => $frequencies,
            'units' => $units,
            'meals' => $meals,
            'durations' => $durations,
            'medicines' => $medicines
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'add_master') {
    header('Content-Type: application/json; charset=utf-8');
    $master_type = trim((string)($_POST['master_type'] ?? ''));
    $value       = strtoupper(trim((string)($_POST['value'] ?? '')));

    $allowed = ['symptom' => 'master_symptoms', 'diagnosis' => 'master_diagnoses', 'investigation' => 'master_investigations', 'advice' => 'master_advices'];
    if (isset($allowed[$master_type]) && $value !== '') {
        try {
            $table = $allowed[$master_type];
            $col_name = $master_type . '_name';
            // Match the OPD popup behavior: if the master already exists, select it instead of inserting a duplicate.
            $find = $tenant_pdo->prepare("SELECT id, {$col_name} AS master_name FROM {$table} WHERE org_id = ? AND center_id = ? AND LOWER({$col_name}) = LOWER(?) AND status = 1 LIMIT 1");
            $find->execute([$org_id, $center_id, $value]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                echo json_encode(['ok' => true, 'created' => false, 'id' => (int)$existing['id'], 'name' => $existing['master_name'], 'message' => 'Already exists; selected existing master.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $ins = $tenant_pdo->prepare("INSERT INTO {$table} (org_id, center_id, {$col_name}, status, created_by) VALUES (?, ?, ?, 1, ?)");
            $ins->execute([$org_id, $center_id, $value, $user_id ?: null]);
            echo json_encode(['ok' => true, 'created' => true, 'id' => (int)$tenant_pdo->lastInsertId(), 'name' => $value, 'message' => 'Added!'], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) { echo json_encode(['ok' => false, 'message' => $e->getMessage()]); }
    } else {
        echo json_encode(['ok' => false, 'message' => 'Invalid master type or empty value.']);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_prescription_template') {
    header('Content-Type: application/json; charset=utf-8');
    $template_id = (int)($_GET['template_id'] ?? 0);
    try {
        $st = $tenant_pdo->prepare("SELECT template_id, template_name, default_symptoms, default_diagnosis, default_investigations, default_advice, follow_up_days, default_vitals, custom_fields_schema FROM prescription_template_master WHERE template_id = ? AND org_id = ? AND center_id = ? AND status = 1 LIMIT 1");
        $st->execute([$template_id, $org_id, $center_id]);
        $template = $st->fetch(PDO::FETCH_ASSOC);

        if (!$template) { echo json_encode(['ok' => false, 'message' => 'PRESCRIPTION TEMPLATE NOT FOUND.']); exit; }

        $st = $tenant_pdo->prepare("
            SELECT pti.item_id, pti.medicine_name, pti.dose, pti.duration, pti.quantity,
                   mm.id AS medicine_id, mm.medicine_name AS master_medicine_name, mm.generic_name, mm.strength, mm.frequency_id, mm.default_qty, mm.dosage_form,
                   mu.unit_name, meal.meal_name, fm.frequency_name, fm.frequency_code, md.duration_name
            FROM prescription_template_items pti
            LEFT JOIN master_medicines mm ON mm.medicine_name = pti.medicine_name AND mm.org_id = ? AND mm.center_id = ? AND mm.status = 1
            LEFT JOIN master_units mu ON mu.id = mm.unit_id
            LEFT JOIN master_meals meal ON meal.id = mm.meal_id
            LEFT JOIN frequency_master fm ON fm.id = mm.frequency_id
            LEFT JOIN master_durations md ON md.id = mm.default_duration_id
            WHERE pti.template_id = ? ORDER BY pti.sort_order, pti.item_id
        ");
        $st->execute([$org_id, $center_id, $template_id]);
        $template['items'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['ok' => true, 'template' => $template], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'message' => $e->getMessage()]); }
    exit;
}

/* ==========================================================
   SAVE WARD ROUND DATA
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doctor_action']) && $_POST['doctor_action'] === 'save_round') {
    $ipd_id = (int)($_POST['ipd_id'] ?? 0);
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    
    if ($ipd_id > 0) {
        try {
            // Confirm this IPD/patient belongs to the active tenant and center before saving.
            $admissionCheck = $tenant_pdo->prepare("SELECT patient_id, doctor_id FROM ipd_admissions WHERE ipd_id = ? AND org_id = ? AND center_id = ? AND status = 1 LIMIT 1");
            $admissionCheck->execute([$ipd_id, $org_id, $center_id]);
            $admissionRow = $admissionCheck->fetch(PDO::FETCH_ASSOC);
            $admittedPatientId = (int)($admissionRow['patient_id'] ?? 0);
            if ($admittedPatientId <= 0 || $admittedPatientId !== $patient_id) {
                throw new RuntimeException('Selected IPD admission is not active or patient details do not match.');
            }

            // Doctor accounts can only save under their own linked Doctor Master ID.
            $round_doctor_id = $is_logged_in_doctor
                ? $logged_doctor_id
                : ($doctor_id > 0 ? $doctor_id : (int)($admissionRow['doctor_id'] ?? 0));

            if ($round_doctor_id <= 0) {
                throw new RuntimeException('PLEASE SELECT A DOCTOR BEFORE SAVING WARD ROUND NOTES.');
            }

            $doctorValidStmt = $tenant_pdo->prepare("
                SELECT id FROM master_doctors
                WHERE id = ? AND org_id = ? AND center_id = ? AND status = 1
                LIMIT 1
            ");
            $doctorValidStmt->execute([$round_doctor_id, $org_id, $center_id]);
            if (!$doctorValidStmt->fetchColumn()) {
                throw new RuntimeException('SELECTED DOCTOR IS INVALID OR INACTIVE.');
            }

            if ($is_logged_in_doctor && (int)($admissionRow['doctor_id'] ?? 0) !== $logged_doctor_id) {
                throw new RuntimeException('YOU CAN ONLY ADD WARD ROUND NOTES FOR PATIENTS ASSIGNED TO YOU.');
            }

            $symptoms          = trim($_POST['symptoms'] ?? '');
            $diagnosis         = trim($_POST['diagnosis'] ?? '');
            $investigations    = trim($_POST['investigations'] ?? '');
            $advice            = trim($_POST['advice'] ?? '');
            $doctor_notes      = trim($_POST['doctor_notes'] ?? '');
            $custom_fields     = trim((string)($_POST['custom_fields_values_json'] ?? '{}'));
            $custom_fields     = $custom_fields !== '' ? $custom_fields : '{}';
            
            $vitals_raw        = trim((string)($_POST['vitals_json'] ?? '{}'));
            $vitals_decoded    = json_decode($vitals_raw, true) ?: [];
            $vitals_json       = json_encode($vitals_decoded, JSON_UNESCAPED_UNICODE);

            $rx_json_raw       = trim($_POST['prescription_items_json'] ?? '[]');
            $decoded_rx        = json_decode($rx_json_raw, true) ?: [];
            $prescription_json = json_encode(['items' => $decoded_rx], JSON_UNESCAPED_UNICODE);

            $round_id = (int)($_POST['round_id'] ?? 0);
            $tenant_pdo->beginTransaction();

            if ($round_id > 0) {
                $oldStmt = $tenant_pdo->prepare("SELECT * FROM ipd_ward_rounds WHERE id = ? AND org_id = ? AND center_id = ? AND ipd_id = ? AND patient_id = ? LIMIT 1");
                $oldStmt->execute([$round_id, $org_id, $center_id, $ipd_id, $patient_id]);
                $oldRound = $oldStmt->fetch(PDO::FETCH_ASSOC);
                if (!$oldRound) {
                    throw new RuntimeException('WARD ROUND NOT FOUND OR YOU DO NOT HAVE ACCESS TO EDIT IT.');
                }
                if ($is_logged_in_doctor && (int)$oldRound['doctor_id'] !== $logged_doctor_id) {
                    throw new RuntimeException('YOU CAN ONLY EDIT WARD ROUNDS CREATED UNDER YOUR DOCTOR ACCOUNT.');
                }

                $upd = $tenant_pdo->prepare("
                    UPDATE ipd_ward_rounds
                    SET doctor_id = ?, vitals_json = ?, symptoms = ?, diagnosis = ?, investigations = ?, advice = ?, doctor_notes = ?, prescription_json = ?, custom_fields_json = ?
                    WHERE id = ? AND org_id = ? AND center_id = ? AND ipd_id = ? AND patient_id = ?
                ");
                $upd->execute([$round_doctor_id, $vitals_json, $symptoms, $diagnosis, $investigations, $advice, $doctor_notes, $prescription_json, $custom_fields, $round_id, $org_id, $center_id, $ipd_id, $patient_id]);

                $auditChanges = [];
                foreach ([
                    'doctor_id' => $round_doctor_id,
                    'vitals_json' => $vitals_json,
                    'symptoms' => $symptoms,
                    'diagnosis' => $diagnosis,
                    'investigations' => $investigations,
                    'advice' => $advice,
                    'doctor_notes' => $doctor_notes,
                    'prescription_json' => $prescription_json,
                    'custom_fields_json' => $custom_fields
                ] as $field => $newValue) {
                    $oldValue = $oldRound[$field] ?? null;
                    if ((string)$oldValue !== (string)$newValue) {
                        $auditChanges[] = ['field_name'=>$field, 'old_value'=>$oldValue, 'new_value'=>$newValue];
                    }
                }
                if ($auditChanges) {
                    audit_log_event($tenant_pdo, ['org_id'=>$org_id,'center_id'=>$center_id,'user_id'=>$user_id,'action_type'=>'UPDATE','module_name'=>'IPD DOCTOR DESK','table_name'=>'ipd_ward_rounds','record_id'=>$round_id,'patient_id'=>$patient_id,'transaction_id'=>$ipd_id,'reason'=>'Doctor ward round edited','changes'=>$auditChanges]);
                }
                $savedRoundId = $round_id;
                $successMessage = 'WARD ROUND UPDATED SUCCESSFULLY.';
            } else {
                $round_date = date('Y-m-d H:i:s');
                $tenant_pdo->prepare("
                    INSERT INTO ipd_ward_rounds
                    (org_id, center_id, ipd_id, patient_id, doctor_id, round_date, vitals_json, symptoms, diagnosis, investigations, advice, doctor_notes, prescription_json, custom_fields_json, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$org_id, $center_id, $ipd_id, $patient_id, $round_doctor_id, $round_date, $vitals_json, $symptoms, $diagnosis, $investigations, $advice, $doctor_notes, $prescription_json, $custom_fields, $user_id]);
                $savedRoundId = (int)$tenant_pdo->lastInsertId();
                audit_log_event($tenant_pdo, ['org_id'=>$org_id,'center_id'=>$center_id,'user_id'=>$user_id,'action_type'=>'INSERT','module_name'=>'IPD DOCTOR DESK','table_name'=>'ipd_ward_rounds','record_id'=>$savedRoundId,'patient_id'=>$patient_id,'transaction_id'=>$ipd_id,'reason'=>'Doctor ward round saved','changes'=>[['field_name'=>'doctor_id','old_value'=>null,'new_value'=>$round_doctor_id],['field_name'=>'symptoms','old_value'=>null,'new_value'=>$symptoms],['field_name'=>'diagnosis','old_value'=>null,'new_value'=>$diagnosis],['field_name'=>'investigations','old_value'=>null,'new_value'=>$investigations],['field_name'=>'advice','old_value'=>null,'new_value'=>$advice],['field_name'=>'doctor_notes','old_value'=>null,'new_value'=>$doctor_notes],['field_name'=>'prescription_json','old_value'=>null,'new_value'=>$prescription_json]]]);
                $successMessage = 'WARD ROUND SAVED SUCCESSFULLY.';
            }
            $tenant_pdo->commit();

            $_SESSION['swal_success'] = $successMessage;
            header("Location: ipd_doctor_desk.php?ipd_id=$ipd_id&doctor_id=$round_doctor_id");
            exit;
        } catch (Exception $e) {
            if ($tenant_pdo->inTransaction()) $tenant_pdo->rollBack();
            $err = $e->getMessage();
        }
    }
}

/* ==========================================================
   FETCH ADMITTED PATIENTS (WARD QUEUE)
   ========================================================== */
$admitted_patients = [];
try {
    $whereDoctor = '';
    $params = [$org_id, $center_id];
    if ($is_logged_in_doctor) {
        $whereDoctor = ' AND a.doctor_id = ? ';
        $params[] = $logged_doctor_id;
    } elseif ($doctor_id > 0) {
        $whereDoctor = ' AND a.doctor_id = ? ';
        $params[] = $doctor_id;
    }

    $q = $tenant_pdo->prepare("
        SELECT a.*, p.uhid, p.fullname, p.gender, p.age, bd.bed_no, r.room_no, d.full_name AS doctor_name, dept.dept_name
        FROM ipd_admissions a
        JOIN patient_master p ON p.patient_id = a.patient_id
        JOIN master_beds bd ON a.bed_id = bd.id
        JOIN master_rooms r ON bd.room_id = r.id
        LEFT JOIN master_doctors d ON d.id = a.doctor_id
        LEFT JOIN master_departments dept ON dept.id = a.department_id
        WHERE a.org_id = ? AND a.center_id = ? AND a.status = 1 {$whereDoctor}
        ORDER BY r.room_no ASC, bd.bed_no ASC
    ");
    $q->execute($params);
    $admitted_patients = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

/* ==========================================================
   SELECTED PATIENT DETAILS & PAST ROUNDS
   ========================================================== */
$selected = null;
$past_rounds = [];
$selected_template_id = 0;

if ($selected_ipd_id > 0) {
    foreach ($admitted_patients as $pt) {
        if ((int)$pt['ipd_id'] === $selected_ipd_id) {
            $selected = $pt;
            break;
        }
    }

    if ($selected) {
        $pv = $tenant_pdo->prepare("
            SELECT * FROM ipd_ward_rounds 
            WHERE ipd_id = ? AND org_id = ? AND center_id = ? 
            ORDER BY round_date DESC
        ");
        $pv->execute([$selected_ipd_id, $org_id, $center_id]);
        $past_rounds = $pv->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Automatically choose the active prescription template assigned to this patient's department.
        // Department mapping takes priority; do not accidentally select another department's/doctor's template.
        try {
            $schemaStmt = $tenant_pdo->prepare("
                SELECT template_id
                FROM prescription_template_master
                WHERE org_id = ? AND center_id = ? AND status = 1 AND department_id = ?
                ORDER BY template_id DESC
                LIMIT 1
            ");
            $schemaStmt->execute([$org_id, $center_id, (int)($selected['department_id'] ?? 0)]);
            if ($schemaRow = $schemaStmt->fetch(PDO::FETCH_ASSOC)) {
                $selected_template_id = (int)$schemaRow['template_id'];
            }
        } catch (Throwable $e) {
            $selected_template_id = 0;
        }
    }
}

// Fetch all templates for dropdown
$prescription_templates = $tenant_pdo->query("SELECT template_id, template_name, doctor_id, department_id FROM prescription_template_master WHERE org_id = $org_id AND status = 1")->fetchAll();

require_once __DIR__ . '/layout_header.php';
?>

<style>
body, .card, .form-label, .form-control, .form-select, .btn, .badge, .table, th, td { text-transform: uppercase; }
input[type="number"], textarea { text-transform: none; }
.doctor-desk-wrap { min-width: 0; font-size: 11px; }
.doctor-desk-wrap .row { --bs-gutter-x: .6rem; --bs-gutter-y: .6rem; }
.dd-main-card { border:0; border-radius:10px; box-shadow:0 2px 10px rgba(15,23,42,.06); }
.dd-patient-banner { background:linear-gradient(135deg,#eff6ff,#ffffff); border:1px solid #dbeafe; border-radius:9px; padding:9px 10px; margin-bottom: .55rem !important; }
.dd-patient-banner .patient-name { font-size:16px; font-weight:900; }
.dd-patient-banner .uhid { font-family:monospace; font-size:9px; font-weight:800; color:#2563eb; }
.dd-section-title { font-size:9px; font-weight:900; color:#0f172a; letter-spacing:.3px; }
.dd-vital { border:1px solid #e5e7eb; border-radius:9px; padding:7px; min-height:66px; background:#fff; }
.dd-vital label { font-size:9px; color:#64748b; font-weight:800; margin-bottom:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; }
.dd-vital input { font-weight:800; }

.dd-medicine-row { border:1px solid #dfe5ec; border-radius:7px; padding:6px 6px 7px; margin-bottom:5px; background:#fafbfc; }
.dd-medicine-row .form-label { font-size:7px !important; line-height:1.05; font-weight:800; margin:0 0 2px !important; color:#4b5563; display:block; }
.dd-medicine-row .form-control, .dd-medicine-row .form-select, .dd-medicine-row .btn { height:30px; min-height:30px; font-size:10px; line-height:1.1; padding:3px 6px; border-radius:4px; }
.dd-medicine-row .dd-readonly { color:#475569; }

.dd-investigation-box { border:1px solid #e5e7eb; border-radius:10px; padding:10px; background:#fff; position:relative; }
.master-search-row { display:flex; gap:6px; }
.master-chip-wrap { min-height:36px; display:flex; flex-wrap:wrap; gap:6px; padding:4px 0; }
.master-chip { display:inline-flex; align-items:center; gap:5px; border:1px solid #bfdbfe; background:#eff6ff; color:#1e3a8a; border-radius:999px; padding:4px 8px; font-size:10px; font-weight:800; }
.master-chip button { border:0; background:transparent; color:inherit; font-weight:900; line-height:1; padding:0 2px; cursor:pointer; }

.dd-clinical-mini { border:1px solid #dbe3ed; border-radius:9px; padding:10px; min-height:86px; background:#fff; transition:all .15s ease; }
.dd-clinical-mini:hover { border-color:#93c5fd; background:#f8fbff; }
.dd-clinical-mini-head { display:flex; align-items:center; justify-content:space-between; gap:6px; color:#334155; font-size:9px; font-weight:900; margin-bottom:7px; }
.dd-clinical-mini-head i { color:#2563eb; }
.dd-summary-chips, .dd-summary-text { font-size:10px; color:#475569; line-height:1.4; overflow-wrap:anywhere; }
.dd-summary-empty { color:#94a3b8; font-weight:700; }
.master-inline-add .input-group { max-width: 100%; }
.master-option-list { display:none; position:absolute; z-index:100; top:82px; left:10px; right:10px; max-height:115px; overflow-y:auto; overscroll-behavior:contain; border:1px solid #cbd5e1; border-radius:6px; background:#fff; box-shadow:0 5px 14px rgba(15,23,42,.14); margin:0; }
.master-option-list.show { display:block; }
.master-option-list::-webkit-scrollbar { width:6px; }
.master-option-list::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:8px; }
.master-option { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:7px 10px; font-size:10px; font-weight:700; color:#334155; cursor:pointer; border-bottom:1px solid #f1f5f9; }
.master-option:hover { background:#eff6ff; }
.master-option.selected { color:#64748b; background:#f8fafc; }
.master-option small { color:#2563eb; font-size:9px; }
.master-inline-add .form-control, .master-inline-add .btn { font-size: 10px; }
.modal-backdrop.show { opacity:.45; }


.patient-list-item { cursor: pointer; transition: 0.2s; border-left: 4px solid transparent; }
.patient-list-item:hover { background-color: #f8fbff; border-left-color: #cbd5e1; }
.patient-list-item.active { background-color: #eff6ff; border-left-color: #3b82f6; }

#consultationTabs .nav-link { background-color: #f1f5f9 !important; color: #64748b !important; border: 1px solid #cbd5e1; border-bottom: none; margin: 0 4px; transition: 0.2s; }
#consultationTabs .nav-link.active { background-color: #1e3a8a !important; color: #ffffff !important; border-color: #1e3a8a !important; }
#consultationTabs .nav-link.active i { color: #93c5fd !important; }
</style>

<div class="doctor-desk-wrap">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h5 class="fw-bold mb-0" style="font-size:14px;"><i class="bi bi-hospital text-primary me-2"></i>IPD DOCTOR DESK</h5>
            <div class="text-muted small" style="font-size:9px;">DAILY IPD WARD ROUNDS & DOCTOR CLINICAL NOTES</div>
        </div>
        <form method="GET" class="d-flex align-items-center gap-2">
            <?php if ($selected_ipd_id > 0): ?>
                <input type="hidden" name="ipd_id" value="<?= (int)$selected_ipd_id ?>">
            <?php endif; ?>
            <label class="small fw-bold text-secondary mb-0">DOCTOR</label>
            <?php if ($is_logged_in_doctor): ?>
                <input type="hidden" name="doctor_id" value="<?= (int)$logged_doctor_id ?>">
                <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($logged_doctor_name) ?>" readonly style="min-width:190px;">
            <?php else: ?>
                <select name="doctor_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:190px;">
                    <option value="0" <?= $doctor_id === 0 ? 'selected' : '' ?>>ALL DOCTORS</option>
                    <?php foreach ($doctors as $doc): ?>
                        <option value="<?= (int)$doc['id'] ?>" <?= $doctor_id === (int)$doc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </form>
    </div>

    <div class="row g-3">
        <!-- LEFT PANEL: WARD QUEUE -->
        <div class="col-lg-4">
            <div class="card dd-main-card">
                <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center" style="min-height:38px;">
                    <div class="fw-bold"><i class="bi bi-people-fill me-2 text-primary"></i>ADMITTED PATIENTS</div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= count($admitted_patients) ?></span>
                </div>
                <div class="p-2 border-bottom">
                    <input type="text" id="queueSearch" class="form-control form-control-sm" placeholder="SEARCH ROOM / PATIENT / UHID">
                </div>
                <div class="card-body p-0" style="max-height: 85vh; overflow-y: auto;">
                    <?php if(empty($admitted_patients)): ?>
                        <div class="p-4 text-center text-muted small fw-bold">NO PATIENTS ADMITTED UNDER YOUR CARE.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach($admitted_patients as$v): ?>
                                <a href="?ipd_id=<?= (int)$v['ipd_id'] ?>&doctor_id=<?= (int)$doctor_id ?>" class="list-group-item list-group-item-action patient-list-item p-3 <?= $selected_ipd_id ==$v['ipd_id'] ? 'active' : '' ?>" data-search="<?= htmlspecialchars(strtolower($v['room_no'].' '.$v['bed_no'].' '.$v['fullname'].' '.$v['uhid'])) ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-bold text-dark" style="font-size: 12px;"><?= htmlspecialchars($v['fullname']) ?></span>
                                        <span class="badge bg-warning-subtle text-warning border border-warning" style="font-size: 8px;">ADMITTED</span>
                                    </div>
                                    <div class="text-secondary" style="font-size: 10px;">
                                        <?= htmlspecialchars($v['uhid']) ?> | <?= htmlspecialchars($v['gender']) ?> • <?= (int)$v['age'] ?> YRS
                                    </div>
                                    <div class="text-primary fw-bold mt-1" style="font-size: 10px;">
                                        <i class="bi bi-door-open"></i> BED: <?= htmlspecialchars($v['room_no'] . ' - ' .$v['bed_no']) ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL: PATIENT DETAILS & PAST ROUNDS -->
        <div class="col-lg-8">
            <div class="card dd-main-card h-100">
                <?php if (!$selected): ?>
                    <div class="card-body text-center py-5">
                        <i class="bi bi-person-bounding-box display-4 text-primary"></i>
                        <h5 class="fw-bold mt-3">SELECT PATIENT</h5>
                        <p class="text-muted mb-0">SELECT AN ADMITTED PATIENT TO RECORD ROUND NOTES.</p>
                    </div>
                <?php else: ?>
                    <div class="card-body" style="padding:.65rem;">
                        <div class="dd-patient-banner d-flex justify-content-between gap-3 flex-wrap align-items-center">
                            <div>
                                <div class="patient-name"><?= htmlspecialchars($selected['fullname']) ?></div>
                                <div class="uhid"><?= htmlspecialchars($selected['uhid']) ?></div>
                                <div class="small text-muted mt-1" style="font-size:8px;">
                                    <?= (int)$selected['age'] ?> YRS / <?= htmlspecialchars($selected['gender']) ?>
                                </div>
                            </div>
                            <div class="text-end d-flex flex-column align-items-end">
                                <div class="fw-bold text-danger fs-6">WARD: <?= htmlspecialchars($selected['room_no'] . ' - ' .$selected['bed_no']) ?></div>
                                <div class="small text-muted mb-1" style="font-size:8px;">ADMITTED ON: <?= date('d-m-Y', strtotime($selected['admission_date'])) ?></div>
                                <div class="d-flex gap-1 mt-1">
                                    <a href="ipd_doctor_desk.php?ipd_id=<?= (int)$selected_ipd_id ?>&doctor_id=<?= (int)$doctor_id ?>" class="btn btn-sm btn-outline-primary fw-bold" style="font-size:10px; padding:4px 8px;"><i class="bi bi-arrow-clockwise me-1"></i> REFRESH</a>
                                    <button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#combinedConsultationModal" onclick="prepareNewWardRound()" style="font-size:10px; padding:4px 10px;">
                                        <i class="bi bi-plus-circle me-1"></i> ADD NEW ROUND NOTES
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- TIMELINE OF PAST ROUNDS -->
                        <div class="dd-section-title mb-2 mt-3">TIMELINE: PREVIOUS WARD ROUNDS</div>
                        <div class="border rounded p-3 bg-light" style="max-height: 60vh; overflow-y: auto;">
                            <?php if (empty($past_rounds)): ?>
                                <div class="text-center text-muted small fw-bold">NO PAST ROUNDS RECORDED.</div>
                            <?php else: ?>
                                <?php foreach($past_rounds as$rnd): ?>
                                    <div class="bg-white border rounded p-3 mb-3 shadow-sm">
                                        <div class="d-flex justify-content-between align-items-center mb-2 border-bottom pb-2">
                                            <span class="fw-bold text-primary"><i class="bi bi-calendar-check me-1"></i> <?= date('d-M-Y h:i A', strtotime($rnd['round_date'])) ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" onclick="copyPastNotes(<?= htmlspecialchars($rnd['prescription_json'], ENT_QUOTES, 'UTF-8') ?>)" style="font-size:9px;">
                                                <i class="bi bi-files me-1"></i> COPY MEDICINES
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 ms-1" onclick="editWardRound(this)" data-round="<?= htmlspecialchars(json_encode($rnd, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG), ENT_QUOTES, 'UTF-8') ?>" style="font-size:9px;">
                                                <i class="bi bi-pencil-square me-1"></i> EDIT ROUND
                                            </button>
                                        </div>
                                        
                                        <!-- Vitals -->
                                        <?php 
                                            $vitals = json_decode($rnd['vitals_json'], true); 
                                            if(!empty($vitals)):
                                        ?>
                                            <div class="d-flex flex-wrap gap-2 mb-2">
                                                <?php foreach($vitals as $vKey =>$vVal): ?>
                                                    <?php if(trim($vVal) !== ''): ?>
                                                        <span class="badge bg-light text-dark border border-secondary">
                                                            <strong class="text-secondary"><?= strtoupper(str_replace('_', ' ', $vKey)) ?>:</strong> <?= htmlspecialchars($vVal) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Notes -->
                                        <div style="font-size: 11px;">
                                            <?php if($rnd['symptoms']): ?><strong>SYMPTOMS:</strong> <?= nl2br(htmlspecialchars($rnd['symptoms'])) ?><br><?php endif; ?>
                                            <?php if($rnd['diagnosis']): ?><strong>DIAGNOSIS:</strong> <?= nl2br(htmlspecialchars($rnd['diagnosis'])) ?><br><?php endif; ?>
                                            <?php if($rnd['doctor_notes']): ?><strong>NOTES:</strong> <?= nl2br(htmlspecialchars($rnd['doctor_notes'])) ?><br><?php endif; ?>
                                        </div>

                                        <!-- Prescription -->
                                        <?php 
                                            $meds = json_decode($rnd['prescription_json'], true);
                                            if(!empty($meds['items'])):
                                        ?>
                                            <div class="bg-light p-2 rounded mt-2 border">
                                                <strong class="text-danger d-block mb-1" style="font-size: 9px;">MEDICINES:</strong>
                                                <ul class="mb-0 ps-3" style="font-size: 10px; font-weight: 600;">
                                                    <?php foreach($meds['items'] as $m): ?>
                                                        <?php
                                                            // Support both current keys (name/freq) and older saved keys
                                                            // (medicine_name/frequency) so historical rounds display safely.
                                                            $displayMedicine = trim((string)($m['medicine_name'] ?? $m['name'] ?? ''));
                                                            $displayStrength = trim((string)($m['strength'] ?? ''));
                                                            $displayFrequency = trim((string)($m['frequency'] ?? $m['freq'] ?? ''));
                                                            $displayDose = trim((string)($m['dose'] ?? ''));
                                                            $displayDuration = trim((string)($m['duration'] ?? ''));
                                                            $displayQty = trim((string)($m['quantity'] ?? $m['qty'] ?? ''));
                                                        ?>
                                                        <li>
                                                            <?= htmlspecialchars($displayMedicine) ?>
                                                            <?= htmlspecialchars($displayStrength) ?>
                                                            <?php if ($displayDose !== ''): ?> - <?= htmlspecialchars($displayDose) ?><?php endif; ?>
                                                            <?php if ($displayFrequency !== ''): ?> - <?= htmlspecialchars($displayFrequency) ?><?php endif; ?>
                                                            <?php if ($displayDuration !== ''): ?> - <?= htmlspecialchars($displayDuration) ?><?php endif; ?>
                                                            <?php if ($displayQty !== ''): ?> (QTY: <?= htmlspecialchars($displayQty) ?>)<?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- COMBINED CONSULTATION MODAL -->
<!-- ========================================================================= -->
<?php if ($selected): ?>
<form method="POST" id="wardRoundForm">
                    <input type="hidden" name="doctor_id" value="<?= (int)$doctor_id ?>"> 
<input type="hidden" name="doctor_action" value="save_round">
<input type="hidden" name="round_id" id="round_id" value="0">
<input type="hidden" name="ipd_id" value="<?= $selected_ipd_id ?>">
<input type="hidden" name="patient_id" value="<?= $selected['patient_id'] ?>">
<input type="hidden" id="prescription_items_json" name="prescription_items_json" value="[]">
<input type="hidden" id="vitals_json" name="vitals_json" value="{}">

<!-- Hidden Fields for textareas -->
<input type="hidden" name="symptoms" id="form_symptoms" value="">
<input type="hidden" name="diagnosis" id="form_diagnosis" value="">
<input type="hidden" name="investigations" id="form_investigations" value="">
<input type="hidden" name="advice" id="form_advice" value="">
<input type="hidden" name="doctor_notes" id="form_doctor_notes" value="">

<div class="modal fade" id="combinedConsultationModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width: 96%;">
        <div class="modal-content">
            <div class="modal-header py-2 bg-light d-flex justify-content-between">
                <h6 class="modal-title fw-bold" id="wardRoundModalTitle"><i class="bi bi-journal-medical text-primary me-2"></i>ADD NEW WARD ROUND NOTES</h6>
                <div class="d-flex align-items-center gap-2">
                    <select id="prescription_template_id" class="form-select form-select-sm border-primary fw-bold" style="width: 250px;" onchange="applyPrescriptionTemplate()">
                        <option value="">-- CHOOSE TEMPLATE --</option>
                        <?php foreach ($prescription_templates as$pt): ?>
                            <option value="<?= (int)$pt['template_id'] ?>" <?= ($selected_template_id == $pt['template_id']) ? 'selected' : '' ?>><?= htmlspecialchars($pt['template_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-sm btn-primary fw-bold" onclick="applyPrescriptionTemplate()"><i class="bi bi-cloud-download"></i></button>
                    <button type="button" class="btn-close ms-3" data-bs-dismiss="modal"></button>
                </div>
            </div>
            
            <div class="modal-body p-0">
                <ul class="nav nav-tabs nav-fill bg-light border-bottom-0" id="consultationTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active fw-bold text-dark rounded-0 border-0" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes-pane" type="button"><i class="bi bi-pencil-square me-1"></i> CLINICAL NOTES & VITALS</button></li>
                    <li class="nav-item"><button class="nav-link fw-bold text-dark rounded-0 border-0" id="meds-tab" data-bs-toggle="tab" data-bs-target="#meds-pane" type="button"><i class="bi bi-capsule me-1"></i> PRESCRIBE MEDICINES</button></li>
                </ul>

                <div class="tab-content p-3" id="consultationTabsContent">
                    <!-- CLINICAL NOTES TAB -->
                    <div class="tab-pane fade show active" id="notes-pane" role="tabpanel">
                        <div id="vitalsSection" class="mb-3">
                            <div class="dd-section-title mb-2">TODAY'S VITALS</div>
                            <div class="row g-2" id="vitalsContainer"></div>
                        </div>

                        <div class="dd-section-title mb-2">CLINICAL NOTES</div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6" id="symptomSection">
                                <div class="dd-investigation-box h-100">
                                    <label class="form-label small fw-bold mb-1">SYMPTOMS / COMPLAINTS</label>
                                    <div class="master-search-row mb-2">
                                        <input type="text" id="symptomSearch" class="form-control form-control-sm" placeholder="SEARCH / SELECT SYMPTOM" autocomplete="off" onfocus="showMasterOptions('symptom')" oninput="showMasterOptions('symptom')" onkeydown="masterSearchKey(event,'symptom')">
                                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="openQuickAdd('symptom')">+ ADD</button>
                                    </div>
                                    <div id="quickAdd-symptom" class="master-inline-add d-none mb-2">
                                        <div class="input-group input-group-sm"><input type="text" class="form-control text-uppercase" id="quickValue-symptom" placeholder="NEW SYMPTOM NAME"><button type="button" class="btn btn-primary" onclick="saveQuickAdd('symptom')">SAVE</button><button type="button" class="btn btn-light" onclick="closeQuickAdd('symptom')">CANCEL</button></div>
                                    </div>
                                    <div id="symptomSelected" class="master-chip-wrap"></div>
                                    <div id="symptomMasterOptions" class="master-option-list"></div>
                                    <textarea id="symptoms" class="d-none"></textarea>
                                </div>
                            </div>
                            <div class="col-md-6" id="diagnosisSection">
                                <div class="dd-investigation-box h-100">
                                    <label class="form-label small fw-bold mb-1">DIAGNOSIS</label>
                                    <div class="master-search-row mb-2">
                                        <input type="text" id="diagnosisSearch" class="form-control form-control-sm" placeholder="SEARCH / SELECT DIAGNOSIS" autocomplete="off" onfocus="showMasterOptions('diagnosis')" oninput="showMasterOptions('diagnosis')" onkeydown="masterSearchKey(event,'diagnosis')">
                                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="openQuickAdd('diagnosis')">+ ADD</button>
                                    </div>
                                    <div id="quickAdd-diagnosis" class="master-inline-add d-none mb-2">
                                        <div class="input-group input-group-sm"><input type="text" class="form-control text-uppercase" id="quickValue-diagnosis" placeholder="NEW DIAGNOSIS NAME"><button type="button" class="btn btn-primary" onclick="saveQuickAdd('diagnosis')">SAVE</button><button type="button" class="btn btn-light" onclick="closeQuickAdd('diagnosis')">CANCEL</button></div>
                                    </div>
                                    <div id="diagnosisSelected" class="master-chip-wrap"></div>
                                    <div id="diagnosisMasterOptions" class="master-option-list"></div>
                                    <textarea id="diagnosis" class="d-none"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mb-3" id="investigationAdviceRow">
                            <div class="col-md-6" id="investigationsSection">
                                <div class="dd-investigation-box h-100">
                                    <label class="form-label small fw-bold mb-1">INVESTIGATIONS</label>
                                    <div class="master-search-row mb-2">
                                        <input type="text" id="investigationSearch" class="form-control form-control-sm" placeholder="SEARCH / SELECT INVESTIGATION" autocomplete="off" onfocus="showMasterOptions('investigation')" oninput="showMasterOptions('investigation')" onkeydown="masterSearchKey(event,'investigation')">
                                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="openQuickAdd('investigation')">+ ADD</button>
                                    </div>
                                    <div id="quickAdd-investigation" class="master-inline-add d-none mb-2">
                                        <div class="input-group input-group-sm"><input type="text" class="form-control text-uppercase" id="quickValue-investigation" placeholder="NEW INVESTIGATION NAME"><button type="button" class="btn btn-primary" onclick="saveQuickAdd('investigation')">SAVE</button><button type="button" class="btn btn-light" onclick="closeQuickAdd('investigation')">CANCEL</button></div>
                                    </div>
                                    <div id="investigationSelected" class="master-chip-wrap"></div>
                                    <div id="investigationMasterOptions" class="master-option-list"></div>
                                    <textarea id="investigations" class="d-none"></textarea>
                                </div>
                            </div>
                            <div class="col-md-6" id="adviceSection">
                                <div class="dd-investigation-box h-100">
                                    <label class="form-label small fw-bold mb-1">ADVICE</label>
                                    <div class="master-search-row mb-2">
                                        <input type="text" id="adviceSearch" class="form-control form-control-sm" placeholder="SEARCH / SELECT ADVICE" autocomplete="off" onfocus="showMasterOptions('advice')" oninput="showMasterOptions('advice')" onkeydown="masterSearchKey(event,'advice')">
                                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="openQuickAdd('advice')">+ ADD</button>
                                    </div>
                                    <div id="quickAdd-advice" class="master-inline-add d-none mb-2">
                                        <div class="input-group input-group-sm"><input type="text" class="form-control text-uppercase" id="quickValue-advice" placeholder="NEW ADVICE NAME"><button type="button" class="btn btn-primary" onclick="saveQuickAdd('advice')">SAVE</button><button type="button" class="btn btn-light" onclick="closeQuickAdd('advice')">CANCEL</button></div>
                                    </div>
                                    <div id="adviceSelected" class="master-chip-wrap"></div>
                                    <div id="adviceMasterOptions" class="master-option-list"></div>
                                    <textarea id="advice" class="d-none"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mb-3" id="progressNotesSection">
                            <div class="col-12">
                                <div class="dd-investigation-box">
                                    <label class="form-label small fw-bold">PROGRESS NOTES (INTERNAL / EXTRA)</label>
                                    <textarea id="doctor_notes" rows="2" class="form-control form-control-sm text-uppercase" placeholder="ENTER WARD PROGRESS NOTES HERE..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- MEDICINES TAB -->
                    <div class="tab-pane fade" id="meds-pane" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="dd-section-title mb-0">MEDICINE LIST</div>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="addMedicine({})"><i class="bi bi-plus-lg"></i> ADD MEDICINE</button>
                            </div>
                        </div>
                        
                        <div class="row g-1 d-none d-lg-flex mb-1 px-1 text-muted text-uppercase" style="font-size: 8px; font-weight: 800;">
                            <div class="col-lg-2">MEDICINE</div><div class="col-lg-1">STRENGTH</div><div class="col-lg-2">GENERIC / SALT NAME</div>
                            <div class="col-lg-2">FREQUENCY</div><div class="col-lg-1">UNIT / FORM</div><div class="col-lg-1">MEAL</div>
                            <div class="col-lg-1">QTY</div><div class="col-lg-1">DURATION</div><div class="col-lg-1 text-center">ACTION</div>
                        </div>

                        <datalist id="medicineMasterList"></datalist>
                        <div id="medicineList" class="mb-2"></div>
                        <div class="text-center text-muted py-3 small" id="emptyMedicineText">NO MEDICINE ADDED.</div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer py-2 border-top bg-light">
                <button type="button" class="btn btn-sm btn-secondary fw-bold" data-bs-dismiss="modal">CANCEL</button>
                <button type="submit" id="saveWardRoundBtn" class="btn btn-sm btn-success fw-bold px-4" onclick="return prepareDoctorSave();"><i class="bi bi-check2-all me-1"></i> FINISH & SAVE ROUND</button>
            </div>
        </div>
    </div>
</div>

                        </form>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
<?php if ($err): ?> Swal.fire({ icon: 'error', title: 'Error', text: <?= json_encode($err) ?> }); <?php endif; ?>
<?php if (isset($_SESSION['swal_success'])): ?>
    Swal.fire({ icon: 'success', title: 'Success', text: '<?= $_SESSION['swal_success'] ?>', timer: 2000, showConfirmButton:false });
    <?php unset($_SESSION['swal_success']); ?>
<?php endif; ?>

// SEARCH LEFT PANEL
document.getElementById('queueSearch')?.addEventListener('keyup', function() {
    let filter = this.value.toLowerCase().trim();
    let items = document.querySelectorAll('.patient-list-item');
    items.forEach(item => {
        let text = (item.dataset.search || '').toLowerCase();
        item.style.display = (!filter || text.includes(filter)) ? '' : 'none';
    });
});

// ==========================================
// FULL JAVASCRIPT LOGIC FOR MODAL & MASTERS
// ==========================================
let allMasters = {};
let selectedData = { symptom: [], diagnosis: [], investigation: [], advice: [] };
// When a prescription template is active, show only its configured clinical defaults.
// null means no template filter has been applied yet.
let activeTemplateOptions = null;
let lastAppliedTemplateId = '';
let medicineList = [];

document.addEventListener('DOMContentLoaded', function () {
    // Load every active master once. Opening the modal must not re-apply a template
    // or clear the doctor's current selections.
    fetchMasters();
    const roundModal = document.getElementById('combinedConsultationModal');
    roundModal?.addEventListener('shown.bs.modal', function () {
        const templateSelect = document.getElementById('prescription_template_id');
        if (templateSelect?.value && lastAppliedTemplateId !== templateSelect.value) {
            applyPrescriptionTemplate();
        }
    });
    document.querySelectorAll('#combinedConsultationModal input[type="text"], #combinedConsultationModal textarea').forEach(field => {
        field.addEventListener('input', function () {
            const start = this.selectionStart; const end = this.selectionEnd;
            this.value = this.value.toUpperCase();
            try { this.setSelectionRange(start, end); } catch(e){}
            if (this.id === 'doctor_notes') syncClinicalSummaries();
        });
    });
});


function fetchMasters() {
    return fetch('?ajax=get_masters')
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                allMasters = data;
                populateDatalist('medicineMasterList', data.medicines, 'medicine_name');
                renderVitalsForm();
                ['symptom','diagnosis','investigation','advice'].forEach(type => { renderChips(type); showMasterOptions(type); });
            }
            return data;
        });
}

function populateDatalist(id, array, key) {
    const dl = document.getElementById(id);
    if (!dl) return;
    dl.innerHTML = '';
    array.forEach(item => {
        let opt = document.createElement('option');
        opt.value = item[key];
        dl.appendChild(opt);
    });
}

function renderVitalsForm() {
    const vc = document.getElementById('vitalsContainer');
    if (!vc || !allMasters.vitals) return;
    vc.innerHTML = '';
    allMasters.vitals.forEach(v => {
        let unitHtml = v.unit ? ` <span class="text-muted">(${v.unit})</span>` : '';
        vc.innerHTML += `
            <div class="col-md-3 col-sm-4 col-6">
                <div class="dd-vital">
                    <label>${v.vital_name}${unitHtml}</label>
                    <input type="text" class="form-control form-control-sm text-uppercase vital-input border-0 bg-light" data-id="${v.id}" data-key="${v.vital_key}" placeholder="${v.placeholder || ''}">
                </div>
            </div>
        `;
    });
}

function masterSearchKey(e, type) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const first = document.querySelector(`#${type}MasterOptions .master-option:not(.selected)`);
        if (first) first.click();
    } else if (e.key === 'Escape') {
        document.getElementById(type + 'MasterOptions')?.classList.remove('show');
    }
}

function getMasterArray(type) {
    return ({
        symptom: allMasters.symptoms || [],
        diagnosis: allMasters.diagnoses || [],
        investigation: allMasters.investigations || [],
        advice: allMasters.advices || []
    })[type] || [];
}
function getMasterNameKey(type) {
    return ({symptom:'symptom_name', diagnosis:'diagnosis_name', investigation:'investigation_name', advice:'advice_name'})[type];
}
function showMasterOptions(type) {
    ['symptom','diagnosis','investigation','advice'].forEach(otherType => {
        if (otherType !== type) document.getElementById(otherType + 'MasterOptions')?.classList.remove('show');
    });
    const input = document.getElementById(type + 'Search');
    const list = document.getElementById(type + 'MasterOptions');
    if (!input || !list) return;
    const query = input.value.trim().toLowerCase();
    const key = getMasterNameKey(type);
    const masterRows = getMasterArray(type);
    let sourceNames;
    if (activeTemplateOptions && Array.isArray(activeTemplateOptions[type])) {
        // Restrict the dropdown to values configured in the selected template.
        sourceNames = activeTemplateOptions[type];
    } else {
        sourceNames = masterRows.map(item => String(item[key] || '').trim()).filter(Boolean);
    }
    const matches = sourceNames
        .filter(name => name.toLowerCase().includes(query))
        .map(name => ({ name, master: masterRows.find(item => String(item[key] || '').trim().toLowerCase() === name.toLowerCase()) }));
    list.innerHTML = '';
    if (!matches.length) {
        const emptyMessage = activeTemplateOptions
            ? 'NO ITEMS CONFIGURED IN THIS TEMPLATE'
            : 'NO MATCHING OPTIONS';
        list.innerHTML = `<div class="master-option"><span>${emptyMessage}</span></div>`;
    } else {
        matches.forEach(entry => {
            const value = String(entry.name || '').trim().toUpperCase();
            const isSelected = selectedData[type].some(v => v.toLowerCase() === value.toLowerCase());
            const option = document.createElement('div');
            option.className = 'master-option' + (isSelected ? ' selected' : '');
            const name = document.createElement('span');
            name.textContent = value;
            const status = document.createElement('small');
            status.textContent = isSelected ? 'SELECTED' : '＋ SELECT';
            option.append(name, status);
            option.addEventListener('mousedown', e => e.preventDefault());
            option.addEventListener('click', () => {
                if (!isSelected) {
                    selectedData[type].push(value);
                    renderChips(type);
                }
                // Keep the same option list open so multiple entries can be selected quickly.
                input.value = '';
                showMasterOptions(type);
                input.focus();
            });
            list.appendChild(option);
        });
    }
    list.classList.add('show');
}

function selectMasterFromSearch(type) {
    const input = document.getElementById(type + 'Search');
    if (!input) return;
    const raw = input.value.trim();
    if (!raw) return;
    const key = getMasterNameKey(type);
    const match = getMasterArray(type).find(item => String(item[key] || '').trim().toLowerCase() === raw.toLowerCase());
    if (match) {
        const value = String(match[key]).trim().toUpperCase();
        if (!selectedData[type].some(item => item.toLowerCase() === value.toLowerCase())) {
            selectedData[type].push(value);
            renderChips(type);
        }
    }
    input.value = '';
    showMasterOptions(type);
    input.focus();
}

function renderChips(type) {
    const wrap = document.getElementById(type + 'Selected');

    // Explicit mapping avoids the invalid "diagnosiss" ID.
    const hiddenId = {
        symptom: 'symptoms',
        diagnosis: 'diagnosis',
        investigation: 'investigations',
        advice: 'advice'
    }[type];

    const hiddenTextarea = hiddenId ? document.getElementById(hiddenId) : null;
    if (!wrap || !hiddenTextarea || !Array.isArray(selectedData[type])) return;

    wrap.innerHTML = '';
    selectedData[type].forEach((item, index) => {
        const chip = document.createElement('div');
        chip.className = 'master-chip';
        chip.append(document.createTextNode(item + ' '));

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.innerHTML = '&times;';
        remove.setAttribute('aria-label', 'Remove ' + item);
        remove.addEventListener('click', () => removeChip(type, index));

        chip.appendChild(remove);
        wrap.appendChild(chip);
    });

    hiddenTextarea.value = selectedData[type].join(', ');
    syncClinicalSummaries();
}

function syncClinicalSummaries() {
    // Selected chips are already written into the hidden textareas by renderChips().
    return true;
}
function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
}

function removeChip(type, index) {
    selectedData[type].splice(index, 1);
    renderChips(type);
}

function openQuickAdd(type) {
    const panel = document.getElementById('quickAdd-' + type);
    const input = document.getElementById('quickValue-' + type);
    if (!panel || !input) return;
    panel.classList.remove('d-none');
    input.value = '';
    input.focus();
}

function closeQuickAdd(type) {
    document.getElementById('quickAdd-' + type)?.classList.add('d-none');
    const input = document.getElementById('quickValue-' + type);
    if (input) input.value = '';
}

async function saveQuickAdd(type) {
    const input = document.getElementById('quickValue-' + type);
    const val = String(input?.value || '').trim().toUpperCase();
    if (!val) { Swal.fire('Required', 'Please enter a name.', 'warning'); input?.focus(); return; }
    const key = getMasterNameKey(type);
    const existing = getMasterArray(type).find(item => String(item[key] || '').trim().toLowerCase() === val.toLowerCase());
    if (existing) {
        const name = String(existing[key]).trim().toUpperCase();
        if (!selectedData[type].some(item => item.toLowerCase() === name.toLowerCase())) selectedData[type].push(name);
        renderChips(type);
        showMasterOptions(type);
        closeQuickAdd(type);
        return;
    }
    const fd = new FormData();
    fd.append('ajax', 'add_master'); fd.append('master_type', type); fd.append('value', val);
    try {
        const res = await fetch('?ajax=add_master', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.ok) { Swal.fire('Error', res.message || 'Unable to add master.', 'error'); return; }
        const chosen = String(res.name || val).trim().toUpperCase();
        await fetchMasters();
        if (!selectedData[type].some(item => item.toLowerCase() === chosen.toLowerCase())) selectedData[type].push(chosen);
        renderChips(type);
        showMasterOptions(type);
        closeQuickAdd(type);
        Swal.fire({icon:'success', title: res.created === false ? 'Already exists' : 'Added', text: chosen + ' selected.', timer:1300, showConfirmButton:false});
    } catch (e) { Swal.fire('Error', 'Unable to save master. Please try again.', 'error'); }
}

function getSelectOptions(array, keyId, keyName, selectedVal = '') {
    let html = '<option value="">-SELECT-</option>';
    if(array) {
        array.forEach(item => {
            let sel = (selectedVal !== '' && (item[keyName] === selectedVal || item[keyId] == selectedVal)) ? 'selected' : '';
            html += `<option value="${item[keyName]}" ${sel}>${item[keyName]}</option>`;
        });
    } return html;
}

function addMedicine(med = {}) {
    medicineList.push({
        id: Date.now() + Math.random(),
        name: med.name || med.medicine_name || '',
        strength: med.strength || '',
        generic: med.generic || med.generic_name || '',
        freq: med.freq || med.frequency_name || '',
        unit: med.unit || med.unit_name || '',
        meal: med.meal || med.meal_name || '',
        qty: med.qty || med.quantity || '',
        duration: med.duration || med.duration_name || ''
    });
    renderMedicineTable();
}

function renderMedicineTable() {
    const list = document.getElementById('medicineList');
    const emptyText = document.getElementById('emptyMedicineText');
    list.innerHTML = '';
    
    if (medicineList.length === 0) { emptyText.classList.remove('d-none'); return; }
    emptyText.classList.add('d-none');

    medicineList.forEach((med, index) => {
        let row = document.createElement('div');
        row.className = 'dd-medicine-row';
        row.innerHTML = `
            <div class="row g-1">
                <div class="col-lg-2 col-6">
                    <label class="form-label d-lg-none">MEDICINE</label>
                    <input type="text" class="form-control medicine-select text-uppercase" value="${med.name}" list="medicineMasterList" placeholder="SEARCH MED" onchange="autoFillMed(this, ${index})">
                </div>
                <div class="col-lg-1 col-6">
                    <label class="form-label d-lg-none">STRENGTH</label>
                    <input type="text" class="form-control dd-readonly text-uppercase" value="${med.strength}" oninput="updateMed(${index}, 'strength', this.value)">
                </div>
                <div class="col-lg-2 col-12">
                    <label class="form-label d-lg-none">GENERIC / SALT</label>
                    <input type="text" class="form-control dd-readonly text-uppercase" value="${med.generic}" oninput="updateMed(${index}, 'generic', this.value)">
                </div>
                <div class="col-lg-2 col-6">
                    <label class="form-label d-lg-none">FREQUENCY</label>
                    <select class="form-select text-uppercase" onchange="updateMed(${index}, 'freq', this.value)">
                        ${getSelectOptions(allMasters.frequencies, 'id', 'frequency_name', med.freq)}
                    </select>
                </div>
                <div class="col-lg-1 col-6">
                    <label class="form-label d-lg-none">UNIT / FORM</label>
                    <select class="form-select text-uppercase" onchange="updateMed(${index}, 'unit', this.value)">
                        ${getSelectOptions(allMasters.units, 'id', 'unit_name', med.unit)}
                    </select>
                </div>
                <div class="col-lg-1 col-4">
                    <label class="form-label d-lg-none">MEAL</label>
                    <select class="form-select text-uppercase" onchange="updateMed(${index}, 'meal', this.value)">
                        ${getSelectOptions(allMasters.meals, 'id', 'meal_name', med.meal)}
                    </select>
                </div>
                <div class="col-lg-1 col-4">
                    <label class="form-label d-lg-none">QTY</label>
                    <input type="text" class="form-control text-uppercase" value="${med.qty}" oninput="updateMed(${index}, 'qty', this.value)">
                </div>
                <div class="col-lg-1 col-4">
                    <label class="form-label d-lg-none">DURATION</label>
                    <select class="form-select text-uppercase" onchange="updateMed(${index}, 'duration', this.value)">
                        ${getSelectOptions(allMasters.durations, 'id', 'duration_name', med.duration)}
                    </select>
                </div>
                <div class="col-lg-1 col-12 d-flex align-items-end mt-2 mt-lg-0">
                    <button type="button" class="btn btn-outline-danger w-100" onclick="removeMedicine(${index})"><i class="bi bi-trash"></i></button>
                </div>
            </div>
        `;
        list.appendChild(row);
    });
    syncPrescription();
}

function updateMed(index, key, val) {
    medicineList[index][key] = val.toUpperCase();
    syncPrescription();
}

function removeMedicine(index) {
    medicineList.splice(index, 1);
    renderMedicineTable();
}

function autoFillMed(inputEl, index) {
    let medName = inputEl.value.trim().toUpperCase();
    updateMed(index, 'name', medName);
    if(allMasters.medicines) {
        let found = allMasters.medicines.find(m => m.medicine_name.toUpperCase() === medName);
        if(found) {
            medicineList[index].strength = found.strength || '';
            medicineList[index].generic = found.generic_name || '';
            medicineList[index].freq = found.frequency_name || '';
            medicineList[index].unit = found.unit_name || '';
            medicineList[index].meal = found.meal_name || '';
            medicineList[index].qty = found.default_qty || '';
            medicineList[index].duration = found.duration_name || '';
            renderMedicineTable();
        }
    }
}

function syncPrescription() {
    document.getElementById('prescription_items_json').value = JSON.stringify(medicineList);
}

const departmentDefaultTemplateId = <?= (int)$selected_template_id ?>;

function selectAndApplyDepartmentTemplate() {
    const select = document.getElementById('prescription_template_id');
    if (!select || !departmentDefaultTemplateId) return Promise.resolve(false);

    const optionExists = Array.from(select.options).some(option =>
        option.value === String(departmentDefaultTemplateId)
    );
    if (!optionExists) return Promise.resolve(false);

    select.value = String(departmentDefaultTemplateId);
    return applyPrescriptionTemplate({ silent: true });
}

function parseTemplateClinicalValues(raw) {
    if (raw === null || raw === undefined) return [];
    let values = [];
    const text = String(raw).trim();
    if (!text) return [];
    try {
        const decoded = JSON.parse(text);
        if (Array.isArray(decoded)) values = decoded;
        else if (decoded && typeof decoded === 'object') values = Object.values(decoded);
        else values = [decoded];
    } catch (e) {
        values = text.split(/[\r\n,;|]+/);
    }
    return values.map(v => String(v ?? '').trim().toUpperCase()).filter(Boolean);
}

function applyTemplateClinicalDefaults(template) {
    const fields = {
        symptom: template.default_symptoms,
        diagnosis: template.default_diagnosis,
        investigation: template.default_investigations,
        advice: template.default_advice
    };
    activeTemplateOptions = {};
    Object.entries(fields).forEach(([type, raw]) => {
        const defaults = parseTemplateClinicalValues(raw);
        activeTemplateOptions[type] = [...new Set(defaults.map(v => v.toUpperCase()))];
        defaults.forEach(value => {
            if (!selectedData[type].some(existing => existing.toLowerCase() === value.toLowerCase())) {
                selectedData[type].push(value);
            }
        });
        renderChips(type);
        // Refresh an already-open list so non-template master items disappear immediately.
        showMasterOptions(type);
    });
}

// 🔴 THE APPEND MULTIPLE TEMPLATES FUNCTION
function applyPrescriptionTemplate(options = {}) {
    const tId = document.getElementById('prescription_template_id')?.value || '';
    if (!tId) {
        if (!options.silent) Swal.fire('Template required', 'No prescription template is mapped to this patient\'s department.', 'warning');
        return Promise.resolve(false);
    }

    return fetch('?ajax=get_prescription_template&template_id=' + encodeURIComponent(tId))
        .then(res => res.json())
        .then(data => {
            if (!data.ok || !data.template) {
                Swal.fire('Error', data.message || 'Template not found.', 'error');
                return;
            }

            const t = data.template;
            lastAppliedTemplateId = String(tId);

            // Load the selected template's clinical defaults into their visible chips.
            // Existing patient-specific selections are preserved and not duplicated.
            applyTemplateClinicalDefaults(t);

            // IMPORTANT:
            // A template is only a preset. It must never filter the active master lists,
            // hide clinical sections, or erase patient-specific selections already entered.
            ['symptomSection', 'diagnosisSection', 'investigationsSection',
             'adviceSection', 'investigationAdviceRow', 'progressNotesSection'].forEach(id => {
                document.getElementById(id)?.classList.remove('d-none');
            });

            // Keep every active vital visible, regardless of which vitals were ticked
            // in the selected template.
            document.getElementById('vitalsSection')?.classList.toggle(
                'd-none', !(allMasters.vitals && allMasters.vitals.length)
            );
            document.querySelectorAll('#vitalsContainer > div').forEach(el => el.classList.remove('d-none'));

            // Keep the medicines tab available even when the template has no medicines.
            document.getElementById('meds-tab')?.closest('li')?.classList.remove('d-none');
            document.getElementById('meds-pane')?.classList.remove('d-none');

            // Append template medicines without deleting medicines already entered.
            // Clinical dropdowns are restricted to the selected template's configured defaults.
            const existingMedicineKeys = new Set(medicineList.map(item =>
                String(item.medicine_id || item.medicine_name || item.name || '').trim().toLowerCase()
            ));
            (Array.isArray(t.items) ? t.items : []).forEach(item => {
                const key = String(item.medicine_id || item.master_medicine_name || item.medicine_name || '').trim().toLowerCase();
                if (!key || !existingMedicineKeys.has(key)) {
                    addMedicine(item);
                    if (key) existingMedicineKeys.add(key);
                }
            });

            // Rebuild datalists from the complete master response after applying a template.
            populateDatalist('medicineMasterList', allMasters.medicines || [], 'medicine_name');

            syncPrescription();
            if (!options.silent) {
                Swal.fire({
                    icon: 'success',
                    title: 'Template applied',
                    text: 'Department template defaults loaded.',
                    timer: 1400,
                    showConfirmButton: false
                });
            }
            return true;
        })
        .catch(() => {
            if (!options.silent) Swal.fire('Error', 'Unable to load template. Please try again.', 'error');
            return false;
        });
}

function prepareNewWardRound() {
    document.getElementById('round_id').value = '0';
    document.getElementById('wardRoundModalTitle').innerHTML = '<i class="bi bi-journal-medical text-primary me-2"></i>ADD NEW WARD ROUND NOTES';
    document.getElementById('saveWardRoundBtn').innerHTML = '<i class="bi bi-check2-all me-1"></i> FINISH & SAVE ROUND';
    selectedData = { symptom: [], diagnosis: [], investigation: [], advice: [] };
    medicineList = [];
    ['symptom','diagnosis','investigation','advice'].forEach(type => {
        renderChips(type);
        const input = document.getElementById(type + 'Search'); if (input) input.value = '';
        document.getElementById(type + 'MasterOptions')?.classList.remove('show');
    });
    document.getElementById('doctor_notes').value = '';
    document.querySelectorAll('.vital-input').forEach(input => input.value = '');
    renderMedicineTable();
    document.getElementById('prescription_template_id').value = '';
    activeTemplateOptions = null;
    lastAppliedTemplateId = '';
    fetchMasters().then(() => selectAndApplyDepartmentTemplate());
}

function splitSavedClinicalValues(raw) {
    return String(raw || '').split(/[\r\n,;|]+/).map(v => v.trim().toUpperCase()).filter(Boolean);
}

function editWardRound(button) {
    let round;
    try { round = JSON.parse(button.getAttribute('data-round') || '{}'); }
    catch (e) { Swal.fire('Error', 'Unable to read this ward round.', 'error'); return; }
    if (!round.id) { Swal.fire('Error', 'Ward round ID is missing.', 'error'); return; }

    // Wait for active masters so vitals and medicine controls can be rebuilt safely.
    fetchMasters().then(() => {
        document.getElementById('round_id').value = String(round.id);
        document.getElementById('wardRoundModalTitle').innerHTML = '<i class="bi bi-pencil-square text-primary me-2"></i>EDIT WARD ROUND NOTES';
        document.getElementById('saveWardRoundBtn').innerHTML = '<i class="bi bi-check2-all me-1"></i> UPDATE WARD ROUND';

        selectedData = {
            symptom: splitSavedClinicalValues(round.symptoms),
            diagnosis: splitSavedClinicalValues(round.diagnosis),
            investigation: splitSavedClinicalValues(round.investigations),
            advice: splitSavedClinicalValues(round.advice)
        };
        ['symptom','diagnosis','investigation','advice'].forEach(type => {
            renderChips(type);
            const search = document.getElementById(type + 'Search');
            if (search) search.value = '';
            showMasterOptions(type);
            document.getElementById(type + 'MasterOptions')?.classList.remove('show');
        });

        let vitals = {};
        try { vitals = JSON.parse(round.vitals_json || '{}') || {}; } catch (e) {}
        renderVitalsForm();
        document.querySelectorAll('.vital-input').forEach(input => {
            const key = input.getAttribute('data-key');
            input.value = vitals[key] ?? '';
        });

        let rx = [];
        try {
            const parsed = JSON.parse(round.prescription_json || '{}');
            rx = Array.isArray(parsed.items) ? parsed.items : [];
        } catch (e) {}
        medicineList = [];
        rx.forEach(item => addMedicine(item));
        renderMedicineTable();

        document.getElementById('doctor_notes').value = round.doctor_notes || '';
        document.getElementById('prescription_items_json').value = JSON.stringify(medicineList);
        document.getElementById('vitals_json').value = JSON.stringify(vitals);
        // Re-select the template mapped to this patient's department and apply its defaults automatically.
        activeTemplateOptions = null;
        lastAppliedTemplateId = '';

        const modalEl = document.getElementById('combinedConsultationModal');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        selectAndApplyDepartmentTemplate();
    }).catch(() => Swal.fire('Error', 'Unable to load master data for editing.', 'error'));
}

function copyPastNotes(jsonObj) {
    if(jsonObj && jsonObj.items && jsonObj.items.length > 0) {
        jsonObj.items.forEach(item => addMedicine(item));
        syncPrescription();
        var modal = new bootstrap.Modal(document.getElementById('combinedConsultationModal'));
        modal.show();
        var tabEl = document.querySelector('#meds-tab');
        bootstrap.Tab.getOrCreateInstance(tabEl).show();
        Swal.fire({ icon: 'success', title: 'Copied!', text: 'PAST MEDICINES COPIED.', timer:1500, showConfirmButton:false });
    } else {
        Swal.fire({ icon: 'warning', title: 'Oops', text: 'No medicines found in this round.' });
    }
}

function prepareDoctorSave() {
    syncClinicalSummaries();
    let vitals = {};
    document.querySelectorAll('.vital-input').forEach(inp => {
        if(inp.value.trim() !== '') vitals[inp.getAttribute('data-key')] = inp.value.trim();
    });
    document.getElementById('vitals_json').value = JSON.stringify(vitals);
    document.getElementById('form_symptoms').value = document.getElementById('symptoms').value;
    document.getElementById('form_diagnosis').value = document.getElementById('diagnosis').value;
    document.getElementById('form_investigations').value = document.getElementById('investigations').value;
    document.getElementById('form_advice').value = document.getElementById('advice').value;
    document.getElementById('form_doctor_notes').value = document.getElementById('doctor_notes').value;
    return true;
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
