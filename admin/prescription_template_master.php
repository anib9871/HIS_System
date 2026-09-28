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

/*
 * TABLES USED
 * -----------
 * prescription_template_master
 * prescription_template_items
 * master_medicines
 * master_routes
 * master_frequencies
 * master_timings
 * master_durations
 * master_prescription_instructions
 * master_symptoms
 * master_diagnoses
 *
 * Department / Specialization / Doctor masters already exist in the HIS.
 */

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
                default_advice,
                follow_up_days,
                status
            FROM prescription_template_master
            WHERE template_id = ?
              AND org_id = ?
              AND center_id = ?
            LIMIT 1
        ");
        $stmt->execute([$template_id, $org_id, $center_id]);

        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            echo json_encode([
                'ok' => false,
                'message' => 'Template not found.'
            ]);
            exit;
        }

        $itemsStmt = $tenant_pdo->prepare("
            SELECT
                item_id,
                medicine_name,
                dose,
                route,
                frequency,
                timing,
                duration,
                quantity,
                instructions,
                sort_order
            FROM prescription_template_items
            WHERE template_id = ?
            ORDER BY sort_order, item_id
        ");
        $itemsStmt->execute([$template_id]);

        $template['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok' => true,
            'template' => $template
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage()
        ]);
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

            $default_symptoms  = trim($_POST['default_symptoms'] ?? '');
            $default_diagnosis = trim($_POST['default_diagnosis'] ?? '');
            $default_advice    = trim($_POST['default_advice'] ?? '');

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
                        default_advice = ?,
                        follow_up_days = ?
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
                    $default_advice !== '' ? $default_advice : null,
                    $follow_up_days,
                    $template_id,
                    $org_id,
                    $center_id
                ]);

                $save_id = $template_id;

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
                        default_advice,
                        follow_up_days,
                        status,
                        created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
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
                    $default_advice !== '' ? $default_advice : null,
                    $follow_up_days,
                    $user_id ?: null
                ]);

                $save_id = (int)$tenant_pdo->lastInsertId();
            }

            /* ------------------------------------------------
               SAVE MEDICINE ITEMS
            ------------------------------------------------ */
            $medicine_name = $_POST['medicine_name'] ?? [];
            $dose          = $_POST['dose'] ?? [];
            $route         = $_POST['route'] ?? [];
            $frequency     = $_POST['frequency'] ?? [];
            $timing        = $_POST['timing'] ?? [];
            $duration      = $_POST['duration'] ?? [];
            $quantity      = $_POST['quantity'] ?? [];
            $instructions  = $_POST['instructions'] ?? [];

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
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $max = max(
                count($medicine_name),
                count($dose),
                count($route),
                count($frequency),
                count($timing),
                count($duration),
                count($quantity),
                count($instructions)
            );

            $sort = 1;

            for ($i = 0; $i < $max; $i++) {

                $med = strtoupper(trim((string)($medicine_name[$i] ?? '')));

                if ($med === '') {
                    continue;
                }

                $itemStmt->execute([
                    $save_id,
                    $med,
                    trim((string)($dose[$i] ?? '')),
                    trim((string)($route[$i] ?? '')),
                    trim((string)($frequency[$i] ?? '')),
                    trim((string)($timing[$i] ?? '')),
                    trim((string)($duration[$i] ?? '')),
                    trim((string)($quantity[$i] ?? '')),
                    trim((string)($instructions[$i] ?? '')),
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
                $stmt->execute([
                    $template_id,
                    $org_id,
                    $center_id
                ]);
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

$departments   = [];
$doctors       = [];
$specializations = [];

$medicines     = [];
$routes        = [];
$frequencies   = [];
$timings       = [];
$durations     = [];
$instructions  = [];
$symptoms      = [];
$diagnoses     = [];
$templates     = [];

/* Department */
try {
    $st = $tenant_pdo->prepare("
        SELECT id, dept_name
        FROM master_departments
        WHERE org_id = ?
          AND center_id = ?
          AND status = 1
        ORDER BY dept_name
    ");
    $st->execute([$org_id, $center_id]);
    $departments = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Doctor */
try {
    $st = $tenant_pdo->prepare("
        SELECT id, full_name, department_id
        FROM master_doctors
        WHERE org_id = ?
          AND center_id = ?
          AND status = 1
        ORDER BY full_name
    ");
    $st->execute([$org_id, $center_id]);
    $doctors = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Specialization */
try {
    $st = $tenant_pdo->prepare("
        SELECT id, specialization_name
        FROM master_specializations
        WHERE org_id = ?
          AND center_id = ?
          AND status = 1
        ORDER BY specialization_name
    ");
    $st->execute([$org_id, $center_id]);
    $specializations = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $st = $tenant_pdo->prepare("
            SELECT id, specialization_name
            FROM master_specializations
            WHERE org_id = ?
            ORDER BY specialization_name
        ");
        $st->execute([$org_id]);
        $specializations = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {}
}

/* Medicine */
try {
    $st = $tenant_pdo->prepare("
        SELECT id, medicine_name, generic_name, brand_name, strength, dosage_form
        FROM master_medicines
        WHERE org_id = ?
          AND center_id = ?
          AND status = 1
        ORDER BY medicine_name
    ");
    $st->execute([$org_id, $center_id]);
    $medicines = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Route */
try {
    $st = $tenant_pdo->prepare("
        SELECT id, route_name, route_code
        FROM master_routes
        WHERE org_id = ?
          AND center_id = ?
          AND status = 1
        ORDER BY route_name
    ");
    $st->execute([$org_id, $center_id]);
    $routes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Frequency */
try {
    $st = $tenant_pdo->prepare("
        SELECT id, frequency_name, frequency_code
        FROM master_frequencies
        WHERE org_id = ?
          AND center_id = ?
          AND status = 1
        ORDER BY frequency_name
    ");
    $st->execute([$org_id, $center_id]);
    $frequencies = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Timing */
try {
    $st = $tenant_pdo->prepare("
        SELECT id, timing_name, timing_code
        FROM master_timings
        WHERE org_id = ?
          AND center_id = ?
          AND status = 1
        ORDER BY timing_name
    ");
    $st->execute([$org_id, $center_id]);
    $timings = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Duration */
try {
    $st = $tenant_pdo->prepare("
        SELECT id, duration_name, duration_value, duration_unit
        FROM master_durations
        WHERE org_id = ?
          AND center_id = ?
          AND status = 1
        ORDER BY id
    ");
    $st->execute([$org_id, $center_id]);
    $durations = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Instructions */
try {
    $st = $tenant_pdo->prepare("
        SELECT id, instruction_name, instruction_code, instruction_text
        FROM master_prescription_instructions
        WHERE org_id = ?
          AND center_id = ?
          AND status = 1
        ORDER BY instruction_name
    ");
    $st->execute([$org_id, $center_id]);
    $instructions = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Symptoms */
try {
    $st = $tenant_pdo->prepare("
        SELECT id, symptom_name, symptom_code
        FROM master_symptoms
        WHERE org_id = ?
          AND center_id = ?
          AND status = 1
        ORDER BY symptom_name
    ");
    $st->execute([$org_id, $center_id]);
    $symptoms = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Diagnoses */
try {
    $st = $tenant_pdo->prepare("
        SELECT id, diagnosis_name, diagnosis_code
        FROM master_diagnoses
        WHERE org_id = ?
          AND center_id = ?
          AND status = 1
        ORDER BY diagnosis_name
    ");
    $st->execute([$org_id, $center_id]);
    $diagnoses = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Templates List */
try {
    $st = $tenant_pdo->prepare("
        SELECT
            t.*,
            dept.dept_name,
            d.full_name AS doctor_name,
            sp.specialization_name
        FROM prescription_template_master t
        LEFT JOIN master_departments dept
            ON dept.id = t.department_id
        LEFT JOIN master_doctors d
            ON d.id = t.doctor_id
        LEFT JOIN master_specializations sp
            ON sp.id = t.specialization_id
        WHERE t.org_id = ?
          AND t.center_id = ?
        ORDER BY t.status DESC, t.template_name ASC
    ");
    $st->execute([$org_id, $center_id]);
    $templates = $st->fetchAll(PDO::FETCH_ASSOC);
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
</style>

<div class="container-fluid px-0">

    <?php if ($err): ?>
        <div class="alert alert-danger py-2 px-3 fw-bold">
            <?= htmlspecialchars($err) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success py-2 px-3 fw-bold">
            <?= htmlspecialchars($success) ?>
        </div>
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

        <button
            type="button"
            class="btn btn-primary btn-sm fw-bold"
            onclick="openTemplateForm()"
        >
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

                                <td>
                                    <?= htmlspecialchars($t['dept_name'] ?? 'ALL DEPARTMENTS') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($t['specialization_name'] ?? 'ALL SPECIALIZATIONS') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($t['doctor_name'] ?? 'ALL DOCTORS') ?>
                                </td>

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
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary py-0 px-2 me-1"
                                        onclick="editTemplate(<?= (int)$t['template_id'] ?>)"
                                        title="EDIT"
                                    >
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <form
                                        method="POST"
                                        class="d-inline"
                                        onsubmit="return confirm('DELETE THIS PRESCRIPTION TEMPLATE?');"
                                    >
                                        <input type="hidden" name="action" value="delete">
                                        <input
                                            type="hidden"
                                            name="template_id"
                                            value="<?= (int)$t['template_id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger py-0 px-2"
                                            title="DELETE"
                                        >
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
<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-xl modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold" id="templateModalTitle">
                    NEW PRESCRIPTION TEMPLATE
                </h6>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>
            </div>

            <form method="POST">

                <input type="hidden" name="action" value="save">
                <input type="hidden" name="template_id" id="template_id" value="0">

                <div class="modal-body">

                    <!-- BASIC MAPPING -->
                    <div class="card border mb-3">
                        <div class="card-header bg-light py-2">
                            <div class="fw-bold small">
                                TEMPLATE MAPPING
                            </div>

                            <div class="pt-help">
                                MAP THIS TEMPLATE TO DEPARTMENT, SPECIALIZATION OR A SPECIFIC DOCTOR.
                            </div>
                        </div>

                        <div class="card-body">

                            <div class="row g-2">

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">
                                        TEMPLATE NAME *
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control form-control-sm text-uppercase"
                                        name="template_name"
                                        id="template_name"
                                        maxlength="150"
                                        required
                                        placeholder="E.G. CARDIO FOLLOW-UP"
                                    >
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">
                                        DEPARTMENT
                                    </label>

                                    <select
                                        class="form-select form-select-sm"
                                        name="department_id"
                                        id="department_id"
                                    >
                                        <option value="">ALL DEPARTMENTS</option>

                                        <?php foreach ($departments as $d): ?>
                                            <option value="<?= (int)$d['id'] ?>">
                                                <?= htmlspecialchars($d['dept_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">
                                        SPECIALIZATION
                                    </label>

                                    <select
                                        class="form-select form-select-sm"
                                        name="specialization_id"
                                        id="specialization_id"
                                    >
                                        <option value="">ALL SPECIALIZATIONS</option>

                                        <?php foreach ($specializations as $s): ?>
                                            <option value="<?= (int)$s['id'] ?>">
                                                <?= htmlspecialchars($s['specialization_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-8">
                                    <label class="form-label small fw-bold">
                                        DOCTOR
                                    </label>

                                    <select
                                        class="form-select form-select-sm"
                                        name="doctor_id"
                                        id="doctor_id"
                                    >
                                        <option value="">ALL DOCTORS</option>

                                        <?php foreach ($doctors as $d): ?>
                                            <option value="<?= (int)$d['id'] ?>">
                                                <?= htmlspecialchars($d['full_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">
                                        DEFAULT FOLLOW-UP
                                    </label>

                                    <div class="input-group input-group-sm">
                                        <input
                                            type="number"
                                            min="0"
                                            name="follow_up_days"
                                            id="follow_up_days"
                                            class="form-control"
                                            placeholder="E.G. 7"
                                        >
                                        <span class="input-group-text">DAYS</span>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- DEFAULT CLINICAL DATA -->
                    <div class="card border mb-3">
                        <div class="card-header bg-light py-2">
                            <div class="fw-bold small">
                                DEFAULT CLINICAL DATA
                            </div>

                            <div class="pt-help">
                                SELECT COMMON VALUES FROM MASTERS. FREE TEXT CAN ALSO BE ADDED.
                            </div>
                        </div>

                        <div class="card-body">

                            <div class="row g-2">

                                <!-- SYMPTOMS -->
                                <div class="col-md-4 pt-default-box">

                                    <label class="form-label">
                                        DEFAULT SYMPTOMS / COMPLAINTS
                                    </label>

                                    <select
                                        id="symptom_master_select"
                                        class="form-select form-select-sm"
                                        multiple
                                        size="6"
                                    >
                                        <?php foreach ($symptoms as $s): ?>
                                            <option value="<?= htmlspecialchars($s['symptom_name'], ENT_QUOTES) ?>">
                                                <?= htmlspecialchars($s['symptom_name']) ?>
                                                <?php if (!empty($s['symptom_code'])): ?>
                                                    (<?= htmlspecialchars($s['symptom_code']) ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <textarea
                                        name="default_symptoms"
                                        id="default_symptoms"
                                        class="form-control form-control-sm mt-2"
                                        rows="2"
                                        placeholder="OPTIONAL ADDITIONAL SYMPTOMS..."
                                    ></textarea>

                                </div>

                                <!-- DIAGNOSIS -->
                                <div class="col-md-4 pt-default-box">

                                    <label class="form-label">
                                        DEFAULT DIAGNOSIS
                                    </label>

                                    <select
                                        id="diagnosis_master_select"
                                        class="form-select form-select-sm"
                                        multiple
                                        size="6"
                                    >
                                        <?php foreach ($diagnoses as $d): ?>
                                            <option value="<?= htmlspecialchars($d['diagnosis_name'], ENT_QUOTES) ?>">
                                                <?= htmlspecialchars($d['diagnosis_name']) ?>
                                                <?php if (!empty($d['diagnosis_code'])): ?>
                                                    (<?= htmlspecialchars($d['diagnosis_code']) ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <textarea
                                        name="default_diagnosis"
                                        id="default_diagnosis"
                                        class="form-control form-control-sm mt-2"
                                        rows="2"
                                        placeholder="OPTIONAL ADDITIONAL DIAGNOSIS..."
                                    ></textarea>

                                </div>

                                <!-- ADVICE -->
                                <div class="col-md-4 pt-default-box">

                                    <label class="form-label">
                                        DEFAULT ADVICE / INSTRUCTIONS
                                    </label>

                                    <select
                                        id="instruction_master_select"
                                        class="form-select form-select-sm"
                                        multiple
                                        size="6"
                                    >
                                        <?php foreach ($instructions as $ins): ?>
                                            <option value="<?= htmlspecialchars($ins['instruction_text'], ENT_QUOTES) ?>">
                                                <?= htmlspecialchars($ins['instruction_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <textarea
                                        name="default_advice"
                                        id="default_advice"
                                        class="form-control form-control-sm mt-2"
                                        rows="2"
                                        placeholder="OPTIONAL ADDITIONAL ADVICE..."
                                    ></textarea>

                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- DEFAULT MEDICINES -->
                    <div class="card border mb-2">

                        <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">

                            <div>
                                <div class="fw-bold small">
                                    DEFAULT MEDICINES
                                </div>

                                <div class="pt-help">
                                    THESE MEDICINES WILL LOAD AUTOMATICALLY WHEN THE TEMPLATE IS APPLIED.
                                </div>
                            </div>

                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary fw-bold"
                                onclick="addItemRow()"
                            >
                                <i class="bi bi-plus-lg me-1"></i>
                                ADD MEDICINE
                            </button>

                        </div>

                        <div class="card-body p-2">
                            <div id="itemRows"></div>
                        </div>
                    </div>

                </div>

                <div class="modal-footer py-2">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        CANCEL
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary fw-bold"
                    >
                        <i class="bi bi-save me-1"></i>
                        SAVE TEMPLATE
                    </button>

                </div>

            </form>

        </div>
    </div>
</div>

<script>
const MASTER_MEDICINE_OPTIONS = `
    <option value="">SELECT MEDICINE</option>
    <?php foreach ($medicines as $m): ?>
        <option value="<?= htmlspecialchars($m['medicine_name'], ENT_QUOTES) ?>">
            <?= htmlspecialchars($m['medicine_name']) ?>
            <?php if (!empty($m['strength'])): ?>
                - <?= htmlspecialchars($m['strength']) ?>
            <?php endif; ?>
            <?php if (!empty($m['dosage_form'])): ?>
                - <?= htmlspecialchars($m['dosage_form']) ?>
            <?php endif; ?>
        </option>
    <?php endforeach; ?>
`;

const MASTER_ROUTE_OPTIONS = `
    <option value="">SELECT ROUTE</option>
    <?php foreach ($routes as $r): ?>
        <option value="<?= htmlspecialchars($r['route_name'], ENT_QUOTES) ?>">
            <?= htmlspecialchars($r['route_name']) ?>
            <?php if (!empty($r['route_code'])): ?>
                (<?= htmlspecialchars($r['route_code']) ?>)
            <?php endif; ?>
        </option>
    <?php endforeach; ?>
`;

const MASTER_FREQUENCY_OPTIONS = `
    <option value="">SELECT FREQUENCY</option>
    <?php foreach ($frequencies as $f): ?>
        <option value="<?= htmlspecialchars($f['frequency_name'], ENT_QUOTES) ?>">
            <?= htmlspecialchars($f['frequency_name']) ?>
            <?php if (!empty($f['frequency_code'])): ?>
                (<?= htmlspecialchars($f['frequency_code']) ?>)
            <?php endif; ?>
        </option>
    <?php endforeach; ?>
`;

const MASTER_TIMING_OPTIONS = `
    <option value="">SELECT TIMING</option>
    <?php foreach ($timings as $t): ?>
        <option value="<?= htmlspecialchars($t['timing_name'], ENT_QUOTES) ?>">
            <?= htmlspecialchars($t['timing_name']) ?>
            <?php if (!empty($t['timing_code'])): ?>
                (<?= htmlspecialchars($t['timing_code']) ?>)
            <?php endif; ?>
        </option>
    <?php endforeach; ?>
`;

const MASTER_DURATION_OPTIONS = `
    <option value="">SELECT DURATION</option>
    <?php foreach ($durations as $d): ?>
        <option value="<?= htmlspecialchars($d['duration_name'], ENT_QUOTES) ?>">
            <?= htmlspecialchars($d['duration_name']) ?>
        </option>
    <?php endforeach; ?>
`;

const MASTER_INSTRUCTION_OPTIONS = `
    <option value="">SELECT INSTRUCTION</option>
    <?php foreach ($instructions as $ins): ?>
        <option value="<?= htmlspecialchars($ins['instruction_text'], ENT_QUOTES) ?>">
            <?= htmlspecialchars($ins['instruction_name']) ?>
        </option>
    <?php endforeach; ?>
`;

function esc(v) {
    return String(v ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function addItemRow(item = {}) {

    const wrap = document.getElementById('itemRows');

    const row = document.createElement('div');
    row.className = 'pt-item';

    row.innerHTML = `
        <div class="row g-2 align-items-end">

            <div class="col-lg-2">
                <label class="form-label">MEDICINE</label>
                <select
                    name="medicine_name[]"
                    class="form-select form-select-sm medicine-select"
                >${MASTER_MEDICINE_OPTIONS}</select>
            </div>

            <div class="col-lg-1">
                <label class="form-label">DOSE</label>
                <input
                    type="text"
                    name="dose[]"
                    class="form-control form-control-sm"
                    value="${esc(item.dose || '')}"
                    placeholder="1 TAB"
                >
            </div>

            <div class="col-lg-1">
                <label class="form-label">ROUTE</label>
                <select
                    name="route[]"
                    class="form-select form-select-sm route-select"
                >${MASTER_ROUTE_OPTIONS}</select>
            </div>

            <div class="col-lg-2">
                <label class="form-label">FREQUENCY</label>
                <select
                    name="frequency[]"
                    class="form-select form-select-sm frequency-select"
                >${MASTER_FREQUENCY_OPTIONS}</select>
            </div>

            <div class="col-lg-2">
                <label class="form-label">TIMING</label>
                <select
                    name="timing[]"
                    class="form-select form-select-sm timing-select"
                >${MASTER_TIMING_OPTIONS}</select>
            </div>

            <div class="col-lg-1">
                <label class="form-label">DURATION</label>
                <select
                    name="duration[]"
                    class="form-select form-select-sm duration-select"
                >${MASTER_DURATION_OPTIONS}</select>
            </div>

            <div class="col-lg-1">
                <label class="form-label">QTY</label>
                <input
                    type="text"
                    name="quantity[]"
                    class="form-control form-control-sm"
                    value="${esc(item.quantity || '')}"
                    placeholder="10"
                >
            </div>

            <div class="col-lg-2">
                <label class="form-label">INSTRUCTION</label>

                <div class="d-flex gap-1">
                    <select
                        name="instructions[]"
                        class="form-select form-select-sm instruction-select"
                    >${MASTER_INSTRUCTION_OPTIONS}</select>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-danger"
                        onclick="this.closest('.pt-item').remove()"
                        title="REMOVE"
                    >
                        <i class="bi bi-trash"></i>
                    </button>
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

function getSelectedValues(selectId) {

    const el = document.getElementById(selectId);

    if (!el) {
        return [];
    }

    return Array.from(el.selectedOptions)
        .map(option => option.value.trim())
        .filter(Boolean);
}

function setSelectedValues(selectId, textValue) {

    const el = document.getElementById(selectId);

    if (!el) {
        return;
    }

    const values = String(textValue || '')
        .split(/\r?\n/)
        .map(v => v.trim())
        .filter(Boolean);

    Array.from(el.options).forEach(option => {
        option.selected = values.includes(option.value);
    });
}

function syncMasterTextareas() {

    const symptomValues = getSelectedValues('symptom_master_select');
    const diagnosisValues = getSelectedValues('diagnosis_master_select');
    const instructionValues = getSelectedValues('instruction_master_select');

    const symptomExtra = document.getElementById('default_symptoms').value.trim();
    const diagnosisExtra = document.getElementById('default_diagnosis').value.trim();
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

    document.getElementById('default_advice').value =
        [...new Set([
            ...instructionValues,
            ...(adviceExtra ? [adviceExtra] : [])
        ])].join("\n");
}

function clearMasterSelections() {

    [
        'symptom_master_select',
        'diagnosis_master_select',
        'instruction_master_select'
    ].forEach(function(id) {

        const el = document.getElementById(id);

        if (!el) {
            return;
        }

        Array.from(el.options).forEach(function(option) {
            option.selected = false;
        });
    });
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
    document.getElementById('default_advice').value = '';

    clearMasterSelections();

    document.getElementById('templateModalTitle').textContent =
        'NEW PRESCRIPTION TEMPLATE';

    document.getElementById('itemRows').innerHTML = '';
}

function openTemplateForm() {

    resetTemplateForm();

    addItemRow();

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
            'prescription_template_master.php?ajax=get_template&template_id=' +
            encodeURIComponent(id)
        );

        const data = await response.json();

        if (!data.ok) {
            alert(data.message || 'Unable to load template.');
            return;
        }

        const t = data.template;

        document.getElementById('template_id').value =
            t.template_id || '0';

        document.getElementById('templateModalTitle').textContent =
            'EDIT PRESCRIPTION TEMPLATE';

        document.getElementById('template_name').value =
            t.template_name || '';

        document.getElementById('department_id').value =
            t.department_id || '';

        document.getElementById('specialization_id').value =
            t.specialization_id || '';

        document.getElementById('doctor_id').value =
            t.doctor_id || '';

        document.getElementById('follow_up_days').value =
            t.follow_up_days ?? '';

        document.getElementById('default_symptoms').value = '';
        document.getElementById('default_diagnosis').value = '';
        document.getElementById('default_advice').value = '';

        setSelectedValues(
            'symptom_master_select',
            t.default_symptoms || ''
        );

        setSelectedValues(
            'diagnosis_master_select',
            t.default_diagnosis || ''
        );

        setSelectedValues(
            'instruction_master_select',
            t.default_advice || ''
        );

        /* Put non-master text back into textareas after master selections */
        const symptomMaster = getSelectedValues('symptom_master_select');
        const diagnosisMaster = getSelectedValues('diagnosis_master_select');
        const instructionMaster = getSelectedValues('instruction_master_select');

        const symptomStored = String(t.default_symptoms || '')
            .split(/\r?\n/)
            .map(v => v.trim())
            .filter(Boolean);

        const diagnosisStored = String(t.default_diagnosis || '')
            .split(/\r?\n/)
            .map(v => v.trim())
            .filter(Boolean);

        const adviceStored = String(t.default_advice || '')
            .split(/\r?\n/)
            .map(v => v.trim())
            .filter(Boolean);

        document.getElementById('default_symptoms').value =
            symptomStored.filter(v => !symptomMaster.includes(v)).join("\n");

        document.getElementById('default_diagnosis').value =
            diagnosisStored.filter(v => !diagnosisMaster.includes(v)).join("\n");

        document.getElementById('default_advice').value =
            adviceStored.filter(v => !instructionMaster.includes(v)).join("\n");

        const rows = document.getElementById('itemRows');

        rows.innerHTML = '';

        (t.items || []).forEach(function(item) {
            addItemRow(item);
        });

        if (!(t.items || []).length) {
            addItemRow();
        }

        bootstrap.Modal
            .getOrCreateInstance(document.getElementById('templateModal'))
            .show();

    } catch (e) {

        alert('Unable to load prescription template.');

    }
}

/* FORM SUBMIT */
document.addEventListener('DOMContentLoaded', function() {

    const form = document.querySelector('#templateModal form');

    if (form) {

        form.addEventListener('submit', function() {

            syncMasterTextareas();

            document
                .querySelectorAll('#itemRows input')
                .forEach(function(input) {
                    input.value = input.value.trim();
                });

        });

    }

});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
