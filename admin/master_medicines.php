<?php
// admin/master_medicines.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Medicine Master";
$org_id     = (int)($_SESSION['org_id'] ?? 1);
$center_id  = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

// ---------------------------------------------------------
// SAVE / UPDATE
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_medicine'])) {

    $medicine_name       = strtoupper(trim($_POST['medicine_name'] ?? ''));
    $generic_name        = strtoupper(trim($_POST['generic_name'] ?? ''));
    $strength            = strtoupper(trim($_POST['strength'] ?? ''));
    $unit_id             = (int)($_POST['unit_id'] ?? 0);
    $frequency_id        = (int)($_POST['frequency_id'] ?? 0);
    $meal_id             = (int)($_POST['meal_id'] ?? 0);
    $default_qty         = strtoupper(trim($_POST['default_qty'] ?? ''));
    $default_duration_id = (int)($_POST['default_duration_id'] ?? 0);
    $manufacturer        = strtoupper(trim($_POST['manufacturer'] ?? ''));
    $category            = strtoupper(trim($_POST['category'] ?? ''));
    $status              = isset($_POST['status']) ? 1 : 0;
    $edit_id             = (int)($_POST['edit_id'] ?? 0);

    if ($medicine_name === '') {
        set_flash_err("Medicine Name is required.");
    } else {
        try {
            if ($edit_id > 0) {

                $stmt = $tenant_pdo->prepare("
                    UPDATE master_medicines
                    SET medicine_name = ?,
                        generic_name = ?,
                        strength = ?,
                        unit_id = ?,
                        frequency_id = ?,
                        meal_id = ?,
                        default_qty = ?,
                        default_duration_id = ?,
                        manufacturer = ?,
                        category = ?,
                        status = ?
                    WHERE id = ?
                      AND org_id = ?
                      AND center_id = ?
                ");

                $stmt->execute([
                    $medicine_name,
                    $generic_name !== '' ? $generic_name : null,
                    $strength !== '' ? $strength : null,
                    $unit_id > 0 ? $unit_id : null,
                    $frequency_id > 0 ? $frequency_id : null,
                    $meal_id > 0 ? $meal_id : null,
                    $default_qty !== '' ? $default_qty : null,
                    $default_duration_id > 0 ? $default_duration_id : null,
                    $manufacturer !== '' ? $manufacturer : null,
                    $category !== '' ? $category : null,
                    $status,
                    $edit_id,
                    $org_id,
                    $center_id
                ]);

                set_flash_msg("Medicine updated successfully.");

            } else {

                // FIXED: 14 Columns, 14 Question Marks (?)
                $stmt = $tenant_pdo->prepare("
                    INSERT INTO master_medicines
                    (
                        org_id,
                        center_id,
                        medicine_name,
                        generic_name,
                        strength,
                        unit_id,
                        frequency_id,
                        meal_id,
                        default_qty,
                        default_duration_id,
                        manufacturer,
                        category,
                        status,
                        created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $org_id,
                    $center_id,
                    $medicine_name,
                    $generic_name !== '' ? $generic_name : null,
                    $strength !== '' ? $strength : null,
                    $unit_id > 0 ? $unit_id : null,
                    $frequency_id > 0 ? $frequency_id : null,
                    $meal_id > 0 ? $meal_id : null,
                    $default_qty !== '' ? $default_qty : null,
                    $default_duration_id > 0 ? $default_duration_id : null,
                    $manufacturer !== '' ? $manufacturer : null,
                    $category !== '' ? $category : null,
                    $status,
                    $created_by ?: null
                ]);

                set_flash_msg("Medicine added successfully.");
            }

            header("Location: master_medicines.php");
            exit;

        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------
// TOGGLE STATUS
// ---------------------------------------------------------
if (isset($_GET['toggle_status'])) {

    $id = (int)$_GET['toggle_status'];
    $st = ((int)($_GET['st'] ?? 1) === 1) ? 0 : 1;

    $tenant_pdo->prepare("
        UPDATE master_medicines
        SET status = ?
        WHERE id = ?
          AND org_id = ?
          AND center_id = ?
    ")->execute([$st, $id, $org_id, $center_id]);

    header("Location: master_medicines.php");
    exit;
}

// ---------------------------------------------------------
// MASTER DROPDOWNS
// ---------------------------------------------------------
$unitsStmt = $tenant_pdo->prepare("
    SELECT id, unit_name, status
    FROM master_units
    WHERE org_id = ?
      AND center_id = ?
    ORDER BY unit_name ASC
");
$unitsStmt->execute([$org_id, $center_id]);
$units = $unitsStmt->fetchAll() ?: [];

$freqStmt = $tenant_pdo->prepare("
    SELECT id, frequency_name, status
    FROM frequency_master
    WHERE org_id = ?
      AND center_id = ?
    ORDER BY frequency_name ASC
");
$freqStmt->execute([$org_id, $center_id]);
$frequencies = $freqStmt->fetchAll() ?: [];

$mealsStmt = $tenant_pdo->prepare("
    SELECT id, meal_name, status
    FROM master_meals
    WHERE org_id = ?
      AND center_id = ?
    ORDER BY meal_name ASC
");
$mealsStmt->execute([$org_id, $center_id]);
$meals = $mealsStmt->fetchAll() ?: [];

$durationsStmt = $tenant_pdo->prepare("
    SELECT id, duration_name, status
    FROM master_durations
    WHERE org_id = ?
      AND center_id = ?
    ORDER BY duration_name ASC
");
$durationsStmt->execute([$org_id, $center_id]);
$durations = $durationsStmt->fetchAll() ?: [];

// ---------------------------------------------------------
// LIST
// ---------------------------------------------------------
$medicinesStmt = $tenant_pdo->prepare("
    SELECT
        m.*,
        u.unit_name,
        f.frequency_name,
        ml.meal_name,
        d.duration_name
    FROM master_medicines m
    LEFT JOIN master_units u
        ON u.id = m.unit_id
       AND u.org_id = m.org_id
       AND u.center_id = m.center_id
    LEFT JOIN frequency_master f
        ON f.id = m.frequency_id
       AND f.org_id = m.org_id
       AND f.center_id = m.center_id
    LEFT JOIN master_meals ml
        ON ml.id = m.meal_id
       AND ml.org_id = m.org_id
       AND ml.center_id = m.center_id
    LEFT JOIN master_durations d
        ON d.id = m.default_duration_id
       AND d.org_id = m.org_id
       AND d.center_id = m.center_id
    WHERE m.org_id = ?
      AND m.center_id = ?
    ORDER BY m.id DESC
");
$medicinesStmt->execute([$org_id, $center_id]);
$medicines = $medicinesStmt->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">

    <!-- FORM -->
    <div class="col-lg-5">
        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-capsule text-primary me-1"></i> ADD MEDICINE
                </span>
            </div>

            <div class="card-body p-3">

                <form method="POST" action="">
                    <input type="hidden" name="action_medicine" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold text-secondary mb-1">MEDICINE NAME *</label>
                            <input
                                type="text"
                                name="medicine_name"
                                id="medicine_name"
                                class="form-control form-control-sm text-uppercase"
                                placeholder=""
                                maxlength="200"
                                required
                                autofocus
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">STRENGTH</label>
                            <input
                                type="text"
                                name="strength"
                                id="strength"
                                class="form-control form-control-sm text-uppercase"
                                placeholder=""
                                maxlength="100"
                            >
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">GENERIC / SALT NAME</label>
                            <input
                                type="text"
                                name="generic_name"
                                id="generic_name"
                                class="form-control form-control-sm text-uppercase"
                                placeholder=""
                                maxlength="200"
                            >
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">FREQUENCY</label>
                            <select name="frequency_id" id="frequency_id" class="form-select form-select-sm">
                                <option value="">SELECT FREQUENCY</option>
                                <?php foreach ($frequencies as $f): ?>
                                    <option value="<?= (int)$f['id'] ?>" <?= (int)$f['status'] !== 1 ? 'class="text-muted"' : '' ?>>
                                        <?= htmlspecialchars($f['frequency_name']) ?><?= (int)$f['status'] !== 1 ? ' (INACTIVE)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">UNIT / FORM</label>
                            <select name="unit_id" id="unit_id" class="form-select form-select-sm">
                                <option value="">SELECT UNIT</option>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= (int)$u['status'] !== 1 ? 'class="text-muted"' : '' ?>>
                                        <?= htmlspecialchars($u['unit_name']) ?><?= (int)$u['status'] !== 1 ? ' (INACTIVE)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">RELATIONAL MEAL</label>
                            <select name="meal_id" id="meal_id" class="form-select form-select-sm">
                                <option value="">SELECT MEAL</option>
                                <?php foreach ($meals as $ml): ?>
                                    <option value="<?= (int)$ml['id'] ?>">
                                        <?= htmlspecialchars($ml['meal_name']) ?><?= (int)$ml['status'] !== 1 ? ' (INACTIVE)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">DEFAULT QTY</label>
                            <input
                                type="text"
                                name="default_qty"
                                id="default_qty"
                                class="form-control form-control-sm text-uppercase"
                                placeholder="E.G. 1"
                                maxlength="50"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">DEFAULT DURATION</label>
                            <select name="default_duration_id" id="default_duration_id" class="form-select form-select-sm">
                                <option value="">SELECT DURATION</option>
                                <?php foreach ($durations as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>">
                                        <?= htmlspecialchars($d['duration_name']) ?><?= (int)$d['status'] !== 1 ? ' (INACTIVE)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold text-secondary mb-1">MANUFACTURER</label>
                            <input
                                type="text"
                                name="manufacturer"
                                id="manufacturer"
                                class="form-control form-control-sm text-uppercase"
                                placeholder=""
                                maxlength="200"
                            >
                        </div>

                        <div class="col-md-5">
                            <label class="form-label small fw-semibold text-secondary mb-1">CATEGORY</label>
                            <input
                                type="text"
                                name="category"
                                id="category"
                                class="form-control form-control-sm text-uppercase"
                                placeholder=""
                                maxlength="100"
                            >
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center border-top pt-2">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                            <label class="form-check-label small fw-semibold text-secondary" for="status">
                                ACTIVE STATUS
                            </label>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">
                                RESET
                            </button>
                            <button type="submit" class="btn btn-primary btn-sm px-4 fw-semibold" id="btnSubmit">
                                SAVE MEDICINE
                            </button>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <!-- LIST -->
    <div class="col-lg-7">
        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark">
                    <i class="bi bi-list-task text-primary me-1"></i> MEDICINES LIST
                </span>

                <span class="badge bg-light text-secondary border">
                    <?= count($medicines) ?> RECORDS
                </span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:70vh; overflow-y:auto;">
                    <table class="table table-hover align-middle mb-0" style="font-size:11px;">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3" style="width:45px;">#</th>
                                <th>MEDICINE</th>
                                <th>GENERIC / SALT</th>
                                <th>STRENGTH</th>
                                <th>UNIT</th>
                                <th>FREQ</th>
                                <th>MEAL</th>
                                <th>QTY</th>
                                <th>DURATION</th>
                                <th>STATUS</th>
                                <th class="text-end pe-3">ACTION</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (empty($medicines)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-4 text-muted">
                                    NO MEDICINES FOUND.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($medicines as $m): ?>
                                <?php $is_act = (int)$m['status'] === 1; ?>
                                <tr>
                                    <td class="ps-3 font-monospace">#<?= (int)$m['id'] ?></td>

                                    <td>
                                        <div class="fw-bold text-dark text-uppercase">
                                            <?= htmlspecialchars($m['medicine_name']) ?>
                                        </div>
                                    </td>

                                    <td class="text-uppercase">
                                        <?= htmlspecialchars($m['generic_name'] ?? '') ?: '-' ?>
                                    </td>

                                    <td class="font-monospace">
                                        <?= htmlspecialchars($m['strength'] ?? '') ?: '-' ?>
                                    </td>

                                    <td class="text-uppercase">
                                        <?= htmlspecialchars($m['unit_name'] ?? '') ?: '-' ?>
                                    </td>
                                    
                                    <td class="text-uppercase">
                                        <?= htmlspecialchars($m['frequency_name'] ?? '') ?: '-' ?>
                                    </td>

                                    <td class="text-uppercase">
                                        <?= htmlspecialchars($m['meal_name'] ?? '') ?: '-' ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($m['default_qty'] ?? '') ?: '-' ?>
                                    </td>

                                    <td class="text-uppercase">
                                        <?= htmlspecialchars($m['duration_name'] ?? '') ?: '-' ?>
                                    </td>

                                    <td>
                                        <a
                                            href="?toggle_status=<?= (int)$m['id'] ?>&st=<?= $is_act ? 1 : 0 ?>"
                                            class="badge text-decoration-none <?= $is_act
                                                ? 'bg-success-subtle text-success border border-success'
                                                : 'bg-danger-subtle text-danger border border-danger' ?>"
                                        >
                                            <?= $is_act ? 'ACTIVE' : 'INACTIVE' ?>
                                        </a>
                                    </td>

                                    <td class="text-end pe-3">
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm py-0 px-2"
                                            onclick='editRow(<?= json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                            title="EDIT"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>
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
function editRow(d) {
    document.getElementById('edit_id').value = d.id || '0';
    document.getElementById('medicine_name').value = d.medicine_name || '';
    document.getElementById('generic_name').value = d.generic_name || '';
    document.getElementById('strength').value = d.strength || '';
    document.getElementById('unit_id').value = d.unit_id || '';
    document.getElementById('frequency_id').value = d.frequency_id || '';
    document.getElementById('meal_id').value = d.meal_id || '';
    document.getElementById('default_qty').value = d.default_qty || '';
    document.getElementById('default_duration_id').value = d.default_duration_id || '';
    document.getElementById('manufacturer').value = d.manufacturer || '';
    document.getElementById('category').value = d.category || '';
    document.getElementById('status').checked = (parseInt(d.status) === 1);

    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT MEDICINE';

    document.getElementById('btnSubmit').innerText = 'UPDATE MEDICINE';
    document.getElementById('medicine_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('frequency_id').value = '';

    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-capsule text-primary me-1"></i> ADD MEDICINE';

    document.getElementById('btnSubmit').innerText = 'SAVE MEDICINE';

    document.getElementById('status').checked = true;
    document.getElementById('medicine_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
