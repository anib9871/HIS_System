<?php

// admin/prescription_template_master.php

require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Prescription Template Master";

$org_id    = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$user_id   = (int)($_SESSION['user_id'] ?? 0);

$err = '';
$success = '';


/* ==========================================================
   TEMPLATE VITALS & ADVICE COLUMNS
   ========================================================== */
try {
    $checkVitals = $tenant_pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'prescription_template_master'
          AND COLUMN_NAME = 'default_vitals'
        LIMIT 1
    ");
    $checkVitals->execute();
    if (!$checkVitals->fetch(PDO::FETCH_ASSOC)) {
        $tenant_pdo->exec("ALTER TABLE prescription_template_master ADD COLUMN default_vitals TEXT NULL AFTER follow_up_days");
    }

    $checkAdvice = $tenant_pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'prescription_template_master'
          AND COLUMN_NAME = 'default_advice'
        LIMIT 1
    ");
    $checkAdvice->execute();
    if (!$checkAdvice->fetch(PDO::FETCH_ASSOC)) {
        $tenant_pdo->exec("ALTER TABLE prescription_template_master ADD COLUMN default_advice TEXT NULL AFTER default_investigations");
    }
} catch (Throwable $e) {
    $err = 'TEMPLATE SCHEMA SETUP ERROR: ' . $e->getMessage();
}


