<?php
// admin/opd_doctor.php
$page_title = "Doctor Desk";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$org_id    = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$today     = date('Y-m-d');
$err       = '';
$success   = '';
$show_print_modal = false;

/* ==========================================================
   DOCTOR DESK / OPD VISIT FIELDS
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
    ensureVisitColumn($tenant_pdo, 'investigations', 'TEXT NULL', 'diagnosis');
    ensureVisitColumn($tenant_pdo, 'advice', 'TEXT NULL', 'investigations');
    ensureVisitColumn($tenant_pdo, 'custom_fields_json', 'LONGTEXT NULL', 'advice');
    ensureVisitColumn($tenant_pdo, 'prescription', 'TEXT NULL', 'custom_fields_json');
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
    $doctors = $d->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$doctor_id = (int)($_GET['doctor_id'] ?? ($_POST['doctor_id'] ?? ($_SESSION['doctor_id'] ?? 0)));
$selected_visit_id = (int)($_GET['visit_id'] ?? ($_POST['visit_id'] ?? 0));

/* ==========================================================
   PRESCRIPTION / TEMPLATE MASTERS
   ========================================================== */
$prescription_templates = [];
$rx_medicines = [];
$rx_durations = [];
$rx_symptoms = [];
$rx_diagnoses = [];
$rx_investigations = [];
$rx_advices = [];
$rx_vitals = [];
$rx_frequencies = [];
$rx_units = [];
$rx_meals = [];