/* ==========================================================
   AJAX: GET TEMPLATE
   ========================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_template') {
    header('Content-Type: application/json; charset=utf-8');

    $template_id = (int)($_GET['template_id'] ?? 0);

    try {
        $stmt = $tenant_pdo->prepare("
            SELECT
                template_id,
                template_name,
                department_id,
                specialization_id,
                doctor_id,
                default_symptoms,
                default_diagnosis,
                default_investigations,
                default_advice,
                advice_id,
                follow_up_days,
                default_vitals,
                status,
                custom_fields_schema
            FROM prescription_template_master
            WHERE template_id = ?
              AND org_id = ?
              AND center_id = ?
            LIMIT 1
        ");
        $stmt->execute([$template_id, $org_id, $center_id]);

        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            echo json_encode(['ok' => false, 'message' => 'Template not found.']);
            exit;
        }

        $itemStmt = $tenant_pdo->prepare("
            SELECT
                pti.item_id,
                pti.medicine_name,
                pti.dose,
                pti.duration,
                pti.quantity,
                mm.id AS medicine_id,
                mm.generic_name,
                mm.strength,
                mm.frequency_id,
                mm.default_qty,
                mm.dosage_form,
                mm.manufacturer,
                mm.category,
                mm.unit_id,
                mm.meal_id,
                mm.default_duration_id,
                mu.unit_name,
                meal.meal_name,
                md.duration_name,
                fm.frequency_name,
                mm.status AS medicine_status
            FROM prescription_template_items pti
            LEFT JOIN master_medicines mm
                ON mm.medicine_name = pti.medicine_name
               AND mm.org_id = ?
               AND mm.center_id = ?
            LEFT JOIN master_units mu
                ON mu.id = mm.unit_id
            LEFT JOIN master_meals meal
                ON meal.id = mm.meal_id
            LEFT JOIN master_durations md
                ON md.id = mm.default_duration_id
            LEFT JOIN frequency_master fm
                ON fm.id = mm.frequency_id
            WHERE pti.template_id = ?
            ORDER BY pti.sort_order, pti.item_id
        ");
        $itemStmt->execute([$org_id, $center_id, $template_id]);
        $template['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'template' => $template], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ==========================================================
   AJAX: QUICK ADD MASTER
   ========================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'quick_add') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $type = $_POST['type'] ?? '';
        
        if ($type === 'medicine_full') {
            // ADVANCED MEDICINE ADD LOGIC
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
                throw new Exception('Medicine Name is required.');
            }

            $st = $tenant_pdo->prepare("
                INSERT INTO master_medicines 
                (org_id, center_id, medicine_name, generic_name, strength, frequency_id, unit_id, meal_id, default_qty, default_duration_id, manufacturer, category, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st->execute([$org_id, $center_id, $name, $generic, $strength, $frequency_id, $unit_id, $meal_id, $qty, $duration_id, $manufacturer, $category, $status, $user_id]);

        } else {
            // SIMPLE QUICK ADD LOGIC
            $name = trim(strtoupper($_POST['name'] ?? ''));
            if ($name === '') {
                throw new Exception('Name cannot be empty.');
            }

            if ($type === 'symptom') {
                $st = $tenant_pdo->prepare("INSERT INTO master_symptoms (org_id, center_id, symptom_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $name, $user_id]);
            } elseif ($type === 'diagnosis') {
                $st = $tenant_pdo->prepare("INSERT INTO master_diagnoses (org_id, center_id, diagnosis_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $name, $user_id]);
            } elseif ($type === 'investigation') {
                $st = $tenant_pdo->prepare("INSERT INTO master_investigations (org_id, center_id, investigation_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $name, $user_id]);
            } elseif ($type === 'advice') {
                $st = $tenant_pdo->prepare("INSERT INTO master_advices (org_id, center_id, advice_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $name, $user_id]);
            } elseif ($type === 'vital') {
                $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
                $key = trim($key, '_');
                $st = $tenant_pdo->prepare("INSERT INTO master_vitals (org_id, center_id, vital_name, vital_key, status, created_by) VALUES (?, ?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $name, $key, $user_id]);
            } elseif ($type === 'frequency') {
                $st = $tenant_pdo->prepare("INSERT INTO frequency_master (org_id, center_id, frequency_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $name, $user_id]);
            } elseif ($type === 'unit') {
                $st = $tenant_pdo->prepare("INSERT INTO master_units (org_id, center_id, unit_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $name, $user_id]);
            } elseif ($type === 'meal') {
                $st = $tenant_pdo->prepare("INSERT INTO master_meals (org_id, center_id, meal_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $name, $user_id]);
            } elseif ($type === 'duration') {
                $st = $tenant_pdo->prepare("INSERT INTO master_durations (org_id, center_id, duration_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
                $st->execute([$org_id, $center_id, $name, $user_id]);
            } else {
                throw new Exception('Invalid master type.');
            }
        }

        echo json_encode(['ok' => true, 'message' => 'Added successfully!']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ==========================================================
   AJAX: REFRESH MASTERS (NEW)
   ========================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_masters') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $st_sym = $tenant_pdo->prepare("SELECT id, symptom_name, symptom_code FROM master_symptoms WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY symptom_name");
        $st_sym->execute([$org_id, $center_id]);
        $symptoms = $st_sym->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_diag = $tenant_pdo->prepare("SELECT id, diagnosis_name, diagnosis_code FROM master_diagnoses WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY diagnosis_name");
        $st_diag->execute([$org_id, $center_id]);
        $diagnoses = $st_diag->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_inv = $tenant_pdo->prepare("SELECT id, investigation_name, investigation_code, investigation_type FROM master_investigations WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY investigation_name");
        $st_inv->execute([$org_id, $center_id]);
        $investigations = $st_inv->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_adv = $tenant_pdo->prepare("SELECT id, advice_name FROM master_advices WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY advice_name");
        $st_adv->execute([$org_id, $center_id]);
        $advices = $st_adv->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st_vit = $tenant_pdo->prepare("SELECT id, vital_name, vital_key, unit, placeholder, sort_order FROM master_vitals WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY sort_order ASC, vital_name ASC");
        $st_vit->execute([$org_id, $center_id]);
        $vitals = $st_vit->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Additional sub-masters for the Advanced Medicine Add form
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
                   mu.unit_name, meal.meal_name, md.duration_name, fm.frequency_name, mm.status AS medicine_status
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
   SAVE / UPDATE / DELETE
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {

            $template_id       = (int)($_POST['template_id'] ?? 0);
            $template_name     = strtoupper(trim($_POST['template_name'] ?? ''));
            $department_id     = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
            $specialization_id = !empty($_POST['specialization_id']) ? (int)$_POST['specialization_id'] : null;
            $doctor_id         = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;

            $default_symptoms       = trim($_POST['default_symptoms'] ?? '');
            $default_diagnosis      = trim($_POST['default_diagnosis'] ?? '');
            $default_investigations = trim($_POST['default_investigations'] ?? '');
            $default_advice         = trim($_POST['default_advice'] ?? '');

            $default_vitals         = trim($_POST['default_vitals'] ?? '');
            $custom_fields_schema   = trim((string)($_POST['custom_fields_schema'] ?? '[]'));
            if ($custom_fields_schema === '') {
                $custom_fields_schema = '[]';
            }

            $follow_up_raw = trim((string)($_POST['follow_up_days'] ?? ''));
            $follow_up_days = ($follow_up_raw !== '') ? max(0, (int)$follow_up_raw) : null;

            if ($template_name === '') {
                throw new Exception('TEMPLATE NAME IS REQUIRED.');
            }

            $tenant_pdo->beginTransaction();

            if ($template_id > 0) {
                $up = $tenant_pdo->prepare("
                    UPDATE prescription_template_master
                    SET
                        template_name = ?,
                        department_id = ?,
                        specialization_id = ?,
                        doctor_id = ?,
                        default_symptoms = ?,
                        default_diagnosis = ?,
                        default_investigations = ?,
                        default_advice = ?,
                        advice_id = NULL,
                        follow_up_days = ?,
                        default_vitals = ?,
                        custom_fields_schema = ?
                    WHERE template_id = ?
                      AND org_id = ?
                      AND center_id = ?
                ");

                $up->execute([
                    $template_name,
                    $department_id,
                    $specialization_id,
                    $doctor_id,
                    $default_symptoms !== '' ? $default_symptoms : null,
                    $default_diagnosis !== '' ? $default_diagnosis : null,
                    $default_investigations !== '' ? $default_investigations : null,
                    $default_advice !== '' ? $default_advice : null,
                    $follow_up_days,
                    $default_vitals !== '' ? $default_vitals : null,
                    $custom_fields_schema,
                    $template_id,
                    $org_id,
                    $center_id
                ]);

                $save_id = $template_id;

                // Rebuild medicine rows during update.
                $tenant_pdo->prepare("
                    DELETE FROM prescription_template_items
                    WHERE template_id = ?
                ")->execute([$save_id]);

            } else {
                $ins = $tenant_pdo->prepare("
                    INSERT INTO prescription_template_master
                    (
                        org_id,
                        center_id,
                        template_name,
                        department_id,
                        specialization_id,
                        doctor_id,
                        default_symptoms,
                        default_diagnosis,
                        default_investigations,
                        default_advice,
                        advice_id,
                        follow_up_days,
                        default_vitals,
                        custom_fields_schema,
                        status,
                        created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 1, ?)
                ");

                $ins->execute([
                    $org_id,
                    $center_id,
                    $template_name,
                    $department_id,
                    $specialization_id,
                    $doctor_id,
                    $default_symptoms !== '' ? $default_symptoms : null,
                    $default_diagnosis !== '' ? $default_diagnosis : null,
                    $default_investigations !== '' ? $default_investigations : null,
                    $default_advice !== '' ? $default_advice : null,
                    $follow_up_days,
                    $default_vitals !== '' ? $default_vitals : null,
                    $custom_fields_schema,
                    $user_id ?: null
                ]);

                $save_id = (int)$tenant_pdo->lastInsertId();
            }

            /* ------------------------------------------------
               SAVE MEDICINE ITEMS
               Final structure: MEDICINE / DOSE / DURATION / QTY
               ------------------------------------------------ */
            $medicine_ids = $_POST['medicine_id'] ?? [];

            $itemStmt = $tenant_pdo->prepare("
                INSERT INTO prescription_template_items
                (
                    template_id,
                    medicine_name,
                    dose,
                    route,
                    frequency,
                    timing,
                    duration,
                    quantity,
                    instructions,
                    sort_order
                )
                VALUES (?, ?, ?, NULL, NULL, NULL, ?, ?, NULL, ?)
            ");

            $sort = 1;
            foreach ($medicine_ids as $medicine_id) {
                $medicine_id = (int)$medicine_id;
                if ($medicine_id <= 0) {
                    continue;
                }

                $masterStmt = $tenant_pdo->prepare("
                    SELECT medicine_name, default_qty, duration_name
                    FROM master_medicines mm
                    LEFT JOIN master_durations md ON md.id = mm.default_duration_id
                    WHERE mm.id = ?
                      AND mm.org_id = ?
                      AND mm.center_id = ?
                      AND mm.status = 1
                    LIMIT 1
                ");
                $masterStmt->execute([$medicine_id, $org_id, $center_id]);
                $master = $masterStmt->fetch(PDO::FETCH_ASSOC);

                if (!$master) {
                    continue;
                }

                $itemStmt->execute([
                    $save_id,
                    strtoupper(trim((string)$master['medicine_name'])),
                    '',
                    trim((string)($master['duration_name'] ?? '')),
                    trim((string)($master['default_qty'] ?? '')),
                    $sort++
                ]);
            }

            $tenant_pdo->commit();

            $success = ($template_id > 0)
                ? 'PRESCRIPTION TEMPLATE UPDATED SUCCESSFULLY.'
                : 'PRESCRIPTION TEMPLATE CREATED SUCCESSFULLY.';

        } elseif ($action === 'delete') {
            $template_id = (int)($_POST['template_id'] ?? 0);

            if ($template_id > 0) {
                $stmt = $tenant_pdo->prepare("
                    DELETE FROM prescription_template_master
                    WHERE template_id = ?
                      AND org_id = ?
                      AND center_id = ?
                ");
                $stmt->execute([$template_id, $org_id, $center_id]);
            }

            $success = 'PRESCRIPTION TEMPLATE DELETED SUCCESSFULLY.';
        }

    } catch (Throwable $e) {
        if ($tenant_pdo->inTransaction()) {
            $tenant_pdo->rollBack();
        }
        $err = $e->getMessage();
    }
}

/* ==========================================================
   LOAD EXISTING MASTERS
   ========================================================== */
$departments = [];
$doctors = [];
$specializations = [];
$symptoms = [];
$diagnoses = [];
$investigations = [];
$advices = [];
$vitals = [];
$medicines = [];
$templates = [];