try {
    $st = $tenant_pdo->prepare("
        SELECT
            template_id, template_name, department_id, specialization_id, doctor_id,
            default_symptoms, default_diagnosis, default_investigations, default_advice,
            follow_up_days, default_vitals, custom_fields_schema
        FROM prescription_template_master
        WHERE org_id = ? AND center_id = ? AND status = 1
        ORDER BY template_name
    ");
    $st->execute([$org_id, $center_id]);
    $prescription_templates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("
        SELECT
            mm.id,
            mm.medicine_name,
            mm.generic_name,
            mm.strength,
            mm.unit_id,
            mm.meal_id,
            mm.frequency_id,
            mm.default_qty,
            mm.default_duration_id,
            mm.dosage_form,
            mu.unit_name,
            meal.meal_name,
            fm.frequency_name,
            fm.frequency_code,
            md.duration_name
        FROM master_medicines mm
        LEFT JOIN master_units mu ON mu.id = mm.unit_id
        LEFT JOIN master_meals meal ON meal.id = mm.meal_id
        LEFT JOIN frequency_master fm ON fm.id = mm.frequency_id
        LEFT JOIN master_durations md ON md.id = mm.default_duration_id
        WHERE mm.org_id = ? AND mm.center_id = ? AND mm.status = 1
        ORDER BY mm.medicine_name
    ");
    $st->execute([$org_id, $center_id]);
    $rx_medicines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, duration_name, duration_value, duration_unit FROM master_durations WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY id");
    $st->execute([$org_id, $center_id]);
    $rx_durations = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, symptom_name FROM master_symptoms WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY symptom_name");
    $st->execute([$org_id, $center_id]);
    $rx_symptoms = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, diagnosis_name FROM master_diagnoses WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY diagnosis_name");
    $st->execute([$org_id, $center_id]);
    $rx_diagnoses = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, investigation_name, investigation_type FROM master_investigations WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY investigation_name");
    $st->execute([$org_id, $center_id]);
    $rx_investigations = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, advice_name FROM master_advices WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY advice_name");
    $st->execute([$org_id, $center_id]);
    $rx_advices = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, vital_name, vital_key, unit, placeholder, sort_order FROM master_vitals WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY sort_order ASC, vital_name ASC");
    $st->execute([$org_id, $center_id]);
    $rx_vitals = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, frequency_name FROM frequency_master WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY frequency_name");
    $st->execute([$org_id, $center_id]);
    $rx_frequencies = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, unit_name FROM master_units WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY unit_name");
    $st->execute([$org_id, $center_id]);
    $rx_units = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, meal_name FROM master_meals WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY meal_name");
    $st->execute([$org_id, $center_id]);
    $rx_meals = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* ==========================================================
   AJAX: ADD MASTER VALUE DIRECTLY FROM DOCTOR DESK
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'add_master') {
    header('Content-Type: application/json; charset=utf-8');

    $master_type = trim((string)($_POST['master_type'] ?? ''));
    $value       = strtoupper(trim((string)($_POST['value'] ?? '')));

    if ($master_type === 'medicine_full') {
        $name = trim(strtoupper($_POST['name'] ?? ''));
        $strength = trim($_POST['strength'] ?? '');
        $generic = trim($_POST['generic'] ?? '');
        $frequency_id = !empty($_POST['frequency_id']) ? (int)$_POST['frequency_id'] : null;
        $unit_id = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
        $meal_id = !empty($_POST['meal_id']) ? (int)$_POST['meal_id'] : null;
        $duration_id = !empty($_POST['duration_id']) ? (int)$_POST['duration_id'] : null;
        $qty = trim($_POST['qty'] ?? '');
        $manufacturer = trim($_POST['manufacturer'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $status = (isset($_POST['status']) && $_POST['status'] === '1') ? 1 : 0;

        if ($name === '') {
            echo json_encode(['ok' => false, 'message' => 'Medicine Name is required.']);
            exit;
        }

        try {
            $st = $tenant_pdo->prepare("
                INSERT INTO master_medicines 
                (org_id, center_id, medicine_name, generic_name, strength, frequency_id, unit_id, meal_id, default_qty, default_duration_id, manufacturer, category, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st->execute([$org_id, $center_id, $name, $generic, $strength, $frequency_id, $unit_id, $meal_id, $qty, $duration_id, $manufacturer, $category, $status, $user_id]);
            echo json_encode(['ok' => true, 'message' => 'Added successfully!']);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if (in_array($master_type, ['frequency', 'unit', 'meal', 'duration', 'vital'])) {
        try {
            if ($value === '') throw new Exception('Name cannot be empty.');
            if ($master_type === 'frequency') {
                $st = $tenant_pdo->prepare("INSERT INTO frequency_master (org_id, center_id, frequency_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $value, $user_id]);
            } elseif ($master_type === 'unit') {
                $st = $tenant_pdo->prepare("INSERT INTO master_units (org_id, center_id, unit_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $value, $user_id]);
            } elseif ($master_type === 'meal') {
                $st = $tenant_pdo->prepare("INSERT INTO master_meals (org_id, center_id, meal_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $value, $user_id]);
            } elseif ($master_type === 'duration') {
                $st = $tenant_pdo->prepare("INSERT INTO master_durations (org_id, center_id, duration_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $value, $user_id]);
            } elseif ($master_type === 'vital') {
                $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $value));
                $key = trim($key, '_');
                $st = $tenant_pdo->prepare("INSERT INTO master_vitals (org_id, center_id, vital_name, vital_key, status, created_by) VALUES (?, ?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $value, $key, $user_id]);
            }
            echo json_encode(['ok' => true, 'message' => 'Added successfully!']);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    $allowed = [
        'symptom'       => 'master_symptoms',
        'diagnosis'     => 'master_diagnoses',
        'investigation' => 'master_investigations',
        'advice'        => 'master_advices',
        'medicine'      => 'master_medicines',
    ];

    if (!isset($allowed[$master_type]) || $value === '') {
        echo json_encode(['ok' => false, 'message' => 'MASTER VALUE IS REQUIRED.']);
        exit;
    }

    try {
        $table = $allowed[$master_type];

        if (in_array($master_type, ['symptom', 'diagnosis', 'investigation', 'advice'])) {
            $col_name = $master_type . '_name';
            $find = $tenant_pdo->prepare("SELECT id, {$col_name} FROM {$table} WHERE org_id = ? AND center_id = ? AND LOWER({$col_name}) = LOWER(?) LIMIT 1");
            $find->execute([$org_id, $center_id, $value]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                echo json_encode(['ok' => true, 'created' => false, 'id' => (int)$existing['id'], 'name' => $existing[$col_name]]);
                exit;
            }

            $ins = $tenant_pdo->prepare("INSERT INTO {$table} (org_id, center_id, {$col_name}, status, created_by) VALUES (?, ?, ?, 1, ?)");
            $ins->execute([$org_id, $center_id, $value, $user_id ?: null]);
            echo json_encode(['ok' => true, 'created' => true, 'id' => (int)$tenant_pdo->lastInsertId(), 'name' => $value]);
            exit;
        }

        // MEDICINE MASTER (LEGACY BACKUP)
        $strength    = strtoupper(trim((string)($_POST['strength'] ?? '')));
        $find = $tenant_pdo->prepare("SELECT id, medicine_name, strength, unit_id, meal_id, default_qty, default_duration_id FROM {$table} WHERE org_id = ? AND center_id = ? AND LOWER(medicine_name) = LOWER(?) LIMIT 1");
        $find->execute([$org_id, $center_id, $value]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            echo json_encode(['ok' => true, 'created' => false, 'medicine' => $existing]);
            exit;
        }

        $ins = $tenant_pdo->prepare("INSERT INTO {$table} (org_id, center_id, medicine_name, strength, status, created_by) VALUES (?, ?, ?, ?, 1, ?)");
        $ins->execute([$org_id, $center_id, $value, $strength !== '' ? $strength : null, $user_id ?: null]);

        echo json_encode([
            'ok' => true, 'created' => true,
            'medicine' => [
                'id' => (int)$tenant_pdo->lastInsertId(), 'medicine_name' => $value, 'strength' => $strength,
                'unit_name' => '', 'meal_name' => '', 'default_qty' => '', 'duration_name' => ''
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ==========================================================
   AJAX: GET TEMPLATE
   ========================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_prescription_template') {
    header('Content-Type: application/json; charset=utf-8');
    $template_id = (int)($_GET['template_id'] ?? 0);

    try {
        $st = $tenant_pdo->prepare("
            SELECT template_id, template_name, default_symptoms, default_diagnosis, default_investigations, default_advice, follow_up_days, default_vitals, custom_fields_schema
            FROM prescription_template_master
            WHERE template_id = ? AND org_id = ? AND center_id = ? AND status = 1 LIMIT 1
        ");
        $st->execute([$template_id, $org_id, $center_id]);
        $template = $st->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            echo json_encode(['ok' => false, 'message' => 'PRESCRIPTION TEMPLATE NOT FOUND.']);
            exit;
        }

        $st = $tenant_pdo->prepare("
            SELECT
                pti.item_id,
                pti.medicine_name,
                pti.dose,
                pti.duration,
                pti.quantity,
                mm.id AS medicine_id,
                mm.medicine_name AS master_medicine_name,
                mm.generic_name,
                mm.strength,
                mm.frequency_id,
                mm.default_qty,
                mm.dosage_form,
                mu.unit_name,
                meal.meal_name,
                fm.frequency_name,
                fm.frequency_code,
                md.duration_name
            FROM prescription_template_items pti
            LEFT JOIN master_medicines mm
                ON mm.medicine_name = pti.medicine_name
               AND mm.org_id = ?
               AND mm.center_id = ?
               AND mm.status = 1
            LEFT JOIN master_units mu ON mu.id = mm.unit_id
            LEFT JOIN master_meals meal ON meal.id = mm.meal_id
            LEFT JOIN frequency_master fm ON fm.id = mm.frequency_id
            LEFT JOIN master_durations md ON md.id = mm.default_duration_id
            WHERE pti.template_id = ?
            ORDER BY pti.sort_order, pti.item_id
        ");
        $st->execute([$org_id, $center_id, $template_id]);
        $template['items'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode(['ok' => true, 'template' => $template], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ==========================================================
   AJAX: REFRESH MASTERS
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

            if (!$visit_row) throw new Exception('PATIENT VISIT NOT FOUND.');

            $save_doctor_id = $doctor_id > 0 ? $doctor_id : (int)$visit_row['doctor_id'];
            if ($save_doctor_id <= 0) throw new Exception('PLEASE SELECT A DOCTOR.');

            if ($action === 'start') {
                $tenant_pdo->prepare("UPDATE opd_visits SET doctor_id = ?, status = 'Inside Cabin', consultation_started_at = COALESCE(consultation_started_at, NOW()) WHERE visit_id = ? AND org_id = ? AND center_id = ?")
                    ->execute([$save_doctor_id, $visit_id, $org_id, $center_id]);
                try {
                    $tenant_pdo->prepare("UPDATE opd_queue_tracking SET status = 'Inside Cabin', in_time = COALESCE(in_time, NOW()), waiting_since = COALESCE(waiting_since, NOW()) WHERE visit_id = ? AND org_id = ? AND center_id = ?")
                        ->execute([$visit_id, $org_id, $center_id]);
                } catch (Exception $e) {}
                $success = 'CONSULTATION STARTED.';
            } else {
                $symptoms          = trim($_POST['symptoms'] ?? '');
                $diagnosis         = trim($_POST['diagnosis'] ?? '');
                $investigations    = trim($_POST['investigations'] ?? '');
                $advice            = trim($_POST['advice'] ?? '');
                $doctor_notes      = trim($_POST['doctor_notes'] ?? '');
                
                $custom_fields_raw = trim((string)($_POST['custom_fields_values_json'] ?? '{}'));
                $custom_fields_json = $custom_fields_raw !== '' ? $custom_fields_raw : '{}';
                
                $follow_up_raw     = trim($_POST['follow_up_date'] ?? '');
                $follow_up         = null;
                
                if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $follow_up_raw)) {
                    $follow_up = DateTime::createFromFormat('d-m-Y', $follow_up_raw)->format('Y-m-d');
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $follow_up_raw)) {
                    $follow_up = $follow_up_raw; 
                }

                $vitals_raw = trim((string)($_POST['vitals_json'] ?? '{}'));
                $vitals_decoded = json_decode($vitals_raw, true);
                $vitals = is_array($vitals_decoded) ? $vitals_decoded : [];
                $vitals_json = json_encode($vitals, JSON_UNESCAPED_UNICODE);

                $rx_items = [];
                $rx_json_raw = trim($_POST['prescription_items_json'] ?? '');

                if ($rx_json_raw !== '') {
                    $decoded_rx = json_decode($rx_json_raw, true);

                    if (is_array($decoded_rx)) {
                        foreach ($decoded_rx as $rx) {
                            $medicine_id = (int)($rx['medicine_id'] ?? 0);
                            if ($medicine_id <= 0) continue;

                            $medStmt = $tenant_pdo->prepare("
                                SELECT
                                    mm.id AS medicine_id,
                                    mm.medicine_name,
                                    mm.generic_name,
                                    mm.strength,
                                    mm.dosage_form,
                                    mm.default_qty,
                                    mu.unit_name,
                                    meal.meal_name,
                                    fm.frequency_name,
                                    fm.frequency_code,
                                    md.duration_name
                                FROM master_medicines mm
                                LEFT JOIN master_units mu ON mu.id = mm.unit_id
                                LEFT JOIN master_meals meal ON meal.id = mm.meal_id
                                LEFT JOIN frequency_master fm ON fm.id = mm.frequency_id
                                LEFT JOIN master_durations md ON md.id = mm.default_duration_id
                                WHERE mm.id = ?
                                  AND mm.org_id = ?
                                  AND mm.center_id = ?
                                  AND mm.status = 1
                                LIMIT 1
                            ");
                            $medStmt->execute([$medicine_id, $org_id, $center_id]);
                            $masterMed = $medStmt->fetch(PDO::FETCH_ASSOC);

                            if (!$masterMed) continue;

                            $getRxValue = static function(array $rx, string $key, $fallback = '') {
                                if (array_key_exists($key, $rx)) {
                                    return trim((string)($rx[$key] ?? ''));
                                }
                                return trim((string)($fallback ?? ''));
                            };

                            $rx_items[] = [
                                'medicine_id'   => (int)$masterMed['medicine_id'],
                                'medicine_name' => trim((string)$masterMed['medicine_name']),
                                'generic_name'  => $getRxValue($rx, 'generic_name', $masterMed['generic_name'] ?? ''),
                                'strength'      => $getRxValue($rx, 'strength', $masterMed['strength'] ?? ''),
                                'dosage_form'   => $getRxValue($rx, 'dosage_form', $masterMed['dosage_form'] ?? ''),
                                'unit'          => $getRxValue($rx, 'unit', $masterMed['unit_name'] ?? ''),
                                'frequency'     => $getRxValue($rx, 'frequency', $masterMed['frequency_name'] ?? ''),
                                'meal'          => $getRxValue($rx, 'meal', $masterMed['meal_name'] ?? ''),
                                'quantity'      => $getRxValue($rx, 'quantity', $masterMed['default_qty'] ?? ''),
                                'duration'      => $getRxValue($rx, 'duration', $masterMed['duration_name'] ?? '')
                            ];
                        }
                    }
                }

                $prescription_json = json_encode(['items' => $rx_items], JSON_UNESCAPED_UNICODE);

                $prescription_lines = [];
                foreach ($rx_items as $rx) {
                    $unitForm = trim(
                        ($rx['unit'] ?? '') .
                        (($rx['unit'] ?? '') !== '' && ($rx['dosage_form'] ?? '') !== '' ? ' / ' : '') .
                        ($rx['dosage_form'] ?? '')
                    );

                    $parts = array_filter([
                        $rx['medicine_name'],
                        $rx['strength'],
                        $rx['generic_name'],
                        $rx['frequency'],
                        $unitForm,
                        $rx['meal'],
                        $rx['quantity'] !== '' ? 'QTY ' . $rx['quantity'] : '',
                        $rx['duration']
                    ], fn($v) => trim((string)$v) !== '');

                    $prescription_lines[] = implode(' | ', $parts);
                }
                $prescription = implode("\n", $prescription_lines);

                if ($action === 'complete') {
                    $status = 'Completed';
                    $tenant_pdo->prepare("
                        UPDATE opd_visits
                        SET doctor_id = ?, symptoms = ?, vitals_json = ?, diagnosis = ?, investigations = ?, advice = ?, custom_fields_json = ?, prescription = ?, prescription_json = ?, doctor_notes = ?, follow_up_date = ?, consultation_completed_at = NOW(), status = ?
                        WHERE visit_id = ? AND org_id = ? AND center_id = ?
                    ")->execute([$save_doctor_id, $symptoms, $vitals_json, $diagnosis, $investigations, $advice, $custom_fields_json, $prescription, $prescription_json, $doctor_notes, $follow_up, $status, $visit_id, $org_id, $center_id]);

                    try {
                        $tenant_pdo->prepare("UPDATE opd_queue_tracking SET status = 'Completed', out_time = NOW() WHERE visit_id = ? AND org_id = ? AND center_id = ?")
                            ->execute([$visit_id, $org_id, $center_id]);
                    } catch (Exception $e) {}

                    $success = 'PRESCRIPTION SAVED SUCCESSFULLY.';
                    $show_print_modal = true; // SHOW PRINT PREVIEW
                } else {
                    $tenant_pdo->prepare("
                        UPDATE opd_visits
                        SET doctor_id = ?, symptoms = ?, vitals_json = ?, diagnosis = ?, investigations = ?, advice = ?, custom_fields_json = ?, prescription = ?, prescription_json = ?, doctor_notes = ?, follow_up_date = ?
                        WHERE visit_id = ? AND org_id = ? AND center_id = ?
                    ")->execute([$save_doctor_id, $symptoms, $vitals_json, $diagnosis, $investigations, $advice, $custom_fields_json, $prescription, $prescription_json, $doctor_notes, $follow_up, $visit_id, $org_id, $center_id]);
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

    $q = $tenant_pdo->prepare("
        SELECT
            v.visit_id, v.patient_id, v.doctor_id, v.department_id, v.token_no, v.visit_date, v.status, v.consultation_started_at, v.consultation_completed_at,
            p.uhid, p.fullname, p.gender, p.age, p.age_unit, p.mobile,
            d.full_name AS doctor_name, dept.dept_name
        FROM opd_visits v
        INNER JOIN opd_queue_tracking qt ON qt.visit_id = v.visit_id AND qt.org_id = v.org_id AND qt.center_id = v.center_id
        JOIN patient_master p ON p.patient_id = v.patient_id
        LEFT JOIN master_doctors d ON d.id = v.doctor_id
        LEFT JOIN master_departments dept ON dept.id = v.department_id
        WHERE v.org_id = ? AND v.center_id = ? AND v.visit_date = ? {$whereDoctor}
          AND qt.status = 'Inside Cabin' AND qt.out_time IS NULL
        ORDER BY qt.in_time ASC, v.token_no ASC
    ");
    $q->execute($params);
    $visit_list = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $err = $err ?: $e->getMessage();
}

/* ==========================================================
   SELECTED PATIENT DETAILS & PAST VISITS
   ========================================================== */
$selected = null;
$selected_vitals = [];
$selected_rx_items = [];
$selected_custom_fields = [];
$past_visits = [];

if ($selected_visit_id > 0) {
    try {
        $autoIn = $tenant_pdo->prepare("
            UPDATE opd_visits
            SET status = 'Inside Cabin',
                doctor_id = CASE WHEN ? > 0 THEN ? ELSE doctor_id END,
                consultation_started_at = COALESCE(consultation_started_at, NOW())
            WHERE visit_id = ? AND org_id = ? AND center_id = ? AND status IN ('Waiting', 'Inside Cabin')
        ");
        $autoIn->execute([$doctor_id, $doctor_id, $selected_visit_id, $org_id, $center_id]);

        try {
            $tenant_pdo->prepare("
                UPDATE opd_queue_tracking
                SET status = 'Inside Cabin', in_time = COALESCE(in_time, NOW()), waiting_since = COALESCE(waiting_since, NOW())
                WHERE visit_id = ? AND org_id = ? AND center_id = ? AND (status IS NULL OR status IN ('Waiting', 'Inside Cabin'))
            ")->execute([$selected_visit_id, $org_id, $center_id]);
        } catch (Throwable $e) {}

        $s = $tenant_pdo->prepare("
            SELECT
                v.*, p.uhid, p.fullname, p.gender, p.age, p.age_unit, p.mobile, p.address,
                d.full_name AS doctor_name, dept.dept_name
            FROM opd_visits v
            JOIN patient_master p ON p.patient_id = v.patient_id
            LEFT JOIN master_doctors d ON d.id = v.doctor_id
            LEFT JOIN master_departments dept ON dept.id = v.department_id
            WHERE v.visit_id = ? AND v.org_id = ? AND v.center_id = ?
            LIMIT 1
        ");
        $s->execute([$selected_visit_id, $org_id, $center_id]);
        $selected = $s->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($selected) {
            $pv = $tenant_pdo->prepare("
                SELECT visit_id, visit_date, diagnosis, advice, prescription_json, doctor_notes, status
                FROM opd_visits
                WHERE patient_id = ? AND visit_id != ? AND org_id = ? AND center_id = ?
                ORDER BY visit_date DESC
                LIMIT 10
            ");
            $pv->execute([$selected['patient_id'], $selected_visit_id, $org_id, $center_id]);
            $past_visits = $pv->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!empty($selected['vitals_json'])) {
                $decoded = json_decode($selected['vitals_json'], true);
                if (is_array($decoded)) $selected_vitals = $decoded;
            }

            if (!empty($selected['custom_fields_json'])) {
                $decoded = json_decode($selected['custom_fields_json'], true);
                if (is_array($decoded)) $selected_custom_fields = $decoded;
            }

            $selected_custom_fields_schema = [];
            $selected_template_vital_id = '';
            try {
                $schemaStmt = $tenant_pdo->prepare("
                    SELECT custom_fields_schema, default_vitals
                    FROM prescription_template_master
                    WHERE org_id = ? AND center_id = ? AND status = 1
                      AND (doctor_id = ? OR department_id = ? OR (doctor_id IS NULL AND department_id IS NULL))
                    ORDER BY CASE WHEN doctor_id = ? THEN 1 WHEN department_id = ? THEN 2 ELSE 3 END, template_id DESC
                    LIMIT 1
                ");
                $schemaStmt->execute([$org_id, $center_id, (int)$selected['doctor_id'], (int)$selected['department_id'], (int)$selected['doctor_id'], (int)$selected['department_id']]);
                $schemaRow = $schemaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $schemaRaw = $schemaRow['custom_fields_schema'] ?? '';
                $selected_custom_fields_schema = json_decode((string)$schemaRaw, true) ?: [];
                $selected_template_vital_id = (string)($schemaRow['default_vitals'] ?? '');
            } catch (Throwable $e) {}

            $rxSource = !empty($selected['prescription_json']) ? $selected['prescription_json'] : ($selected['prescription'] ?? '');
            if (!empty($rxSource)) {
                $rxDecoded = json_decode($rxSource, true);
                if (is_array($rxDecoded) && isset($rxDecoded['items']) && is_array($rxDecoded['items'])) {
                    $selected_rx_items = $rxDecoded['items'];
                } else {
                    $legacyLines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$selected['prescription'])));
                    foreach ($legacyLines as $line) {
                        $selected_rx_items[] = ['medicine_name' => $line, 'dose' => '', 'unit' => '', 'meal' => '', 'duration' => '', 'quantity' => ''];
                    }
                }
            }
        }
    } catch (Exception $e) {
        $err = $err ?: $e->getMessage();
    }
}

$filtered_templates = [];
$visit_doc_id = (int)($selected['doctor_id'] ?? $doctor_id);
$visit_dept_id = (int)($selected['department_id'] ?? 0);

foreach ($prescription_templates as $pt) {
    $t_doc = (int)($pt['doctor_id'] ?? 0);
    $t_dept = (int)($pt['department_id'] ?? 0);
    
    if ($t_doc > 0) {
        if ($t_doc === $visit_doc_id) $filtered_templates[] = $pt;
    } elseif ($t_dept > 0) {
        if ($t_dept === $visit_dept_id) $filtered_templates[] = $pt;
    } else {
        $filtered_templates[] = $pt;
    }
}

$edit_template_defaults = [];
if ($selected) {
    $bestScore = -1;
    foreach ($filtered_templates as $pt) {
        $tDoc = (int)($pt['doctor_id'] ?? 0);
        $tDept = (int)($pt['department_id'] ?? 0);
        $score = 0;
        if ($visit_doc_id > 0 && $tDoc === $visit_doc_id) $score = 100;
        elseif ($visit_dept_id > 0 && $tDept === $visit_dept_id) $score = 50;
        elseif ($tDoc === 0 && $tDept === 0) $score = 10;
        if ($score > $bestScore) {
            $bestScore = $score;
            $edit_template_defaults = $pt;
        }
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<style>
body, .card, .form-label, .form-control, .form-select, .btn, .badge, .table, th, td { text-transform: uppercase; }
input[type="number"], textarea { text-transform: none; }
.doctor-desk-wrap { min-width: 0; font-size: 11px; }
.doctor-desk-wrap .row { --bs-gutter-x: .6rem; --bs-gutter-y: .6rem; }
.dd-stat { border:1px solid #e5e7eb; border-radius:9px; padding:8px 10px; background:#fff; min-height:62px; box-shadow:0 2px 10px rgba(15,23,42,.05); }
.dd-stat .num { font-size:18px; font-weight:800; line-height:1; }
.dd-stat .lbl { font-size:8px; color:#64748b; font-weight:800; margin-top:3px; }
.dd-queue { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; max-height: 560px !important; }
.dd-queue-row { display:block; text-decoration:none; color:inherit; border-bottom:1px solid #eef2f7; padding:8px 9px; }
.dd-queue-row:hover { background:#f8fafc; }
.dd-queue-row.active { background:#eff6ff; border-left:4px solid #0d6efd; }
.dd-token { font-size:16px; font-weight:900; width:36px; }
.dd-patient { min-width:0; }
.dd-patient .name { font-weight:800; font-size:10px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dd-patient .meta { font-size:8px; color:#64748b; margin-top:2px; }
.dd-main-card { border:0; border-radius:10px; box-shadow:0 2px 10px rgba(15,23,42,.06); }
.dd-patient-banner { background:linear-gradient(135deg,#eff6ff,#ffffff); border:1px solid #dbeafe; border-radius:9px; padding:9px 10px; margin-bottom: .55rem !important; }
.dd-patient-banner .patient-name { font-size:16px; font-weight:900; }
.dd-patient-banner .uhid { font-family:monospace; font-size:9px; font-weight:800; color:#2563eb; }
.dd-section-title { font-size:9px; font-weight:900; color:#0f172a; letter-spacing:.3px; }
.dd-vital { border:1px solid #e5e7eb; border-radius:9px; padding:7px; min-height:66px; background:#fff; }
.dd-vital label { font-size:9px; color:#64748b; font-weight:800; margin-bottom:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; }
.dd-vital input { font-weight:800; }

/* COMPACT MEDICINE ROW CSS */
.dd-medicine-row { border:1px solid #dfe5ec; border-radius:7px; padding:6px 6px 7px; margin-bottom:5px; background:#fafbfc; }
.dd-medicine-row .form-label { font-size:7px !important; line-height:1.05; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin:0 0 2px !important; color:#4b5563; display:block; }
.dd-medicine-row .form-control, .dd-medicine-row .form-select, .dd-medicine-row .btn { height:30px; min-height:30px; font-size:10px; line-height:1.1; padding:3px 6px; border-radius:4px; }
.dd-medicine-row .form-select { padding-right:22px; }
.dd-medicine-row .input-group-sm > .btn { padding:0 7px; line-height:1; }
.dd-medicine-row .row { margin-left:0; margin-right:0; }
.dd-medicine-row .row + .row { margin-top:4px !important; }
.dd-medicine-row .dd-readonly { color:#475569; }
.dd-medicine-row .medicine-select { font-size:10px; font-weight:500; }
.dd-medicine-row .btn-outline-danger { white-space:nowrap; font-size:9px; font-weight:700; }
.dd-medicine-row input { text-overflow:ellipsis; }

.dd-template-bar { border:1px solid #dbeafe; background:#eff6ff; border-radius:8px; padding:8px; margin-bottom: .55rem !important; }
.dd-clinical-mini { border:1px solid #e2e8f0; border-radius:8px; padding:7px 8px; background:#fff; min-height:62px; transition:.15s ease; cursor:pointer; }
.dd-clinical-mini:hover { border-color:#93c5fd; background:#f8fbff; }
.dd-clinical-mini-head { display:flex; justify-content:space-between; align-items:center; gap:6px; font-size:8px; font-weight:900; color:#334155; margin-bottom:4px; }
.dd-summary-chips { display:flex; flex-wrap:wrap; gap:4px; min-height:18px; }
.dd-summary-chip { display:inline-flex; align-items:center; border:1px solid #bfdbfe; background:#eff6ff; color:#1e3a8a; border-radius:999px; padding:2px 5px; font-size:8px; font-weight:800; max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dd-summary-empty, .dd-summary-text { font-size:8px; color:#64748b; font-weight:700; }
.dd-custom-summary { border:1px solid #e2e8f0; border-radius:8px; padding:7px 8px; background:#fff; cursor:pointer; }
.dd-readonly { background:#f1f5f9 !important; }
.dd-investigation-box { border:1px solid #e5e7eb; border-radius:10px; padding:10px; background:#fff; }
.master-search-row { display:flex; gap:6px; }
.master-chip-wrap { min-height:36px; display:flex; flex-wrap:wrap; gap:6px; padding:4px 0; }
.master-chip { display:inline-flex; align-items:center; gap:5px; border:1px solid #bfdbfe; background:#eff6ff; color:#1e3a8a; border-radius:999px; padding:4px 8px; font-size:10px; font-weight:800; }
.master-chip button { border:0; background:transparent; color:inherit; font-weight:900; line-height:1; padding:0 2px; cursor:pointer; }
.dd-custom-field { border:1px solid #e5e7eb; border-radius:9px; padding:9px; background:#fafafa; }
.dd-custom-field label { font-size:9px; color:#64748b; font-weight:800; margin-bottom:3px; }
.followup-date-group .form-control,
.followup-date-group .btn {
    height: 28px;
    font-size: 10px;
    padding: 3px 7px;
}
.followup-date-group .btn {
    min-width: 32px;
}

.status-inside { background:#eff6ff; color:#1d4ed8; border:1px solid #93c5fd; animation:ddBlink 1s infinite; }
@keyframes ddBlink { 50% { opacity:.45; } }
@media (min-width: 1200px) { .doctor-desk-wrap .col-lg-4 { width: 30%; } .doctor-desk-wrap .col-lg-8 { width: 70%; } }

/* LEFT-ALIGNED SEARCH DROPDOWN */
.left-aligned-datalist {
    position: absolute;
    z-index: 99999;
    display: none;
    background: #fff;
    border: 1px solid #ced4da;
    border-radius: 0 0 5px 5px;
    box-shadow: 0 4px 12px rgba(0,0,0,.15);
    max-height: 250px;
    overflow-y: auto;
    text-align: left;
    width: 100%;
}
.left-aligned-datalist.show { display: block; }
.left-aligned-datalist-item {
    display: block;
    width: 100%;
    padding: 8px 10px;
    border: 0;
    background: #fff;
    color: #212529;
    font-size: 11px;
    line-height: 1.25;
    text-align: left;
    cursor: pointer;
    white-space: normal;
    font-weight: 600;
}
.left-aligned-datalist-item:hover,
.left-aligned-datalist-item.active {
    background: #eff6ff;
    color: #1e3a8a;
}

/* ACTIVE TAB STYLING */
#consultationTabs .nav-link {
    background-color: #f1f5f9 !important;
    color: #64748b !important;
    border: 1px solid #cbd5e1;
    border-bottom: none;
    margin: 0 4px;
    transition: 0.2s;
}
#consultationTabs .nav-link.active {
    background-color: #1e3a8a !important; 
    color: #ffffff !important;
    border-color: #1e3a8a !important;
}
#consultationTabs .nav-link.active i {
    color: #93c5fd !important;
}

</style>

<div class="doctor-desk-wrap">
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($err): ?>
                Swal.fire({ icon: 'error', title: 'Error', text: <?= json_encode($err) ?> });
            <?php endif; ?>
            
            <?php if ($success && !$show_print_modal): ?>
                Swal.fire({ icon: 'success', title: 'Success', text: <?= json_encode($success) ?> });
            <?php endif; ?>

            <?php if ($show_print_modal): ?>
                // POPUP PRINT MODAL AFTER SAVE
                var printModal = new bootstrap.Modal(document.getElementById('printPreviewModal'));
                document.getElementById('printIframe').src = 'print_prescription.php?visit_id=<?= (int)$selected_visit_id ?>';
                printModal.show();
            <?php endif; ?>
        });
    </script>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h5 class="fw-bold mb-0" style="font-size:14px;"><i class="bi bi-prescription2 text-primary me-2"></i>DOCTOR DESK</h5>
            <div class="text-muted small" style="font-size:9px;">TODAY'S OPD CONSULTATION</div>
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
        <div class="col-6 col-md-3"><div class="dd-stat"><div class="num text-primary"><?= count($visit_list) ?></div><div class="lbl">INSIDE CABIN PATIENTS</div></div></div>
        <div class="col-6 col-md-3"><div class="dd-stat"><div class="num text-info"><?= count($visit_list) ?></div><div class="lbl">READY FOR CONSULTATION</div></div></div>
        <div class="col-6 col-md-3"><div class="dd-stat"><div class="num text-warning">0</div><div class="lbl">WAITING OUTSIDE</div></div></div>
        <div class="col-6 col-md-3"><div class="dd-stat"><div class="num text-success"><?= date('d-m-Y') ?></div><div class="lbl">DATE</div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card dd-main-card">
                <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center" style="min-height:38px;">
                    <div class="fw-bold"><i class="bi bi-people-fill me-2 text-primary"></i>PATIENT QUEUE</div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= count($visit_list) ?></span>
                </div>
                <div class="p-2 border-bottom">
                    <input type="text" id="queueSearch" class="form-control form-control-sm" placeholder="SEARCH TOKEN / PATIENT / UHID">
                </div>
                <div class="dd-queue" style="border:0; border-radius:0;">
                    <?php if (!$visit_list): ?>
                        <div class="text-center py-5 text-muted small fw-bold">NO PATIENTS INSIDE CABIN</div>
                    <?php else: ?>
                        <?php foreach ($visit_list as $v): ?>
                            <a class="dd-queue-row <?= $selected_visit_id == (int)$v['visit_id'] ? 'active' : '' ?>" href="?visit_id=<?= (int)$v['visit_id'] ?>&doctor_id=<?= (int)$doctor_id ?>" data-search="<?= htmlspecialchars(strtolower(($v['token_no'] ?? '') . ' ' . ($v['fullname'] ?? '') . ' ' . ($v['uhid'] ?? ''))) ?>">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="dd-token text-primary">#<?= (int)$v['token_no'] ?></div>
                                    <div class="dd-patient flex-grow-1">
                                        <div class="name"><?= htmlspecialchars($v['fullname']) ?></div>
                                        <div class="meta"><?= htmlspecialchars($v['uhid']) ?> • <?= (int)$v['age'] ?> <?= htmlspecialchars($v['age_unit'] ?: 'YRS') ?> • <?= htmlspecialchars($v['gender']) ?></div>
                                    </div>
                                    <span class="badge status-inside rounded-pill" style="font-size:7px;">INSIDE CABIN</span>
                                </div>
                                <div class="small text-muted mt-1 ms-5" style="font-size:8px;"><?= htmlspecialchars($v['doctor_name'] ?? 'GENERAL') ?><?= $v['dept_name'] ? ' • ' . htmlspecialchars($v['dept_name']) : '' ?></div>
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
                    <form method="POST" id="prescriptionForm">
                        <input type="hidden" name="visit_id" value="<?= (int)$selected['visit_id'] ?>">
                        <input type="hidden" name="doctor_id" value="<?= (int)$doctor_id ?>">
                        <input type="hidden" id="prescription_items_json" name="prescription_items_json" value="[]">
                        <input type="hidden" id="custom_fields_values_json" name="custom_fields_values_json" value="<?= htmlspecialchars(!empty($selected['custom_fields_json']) ? $selected['custom_fields_json'] : '{}', ENT_QUOTES) ?>">
                        <input type="hidden" id="vitals_json" name="vitals_json" value="<?= htmlspecialchars(!empty($selected['vitals_json']) ? $selected['vitals_json'] : '{}', ENT_QUOTES) ?>">

                        <!-- EXPLICIT HIDDEN FIELDS FOR CLINICAL NOTES TO PREVENT ANY SAVING ISSUES -->
                        <input type="hidden" name="symptoms" id="form_symptoms" value="">
                        <input type="hidden" name="diagnosis" id="form_diagnosis" value="">
                        <input type="hidden" name="investigations" id="form_investigations" value="">
                        <input type="hidden" name="advice" id="form_advice" value="">
                        <input type="hidden" name="doctor_notes" id="form_doctor_notes" value="">

                        <div class="card-body" style="padding:.65rem;">
                            <div class="dd-patient-banner mb-3 d-flex justify-content-between gap-3 flex-wrap align-items-center">
                                <div>
                                    <div class="patient-name"><?= htmlspecialchars($selected['fullname']) ?></div>
                                    <div class="uhid"><?= htmlspecialchars($selected['uhid']) ?></div>
                                    <div class="small text-muted mt-1" style="font-size:8px;">
                                        <?= (int)$selected['age'] . (str_starts_with(strtoupper($selected['age_unit'] ?? ''), 'M') ? 'M' : 'Y') ?> / <?= substr(strtoupper($selected['gender'] ?? 'U'), 0, 1) ?>
                                    </div>
                                </div>
                                <div class="text-end d-flex flex-column align-items-end">
                                    <div class="fw-bold text-primary fs-5">TOKEN #<?= (int)$selected['token_no'] ?></div>
                                    <div class="small text-muted mb-1" style="font-size:8px;"><?= htmlspecialchars($selected['status']) ?></div>
                                    
                                    <?php if (count($past_visits) > 0): ?>
                                        <button type="button" class="btn btn-sm btn-outline-info fw-bold mt-1" data-bs-toggle="modal" data-bs-target="#pastVisitsModal" style="font-size:9px; padding:2px 6px;">
                                            <i class="bi bi-clock-history me-1"></i> PAST VISITS (<?= count($past_visits) ?>)
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="dd-template-bar mb-3">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-9">
                                        <label class="form-label small fw-bold">PRESCRIPTION TEMPLATE</label>
                                        <select id="prescription_template_id" class="form-select form-select-sm">
                                            <option value="">SELECT TEMPLATE</option>
                                            <?php foreach ($filtered_templates as $pt): ?>
                                                <option value="<?= (int)$pt['template_id'] ?>"
                                                        data-doctor-id="<?= (int)($pt['doctor_id'] ?? 0) ?>"
                                                        data-department-id="<?= (int)($pt['department_id'] ?? 0) ?>">
                                                    <?= htmlspecialchars($pt['template_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary w-100 fw-bold" onclick="applyPrescriptionTemplate(false)"><i class="bi bi-arrow-repeat me-1"></i>LOAD TEMPLATE</button>
                                    </div>
                                </div>
                                <div class="dd-small text-muted mt-2">TEMPLATE WILL NOT LOAD AUTOMATICALLY. CLICK 'LOAD TEMPLATE' TO FILTER OPTIONS.</div>
                            </div>

                            <div id="vitalsSection" class="mb-3" style="display:none;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="dd-section-title">VITALS</div>
                                    <div class="small text-muted">ENTER CURRENT PATIENT VITALS</div>
                                </div>
                                <div class="row g-2" id="vitalsContainer"></div>
                            </div>

                            <div class="dd-section-title mb-2">CLINICAL NOTES</div>

                            <div class="row g-2 mb-3">
                                <div class="col-6 col-xl-3">
                                    <button type="button" class="dd-clinical-mini w-100 text-start" onclick="openClinicalNotesModal('symptom')">
                                        <div class="dd-clinical-mini-head"><span>SYMPTOMS / COMPLAINTS</span><i class="bi bi-pencil-square"></i></div>
                                        <div id="symptomSummary" class="dd-summary-chips"><span class="dd-summary-empty">NONE SELECTED</span></div>
                                    </button>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <button type="button" class="dd-clinical-mini w-100 text-start" onclick="openClinicalNotesModal('diagnosis')">
                                        <div class="dd-clinical-mini-head"><span>DIAGNOSIS</span><i class="bi bi-pencil-square"></i></div>
                                        <div id="diagnosisSummary" class="dd-summary-chips"><span class="dd-summary-empty">NONE SELECTED</span></div>
                                    </button>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <button type="button" class="dd-clinical-mini w-100 text-start" onclick="openClinicalNotesModal('investigation')">
                                        <div class="dd-clinical-mini-head"><span>INVESTIGATIONS</span><i class="bi bi-pencil-square"></i></div>
                                        <div id="investigationSummary" class="dd-summary-chips"><span class="dd-summary-empty">NONE SELECTED</span></div>
                                    </button>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <button type="button" class="dd-clinical-mini w-100 text-start" onclick="openClinicalNotesModal('advice')">
                                        <div class="dd-clinical-mini-head"><span>ADVICE</span><i class="bi bi-pencil-square"></i></div>
                                        <div id="adviceSummary" class="dd-summary-chips"><span class="dd-summary-empty">NONE SELECTED</span></div>
                                    </button>
                                </div>
                            </div>

                            <div class="dd-section-title mb-2 mt-3">PRESCRIPTION</div>
                            <button type="button" class="dd-clinical-mini w-100 text-start mb-3" onclick="openPrescriptionModal()">
                                <div class="dd-clinical-mini-head"><span>MEDICINES</span><i class="bi bi-pencil-square"></i></div>
                                <div id="medicineSummary" class="dd-summary-chips"><span class="dd-summary-empty">NO MEDICINE ADDED. CLICK TO ADD.</span></div>
                            </button>

                            <div id="customFieldsCompactWrap" class="mb-3" style="display:none;">
                                <button type="button" class="dd-custom-summary w-100 text-start" onclick="openCustomFieldsModal()">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-bold small">CUSTOM CLINICAL FIELDS</div>
                                            <div class="dd-small text-muted">FIELDS DEFINED IN THE SELECTED TEMPLATE.</div>
                                        </div>
                                        <i class="bi bi-pencil-square text-primary"></i>
                                    </div>
                                    <div id="customFieldsSummary" class="dd-summary-text mt-1">OPEN FIELDS</div>
                                </button>
                            </div>

                            <div class="row g-2 mb-2 mt-3">
                                <div class="col-md-4 offset-md-8">
                                    <label class="form-label fw-bold mb-1" style="font-size:9px;">FOLLOW-UP DATE</label>
                                    <?php 
                                        $disp_follow_up = '';
                                        if (!empty($selected['follow_up_date'])) {
                                            $disp_follow_up = date('d-m-Y', strtotime($selected['follow_up_date']));
                                        } else {
                                            // DEFAULT TO 5 DAYS AHEAD IF EMPTY
                                            $disp_follow_up = date('d-m-Y', strtotime('+5 days'));
                                        }
                                    ?>
                                    <div class="input-group input-group-sm followup-date-group">
                                        <input type="text"
                                               name="follow_up_date"
                                               id="follow_up_date"
                                               class="form-control form-control-sm"
                                               placeholder="DD-MM-YYYY"
                                               autocomplete="off"
                                               value="<?= htmlspecialchars($disp_follow_up) ?>">
                                        <button type="button"
                                                class="btn btn-outline-secondary"
                                                id="followUpCalendarBtn"
                                                title="SELECT FOLLOW-UP DATE">
                                            <i class="bi bi-calendar3"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer bg-white border-top d-flex flex-wrap justify-content-end py-2">
                            <button type="submit"
                                    name="doctor_action"
                                    value="complete"
                                    class="btn btn-success btn-sm fw-bold px-3 py-1"
                                    style="font-size:10px; border-radius:6px;"
                                    onclick="return prepareDoctorSave();">
                                <i class="bi bi-check2-circle me-1"></i> SAVE & VIEW PRESCRIPTION
                            </button>
                        </div>

<!-- COMBINED CONSULTATION MODAL -->
<div class="modal fade" id="combinedConsultationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width: 96%;">
        <div class="modal-content">
            <div class="modal-header py-2 bg-light">
                <h6 class="modal-title fw-bold"><i class="bi bi-journal-medical text-primary me-2"></i>CONSULTATION DETAILS</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-0">
                <!-- TABS NAVIGATION -->
                <ul class="nav nav-tabs nav-fill bg-light border-bottom-0" id="consultationTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold text-dark rounded-0 border-0" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes-pane" type="button" role="tab"><i class="bi bi-pencil-square me-1 text-primary"></i> CLINICAL NOTES</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold text-dark rounded-0 border-0" id="meds-tab" data-bs-toggle="tab" data-bs-target="#meds-pane" type="button" role="tab"><i class="bi bi-capsule me-1 text-primary"></i> PRESCRIBE MEDICINES</button>
                    </li>
                </ul>

                <div class="tab-content p-3" id="consultationTabsContent">
                    <!-- CLINICAL NOTES TAB -->
                    <div class="tab-pane fade show active" id="notes-pane" role="tabpanel">
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <div class="dd-investigation-box h-100">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label small fw-bold mb-0">SYMPTOMS / COMPLAINTS</label>
                                    </div>
                                    <div class="master-search-row mb-2">
                                        <input type="text" id="symptomSearch" class="form-control form-control-sm" list="symptomMasterList" placeholder="SEARCH / SELECT SYMPTOM" autocomplete="off" onkeydown="masterSearchKey(event,'symptom')" onchange="selectMasterFromSearch('symptom')">
                                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="openQuickAdd('symptom', 'ADD SYMPTOM')">+ ADD</button>
                                    </div>
                                    <div id="symptomSelected" class="master-chip-wrap"></div>
                                    <datalist id="symptomMasterList">
                                        <?php foreach ($rx_symptoms as $sm): ?><option value="<?= htmlspecialchars($sm['symptom_name'], ENT_QUOTES) ?>"></option><?php endforeach; ?>
                                    </datalist>
                                    <textarea id="symptoms" class="d-none"><?= htmlspecialchars($selected['symptoms'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="dd-investigation-box h-100">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label small fw-bold mb-0">DIAGNOSIS</label>
                                    </div>
                                    <div class="master-search-row mb-2">
                                        <input type="text" id="diagnosisSearch" class="form-control form-control-sm" list="diagnosisMasterList" placeholder="SEARCH / SELECT DIAGNOSIS" autocomplete="off" onkeydown="masterSearchKey(event,'diagnosis')" onchange="selectMasterFromSearch('diagnosis')">
                                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="openQuickAdd('diagnosis', 'ADD DIAGNOSIS')">+ ADD</button>
                                    </div>
                                    <div id="diagnosisSelected" class="master-chip-wrap"></div>
                                    <datalist id="diagnosisMasterList">
                                        <?php foreach ($rx_diagnoses as $dg): ?><option value="<?= htmlspecialchars($dg['diagnosis_name'], ENT_QUOTES) ?>"></option><?php endforeach; ?>
                                    </datalist>
                                    <textarea id="diagnosis" class="d-none"><?= htmlspecialchars($selected['diagnosis'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <div class="dd-investigation-box h-100">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label small fw-bold mb-0">INVESTIGATIONS</label>
                                    </div>
                                    <div class="master-search-row mb-2">
                                        <input type="text" id="investigationSearch" class="form-control form-control-sm" list="investigationMasterList" placeholder="SEARCH / SELECT INVESTIGATION" autocomplete="off" onkeydown="masterSearchKey(event,'investigation')" onchange="selectMasterFromSearch('investigation')">
                                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="openQuickAdd('investigation', 'ADD INVESTIGATION')">+ ADD</button>
                                    </div>
                                    <div id="investigationSelected" class="master-chip-wrap"></div>
                                    <datalist id="investigationMasterList">
                                        <?php foreach ($rx_investigations as $inv): ?><option value="<?= htmlspecialchars($inv['investigation_name'], ENT_QUOTES) ?>"></option><?php endforeach; ?>
                                    </datalist>
                                    <textarea id="investigations" class="d-none"><?= htmlspecialchars($selected['investigations'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="dd-investigation-box h-100">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label small fw-bold mb-0">ADVICE</label>
                                    </div>
                                    <div class="master-search-row mb-2">
                                        <input type="text" id="adviceSearch" class="form-control form-control-sm" list="adviceMasterList" placeholder="SEARCH / SELECT ADVICE" autocomplete="off" onkeydown="masterSearchKey(event,'advice')" onchange="selectMasterFromSearch('advice')">
                                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="openQuickAdd('advice', 'ADD ADVICE')">+ ADD</button>
                                    </div>
                                    <div id="adviceSelected" class="master-chip-wrap"></div>
                                    <datalist id="adviceMasterList">
                                        <?php foreach ($rx_advices as $adv): ?><option value="<?= htmlspecialchars($adv['advice_name'], ENT_QUOTES) ?>"></option><?php endforeach; ?>
                                    </datalist>
                                    <textarea id="advice" class="d-none"><?= htmlspecialchars($selected['advice'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row g-2">
                            <div class="col-md-12">
                                <div class="dd-investigation-box h-100">
                                    <label class="form-label small fw-bold">DOCTOR NOTES (INTERNAL / EXTRA)</label>
                                    <textarea id="doctor_notes" rows="2" class="form-control form-control-sm"><?= htmlspecialchars($selected['doctor_notes'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- MEDICINES TAB -->
                    <div class="tab-pane fade" id="meds-pane" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="dd-section-title mb-0">MEDICINE LIST</div>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold me-1" onclick="refreshMasterData()" title="Refresh Masters">
                                    <i class="bi bi-arrow-clockwise"></i> REFRESH
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-success fw-bold me-1" onclick="openAddMedicineModal()" title="Add New Medicine">
                                    <i class="bi bi-plus-circle"></i> NEW
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="addMedicine({})">
                                    <i class="bi bi-plus-lg me-1"></i> ADD MEDICINE
                                </button>
                            </div>
                        </div>
                        
                        <!-- UPDATED MEDICINE HEADERS FOR SINGLE LINE WIDE LAYOUT -->
                        <div class="row g-1 d-none d-lg-flex mb-1 px-1 text-muted text-uppercase" style="font-size: 8px; font-weight: 800;">
                            <div class="col-lg-2">MEDICINE</div>
                            <div class="col-lg-1">STRENGTH</div>
                            <div class="col-lg-2">GENERIC / SALT NAME</div>
                            <div class="col-lg-2">FREQUENCY</div>
                            <div class="col-lg-1">UNIT / FORM</div>
                            <div class="col-lg-1">MEAL</div>
                            <div class="col-lg-1">QTY</div>
                            <div class="col-lg-1">DURATION <span class="text-primary text-lowercase fw-normal">(EDIT)</span></div>
                            <div class="col-lg-1 text-center">ACTION</div>
                        </div>

                        <datalist id="medicineMasterList"></datalist>
                        <div id="medicineList" class="mb-2">
                            <?php foreach ($selected_rx_items as $rx): ?>
                                <script type="application/json" class="server-rx-item"><?= json_encode($rx, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!$selected_rx_items): ?>
                            <div class="text-center text-muted py-3 small" id="emptyMedicineText">
                                NO MEDICINE ADDED. CLICK <b>ADD MEDICINE ROW</b> OR APPLY A TEMPLATE.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer py-2 border-top bg-light">
                <button type="button" class="btn btn-sm btn-secondary fw-bold" data-bs-dismiss="modal">CLOSE</button>
                <button type="button" class="btn btn-sm btn-success fw-bold px-4" data-bs-dismiss="modal" onclick="syncPrescription();"><i class="bi bi-check2-all me-1"></i> SAVE UPDATES</button>
            </div>
        </div>
    </div>
</div>

                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- NEW ADVANCED MEDICINE ADD MODAL -->
<!-- Removed tabindex="-1" to prevent focus trapping issues when Quick Add Modal opens on top -->
<div class="modal fade" id="addMedicineModal" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 bg-light">
                <h6 class="modal-title fw-bold text-primary"><i class="bi bi-capsule me-2"></i>ADD MEDICINE</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="newMedicineForm">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold">MEDICINE NAME *</label>
                            <input type="text" class="form-control form-control-sm border-primary text-uppercase" id="new_med_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">STRENGTH</label>
                            <input type="text" class="form-control form-control-sm text-uppercase" id="new_med_strength">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">GENERIC / SALT</label>
                            <input type="text" class="form-control form-control-sm text-uppercase" id="new_med_generic">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">FREQUENCY</label>
                            <div class="input-group input-group-sm">
                                <select class="form-select" id="new_med_freq">
                                    <option value="">SELECT</option>
                                    <?php foreach ($rx_frequencies as $f): ?><option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['frequency_name']) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-outline-secondary px-2" type="button" onclick="refreshMasterData()" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
                                <button class="btn btn-outline-secondary px-2" type="button" onclick="openQuickAdd('frequency', 'ADD FREQUENCY')" title="Add New"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">UNIT / FORM</label>
                            <div class="input-group input-group-sm">
                                <select class="form-select" id="new_med_unit">
                                    <option value="">SELECT</option>
                                    <?php foreach ($rx_units as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['unit_name']) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-outline-secondary px-2" type="button" onclick="refreshMasterData()" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
                                <button class="btn btn-outline-secondary px-2" type="button" onclick="openQuickAdd('unit', 'ADD UNIT / FORM')" title="Add New"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">MEAL TIMING</label>
                            <div class="input-group input-group-sm">
                                <select class="form-select" id="new_med_meal">
                                    <option value="">SELECT</option>
                                    <?php foreach ($rx_meals as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['meal_name']) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-outline-secondary px-2" type="button" onclick="refreshMasterData()" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
                                <button class="btn btn-outline-secondary px-2" type="button" onclick="openQuickAdd('meal', 'ADD MEAL TIMING')" title="Add New"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DEFAULT QTY</label>
                            <input type="text" class="form-control form-control-sm text-uppercase" id="new_med_qty" placeholder="E.G. 1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DURATION</label>
                            <div class="input-group input-group-sm">
                                <select class="form-select" id="new_med_duration">
                                    <option value="">SELECT</option>
                                    <?php foreach ($rx_durations as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['duration_name']) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-outline-secondary px-2" type="button" onclick="refreshMasterData()" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
                                <button class="btn btn-outline-secondary px-2" type="button" onclick="openQuickAdd('duration', 'ADD DURATION')" title="Add New"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">MANUFACTURER</label>
                            <input type="text" class="form-control form-control-sm text-uppercase" id="new_med_manufacturer">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">CATEGORY</label>
                            <input type="text" class="form-control form-control-sm text-uppercase" id="new_med_category">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer py-2 d-flex justify-content-between">
                <div class="form-check form-switch mt-1">
                    <input class="form-check-input" type="checkbox" id="new_med_active" checked>
                    <label class="form-check-label small fw-bold" for="new_med_active">ACTIVE</label>
                </div>
                <div>
                    <button type="button" class="btn btn-sm btn-light border fw-bold me-1" onclick="document.getElementById('newMedicineForm').reset();">RESET</button>
                    <button type="button" class="btn btn-sm btn-primary fw-bold" onclick="saveMedicineFull()">SAVE</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- QUICK ADD NATIVE MODAL (UNIVERSAL) -->
<!-- Removed tabindex="-1" to prevent focus freezing -->
<div class="modal fade" id="quickAddModal" aria-hidden="true" style="z-index: 1070;">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 bg-light">
                <h6 class="modal-title fw-bold" id="quickAddTitle">ADD NEW</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="quick_add_type">
                <label class="form-label small fw-bold">NAME *</label>
                <input type="text" class="form-control form-control-sm text-uppercase" id="quick_add_name" required>
            </div>
            <div class="modal-footer py-1">
                <button type="button" class="btn btn-sm btn-primary fw-bold w-100" onclick="saveQuickAdd()">SAVE</button>
            </div>
        </div>
    </div>
</div>

<!-- CUSTOM FIELDS MODAL -->
<div class="modal fade" id="customFieldsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold">CUSTOM CLINICAL FIELDS</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <div id="customFieldsContainer">
                    <div class="text-center text-muted small py-2">NO CUSTOM FIELDS LOADED.</div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">CLOSE</button>
                <button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-dismiss="modal" onclick="syncPrescription();">SAVE FIELDS</button>
            </div>
        </div>
    </div>
</div>

<!-- PRINT PREVIEW MODAL -->
<div class="modal fade" id="printPreviewModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header py-2 bg-primary text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-medical me-2"></i>PRESCRIPTION PREVIEW</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="window.location.href='opd_doctor.php'"></button>
            </div>
            <div class="modal-body p-0 bg-light" style="height: 75vh;">
                <iframe id="printIframe" src="" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>
            <div class="modal-footer py-2 bg-white">
                <button type="button" class="btn btn-sm btn-secondary fw-bold" data-bs-dismiss="modal" onclick="window.location.href='opd_doctor.php'">CLOSE DESK</button>
                <button type="button" class="btn btn-sm btn-dark fw-bold px-4" onclick="triggerIframePrint()"><i class="bi bi-printer me-1"></i> PRINT PRESCRIPTION</button>
            </div>
        </div>
    </div>
</div>

<!-- PAST VISITS MODAL -->
<?php if ($selected && count($past_visits) > 0): ?>
<div class="modal fade" id="pastVisitsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2 bg-light">
                <h6 class="modal-title fw-bold"><i class="bi bi-clock-history text-primary me-2"></i>PAST VISITS FOR <?= htmlspecialchars($selected['fullname']) ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="row g-3">
                    <?php foreach ($past_visits as $pv): ?>
                        <div class="col-md-6">
                            <div class="card border shadow-sm h-100" style="border-radius:10px;">
                                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-primary"><i class="bi bi-calendar-check me-1"></i> <?= date('d-m-Y', strtotime($pv['visit_date'])) ?></span>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($pv['status']) ?></span>
                                </div>
                                <div class="card-body" style="font-size:11px;">
                                    <?php if ($pv['diagnosis']): ?>
                                        <div class="mb-2"><strong>DIAGNOSIS:</strong> <?= nl2br(htmlspecialchars($pv['diagnosis'])) ?></div>
                                    <?php endif; ?>
                                    <?php if ($pv['advice']): ?>
                                        <div class="mb-2"><strong>ADVICE:</strong> <?= nl2br(htmlspecialchars($pv['advice'])) ?></div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-3">
                                        <strong>PRESCRIPTION:</strong>
                                        <?php 
                                            $rxDecoded = json_decode($pv['prescription_json'], true); 
                                            if (is_array($rxDecoded) && !empty($rxDecoded['items'])): 
                                        ?>
                                            <ul class="mb-2 mt-1 ps-3 text-muted" style="list-style-type: disc;">
                                                <?php foreach ($rxDecoded['items'] as $item): ?>
                                                    <li><?= htmlspecialchars($item['medicine_name']) ?> <?= htmlspecialchars($item['dose']) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <button type="button" class="btn btn-sm btn-outline-success fw-bold py-1 w-100 mt-2" onclick="copyPastMedicines(<?= htmlspecialchars(json_encode($rxDecoded['items']), ENT_QUOTES, 'UTF-8') ?>)">
                                                <i class="bi bi-files me-1"></i> COPY MEDICINES TO CURRENT VISIT
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">NO MEDICINES RECORDED.</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">CLOSE</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function triggerIframePrint() {
    const iframe = document.getElementById('printIframe');
    if(iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}

function openAddMedicineModal() {
    document.getElementById('newMedicineForm').reset();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addMedicineModal')).show();
    setTimeout(() => {
        document.getElementById('new_med_name').focus();
    }, 300);
}

async function saveMedicineFull() {
    const name = document.getElementById('new_med_name').value.trim();
    if(!name) {
        alert('Please enter a medicine name.');
        document.getElementById('new_med_name').focus();
        return;
    }

    const formData = new URLSearchParams();
    formData.append('ajax', 'add_master');
    formData.append('master_type', 'medicine_full');
    formData.append('name', name);
    formData.append('strength', document.getElementById('new_med_strength').value.trim());
    formData.append('generic', document.getElementById('new_med_generic').value.trim());
    formData.append('frequency_id', document.getElementById('new_med_freq').value);
    formData.append('unit_id', document.getElementById('new_med_unit').value);
    formData.append('meal_id', document.getElementById('new_med_meal').value);
    formData.append('qty', document.getElementById('new_med_qty').value.trim());
    formData.append('duration_id', document.getElementById('new_med_duration').value);
    formData.append('manufacturer', document.getElementById('new_med_manufacturer').value.trim());
    formData.append('category', document.getElementById('new_med_category').value.trim());
    formData.append('status', document.getElementById('new_med_active').checked ? '1' : '0');

    try {
        const response = await fetch('opd_doctor.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData.toString() });
        const data = await response.json();
        if(data.ok) {
            bootstrap.Modal.getInstance(document.getElementById('addMedicineModal')).hide();
            document.getElementById('newMedicineForm').reset();
            refreshMasterData(); 
            Swal.fire({ icon: 'success', title: 'Added', text: 'MEDICINE ADDED TO MASTER.', timer: 1500, showConfirmButton: false });
        } else {
            alert('Error: ' + data.message);
        }
    } catch(e) {
        console.error(e);
        alert('Error saving medicine.');
    }
}

// UNIVERSAL QUICK ADD - Replaces both old masterAddModal and quickAddModal
function openQuickAdd(type, title) {
    document.getElementById('quickAddTitle').textContent = title;
    document.getElementById('quick_add_type').value = type;
    document.getElementById('quick_add_name').value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('quickAddModal')).show();
    setTimeout(() => {
        document.getElementById('quick_add_name').focus();
    }, 300);
}

async function saveQuickAdd() {
    const type = document.getElementById('quick_add_type').value;
    const nameInput = document.getElementById('quick_add_name');
    const name = nameInput.value.trim();

    if(!name) {
        alert('Please enter a name.');
        nameInput.focus();
        return;
    }

    const formData = new URLSearchParams();
    formData.append('ajax', 'add_master');
    formData.append('master_type', type);
    formData.append('value', name);

    try {
        const response = await fetch('opd_doctor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        });
        const data = await response.json();
        if(data.ok) {
            nameInput.value = '';
            bootstrap.Modal.getInstance(document.getElementById('quickAddModal')).hide();
            refreshMasterData();
            Swal.fire({ icon: 'success', title: 'Added', text: 'ADDED TO MASTER.', timer: 1500, showConfirmButton: false });
        } else {
            alert('Error: ' + data.message);
        }
    } catch(e) {
        console.error(e);
        alert('Error saving data.');
    }
}

async function refreshMasterData() {
    try {
        const response = await fetch('opd_doctor.php?ajax=get_masters');
        const data = await response.json();
        if (data.ok) {
            MASTER_SYMPTOMS.length = 0; MASTER_SYMPTOMS.push(...data.symptoms.map(s => s.symptom_name));
            MASTER_DIAGNOSES.length = 0; MASTER_DIAGNOSES.push(...data.diagnoses.map(d => d.diagnosis_name));
            MASTER_INVESTIGATIONS.length = 0; MASTER_INVESTIGATIONS.push(...data.investigations.map(i => i.investigation_name));
            MASTER_ADVICES.length = 0; MASTER_ADVICES.push(...data.advices.map(a => a.advice_name));
            
            MASTER_VITALS.length = 0; MASTER_VITALS.push(...data.vitals);
            MASTER_MEDICINES.length = 0; MASTER_MEDICINES.push(...data.medicines);
            MASTER_DURATIONS.length = 0; MASTER_DURATIONS.push(...data.durations);

            refreshMasterDatalist('symptom');
            refreshMasterDatalist('diagnosis');
            refreshMasterDatalist('investigation');
            refreshMasterDatalist('advice');
            
            updateSelectOptions('new_med_freq', data.frequencies, 'frequency_name', null, 'id');
            updateSelectOptions('new_med_unit', data.units, 'unit_name', null, 'id');
            updateSelectOptions('new_med_meal', data.meals, 'meal_name', null, 'id');
            updateSelectOptions('new_med_duration', data.durations, 'duration_name', null, 'id');

            const vWrap = document.getElementById('vitalsContainer');
            if(data.vitals && data.vitals.length > 0) {
                let vHtml = '';
                const selectedVitals = getSelectedVitals();
                data.vitals.forEach(vital => {
                    let vitalKey = (vital.vital_key || '').trim();
                    let vitalName = (vital.vital_name || '').trim();
                    let vitalUnit = (vital.unit || '').trim();
                    let vitalPlaceholder = (vital.placeholder || '').trim();
                    if (!vitalKey) {
                        vitalKey = vitalName.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
                    }
                    
                    const isChecked = selectedVitals.includes(vitalKey) ? 'checked' : '';
                    
                    vHtml += `<div class="col-6 col-md-3 col-lg-2">
                        <div class="form-check vital-option border rounded h-100">
                            <input class="form-check-input template-vital-check" type="checkbox" value="${esc(vitalKey)}" id="vital_${esc(vitalKey)}" ${isChecked}>
                            <label class="form-check-label fw-bold small" for="vital_${esc(vitalKey)}">
                                ${esc(vitalName)}
                                ${vitalUnit || vitalPlaceholder ? `<span class="text-muted d-block" style="font-size:9px; font-weight:600;">${esc(vitalUnit)} ${vitalUnit && vitalPlaceholder ? ' • ' : ''} ${esc(vitalPlaceholder)}</span>` : ''}
                            </label>
                        </div>
                    </div>`;
                });
                vWrap.innerHTML = vHtml;
            } else {
                vWrap.innerHTML = '<div class="text-muted small fw-semibold py-2 w-100">NO ACTIVE VITALS FOUND.</div>';
            }

            document.querySelectorAll('.medicine-select').forEach(sel => {
                const currentVal = sel.value;
                sel.innerHTML = medicineOptionsHtml(currentVal);
            });
            
            medicineOptionsDataList();

        } else {
            alert('Failed to refresh: ' + data.message);
        }
    } catch (e) {
        console.error(e);
        alert('Error refreshing masters.');
    }
}

function updateSelectOptions(selectId, dataArray, nameField, codeField = null, valueField = null) {
    const sel = document.getElementById(selectId);
    if (!sel) return;
    
    const selected = Array.from(sel.selectedOptions).map(o => o.value);
    sel.innerHTML = '';
    
    if(['new_med_freq','new_med_unit','new_med_meal','new_med_duration'].includes(selectId)) {
         sel.innerHTML = '<option value="">SELECT</option>';
    }
    
    dataArray.forEach(item => {
        const opt = document.createElement('option');
        opt.value = valueField ? item[valueField] : item[nameField];
        let text = item[nameField];
        if (codeField && item[codeField]) {
            text += ' (' + item[codeField] + ')';
        }
        opt.textContent = text;
        if (selected.includes(String(opt.value))) {
            opt.selected = true;
        }
        sel.appendChild(opt);
    });
}

function openClinicalNotesModal(section) {
    const modal = document.getElementById('combinedConsultationModal');
    if (!modal) return;
    
    const tabEl = document.querySelector('#notes-tab');
    bootstrap.Tab.getOrCreateInstance(tabEl).show();
    
    bootstrap.Modal.getOrCreateInstance(modal).show();
    
    setTimeout(function() {
        const map = { symptom: 'symptomSearch', diagnosis: 'diagnosisSearch', investigation: 'investigationSearch', advice: 'adviceSearch' };
        const target = document.getElementById(map[section]);
        if (target) target.focus();
    }, 250);
}

function openPrescriptionModal() {
    const modal = document.getElementById('combinedConsultationModal');
    if (!modal) return;
    
    const tabEl = document.querySelector('#meds-tab');
    bootstrap.Tab.getOrCreateInstance(tabEl).show();
    
    bootstrap.Modal.getOrCreateInstance(modal).show();
}

function openCustomFieldsModal() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('customFieldsModal')).show();
}

function copyPastMedicines(items) {
    if(Array.isArray(items) && items.length > 0) {
        items.forEach(item => addMedicine(item));
        syncPrescription();
        bootstrap.Modal.getInstance(document.getElementById('pastVisitsModal')).hide();
        Swal.fire({ icon: 'success', title: 'Copied!', text: 'MEDICINES COPIED SUCCESSFULLY! YOU CAN NOW EDIT THEM.' });
    }
}

const MASTER_SYMPTOMS = <?= json_encode(array_values(array_map(fn($x) => $x['symptom_name'], $rx_symptoms)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const MASTER_DIAGNOSES = <?= json_encode(array_values(array_map(fn($x) => $x['diagnosis_name'], $rx_diagnoses)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const MASTER_INVESTIGATIONS = <?= json_encode(array_values(array_map(fn($x) => $x['investigation_name'], $rx_investigations)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const MASTER_ADVICES = <?= json_encode(array_values(array_map(fn($x) => $x['advice_name'], $rx_advices)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const MASTER_MEDICINES = <?= json_encode($rx_medicines, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const MASTER_DURATIONS = <?= json_encode($rx_durations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const MASTER_VITALS = <?= json_encode($rx_vitals, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const SELECTED_CUSTOM_FIELDS = <?= json_encode($selected_custom_fields, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const SELECTED_VITALS = <?= json_encode(json_decode((string)($selected['vitals_json'] ?? '{}'), true) ?: [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

let TEMPLATE_OPTIONS = { symptom: null, diagnosis: null, investigation: null, advice: null };

function normalizeVitalKeys(value) {
    const raw = String(value ?? '').trim();
    if (!raw) return [];
    try {
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) return parsed.map(v => String(v).trim().toLowerCase()).filter(Boolean);
    } catch (e) {}
    return raw.split(/[\n,]+/).map(v => String(v).trim().toLowerCase()).filter(Boolean);
}

function renderVitals(vitalValue, values = {}) {
    const section = document.getElementById('vitalsSection');
    const wrap = document.getElementById('vitalsContainer');
    if (!section || !wrap) return;

    const vitalKeys = normalizeVitalKeys(vitalValue);
    
    let selectedVitals = vitalKeys.map(k => MASTER_VITALS.find(v => {
        let vk = String(v.vital_key || ('vital_' + v.id)).trim().toLowerCase();
        if (!v.vital_key) {
            vk = String(v.vital_name).trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        }
        return vk === k;
    })).filter(Boolean);

    if (!selectedVitals.length && values && typeof values === 'object' && Object.keys(values).length) {
        selectedVitals = MASTER_VITALS.filter(function(vital) {
            let key = String(vital.vital_key || ('vital_' + vital.id)).trim().toLowerCase();
            if (!vital.vital_key) {
                key = String(vital.vital_name).trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
            }
            return Object.prototype.hasOwnProperty.call(values, key);
        });
    }

    if (!selectedVitals.length) {
        section.style.display = 'none';
        wrap.innerHTML = '';
        return;
    }
    section.style.display = '';

    wrap.innerHTML = selectedVitals.map(function(vital) {
        let key = String(vital.vital_key || ('vital_' + vital.id)).trim().toLowerCase();
        if (!vital.vital_key) {
            key = String(vital.vital_name).trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        }
        const label = String(vital.vital_name || '').trim();
        const unit = String(vital.unit || '').trim();
        const placeholder = String(vital.placeholder || '').trim();
        const val = values[key] ?? '';
        return `<div class="col-6 col-md-3 col-lg-2">
            <div class="dd-vital">
                <label>${escapeHtml(label)}${unit ? ' <span class="text-muted">(' + escapeHtml(unit) + ')</span>' : ''}</label>
                <input type="text" name="${escapeHtml(key)}" class="form-control form-control-sm vital-input" value="${escapeHtml(val)}" placeholder="${escapeHtml(placeholder)}" data-vital-id="${Number(vital.id)}">
            </div>
        </div>`;
    }).join('');
}

function collectVisibleVitals() {
    const values = {};
    document.querySelectorAll('#vitalsContainer .vital-input').forEach(function(el) { values[el.name] = el.value.trim(); });
    return values;
}

function syncVitals() {
    const target = document.getElementById('vitals_json');
    if (target) target.value = JSON.stringify(collectVisibleVitals());
}

function escapeHtml(v) { return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

function medicineOptionsDataList() {
    const list = document.getElementById('medicineMasterList');
    if (list) {
        list.innerHTML = MASTER_MEDICINES.map(m =>
            `<option value="${escapeHtml(m.medicine_name)}"></option>`
        ).join('');
    }
}

function medicineOptionsHtml(selectedId = '', selectedName = '') {
    let html = '<option value="">SELECT MEDICINE</option>';

    MASTER_MEDICINES.forEach(function(m) {
        const isSelected =
            (selectedId && String(m.id) === String(selectedId)) ||
            (!selectedId && selectedName &&
             String(m.medicine_name || '').trim().toLowerCase() === String(selectedName).trim().toLowerCase());

        const label = [m.medicine_name, m.strength].filter(Boolean).join(' - ');

        html += `<option value="${escapeHtml(m.id)}" ${isSelected ? 'selected' : ''}>${escapeHtml(label)}</option>`;
    });

    return html;
}

function findMedicineById(id) {
    return MASTER_MEDICINES.find(function(m) {
        return String(m.id) === String(id);
    }) || null;
}

function findMedicineByName(name) {
    const key = String(name || '').trim().toLowerCase();
    return MASTER_MEDICINES.find(function(m) {
        return String(m.medicine_name || '').trim().toLowerCase() === key;
    }) || null;
}

function addMedicine(item = {}) {
    const wrap = document.getElementById('medicineList');
    if (!wrap) return;

    const empty = document.getElementById('emptyMedicineText');
    if (empty) empty.remove();

    const row = document.createElement('div');
    row.className = 'dd-medicine-row';

    const medicineId = item.medicine_id || item.id || '';
    const medicineName = item.medicine_name || item.master_medicine_name || '';

    row.innerHTML = `
        <input type="hidden" name="rx_medicine_id[]" class="medicine-id" value="${escapeHtml(medicineId)}">

        <div class="row g-1 align-items-center">
            <div class="col-lg-2">
                <label class="form-label d-lg-none">MEDICINE</label>
                <select class="form-select form-select-sm medicine-select" onchange="applyMedicineDefaults(this)">
                    ${medicineOptionsHtml(medicineId, medicineName)}
                </select>
            </div>

            <div class="col-lg-1">
                <label class="form-label d-lg-none">STRENGTH</label>
                <input type="text" class="form-control form-control-sm dd-readonly strength-name" readonly>
            </div>

            <div class="col-lg-2">
                <label class="form-label d-lg-none">GENERIC / SALT NAME</label>
                <input type="text" class="form-control form-control-sm dd-readonly generic-name" readonly>
            </div>

            <div class="col-lg-2">
                <label class="form-label d-lg-none">FREQUENCY</label>
                <input type="text" class="form-control form-control-sm dd-readonly frequency-name" readonly>
            </div>

            <div class="col-lg-1">
                <label class="form-label d-lg-none">UNIT / FORM</label>
                <input type="text" class="form-control form-control-sm dd-readonly unit-form" readonly>
            </div>

            <div class="col-lg-1">
                <label class="form-label d-lg-none">MEAL</label>
                <input type="text" class="form-control form-control-sm dd-readonly meal-name" readonly>
            </div>

            <div class="col-lg-1">
                <label class="form-label d-lg-none">QTY</label>
                <input type="text" class="form-control form-control-sm dd-readonly quantity-input" readonly>
            </div>

            <div class="col-lg-1">
                <label class="form-label d-lg-none">DURATION <span class="text-primary text-lowercase fw-normal">(EDIT)</span></label>
                <input type="text" class="form-control form-control-sm duration-name" placeholder="E.G. 5 DAYS">
            </div>

            <div class="col-lg-1 text-center">
                <label class="form-label d-none d-lg-block">&nbsp;</label>
                <button type="button" class="btn btn-sm btn-outline-danger w-100"
                        onclick="this.closest('.dd-medicine-row').remove(); syncPrescription();"
                        title="REMOVE">
                    <i class="bi bi-trash"></i> <span class="d-lg-none">REMOVE</span>
                </button>
            </div>
        </div>
    `;

    wrap.appendChild(row);

    const select = row.querySelector('.medicine-select');

    if (!select.value && medicineName) {
        const resolved = findMedicineByName(medicineName);
        if (resolved) select.value = String(resolved.id);
    }

    const saved = item || {};
    applyMedicineDefaults(select, false, saved);
}

function applyMedicineDefaults(selectEl, overwriteValues = true, saved = {}) {
    const row = selectEl?.closest('.dd-medicine-row');
    if (!row) return;

    const medicine = findMedicineById(selectEl.value);

    if (!medicine) {
        row.querySelector('.medicine-id').value = '';
        if (overwriteValues) {
            row.querySelector('.strength-name').value = '';
            row.querySelector('.generic-name').value = '';
            row.querySelector('.frequency-name').value = '';
            row.querySelector('.unit-form').value = '';
            row.querySelector('.meal-name').value = '';
            row.querySelector('.quantity-input').value = '';
            row.querySelector('.duration-name').value = '';
        }
        return;
    }

    row.querySelector('.medicine-id').value = medicine.id || '';

    const masterUnitForm = [medicine.unit_name || '', medicine.dosage_form || ''].filter(Boolean).join(' / ');
    
    const freqName = medicine.frequency_name || '';
    const freqCode = medicine.frequency_code || '';
    const finalFreq = freqCode ? `${freqName} (${freqCode})` : freqName;

    if (overwriteValues) {
        row.querySelector('.strength-name').value = medicine.strength || '';
        row.querySelector('.generic-name').value = medicine.generic_name || '';
        row.querySelector('.frequency-name').value = finalFreq;
        row.querySelector('.unit-form').value = masterUnitForm;
        row.querySelector('.meal-name').value = medicine.meal_name || '';
        row.querySelector('.quantity-input').value = medicine.default_qty || '';
        row.querySelector('.duration-name').value = medicine.duration_name || '';
        return;
    }

    const fillIfEmpty = (selector, masterValue) => {
        const el = row.querySelector(selector);
        if (el && !String(el.value || '').trim()) el.value = masterValue || '';
    };
    fillIfEmpty('.strength-name', saved.strength ?? medicine.strength);
    fillIfEmpty('.generic-name', saved.generic_name ?? medicine.generic_name);
    fillIfEmpty('.frequency-name', saved.frequency ?? finalFreq);
    fillIfEmpty('.unit-form', [saved.unit || medicine.unit_name || '', saved.dosage_form || medicine.dosage_form || ''].filter(Boolean).join(' / '));
    fillIfEmpty('.meal-name', saved.meal ?? medicine.meal_name);
    fillIfEmpty('.quantity-input', saved.quantity ?? medicine.default_qty);
    fillIfEmpty('.duration-name', saved.duration ?? medicine.duration_name);

    if (Object.prototype.hasOwnProperty.call(saved, 'strength')) row.querySelector('.strength-name').value = saved.strength || '';
    if (Object.prototype.hasOwnProperty.call(saved, 'generic_name')) row.querySelector('.generic-name').value = saved.generic_name || '';
    if (Object.prototype.hasOwnProperty.call(saved, 'frequency')) row.querySelector('.frequency-name').value = saved.frequency || '';
    if (Object.prototype.hasOwnProperty.call(saved, 'unit') || Object.prototype.hasOwnProperty.call(saved, 'dosage_form')) {
        row.querySelector('.unit-form').value = [saved.unit || '', saved.dosage_form || ''].filter(Boolean).join(' / ');
    }
    if (Object.prototype.hasOwnProperty.call(saved, 'meal')) row.querySelector('.meal-name').value = saved.meal || '';
    if (Object.prototype.hasOwnProperty.call(saved, 'quantity')) row.querySelector('.quantity-input').value = saved.quantity || '';
    if (Object.prototype.hasOwnProperty.call(saved, 'duration')) row.querySelector('.duration-name').value = saved.duration || '';
}

function medicineKey(event, input) {
    if (event.key === 'Enter') {
        event.preventDefault();
        const medicine = findMedicineByName(input.value);
        const row = input.closest('.dd-medicine-row');
        const select = row?.querySelector('.medicine-select');

        if (medicine && select) {
            select.value = String(medicine.id);
            applyMedicineDefaults(select);
        }
    }
}

const SELECTED_MASTERS = { symptom: [], diagnosis: [], investigation: [], advice: [] };
const MANUAL_CLINICAL_SELECTION = { symptom: false, diagnosis: false, investigation: false, advice: false };

function normalizeLines(text) { return [...new Set(String(text || '').split(/\r?\n/).map(v => v.trim()).filter(Boolean))]; }

function renderMasterChips(type) {
    const map = {
        symptom: ['symptomSelected', 'symptoms'],
        diagnosis: ['diagnosisSelected', 'diagnosis'],
        investigation: ['investigationSelected', 'investigations'],
        advice: ['adviceSelected', 'advice']
    };
    const wrap = document.getElementById(map[type][0]);
    const hidden = document.getElementById(map[type][1]);

    if (wrap) {
        const values = SELECTED_MASTERS[type] || [];
        wrap.innerHTML = values.length ? values.map(value => `
            <span class="master-chip"><span class="master-chip-text">${escapeHtml(value)}</span>
            <button type="button" class="master-chip-remove" onclick="removeMasterValue('${type}', decodeURIComponent('${encodeURIComponent(value)}'))">&times;</button></span>
        `).join('') : '<span class="master-empty">NO VALUES SELECTED.</span>';
    }
    if (hidden) hidden.value = (SELECTED_MASTERS[type] || []).join('\n');

    const summaryMap = { symptom: 'symptomSummary', diagnosis: 'diagnosisSummary', investigation: 'investigationSummary', advice: 'adviceSummary' };
    const summary = document.getElementById(summaryMap[type]);
    if (summary) {
        const values = SELECTED_MASTERS[type] || [];
        if (!MANUAL_CLINICAL_SELECTION[type]) {
            summary.innerHTML = '<span class="dd-summary-empty">NONE SELECTED</span>';
        } else {
            summary.innerHTML = values.length
                ? values.slice(0, 4).map(value => `<span class="dd-summary-chip">${escapeHtml(value)}</span>`).join('') + (values.length > 4 ? `<span class="dd-summary-empty">+${values.length - 4} MORE</span>` : '')
                : '<span class="dd-summary-empty">NONE SELECTED</span>';
        }
    }
}

function setMasterValues(type, valuesText) {
    const values = normalizeLines(valuesText);
    SELECTED_MASTERS[type] = values;
    MANUAL_CLINICAL_SELECTION[type] = values.length > 0;
    renderMasterChips(type);

    const hiddenMap = { symptom: 'symptoms', diagnosis: 'diagnosis', investigation: 'investigations', advice: 'advice' };
    const hidden = document.getElementById(hiddenMap[type]);
    if (hidden) hidden.value = values.join('\n');
}

function addSelectedMaster(type, value) {
    value = String(value || '').trim().toUpperCase();
    if (!value) return false;
    if ((SELECTED_MASTERS[type] || []).some(v => String(v).trim().toLowerCase() === value.toLowerCase())) return false;
    SELECTED_MASTERS[type].push(value);
    MANUAL_CLINICAL_SELECTION[type] = true;
    renderMasterChips(type);
    syncPrescription();
    return true;
}

function removeMasterValue(type, value) {
    SELECTED_MASTERS[type] = (SELECTED_MASTERS[type] || []).filter(v => String(v).toLowerCase() !== String(value).toLowerCase());
    MANUAL_CLINICAL_SELECTION[type] = true;
    renderMasterChips(type);
    syncPrescription();
    const input = getSearchInput(type);
    if (input) {
        input.value = '';
        input.blur();
    }
}

function getSearchInput(type) { return document.getElementById({ symptom: 'symptomSearch', diagnosis: 'diagnosisSearch', investigation: 'investigationSearch', advice: 'adviceSearch' }[type]); }

function masterSearchKey(event, type) { if (event.key === 'Enter') { event.preventDefault(); selectMasterFromSearch(type); } }

function selectMasterFromSearch(type) {
    const input = getSearchInput(type);
    if (!input) return;
    const value = String(input.value || '').trim();
    if (!value) return;

    let sourceArray = [];
    if (typeof TEMPLATE_OPTIONS !== 'undefined' && TEMPLATE_OPTIONS[type] !== null) {
        sourceArray = TEMPLATE_OPTIONS[type];
    } else {
        const map = { symptom: MASTER_SYMPTOMS, diagnosis: MASTER_DIAGNOSES, investigation: MASTER_INVESTIGATIONS, advice: MASTER_ADVICES };
        sourceArray = map[type] || [];
    }

    const match = sourceArray.find(v => String(v).toLowerCase() === value.toLowerCase());
    if (!match) { input.value = ''; return; }
    addSelectedMaster(type, match);
    input.value = '';
    input.focus();
}

function refreshMasterDatalist(type) {
    const map = { symptom: ['symptomMasterList', MASTER_SYMPTOMS], diagnosis: ['diagnosisMasterList', MASTER_DIAGNOSES], investigation: ['investigationMasterList', MASTER_INVESTIGATIONS], advice: ['adviceMasterList', MASTER_ADVICES] };
    const list = document.getElementById(map[type][0]);
    
    let sourceArray = map[type][1];
    if (typeof TEMPLATE_OPTIONS !== 'undefined' && TEMPLATE_OPTIONS[type] !== null) {
        sourceArray = TEMPLATE_OPTIONS[type];
    }

    if (list) list.innerHTML = sourceArray.map(v => `<option value="${escapeHtml(v)}"></option>`).join('');
}

function customFieldId(field, index) { return field.field_id || String(field.label || 'field_' + index).toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || ('field_' + index); }

function renderCustomFields(schema, values = {}) {
    const wrap = document.getElementById('customFieldsContainer');
    if (!wrap) return;
    let fields = typeof schema === 'string' ? (function(){ try{return JSON.parse(schema||'[]');}catch(e){return [];} })() : schema;
    if (!Array.isArray(fields) || !fields.length) { wrap.innerHTML = '<div class="text-center text-muted small py-2">NO CUSTOM FIELDS IN THIS TEMPLATE.</div>'; return; }
    wrap.innerHTML = '<div class="row g-2" id="customFieldsGrid"></div>';
    const grid = document.getElementById('customFieldsGrid');
    fields.forEach(function(field, index) {
        const id = customFieldId(field, index);
        const width = [4,6,12].includes(Number(field.grid_width)) ? Number(field.grid_width) : 6;
        const value = values[id] !== undefined ? values[id] : (field.default_value || '');
        const type = field.type || 'text';
        const options = Array.isArray(field.options) ? field.options : [];
        const col = document.createElement('div');
        col.className = `col-md-${width}`;
        let control = '';
        if (type === 'textarea') { control = `<textarea class="form-control form-control-sm cf-value" data-field-id="${escapeHtml(id)}" rows="3">${escapeHtml(value)}</textarea>`; }
        else if (type === 'select') { control = `<select class="form-select form-select-sm cf-value" data-field-id="${escapeHtml(id)}"><option value="">SELECT</option>${options.map(o => `<option value="${escapeHtml(o)}" ${String(value) === String(o) ? 'selected' : ''}>${escapeHtml(o)}</option>`).join('')}</select>`; }
        else if (type === 'multiselect') { const vals = Array.isArray(value) ? value : String(value || '').split(/\r?\n/).map(v => v.trim()).filter(Boolean); control = `<select class="form-select form-select-sm cf-value" data-field-id="${escapeHtml(id)}" multiple size="4">${options.map(o => `<option value="${escapeHtml(o)}" ${vals.includes(String(o)) ? 'selected' : ''}>${escapeHtml(o)}</option>`).join('')}</select>`; }
        else { control = `<input type="text" class="form-control form-control-sm cf-value" data-field-id="${escapeHtml(id)}" value="${escapeHtml(Array.isArray(value) ? value.join(', ') : value)}">`; }
        col.innerHTML = `<div class="dd-custom-field"><label class="form-label">${escapeHtml(field.label || id)} ${field.required ? '<span class="text-danger">*</span>' : ''}</label>${control}</div>`;
        grid.appendChild(col);
    });
}

function collectCustomFieldValues() {
    const values = {};
    document.querySelectorAll('#customFieldsContainer .cf-value').forEach(function(el) {
        const id = el.dataset.fieldId;
        if (!id) return;
        if (el.multiple) values[id] = Array.from(el.selectedOptions).map(o => o.value).filter(Boolean);
        else values[id] = el.value.trim();
    });
    return values;
}

function syncCustomFields() {
    const target = document.getElementById('custom_fields_values_json');
    if (target) target.value = JSON.stringify(collectCustomFieldValues());
    const compact = document.getElementById('customFieldsCompactWrap'), summary = document.getElementById('customFieldsSummary');
    const count = document.querySelectorAll('#customFieldsContainer .cf-value').length;
    if (compact) compact.style.display = count ? '' : 'none';
    if (summary) summary.textContent = count ? `${count} FIELD${count === 1 ? '' : 'S'} CONFIGURED` : 'OPEN FIELDS';
}

function collectPrescriptionItems() {
    return Array.from(document.querySelectorAll('#medicineList .dd-medicine-row')).map(row => {
        const select = row.querySelector('.medicine-select');
        const medicineId = row.querySelector('.medicine-id')?.value.trim() || select?.value?.trim() || '';
        const medicine = findMedicineById(medicineId);

        const value = (selector) => row.querySelector(selector)?.value?.trim() || '';
        const unitForm = value('.unit-form');
        let unit = '';
        let dosageForm = '';
        if (unitForm) {
            const parts = unitForm.split(' / ');
            unit = parts.shift() || '';
            dosageForm = parts.join(' / ');
        }

        return {
            medicine_id: medicineId,
            medicine_name: medicine?.medicine_name || select?.selectedOptions?.[0]?.textContent?.split(' - ')[0]?.trim() || '',
            strength: value('.strength-name'),
            generic_name: value('.generic-name'),
            frequency: value('.frequency-name'),
            unit: unit,
            dosage_form: dosageForm,
            meal: value('.meal-name'),
            quantity: value('.quantity-input'),
            duration: value('.duration-name')
        };
    }).filter(item => item.medicine_id !== '');
}

function syncPrescription() {
    const items = collectPrescriptionItems();
    const target = document.getElementById('prescription_items_json');
    if (target) target.value = JSON.stringify(items);
    
    syncCustomFields();
    syncVitals();
    
    if(document.getElementById('form_symptoms')) document.getElementById('form_symptoms').value = document.getElementById('symptoms')?.value || '';
    if(document.getElementById('form_diagnosis')) document.getElementById('form_diagnosis').value = document.getElementById('diagnosis')?.value || '';
    if(document.getElementById('form_investigations')) document.getElementById('form_investigations').value = document.getElementById('investigations')?.value || '';
    if(document.getElementById('form_advice')) document.getElementById('form_advice').value = document.getElementById('advice')?.value || '';
    if(document.getElementById('form_doctor_notes')) document.getElementById('form_doctor_notes').value = document.getElementById('doctor_notes')?.value || '';
    
    const summary = document.getElementById('medicineSummary');
    if (summary) {
        if (items.length === 0) {
            summary.innerHTML = '<span class="dd-summary-empty">NO MEDICINE ADDED. CLICK TO ADD.</span>';
        } else {
            summary.innerHTML = items.map(item => `<span class="dd-summary-chip">${escapeHtml(item.medicine_name)}</span>`).join('');
        }
    }
    
    return true;
}

function prepareDoctorSave() { 
    syncPrescription(); 
    
    const form = document.querySelector('form[method="POST"]');
    
    if (form) {
        const fields = ['symptoms', 'diagnosis', 'investigations', 'advice', 'doctor_notes'];
        
        fields.forEach(f => {
            let hiddenInput = form.querySelector('input[data-copy="'+f+'"]');
            if (!hiddenInput) {
                hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = f;
                hiddenInput.setAttribute('data-copy', f);
                form.appendChild(hiddenInput);
            }
            const sourceTextarea = document.getElementById(f);
            if (sourceTextarea) {
                hiddenInput.value = sourceTextarea.value;
            }
        });
    }
    
    return true; 
}

// SMART PARSER: Template values handle karta hai (Chahe wo JSON ho ya Comma-separated)
async function applyPrescriptionTemplate(isAutoLoad = false) {
    const templateId = document.getElementById('prescription_template_id')?.value || '';
    if (!templateId) { 
        if(!isAutoLoad) Swal.fire({ icon: 'warning', title: 'Notice', text: 'PLEASE SELECT A PRESCRIPTION TEMPLATE.' }); 
        return; 
    }
    try {
        const res = await fetch('opd_doctor.php?ajax=get_prescription_template&template_id=' + encodeURIComponent(templateId), { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!data.ok) { 
            if(!isAutoLoad) Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'TEMPLATE COULD NOT BE LOADED.' }); 
            return; 
        }
        const t = data.template || {};

        const parseTemplateData = (dataString) => {
            if (!dataString) return [];
            let str = String(dataString).trim();
            if (!str) return [];
            try {
                let parsed = JSON.parse(str);
                if (Array.isArray(parsed)) return parsed.map(v => String(v).trim()).filter(Boolean);
            } catch(e) {}
            if (str.includes('\n')) return str.split(/\r?\n/).map(v => v.trim()).filter(Boolean);
            if (str.includes(',')) return str.split(',').map(v => v.trim()).filter(Boolean);
            return [str];
        };

        TEMPLATE_OPTIONS.symptom = parseTemplateData(t.default_symptoms);
        TEMPLATE_OPTIONS.diagnosis = parseTemplateData(t.default_diagnosis);
        TEMPLATE_OPTIONS.investigation = parseTemplateData(t.default_investigations);
        TEMPLATE_OPTIONS.advice = parseTemplateData(t.default_advice);

        refreshMasterDatalist('symptom');
        refreshMasterDatalist('diagnosis');
        refreshMasterDatalist('investigation');
        refreshMasterDatalist('advice');

        if (!isAutoLoad) {
            renderVitals(t.default_vitals || '', {});

            let customSchema = []; try { customSchema = JSON.parse(t.custom_fields_schema || '[]'); } catch (e) { customSchema = []; }
            renderCustomFields(customSchema, {});

            const wrap = document.getElementById('medicineList'); wrap.innerHTML = '';
            if ((t.items || []).length) { t.items.forEach(item => addMedicine(item)); }
            else { wrap.innerHTML = '<div id="emptyMedicineText" class="text-center text-muted py-3 small">NO MEDICINE IN THIS TEMPLATE. CLICK <b>ADD MEDICINE ROW</b>.</div>'; }

            syncPrescription();
            const customWrap = document.getElementById('customFieldsCompactWrap');
            if (customWrap) customWrap.style.display = customSchema.length ? '' : 'none';
            const customSummary = document.getElementById('customFieldsSummary');
            if (customSummary) customSummary.textContent = customSchema.length ? `${customSchema.length} FIELD${customSchema.length === 1 ? '' : 'S'} CONFIGURED` : 'OPEN FIELDS';

            Swal.fire({
                icon: 'success',
                title: 'Template Loaded',
                text: 'Dropdown options and Medicines updated successfully.',
                timer: 2000,
                showConfirmButton: false
            });
        }
    } catch (e) { 
        if(!isAutoLoad) Swal.fire({ icon: 'error', title: 'Error', text: 'UNABLE TO LOAD PRESCRIPTION TEMPLATE.' }); 
    }
}

document.addEventListener('input', function(e) { if (e.target.matches('#doctor_notes, #follow_up_date, #medicineList input, #customFieldsContainer input, #customFieldsContainer textarea, #vitalsContainer input')) syncPrescription(); });
document.addEventListener('change', function(e) { if (e.target.matches('#medicineList select, #customFieldsContainer select')) syncPrescription(); });

document.addEventListener('DOMContentLoaded', function() {
    MANUAL_CLINICAL_SELECTION.symptom = false; MANUAL_CLINICAL_SELECTION.diagnosis = false; MANUAL_CLINICAL_SELECTION.investigation = false; MANUAL_CLINICAL_SELECTION.advice = false;
    renderMasterChips('symptom'); renderMasterChips('diagnosis'); renderMasterChips('investigation'); renderMasterChips('advice');

    const q = document.getElementById('queueSearch');
    if (q) q.addEventListener('input', function() {
        const term = this.value.toLowerCase().trim();
        document.querySelectorAll('.dd-queue-row').forEach(row => { row.style.display = (!term || (row.dataset.search || '').includes(term)) ? '' : 'none'; });
    });

    medicineOptionsDataList();

    setMasterValues('symptom', <?= json_encode($selected['symptoms'] ?? '', JSON_UNESCAPED_UNICODE) ?>);
    setMasterValues('diagnosis', <?= json_encode($selected['diagnosis'] ?? '', JSON_UNESCAPED_UNICODE) ?>);
    setMasterValues('investigation', <?= json_encode($selected['investigations'] ?? '', JSON_UNESCAPED_UNICODE) ?>);
    setMasterValues('advice', <?= json_encode($selected['advice'] ?? '', JSON_UNESCAPED_UNICODE) ?>);

    renderVitals(<?= json_encode($selected_template_vital_id ?? '') ?>, SELECTED_VITALS);

    renderCustomFields(
        <?= json_encode($selected_custom_fields_schema ?? [], JSON_UNESCAPED_UNICODE) ?>,
        SELECTED_CUSTOM_FIELDS
    );

    const initialCustomCount = document.querySelectorAll('#customFieldsContainer .cf-value').length;
    const initialCustomWrap = document.getElementById('customFieldsCompactWrap');
    const initialCustomSummary = document.getElementById('customFieldsSummary');
    if (initialCustomWrap) initialCustomWrap.style.display = initialCustomCount ? '' : 'none';
    if (initialCustomSummary) initialCustomSummary.textContent = initialCustomCount ? `${initialCustomCount} FIELD${initialCustomCount === 1 ? '' : 'S'} CONFIGURED` : 'OPEN FIELDS';

    document.querySelectorAll('#medicineList .server-rx-item').forEach(function(scriptEl) {
        try { addMedicine(JSON.parse(scriptEl.textContent)); } catch (e) {}
        scriptEl.remove();
    });

    const templateSelect = document.getElementById('prescription_template_id');
    if (templateSelect) {
        const activeDoctorId = <?= (int)($selected['doctor_id'] ?? $doctor_id) ?>;
        const activeDepartmentId = <?= (int)($selected['department_id'] ?? 0) ?>;
        let best = null;
        Array.from(templateSelect.options).forEach(function(opt) {
            if (!opt.value) return;
            const optDoctor = parseInt(opt.dataset.doctorId || '0', 10) || 0;
            const optDept = parseInt(opt.dataset.departmentId || '0', 10) || 0;
            let score = 0;
            if (activeDoctorId && optDoctor === activeDoctorId) score = 100;
            else if (activeDepartmentId && optDept === activeDepartmentId) score = 50;
            else if (!optDoctor && !optDept) score = 10;
            if (!best || score > best.score) best = { value: opt.value, score };
        });
        
        if (best && best.score > 0) { templateSelect.value = best.value; }
    }
    
    // BACKEND SE AUTOMATICALLY DROPDOWN OPTIONS KO FILTER KAR DIYA JAYEGA!
    applyPrescriptionTemplate(true);
    
    syncPrescription();
});
</script>

<script>
/* ==========================================================
   LEFT-ALIGNED SEARCH DROPDOWNS
   ========================================================== */
(function () {
    function initLeftAlignedDatalist(input) {
        if (!input || input.dataset.leftDropdownReady === '1') return;

        var listId = input.getAttribute('list');
        if (!listId) return;

        var list = document.getElementById(listId);
        if (!list) return;

        input.dataset.leftDropdownReady = '1';
        input.removeAttribute('list');

        var parent = input.parentElement;
        if (!parent) return;

        if (getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }

        var dropdown = document.createElement('div');
        dropdown.className = 'left-aligned-datalist';
        dropdown.setAttribute('role', 'listbox');
        parent.appendChild(dropdown);

        function positionDropdown() {
            dropdown.style.left = input.offsetLeft + 'px';
            dropdown.style.top = (input.offsetTop + input.offsetHeight) + 'px';
            dropdown.style.width = input.offsetWidth + 'px';
        }

        function getValues() {
            return Array.prototype.map.call(
                list.querySelectorAll('option'),
                function (option) {
                    return String(option.value || '').trim();
                }
            ).filter(Boolean);
        }

        function render(showAll) {
            var term = String(input.value || '').trim().toLowerCase();

            var values = getValues().filter(function (value) {
                return showAll || !term ||
                    value.toLowerCase().indexOf(term) !== -1;
            });

            dropdown.innerHTML = '';

            if (!values.length) {
                dropdown.classList.remove('show');
                return;
            }

            values.forEach(function (value) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'left-aligned-datalist-item';
                item.textContent = value;

                item.addEventListener('mousedown', function (event) {
                    event.preventDefault(); 
                    input.value = value;
                    
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    
                    input.focus();
                    setTimeout(function() { render(true); }, 50);
                });

                dropdown.appendChild(item);
            });

            positionDropdown();
            dropdown.classList.add('show');
        }

        input.addEventListener('focus', function () { render(true); });
        input.addEventListener('click', function () { render(true); });
        input.addEventListener('touchstart', function () { render(true); }, {passive: true});
        input.addEventListener('input', function () { render(false); });

        input.addEventListener('keydown', function (event) {
            if (!dropdown.classList.contains('show')) return;

            var items = dropdown.querySelectorAll('.left-aligned-datalist-item');
            if (!items.length) return;

            var active = dropdown.querySelector('.left-aligned-datalist-item.active');
            var index = Array.prototype.indexOf.call(items, active);

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                index = Math.min(index + 1, items.length - 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                index = Math.max(index - 1, 0);
            } else if (event.key === 'Enter' && active) {
                event.preventDefault();
                active.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                return;
            } else if (event.key === 'Escape') {
                dropdown.classList.remove('show');
                return;
            } else {
                return;
            }

            Array.prototype.forEach.call(items, function (item, i) {
                item.classList.toggle('active', i === index);
            });

            if (items[index]) {
                items[index].scrollIntoView({ block: 'nearest' });
            }
        });

        document.addEventListener('mousedown', function (event) {
            if (!parent.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        window.addEventListener('resize', positionDropdown);
        window.addEventListener('scroll', function () {
            if (dropdown.classList.contains('show')) {
                positionDropdown();
            }
        }, true);
    }

    function initAll(root) {
        (root || document).querySelectorAll('input[list]').forEach(
            initLeftAlignedDatalist
        );
    }

    document.addEventListener('DOMContentLoaded', function () {
        initAll(document);

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                Array.prototype.forEach.call(
                    mutation.addedNodes,
                    function (node) {
                        if (node.nodeType === 1) {
                            initAll(node);
                            if (node.matches &&
                                node.matches('input[list]')) {
                                initLeftAlignedDatalist(node);
                            }
                        }
                    }
                );
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    });
})();
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