// Sub-masters for Medicine Add Modal
$frequencies = [];
$units = [];
$meals = [];
$durations = [];

try {
    $st = $tenant_pdo->prepare("SELECT id, dept_name FROM master_departments WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY dept_name");
    $st->execute([$org_id, $center_id]);
    $departments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, full_name, department_id FROM master_doctors WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY full_name");
    $st->execute([$org_id, $center_id]);
    $doctors = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, specialization_name FROM master_specializations WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY specialization_name");
    $st->execute([$org_id, $center_id]);
    $specializations = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, symptom_name, symptom_code FROM master_symptoms WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY symptom_name");
    $st->execute([$org_id, $center_id]);
    $symptoms = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, diagnosis_name, diagnosis_code FROM master_diagnoses WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY diagnosis_name");
    $st->execute([$org_id, $center_id]);
    $diagnoses = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, investigation_name, investigation_code, investigation_type FROM master_investigations WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY investigation_name");
    $st->execute([$org_id, $center_id]);
    $investigations = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, advice_name FROM master_advices WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY advice_name");
    $st->execute([$org_id, $center_id]);
    $advices = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, vital_name, vital_key, unit, placeholder, sort_order FROM master_vitals WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY sort_order ASC, vital_name ASC");
    $st->execute([$org_id, $center_id]);
    $vitals = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// Fetch sub-masters for the add medicine form
try {
    $st = $tenant_pdo->prepare("SELECT id, frequency_name FROM frequency_master WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY frequency_name");
    $st->execute([$org_id, $center_id]);
    $frequencies = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, unit_name FROM master_units WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY unit_name");
    $st->execute([$org_id, $center_id]);
    $units = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, meal_name FROM master_meals WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY meal_name");
    $st->execute([$org_id, $center_id]);
    $meals = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("SELECT id, duration_name FROM master_durations WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY duration_name");
    $st->execute([$org_id, $center_id]);
    $durations = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("
        SELECT
            mm.id,
            mm.medicine_name,
            mm.generic_name,
            mm.strength,
            mm.frequency_id,
            mm.unit_id,
            mm.meal_id,
            mm.default_qty,
            mm.default_duration_id,
            mm.dosage_form,
            mm.manufacturer,
            mm.category,
            mu.unit_name,
            meal.meal_name,
            md.duration_name,
            fm.frequency_name,
            mm.status AS medicine_status
        FROM master_medicines mm
        LEFT JOIN master_units mu ON mu.id = mm.unit_id
        LEFT JOIN master_meals meal ON meal.id = mm.meal_id
        LEFT JOIN master_durations md ON md.id = mm.default_duration_id
        LEFT JOIN frequency_master fm ON fm.id = mm.frequency_id
        WHERE mm.org_id = ? AND mm.center_id = ? AND mm.status = 1
        ORDER BY mm.medicine_name
    ");
    $st->execute([$org_id, $center_id]);
    $medicines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $tenant_pdo->prepare("
        SELECT
            t.*,
            dept.dept_name,
            d.full_name AS doctor_name,
            sp.specialization_name
        FROM prescription_template_master t
        LEFT JOIN master_departments dept ON dept.id = t.department_id
        LEFT JOIN master_doctors d ON d.id = t.doctor_id
        LEFT JOIN master_specializations sp ON sp.id = t.specialization_id
        WHERE t.org_id = ? AND t.center_id = ?
        ORDER BY t.status DESC, t.template_name ASC
    ");
    $st->execute([$org_id, $center_id]);
    $templates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

require_once __DIR__ . '/layout_header.php';
?>

<style>
.pt-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 3px 18px rgba(15,23,42,.07);
}

.pt-item {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px;
    background: #f8fafc;
    margin-bottom: 8px;
}

.pt-item .form-label,
.pt-default-box .form-label {
    font-size: 9px;
    font-weight: 800;
    color: #64748b;
    margin-bottom: 3px;
}

.pt-help {
    font-size: 10px;
    color: #64748b;
}

.template-list-wrap {
    max-height: 68vh;
    overflow-y: auto;
}

.template-list-wrap thead th {
    position: sticky;
    top: 0;
    z-index: 2;
}

.medicine-row .form-control,
.medicine-row .form-select {
    min-height: 31px;
    font-size: 10px;
}

.medicine-row .form-label {
    font-size: 8px;
    font-weight: 800;
    margin-bottom: 2px;
}

.medicine-readonly {
    background: #f1f5f9 !important;
    color: #334155;
}

/* DEFAULT VITALS - FIX CHECKBOX ALIGNMENT */
.vital-option {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    min-height: 58px;
    cursor: pointer;
    padding: 9px 10px !important;
}

.vital-option .template-vital-check {
    position: static !important;
    margin: 2px 0 0 0 !important;
    padding: 0 !important;
    width: 17px;
    height: 17px;
    flex: 0 0 17px;
    cursor: pointer;
}

.vital-option .form-check-label {
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1.15;
    cursor: pointer;
}

.vital-option .form-check-label span {
    margin-top: 3px;
    line-height: 1.2;
}

.vital-option:has(.template-vital-check:checked) {
    background: #eff6ff;
    border-color: #2563eb !important;
}
</style>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.pt-swal-popup { border-radius: 14px !important; }
.pt-swal-title { font-size: 20px !important; padding-top: 8px !important; }
.pt-swal-text { font-size: 13px !important; }
.pt-swal-popup .swal2-icon { transform: scale(.78); margin-top: 8px; margin-bottom: 8px; }
.pt-swal-popup .swal2-confirm { font-size: 12px !important; padding: 8px 22px !important; }
</style>

<div class="container-fluid px-0">

    <?php if ($err || $success): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof Swal === 'undefined') return;
            <?php if ($err): ?>
            Swal.fire({
                icon: 'error',
                title: 'Something went wrong',
                text: <?= json_encode($err, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                width: '340px',
                padding: '1.1em',
                confirmButtonText: 'OK',
                customClass: { popup: 'pt-swal-popup', title: 'pt-swal-title', htmlContainer: 'pt-swal-text' }
            });
            <?php elseif ($success): ?>
            Swal.fire({
                icon: 'success',
                title: 'Successful!',
                text: <?= json_encode($success, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                width: '340px',
                padding: '1.1em',
                timer: 1800,
                showConfirmButton: false,
                customClass: { popup: 'pt-swal-popup', title: 'pt-swal-title', htmlContainer: 'pt-swal-text' }
            });
            <?php endif; ?>
        });
        </script>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="fw-bold mb-0">
                <i class="bi bi-file-earmark-medical text-primary me-2"></i>
                PRESCRIPTION TEMPLATE MASTER
            </h5>
            <div class="text-muted small">
                CREATE DEPARTMENT / SPECIALIZATION / DOCTOR WISE PRESCRIPTION TEMPLATES
            </div>
        </div>

        <button type="button" class="btn btn-primary btn-sm fw-bold" onclick="openTemplateForm()">
            <i class="bi bi-plus-lg me-1"></i> NEW TEMPLATE
        </button>
    </div>

    <!-- TEMPLATE LIST -->
    <div class="card pt-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <span class="fw-bold small">
                <i class="bi bi-list-task text-primary me-1"></i>
                PRESCRIPTION TEMPLATES
            </span>
            <span class="badge bg-light text-secondary border">
                <?= count($templates) ?> RECORDS
            </span>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive template-list-wrap">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">TEMPLATE</th>
                            <th>DEPARTMENT</th>
                            <th>SPECIALIZATION</th>
                            <th>DOCTOR</th>
                            <th>FOLLOW-UP</th>
                            <th>STATUS</th>
                            <th class="text-end pe-3">ACTION</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$templates): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                NO PRESCRIPTION TEMPLATES FOUND.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($templates as $t): ?>
                            <?php $is_active = (int)$t['status'] === 1; ?>
                            <tr>
                                <td class="ps-3 fw-bold text-dark">
                                    <?= htmlspecialchars($t['template_name']) ?>
                                </td>
                                <td><?= htmlspecialchars($t['dept_name'] ?? 'ALL DEPARTMENTS') ?></td>
                                <td><?= htmlspecialchars($t['specialization_name'] ?? 'ALL SPECIALIZATIONS') ?></td>
                                <td><?= htmlspecialchars($t['doctor_name'] ?? 'ALL DOCTORS') ?></td>
                                <td>
                                    <?= $t['follow_up_days'] !== null
                                        ? ((int)$t['follow_up_days'] . ' DAYS')
                                        : 'NOT SET' ?>
                                </td>
                                <td>
                                    <span class="badge <?= $is_active
                                        ? 'bg-success-subtle text-success border border-success'
                                        : 'bg-danger-subtle text-danger border border-danger' ?>">
                                        <?= $is_active ? 'ACTIVE' : 'INACTIVE' ?>
                                    </span>
                                </td>
                                <td class="text-end pe-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 me-1" onclick="editTemplate(<?= (int)$t['template_id'] ?>)" title="EDIT">
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <form method="POST" class="d-inline" onsubmit="return confirm('DELETE THIS PRESCRIPTION TEMPLATE?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="template_id" value="<?= (int)$t['template_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="DELETE">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
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

<!-- TEMPLATE MODAL -->
<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true" style="z-index: 1055;">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold" id="templateModalTitle">
                    NEW PRESCRIPTION TEMPLATE
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" style="display: contents;">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="template_id" id="template_id" value="0">
                <input type="hidden" name="custom_fields_schema" id="custom_fields_schema" value="[]">
                <input type="hidden" name="default_vitals" id="default_vitals" value="">

                <div class="modal-body">
                    <!-- BASIC MAPPING -->
                    <div class="card border mb-3">
                        <div class="card-header bg-light py-2">
                            <div class="fw-bold small">TEMPLATE MAPPING</div>
                            <div class="pt-help">
                                MAP THIS TEMPLATE TO DEPARTMENT, SPECIALIZATION OR A SPECIFIC DOCTOR.
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">TEMPLATE NAME *</label>
                                    <input type="text" class="form-control form-control-sm text-uppercase" name="template_name" id="template_name" maxlength="150" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">DEPARTMENT</label>
                                    <select class="form-select form-select-sm" name="department_id" id="department_id">
                                        <option value="">ALL DEPARTMENTS</option>
                                        <?php foreach ($departments as $d): ?>
                                            <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['dept_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">SPECIALIZATION</label>
                                    <select class="form-select form-select-sm" name="specialization_id" id="specialization_id">
                                        <option value="">ALL SPECIALIZATIONS</option>
                                        <?php foreach ($specializations as $s): ?>
                                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['specialization_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label small fw-bold">DOCTOR</label>
                                    <select class="form-select form-select-sm" name="doctor_id" id="doctor_id">
                                        <option value="">ALL DOCTORS</option>
                                        <?php foreach ($doctors as $d): ?>
                                            <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">DEFAULT FOLLOW-UP</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" min="0" name="follow_up_days" id="follow_up_days" class="form-control">
                                        <span class="input-group-text">DAYS</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- DEFAULT VITALS -->
                    <div class="card border mb-3">
                        <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold small">DEFAULT VITALS</div>
                                <div class="pt-help">SELECT WHICH VITALS SHOULD APPEAR FOR THIS TEMPLATE IN DOCTOR DESK. VITALS ARE LOADED FROM VITAL MASTER.</div>
                            </div>
                            <div>
                                <button type="button" class="btn btn-sm btn-link py-0 px-1" onclick="refreshMasterData()" title="Refresh Masters"><i class="bi bi-arrow-clockwise"></i></button>
                                <button type="button" class="btn btn-sm btn-link py-0 px-1 text-success" onclick="openQuickAdd('vital', 'ADD NEW VITAL')" title="Add New Vital"><i class="bi bi-plus-circle"></i></button>
                            </div>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2" id="vitalsContainer">
                                <?php if (empty($vitals)): ?>
                                    <div class="text-muted small fw-semibold py-2 w-100">
                                        NO ACTIVE VITALS FOUND. PLEASE ADD VITALS IN VITAL MASTER.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($vitals as $vital): ?>
                                        <?php
                                            $vitalKey = trim((string)($vital['vital_key'] ?? ''));
                                            $vitalName = trim((string)($vital['vital_name'] ?? ''));
                                            $vitalUnit = trim((string)($vital['unit'] ?? ''));
                                            $vitalPlaceholder = trim((string)($vital['placeholder'] ?? ''));
                                            if ($vitalKey === '') {
                                                $vitalKey = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $vitalName));
                                                $vitalKey = trim($vitalKey, '_');
                                            }
                                        ?>
                                        <div class="col-6 col-md-3 col-lg-2">
                                            <div class="form-check vital-option border rounded h-100">
                                                <input class="form-check-input template-vital-check" type="checkbox" value="<?= htmlspecialchars($vitalKey, ENT_QUOTES, 'UTF-8') ?>" id="vital_<?= htmlspecialchars($vitalKey, ENT_QUOTES, 'UTF-8') ?>">
                                                <label class="form-check-label fw-bold small" for="vital_<?= htmlspecialchars($vitalKey, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($vitalName, ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if ($vitalUnit !== '' || $vitalPlaceholder !== ''): ?>
                                                        <span class="text-muted d-block" style="font-size:9px; font-weight:600;">
                                                            <?= htmlspecialchars($vitalUnit, ENT_QUOTES, 'UTF-8') ?>
                                                            <?= ($vitalUnit !== '' && $vitalPlaceholder !== '') ? ' • ' : '' ?>
                                                            <?= htmlspecialchars($vitalPlaceholder, ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- DEFAULT CLINICAL DATA -->
                    <div class="card border mb-3">
                        <div class="card-header bg-light py-2">
                            <div class="fw-bold small">DEFAULT CLINICAL DATA</div>
                            <div class="pt-help">
                                SELECT COMMON VALUES FROM MASTERS. FREE TEXT CAN ALSO BE ADDED.
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-3 pt-default-box">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label mb-0">DEFAULT SYMPTOMS / COMPLAINTS</label>
                                        <div>
                                            <button type="button" class="btn btn-sm btn-link py-0 px-1" onclick="refreshMasterData()" title="Refresh Masters"><i class="bi bi-arrow-clockwise"></i></button>
                                            <button type="button" class="btn btn-sm btn-link py-0 px-1 text-success" onclick="openQuickAdd('symptom', 'ADD NEW SYMPTOM')" title="Add New"><i class="bi bi-plus-circle"></i></button>
                                        </div>
                                    </div>
                                    <input type="text" class="form-control form-control-sm mb-1" placeholder="Search Symptoms..." onkeyup="filterSelect(this, 'symptom_master_select')">
                                    <select id="symptom_master_select" class="form-select form-select-sm" multiple size="6">
                                        <?php if (empty($symptoms)): ?><option disabled>NO SYMPTOMS FOUND</option>
                                        <?php else: ?><?php foreach ($symptoms as $s): ?><option value="<?= htmlspecialchars($s['symptom_name'], ENT_QUOTES) ?>"><?= htmlspecialchars($s['symptom_name']) ?> <?= !empty($s['symptom_code']) ? '(' . htmlspecialchars($s['symptom_code']) . ')' : '' ?></option><?php endforeach; ?><?php endif; ?>
                                    </select>
                                    <textarea name="default_symptoms" id="default_symptoms" class="form-control form-control-sm mt-2" rows="2"></textarea>
                                </div>

                                <div class="col-md-3 pt-default-box">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label mb-0">DEFAULT DIAGNOSIS</label>
                                        <div>
                                            <button type="button" class="btn btn-sm btn-link py-0 px-1" onclick="refreshMasterData()" title="Refresh Masters"><i class="bi bi-arrow-clockwise"></i></button>
                                            <button type="button" class="btn btn-sm btn-link py-0 px-1 text-success" onclick="openQuickAdd('diagnosis', 'ADD NEW DIAGNOSIS')" title="Add New"><i class="bi bi-plus-circle"></i></button>
                                        </div>
                                    </div>
                                    <input type="text" class="form-control form-control-sm mb-1" placeholder="Search Diagnosis..." onkeyup="filterSelect(this, 'diagnosis_master_select')">
                                    <select id="diagnosis_master_select" class="form-select form-select-sm" multiple size="6">
                                        <?php if (empty($diagnoses)): ?><option disabled>NO DIAGNOSES FOUND</option>
                                        <?php else: ?><?php foreach ($diagnoses as $d): ?><option value="<?= htmlspecialchars($d['diagnosis_name'], ENT_QUOTES) ?>"><?= htmlspecialchars($d['diagnosis_name']) ?> <?= !empty($d['diagnosis_code']) ? '(' . htmlspecialchars($d['diagnosis_code']) . ')' : '' ?></option><?php endforeach; ?><?php endif; ?>
                                    </select>
                                    <textarea name="default_diagnosis" id="default_diagnosis" class="form-control form-control-sm mt-2" rows="2"></textarea>
                                </div>

                                <div class="col-md-3 pt-default-box">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label mb-0">DEFAULT INVESTIGATIONS</label>
                                        <div>
                                            <button type="button" class="btn btn-sm btn-link py-0 px-1" onclick="refreshMasterData()" title="Refresh Masters"><i class="bi bi-arrow-clockwise"></i></button>
                                            <button type="button" class="btn btn-sm btn-link py-0 px-1 text-success" onclick="openQuickAdd('investigation', 'ADD NEW INVESTIGATION')" title="Add New"><i class="bi bi-plus-circle"></i></button>
                                        </div>
                                    </div>
                                    <input type="text" class="form-control form-control-sm mb-1" placeholder="Search Investigations..." onkeyup="filterSelect(this, 'investigation_master_select')">
                                    <select id="investigation_master_select" class="form-select form-select-sm" multiple size="6">
                                        <?php if (empty($investigations)): ?><option disabled>NO INVESTIGATIONS FOUND</option>
                                        <?php else: ?><?php foreach ($investigations as $i): ?><option value="<?= htmlspecialchars($i['investigation_name'], ENT_QUOTES) ?>"><?= htmlspecialchars($i['investigation_name']) ?> <?= !empty($i['investigation_code']) ? '(' . htmlspecialchars($i['investigation_code']) . ')' : '' ?></option><?php endforeach; ?><?php endif; ?>
                                    </select>
                                    <textarea name="default_investigations" id="default_investigations" class="form-control form-control-sm mt-2" rows="2"></textarea>
                                </div>

                                <div class="col-md-3 pt-default-box">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label mb-0">DEFAULT ADVICE</label>
                                        <div>
                                            <button type="button" class="btn btn-sm btn-link py-0 px-1" onclick="refreshMasterData()" title="Refresh Masters"><i class="bi bi-arrow-clockwise"></i></button>
                                            <button type="button" class="btn btn-sm btn-link py-0 px-1 text-success" onclick="openQuickAdd('advice', 'ADD NEW ADVICE')" title="Add New"><i class="bi bi-plus-circle"></i></button>
                                        </div>
                                    </div>
                                    <input type="text" class="form-control form-control-sm mb-1" placeholder="Search Advice..." onkeyup="filterSelect(this, 'advice_master_select')">
                                    <select id="advice_master_select" class="form-select form-select-sm" multiple size="6">
                                        <?php if (empty($advices)): ?><option disabled>NO ADVICES FOUND</option>
                                        <?php else: ?><?php foreach ($advices as $a): ?><option value="<?= htmlspecialchars($a['advice_name'], ENT_QUOTES) ?>"><?= htmlspecialchars($a['advice_name']) ?></option><?php endforeach; ?><?php endif; ?>
                                    </select>
                                    <textarea name="default_advice" id="default_advice" class="form-control form-control-sm mt-2" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CUSTOM CLINICAL FIELDS -->
                    <div class="card border mb-3">
                        <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold small">CUSTOM CLINICAL FIELDS</div>
                                <div class="pt-help">CREATE DEPARTMENT-SPECIFIC FIELDS SUCH AS INVESTIGATIONS, AFFECTED JOINT, GRADE, ETC.</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-dark fw-bold" onclick="addCustomFieldRow()">
                                <i class="bi bi-plus-lg me-1"></i> ADD FIELD
                            </button>
                        </div>
                        <div class="card-body p-2">
                            <div id="customFieldRows"></div>
                        </div>
                    </div>

                    <!-- DEFAULT MEDICINES -->
                    <div class="card border mb-3">
                        <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold small">DEFAULT MEDICINES</div>
                                <div class="pt-help">
                                    SELECT MEDICINE FROM MEDICINE MASTER. ALL DETAILS LOAD AUTOMATICALLY BY MEDICINE ID.
                                </div>
                            </div>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold me-1" onclick="refreshMasterData()" title="Refresh Masters">
                                    <i class="bi bi-arrow-clockwise"></i> REFRESH
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-success fw-bold me-1" onclick="openAddMedicineModal()" title="Add New Medicine">
                                    <i class="bi bi-plus-circle"></i> NEW
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="addMedicineRow()">
                                    <i class="bi bi-plus-lg me-1"></i> ADD MEDICINE
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-2">
                            <div id="medicineRows"></div>
                        </div>
                    </div>

                </div>

                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">CANCEL</button>
                    <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-save me-1"></i> SAVE TEMPLATE</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- NEW ADVANCED MEDICINE ADD MODAL -->
<div class="modal fade" id="addMedicineModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
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
                                    <?php foreach ($frequencies as $f): ?><option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['frequency_name']) ?></option><?php endforeach; ?>
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
                                    <?php foreach ($units as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['unit_name']) ?></option><?php endforeach; ?>
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
                                    <?php foreach ($meals as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['meal_name']) ?></option><?php endforeach; ?>
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
                                    <?php foreach ($durations as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['duration_name']) ?></option><?php endforeach; ?>
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

<!-- QUICK ADD NATIVE MODAL (FOR SMALLER MASTERS) -->
<div class="modal fade" id="quickAddModal" tabindex="-1" aria-hidden="true" style="z-index: 1070;">
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

<script>
let MASTER_MEDICINES = <?= json_encode($medicines, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function esc(v) {
    return String(v ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function medicineOptions(selected = '') {
    let html = '<option value="">SELECT MEDICINE</option>';
    MASTER_MEDICINES.forEach(function(m) {
        const textParts = [m.medicine_name];
        if (m.strength) textParts.push(m.strength);

        const selectedId = String(selected || '');
        const isSelected = selectedId === String(m.id);

        html += `<option value="${esc(m.id)}" ${isSelected ? 'selected' : ''}>${esc(textParts.join(' - '))}</option>`;
    });
    return html;
}

// Search functionality for Select dropdowns
function filterSelect(input, selectId) {
    const filter = input.value.toLowerCase();
    const options = document.getElementById(selectId).options;
    for (let i = 0; i < options.length; i++) {
        const text = options[i].text.toLowerCase();
        options[i].style.display = text.includes(filter) ? '' : 'none';
    }
}

// Open Quick Add Native Modal (For Symptoms, Vitals, Freq, Units, etc)
function openQuickAdd(type, title) {
    document.getElementById('quickAddTitle').textContent = title;
    document.getElementById('quick_add_type').value = type;
    document.getElementById('quick_add_name').value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('quickAddModal')).show();
    setTimeout(() => {
        document.getElementById('quick_add_name').focus();
    }, 300);
}

// Open New Advanced Medicine Modal
function openAddMedicineModal() {
    document.getElementById('newMedicineForm').reset();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addMedicineModal')).show();
    setTimeout(() => {
        document.getElementById('new_med_name').focus();
    }, 300);
}

// Save Full Advanced Medicine Data
async function saveMedicineFull() {
    const name = document.getElementById('new_med_name').value.trim();
    if(!name) {
        alert('Please enter a medicine name.');
        document.getElementById('new_med_name').focus();
        return;
    }

    const formData = new FormData();
    formData.append('type', 'medicine_full');
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
        const response = await fetch('prescription_template_master.php?ajax=quick_add', { method: 'POST', body: formData });
        const data = await response.json();
        if(data.ok) {
            bootstrap.Modal.getInstance(document.getElementById('addMedicineModal')).hide();
            document.getElementById('newMedicineForm').reset();
            refreshMasterData(); // Refresh all dropdowns
        } else {
            alert('Error: ' + data.message);
        }
    } catch(e) {
        console.error(e);
        alert('Error saving medicine.');
    }
}

// Save Quick Add Data
async function saveQuickAdd() {
    const type = document.getElementById('quick_add_type').value;
    const nameInput = document.getElementById('quick_add_name');
    const name = nameInput.value.trim();

    if(!name) {
        alert('Please enter a name.');
        nameInput.focus();
        return;
    }

    const formData = new FormData();
    formData.append('type', type);
    formData.append('name', name);

    try {
        const response = await fetch('prescription_template_master.php?ajax=quick_add', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if(data.ok) {
            nameInput.value = '';
            bootstrap.Modal.getInstance(document.getElementById('quickAddModal')).hide();
            refreshMasterData(); // Refresh dropdowns automatically
        } else {
            alert('Error: ' + data.message);
        }
    } catch(e) {
        console.error(e);
        alert('Error saving data.');
    }
}

// AJAX Master Data Refresh
async function refreshMasterData() {
    try {
        const response = await fetch('prescription_template_master.php?ajax=get_masters');
        const data = await response.json();
        if (data.ok) {
            updateSelectOptions('symptom_master_select', data.symptoms, 'symptom_name', 'symptom_code');
            updateSelectOptions('diagnosis_master_select', data.diagnoses, 'diagnosis_name', 'diagnosis_code');
            updateSelectOptions('investigation_master_select', data.investigations, 'investigation_name', 'investigation_code');
            updateSelectOptions('advice_master_select', data.advices, 'advice_name');
            
            // New Medicine form selects update
            updateSelectOptions('new_med_freq', data.frequencies, 'frequency_name', null, 'id');
            updateSelectOptions('new_med_unit', data.units, 'unit_name', null, 'id');
            updateSelectOptions('new_med_meal', data.meals, 'meal_name', null, 'id');
            updateSelectOptions('new_med_duration', data.durations, 'duration_name', null, 'id');
            
            // Render Vitals Checkboxes dynamically
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

            MASTER_MEDICINES = data.medicines;
            document.querySelectorAll('.medicine-select').forEach(sel => {
                const currentVal = sel.value;
                sel.innerHTML = medicineOptions(currentVal);
            });

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
    
    // Add default SELECT option for medicine dropdowns
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

function findMedicine(id) {
    return MASTER_MEDICINES.find(function(m) {
        return String(m.id) === String(id);
    }) || null;
}

function addMedicineRow(item = {}) {
    const wrap = document.getElementById('medicineRows');
    const row = document.createElement('div');
    row.className = 'pt-item medicine-row';

    const selectedMedicineId = item.medicine_id || item.id || '';

    row.innerHTML = `
        <input type="hidden" name="medicine_id[]" class="medicine-id" value="${esc(selectedMedicineId)}">
        <input type="hidden" name="medicine_name[]" class="medicine-name-hidden" value="${esc(item.medicine_name || '')}">

        <div class="row g-2 align-items-end mb-2">
            <div class="col-lg-4">
                <label class="form-label">MEDICINE</label>
                <select class="form-select form-select-sm medicine-select" onchange="applyMedicineDefaults(this)">
                    ${medicineOptions(selectedMedicineId)}
                </select>
            </div>
            <div class="col-lg-2">
                <label class="form-label">STRENGTH</label>
                <input type="text" class="form-control form-control-sm medicine-readonly strength-name" readonly>
            </div>
            <div class="col-lg-3">
                <label class="form-label">GENERIC / SALT NAME</label>
                <input type="text" class="form-control form-control-sm medicine-readonly generic-name" readonly>
            </div>
            <div class="col-lg-3">
                <label class="form-label">FREQUENCY</label>
                <input type="text" class="form-control form-control-sm medicine-readonly frequency-name" readonly>
            </div>
        </div>

        <div class="row g-2 align-items-end">
            <div class="col-lg-2">
                <label class="form-label">UNIT / FORM</label>
                <input type="text" class="form-control form-control-sm medicine-readonly unit-form" readonly>
            </div>
            <div class="col-lg-2">
                <label class="form-label">RELATIONAL MEAL</label>
                <input type="text" class="form-control form-control-sm medicine-readonly meal-name" readonly>
            </div>
            <div class="col-lg-2">
                <label class="form-label">DEFAULT QTY</label>
                <input type="text" class="form-control form-control-sm medicine-readonly quantity-input" readonly>
            </div>
            <div class="col-lg-2">
                <label class="form-label">DEFAULT DURATION</label>
                <input type="text" class="form-control form-control-sm medicine-readonly duration-name" readonly>
            </div>
            <div class="col-lg-2">
                <label class="form-label">MANUFACTURER</label>
                <input type="text" class="form-control form-control-sm medicine-readonly manufacturer-name" readonly>
            </div>
            <div class="col-lg-2">
                <label class="form-label">CATEGORY</label>
                <input type="text" class="form-control form-control-sm medicine-readonly category-name" readonly>
            </div>
        </div>

        <div class="row g-2 align-items-end mt-0">
            <div class="col-lg-12 text-end">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.medicine-row').remove()" title="REMOVE">
                    <i class="bi bi-trash"></i> REMOVE
                </button>
            </div>
        </div>
    `;

    wrap.appendChild(row);

    const select = row.querySelector('.medicine-select');
    if (select.value) {
        applyMedicineDefaults(select, false);
    }
}

function applyMedicineDefaults(selectEl, overwriteUserValues = true) {
    const row = selectEl.closest('.medicine-row');
    const medicine = findMedicine(selectEl.value);

    if (!medicine) {
        row.querySelector('.medicine-id').value = '';
        row.querySelector('.medicine-name-hidden').value = '';
        row.querySelector('.strength-name').value = '';
        row.querySelector('.generic-name').value = '';
        row.querySelector('.frequency-name').value = '';
        row.querySelector('.unit-form').value = '';
        row.querySelector('.meal-name').value = '';
        row.querySelector('.quantity-input').value = '';
        row.querySelector('.duration-name').value = '';
        row.querySelector('.manufacturer-name').value = '';
        row.querySelector('.category-name').value = '';
        return;
    }

    row.querySelector('.medicine-id').value = medicine.id || '';
    row.querySelector('.medicine-name-hidden').value = medicine.medicine_name || '';
    row.querySelector('.strength-name').value = medicine.strength || '';
    row.querySelector('.generic-name').value = medicine.generic_name || '';
    row.querySelector('.frequency-name').value = medicine.frequency_name || '';

    const unit = medicine.unit_name || '';
    const form = medicine.dosage_form || '';
    row.querySelector('.unit-form').value = [unit, form].filter(Boolean).join(' / ');

    row.querySelector('.meal-name').value = medicine.meal_name || '';
    row.querySelector('.quantity-input').value = medicine.default_qty || '';
    row.querySelector('.duration-name').value = medicine.duration_name || '';
    row.querySelector('.manufacturer-name').value = medicine.manufacturer || '';
    row.querySelector('.category-name').value = medicine.category || '';
}

function addCustomFieldRow(field = {}) {
    const wrap = document.getElementById('customFieldRows');
    if (!wrap) return;

    const row = document.createElement('div');
    row.className = 'pt-item custom-field-row';

    const type = field.type || 'text';
    const optionsEnabled = ['select', 'multiselect'].includes(type);

    row.innerHTML = `
        <div class="row g-2 align-items-end">
            <div class="col-lg-2">
                <label class="form-label">FIELD LABEL</label>
                <input type="text" class="form-control form-control-sm cf-label" value="${esc(field.label || '')}" placeholder="e.g. Investigation">
            </div>
            <div class="col-lg-2">
                <label class="form-label">FIELD TYPE</label>
                <select class="form-select form-select-sm cf-type" onchange="toggleCustomFieldOptions(this)">
                    <option value="text" ${type === 'text' ? 'selected' : ''}>SHORT TEXT</option>
                    <option value="textarea" ${type === 'textarea' ? 'selected' : ''}>LONG TEXT</option>
                    <option value="select" ${type === 'select' ? 'selected' : ''}>DROPDOWN</option>
                    <option value="multiselect" ${type === 'multiselect' ? 'selected' : ''}>MULTI-SELECT</option>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label">OPTIONS (IF DROPDOWN)</label>
                <input type="text" class="form-control form-control-sm cf-options" value="${esc((field.options || []).join(', '))}" placeholder="A, B, C..." ${optionsEnabled ? '' : 'disabled'}>
            </div>
            <div class="col-lg-2">
                <label class="form-label">DEFAULT VALUE</label>
                <input type="text" class="form-control form-control-sm cf-default" value="${esc(field.default_value || '')}" placeholder="PRE-FILL TEXT">
            </div>
            <div class="col-lg-1 text-center">
                <label class="form-label d-block">REQ.</label>
                <input class="form-check-input cf-req mt-2" type="checkbox" ${field.required ? 'checked' : ''}>
            </div>
            <div class="col-lg-2">
                <div class="d-flex gap-1 align-items-end">
                    <div class="w-100">
                        <label class="form-label">WIDTH</label>
                        <select class="form-select form-select-sm cf-width">
                            <option value="4" ${Number(field.grid_width) === 4 ? 'selected' : ''}>1/3 ROW</option>
                            <option value="6" ${Number(field.grid_width || 6) === 6 ? 'selected' : ''}>HALF ROW</option>
                            <option value="12" ${Number(field.grid_width) === 12 ? 'selected' : ''}>FULL ROW</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.custom-field-row').remove()" title="REMOVE">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>`;

    wrap.appendChild(row);
}

function toggleCustomFieldOptions(selectEl) {
    const input = selectEl.closest('.row').querySelector('.cf-options');
    if (input) input.disabled = !['select', 'multiselect'].includes(selectEl.value);
}

function loadCustomFields(schema) {
    const wrap = document.getElementById('customFieldRows');
    if (!wrap) return;
    wrap.innerHTML = '';

    let fields = [];
    try {
        fields = Array.isArray(schema) ? schema : JSON.parse(schema || '[]');
    } catch (e) {
        fields = [];
    }

    fields.forEach(addCustomFieldRow);
}

function getSelectedValues(selectId) {
    const el = document.getElementById(selectId);
    if (!el) return [];

    return Array.from(el.selectedOptions)
        .map(option => option.value.trim())
        .filter(Boolean);
}

function setSelectedValues(selectId, textValue) {
    const el = document.getElementById(selectId);
    if (!el) return;

    const values = String(textValue || '')
        .split(/\r?\n/)
        .map(v => v.trim())
        .filter(Boolean);

    Array.from(el.options).forEach(function(option) {
        option.selected = values.includes(option.value);
    });
}

function syncMasterTextareas() {
    const symptomValues = getSelectedValues('symptom_master_select');
    const diagnosisValues = getSelectedValues('diagnosis_master_select');
    const investigationValues = getSelectedValues('investigation_master_select');
    const adviceValues = getSelectedValues('advice_master_select');

    const symptomExtra = document.getElementById('default_symptoms').value.trim();
    const diagnosisExtra = document.getElementById('default_diagnosis').value.trim();
    const investigationExtra = document.getElementById('default_investigations').value.trim();
    const adviceExtra = document.getElementById('default_advice').value.trim();

    document.getElementById('default_symptoms').value =
        [...new Set([
            ...symptomValues,
            ...(symptomExtra ? [symptomExtra] : [])
        ])].join("\n");

    document.getElementById('default_diagnosis').value =
        [...new Set([
            ...diagnosisValues,
            ...(diagnosisExtra ? [diagnosisExtra] : [])
        ])].join("\n");

    document.getElementById('default_investigations').value =
        [...new Set([
            ...investigationValues,
            ...(investigationExtra ? [investigationExtra] : [])
        ])].join("\n");

    document.getElementById('default_advice').value =
        [...new Set([
            ...adviceValues,
            ...(adviceExtra ? [adviceExtra] : [])
        ])].join("\n");
}

function clearMasterSelections() {
    ['symptom_master_select', 'diagnosis_master_select', 'investigation_master_select', 'advice_master_select'].forEach(function(id) {
        const el = document.getElementById(id);
        if (!el) return;

        Array.from(el.options).forEach(function(option) {
            option.selected = false;
        });
    });
}

function getSelectedVitals() {
    return Array.from(document.querySelectorAll('.template-vital-check:checked'))
        .map(el => el.value)
        .filter(Boolean);
}

function setSelectedVitals(value) {
    const values = String(value || '')
        .split(/\r?\n/)
        .map(v => v.trim().toLowerCase())
        .filter(Boolean);
    document.querySelectorAll('.template-vital-check').forEach(function(el) {
        el.checked = values.includes(String(el.value).toLowerCase());
    });
}

function clearVitalSelections() {
    document.querySelectorAll('.template-vital-check').forEach(function(el) {
        el.checked = false;
    });
    const hidden = document.getElementById('default_vitals');
    if (hidden) hidden.value = '';
}

function syncVitalHidden() {
    const hidden = document.getElementById('default_vitals');
    if (hidden) hidden.value = getSelectedVitals().join('\n');
}

function resetTemplateForm() {
    document.getElementById('template_id').value = '0';
    document.getElementById('template_name').value = '';
    document.getElementById('department_id').value = '';
    document.getElementById('specialization_id').value = '';
    document.getElementById('doctor_id').value = '';
    document.getElementById('follow_up_days').value = '';

    document.getElementById('default_symptoms').value = '';
    document.getElementById('default_diagnosis').value = '';
    document.getElementById('default_investigations').value = '';
    document.getElementById('default_advice').value = '';
    clearVitalSelections();
    clearMasterSelections();

    document.getElementById('templateModalTitle').textContent = 'NEW PRESCRIPTION TEMPLATE';
    document.getElementById('medicineRows').innerHTML = '';
    document.getElementById('customFieldRows').innerHTML = '';
    document.getElementById('custom_fields_schema').value = '[]';
}

function openTemplateForm() {
    resetTemplateForm();
    addMedicineRow();

    bootstrap.Modal
        .getOrCreateInstance(document.getElementById('templateModal'))
        .show();

    setTimeout(function() {
        document.getElementById('template_name').focus();
    }, 300);
}

async function editTemplate(id) {
    try {
        const response = await fetch(
            'prescription_template_master.php?ajax=get_template&template_id=' + encodeURIComponent(id)
        );

        const data = await response.json();
        if (!data.ok) {
            alert(data.message || 'Unable to load template.');
            return;
        }

        const t = data.template;
        document.getElementById('template_id').value = t.template_id || '0';
        document.getElementById('templateModalTitle').textContent = 'EDIT PRESCRIPTION TEMPLATE';
        document.getElementById('template_name').value = t.template_name || '';
        document.getElementById('department_id').value = t.department_id || '';
        document.getElementById('specialization_id').value = t.specialization_id || '';
        document.getElementById('doctor_id').value = t.doctor_id || '';
        document.getElementById('follow_up_days').value = t.follow_up_days ?? '';
        setSelectedVitals(t.default_vitals || '');
        syncVitalHidden();

        setSelectedValues('symptom_master_select', t.default_symptoms || '');
        setSelectedValues('diagnosis_master_select', t.default_diagnosis || '');
        setSelectedValues('investigation_master_select', t.default_investigations || '');
        setSelectedValues('advice_master_select', t.default_advice || '');

        const symptomMaster = getSelectedValues('symptom_master_select');
        const diagnosisMaster = getSelectedValues('diagnosis_master_select');
        const investigationMaster = getSelectedValues('investigation_master_select');
        const adviceMaster = getSelectedValues('advice_master_select');

        const symptomStored = String(t.default_symptoms || '').split(/\r?\n/).map(v => v.trim()).filter(Boolean);
        const diagnosisStored = String(t.default_diagnosis || '').split(/\r?\n/).map(v => v.trim()).filter(Boolean);
        const investigationStored = String(t.default_investigations || '').split(/\r?\n/).map(v => v.trim()).filter(Boolean);
        const adviceStored = String(t.default_advice || '').split(/\r?\n/).map(v => v.trim()).filter(Boolean);

        document.getElementById('default_symptoms').value = symptomStored.filter(v => !symptomMaster.includes(v)).join("\n");
        document.getElementById('default_diagnosis').value = diagnosisStored.filter(v => !diagnosisMaster.includes(v)).join("\n");
        document.getElementById('default_investigations').value = investigationStored.filter(v => !investigationMaster.includes(v)).join("\n");
        document.getElementById('default_advice').value = adviceStored.filter(v => !adviceMaster.includes(v)).join("\n");

        document.getElementById('custom_fields_schema').value = t.custom_fields_schema || '[]';
        loadCustomFields(t.custom_fields_schema || '[]');

        document.getElementById('medicineRows').innerHTML = '';

        if (Array.isArray(t.items) && t.items.length) {
            t.items.forEach(function(item) { addMedicineRow(item); });
        } else {
            addMedicineRow();
        }

        bootstrap.Modal
            .getOrCreateInstance(document.getElementById('templateModal'))
            .show();

    } catch (e) {
        alert('Unable to load prescription template.');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    syncVitalHidden();
    const form = document.querySelector('#templateModal form');

    if (form) {
        form.addEventListener('submit', function() {
            syncMasterTextareas();
            syncVitalHidden();

            const customFields = [];
            document.querySelectorAll('#customFieldRows .custom-field-row').forEach(function(row) {
                const label = row.querySelector('.cf-label').value.trim();
                if (!label) return;

                customFields.push({
                    field_id: label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, ''),
                    label: label,
                    type: row.querySelector('.cf-type').value,
                    options: row.querySelector('.cf-options').value.split(',').map(function(v) { return v.trim(); }).filter(Boolean),
                    default_value: row.querySelector('.cf-default').value.trim(),
                    required: row.querySelector('.cf-req').checked,
                    grid_width: parseInt(row.querySelector('.cf-width').value || '6', 10)
                });
            });

            document.getElementById('custom_fields_schema').value = JSON.stringify(customFields);

            document
                .querySelectorAll('#medicineRows input, #medicineRows select')
                .forEach(function(el) {
                    if (typeof el.value === 'string') {
                        el.value = el.value.trim();
                    }
                });
        });
    }
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
