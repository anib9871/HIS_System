<?php
// admin/master_medicines.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Medicine Master";
$org_id     = (int)($_SESSION['org_id'] ?? 1);
$center_id  = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

// ---------------------------------------------------------
// AJAX QUICK ADD MASTER DATA
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_quick_add'])) {
    header('Content-Type: application/json');
    $type = $_POST['type'] ?? '';
    $name = strtoupper(trim($_POST['name'] ?? ''));

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Name cannot be empty.']);
        exit;
    }

    try {
        $table = '';
        $col = '';
        if ($type === 'unit') { $table = 'master_units'; $col = 'unit_name'; }
        elseif ($type === 'frequency') { $table = 'frequency_master'; $col = 'frequency_name'; }
        elseif ($type === 'meal') { $table = 'master_meals'; $col = 'meal_name'; }
        elseif ($type === 'duration') { $table = 'master_durations'; $col = 'duration_name'; }
        else { throw new Exception("Invalid master type."); }

        $stmt = $tenant_pdo->prepare("INSERT INTO {$table} (org_id, center_id, {$col}, status, created_by) VALUES (?, ?, ?, 1, ?)");
        $stmt->execute([$org_id, $center_id, $name, $created_by ?: null]);
        $new_id = $tenant_pdo->lastInsertId();

        echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name, 'type' => $type]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------
// AJAX REFRESH DROPDOWNS
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_refresh'])) {
    header('Content-Type: application/json');
    $type = $_POST['type'] ?? '';
    $data = [];
    try {
        if ($type === 'unit') {
            $data = $tenant_pdo->query("SELECT id, unit_name as name, status FROM master_units WHERE org_id = $org_id AND center_id = $center_id ORDER BY unit_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($type === 'frequency') {
            $data = $tenant_pdo->query("SELECT id, frequency_name as name, status FROM frequency_master WHERE org_id = $org_id AND center_id = $center_id ORDER BY frequency_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($type === 'meal') {
            $data = $tenant_pdo->query("SELECT id, meal_name as name, status FROM master_meals WHERE org_id = $org_id AND center_id = $center_id ORDER BY meal_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($type === 'duration') {
            $data = $tenant_pdo->query("SELECT id, duration_name as name, status FROM master_durations WHERE org_id = $org_id AND center_id = $center_id ORDER BY duration_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            throw new Exception("Invalid master type for refresh.");
        }
        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

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
                    SET medicine_name = ?, generic_name = ?, strength = ?, unit_id = ?, frequency_id = ?, meal_id = ?, default_qty = ?, default_duration_id = ?, manufacturer = ?, category = ?, status = ?
                    WHERE id = ? AND org_id = ? AND center_id = ?
                ");
                $stmt->execute([
                    $medicine_name, $generic_name !== '' ? $generic_name : null, $strength !== '' ? $strength : null,
                    $unit_id > 0 ? $unit_id : null, $frequency_id > 0 ? $frequency_id : null, $meal_id > 0 ? $meal_id : null,
                    $default_qty !== '' ? $default_qty : null, $default_duration_id > 0 ? $default_duration_id : null,
                    $manufacturer !== '' ? $manufacturer : null, $category !== '' ? $category : null, $status,
                    $edit_id, $org_id, $center_id
                ]);
                set_flash_msg("Medicine updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("
                    INSERT INTO master_medicines (org_id, center_id, medicine_name, generic_name, strength, unit_id, frequency_id, meal_id, default_qty, default_duration_id, manufacturer, category, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $org_id, $center_id, $medicine_name, $generic_name !== '' ? $generic_name : null, $strength !== '' ? $strength : null,
                    $unit_id > 0 ? $unit_id : null, $frequency_id > 0 ? $frequency_id : null, $meal_id > 0 ? $meal_id : null,
                    $default_qty !== '' ? $default_qty : null, $default_duration_id > 0 ? $default_duration_id : null,
                    $manufacturer !== '' ? $manufacturer : null, $category !== '' ? $category : null, $status, $created_by ?: null
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
    $tenant_pdo->prepare("UPDATE master_medicines SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    header("Location: master_medicines.php");
    exit;
}

// ---------------------------------------------------------
// MASTER DROPDOWNS
// ---------------------------------------------------------
$units = $tenant_pdo->query("SELECT id, unit_name, status FROM master_units WHERE org_id = $org_id AND center_id = $center_id ORDER BY unit_name ASC")->fetchAll() ?: [];
$frequencies = $tenant_pdo->query("SELECT id, frequency_name, status FROM frequency_master WHERE org_id = $org_id AND center_id = $center_id ORDER BY frequency_name ASC")->fetchAll() ?: [];
$meals = $tenant_pdo->query("SELECT id, meal_name, status FROM master_meals WHERE org_id = $org_id AND center_id = $center_id ORDER BY meal_name ASC")->fetchAll() ?: [];
$durations = $tenant_pdo->query("SELECT id, duration_name, status FROM master_durations WHERE org_id = $org_id AND center_id = $center_id ORDER BY duration_name ASC")->fetchAll() ?: [];

// ---------------------------------------------------------
// LIST
// ---------------------------------------------------------
$medicinesStmt = $tenant_pdo->prepare("
    SELECT m.*, u.unit_name, f.frequency_name, ml.meal_name, d.duration_name
    FROM master_medicines m
    LEFT JOIN master_units u ON u.id = m.unit_id AND u.org_id = m.org_id AND u.center_id = m.center_id
    LEFT JOIN frequency_master f ON f.id = m.frequency_id AND f.org_id = m.org_id AND f.center_id = m.center_id
    LEFT JOIN master_meals ml ON ml.id = m.meal_id AND ml.org_id = m.org_id AND ml.center_id = m.center_id
    LEFT JOIN master_durations d ON d.id = m.default_duration_id AND d.org_id = m.org_id AND d.center_id = m.center_id
    WHERE m.org_id = ? AND m.center_id = ?
    ORDER BY m.id DESC
");
$medicinesStmt->execute([$org_id, $center_id]);
$medicines = $medicinesStmt->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-2">
    <!-- FORM -->
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-2 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-capsule text-primary me-1"></i> ADD MEDICINE
                </span>
            </div>
            <div class="card-body p-2">
                <form method="POST" action="">
                    <input type="hidden" name="action_medicine" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="row g-1 mb-2">
                        <div class="col-md-8">
                            <label class="form-label mb-0" style="font-size: 10px; font-weight: 600; color: #6c757d;">MEDICINE NAME *</label>
                            <input type="text" name="medicine_name" id="medicine_name" class="form-control form-control-sm text-uppercase" maxlength="200" required autofocus>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label mb-0" style="font-size: 10px; font-weight: 600; color: #6c757d;">STRENGTH</label>
                            <input type="text" name="strength" id="strength" class="form-control form-control-sm text-uppercase" maxlength="100">
                        </div>
                    </div>

                    <div class="row g-1 mb-2">
                        <div class="col-md-6">
                            <label class="form-label mb-0" style="font-size: 10px; font-weight: 600; color: #6c757d;">GENERIC / SALT</label>
                            <input type="text" name="generic_name" id="generic_name" class="form-control form-control-sm text-uppercase" maxlength="200">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label mb-0" style="font-size: 10px; font-weight: 600; color: #6c757d;">FREQUENCY</label>
                            <div class="input-group input-group-sm">
                                <select name="frequency_id" id="frequency_id" class="form-select form-select-sm">
                                    <option value="">SELECT</option>
                                    <?php foreach ($frequencies as $f): ?>
                                        <option value="<?= (int)$f['id'] ?>" <?= (int)$f['status'] !== 1 ? 'class="text-muted"' : '' ?>><?= htmlspecialchars($f['frequency_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary px-2" id="btn_ref_frequency" onclick="refreshDropdown('frequency')" title="Refresh">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary px-2" onclick="openQuickAdd('frequency', 'Add Frequency')" title="Add Frequency">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="row g-1 mb-2">
                        <div class="col-md-6">
                            <label class="form-label mb-0" style="font-size: 10px; font-weight: 600; color: #6c757d;">UNIT / FORM</label>
                            <div class="input-group input-group-sm">
                                <select name="unit_id" id="unit_id" class="form-select form-select-sm">
                                    <option value="">SELECT</option>
                                    <?php foreach ($units as $u): ?>
                                        <option value="<?= (int)$u['id'] ?>" <?= (int)$u['status'] !== 1 ? 'class="text-muted"' : '' ?>><?= htmlspecialchars($u['unit_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary px-2" id="btn_ref_unit" onclick="refreshDropdown('unit')" title="Refresh">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary px-2" onclick="openQuickAdd('unit', 'Add Unit / Form')" title="Add Unit">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label mb-0" style="font-size: 10px; font-weight: 600; color: #6c757d;">MEAL TIMING</label>
                            <div class="input-group input-group-sm">
                                <select name="meal_id" id="meal_id" class="form-select form-select-sm">
                                    <option value="">SELECT</option>
                                    <?php foreach ($meals as $ml): ?>
                                        <option value="<?= (int)$ml['id'] ?>"><?= htmlspecialchars($ml['meal_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary px-2" id="btn_ref_meal" onclick="refreshDropdown('meal')" title="Refresh">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary px-2" onclick="openQuickAdd('meal', 'Add Meal Timing')" title="Add Meal Timing">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="row g-1 mb-2">
                        <div class="col-md-6">
                            <label class="form-label mb-0" style="font-size: 10px; font-weight: 600; color: #6c757d;">DEFAULT QTY</label>
                            <input type="text" name="default_qty" id="default_qty" class="form-control form-control-sm text-uppercase" placeholder="E.G. 1" maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label mb-0" style="font-size: 10px; font-weight: 600; color: #6c757d;">DURATION</label>
                            <div class="input-group input-group-sm">
                                <select name="default_duration_id" id="default_duration_id" class="form-select form-select-sm">
                                    <option value="">SELECT</option>
                                    <?php foreach ($durations as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['duration_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary px-2" id="btn_ref_duration" onclick="refreshDropdown('duration')" title="Refresh">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary px-2" onclick="openQuickAdd('duration', 'Add Duration')" title="Add Duration">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="row g-1 mb-3">
                        <div class="col-md-7">
                            <label class="form-label mb-0" style="font-size: 10px; font-weight: 600; color: #6c757d;">MANUFACTURER</label>
                            <input type="text" name="manufacturer" id="manufacturer" class="form-control form-control-sm text-uppercase" maxlength="200">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label mb-0" style="font-size: 10px; font-weight: 600; color: #6c757d;">CATEGORY</label>
                            <input type="text" name="category" id="category" class="form-control form-control-sm text-uppercase" maxlength="100">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center border-top pt-2 mt-1">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                            <label class="form-check-label mb-0" style="font-size: 10px; font-weight: 600; color: #6c757d;" for="status">ACTIVE</label>
                        </div>
                        <div class="d-flex gap-1">
                            <button type="reset" class="btn btn-light btn-sm border px-3 py-1" style="font-size: 11px; font-weight: 600;" onclick="resetForm()">RESET</button>
                            <button type="submit" class="btn btn-primary btn-sm px-3 py-1" style="font-size: 11px; font-weight: 600;" id="btnSubmit">SAVE</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- LIST -->
    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark">
                    <i class="bi bi-list-task text-primary me-1"></i> MEDICINES LIST
                </span>
                
                <div class="d-flex align-items-center gap-2">
                    <div class="input-group input-group-sm" style="width: 250px;">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="liveSearch" class="form-control border-start-0 ps-0" placeholder="Type to search..." onkeyup="filterTable()">
                    </div>
                    <span class="badge bg-light text-secondary border px-2 py-1">
                        <span id="recordCount"><?= count($medicines) ?></span> RECORDS
                    </span>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:75vh; overflow-y:auto;">
                    <table class="table table-sm table-hover align-middle mb-0" style="font-size:11px;" id="medicinesTable">
                        <thead class="table-light sticky-top" style="z-index: 1;">
                            <tr>
                                <th class="ps-2 text-muted" style="width:40px;">#</th>
                                <th class="text-muted">MEDICINE</th>
                                <th class="text-muted">SALT</th>
                                <th class="text-muted">STRENGTH</th>
                                <th class="text-muted">UNIT</th>
                                <th class="text-muted">FREQ</th>
                                <th class="text-muted">MEAL</th>
                                <th class="text-muted">QTY</th>
                                <th class="text-muted">DUR</th>
                                <th class="text-muted text-center">STATUS</th>
                                <th class="text-end pe-2 text-muted">ACT</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                        <?php if (empty($medicines)): ?>
                            <tr id="noRecordsRow">
                                <td colspan="11" class="text-center py-3 text-muted">NO MEDICINES FOUND.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($medicines as $m): ?>
                                <?php $is_act = (int)$m['status'] === 1; ?>
                                <tr>
                                    <td class="ps-2 font-monospace text-muted">#<?= (int)$m['id'] ?></td>
                                    <td><div class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($m['medicine_name']) ?></div></td>
                                    <td class="text-uppercase text-secondary"><?= htmlspecialchars($m['generic_name'] ?? '') ?: '-' ?></td>
                                    <td class="font-monospace text-secondary"><?= htmlspecialchars($m['strength'] ?? '') ?: '-' ?></td>
                                    <td class="text-uppercase text-secondary"><?= htmlspecialchars($m['unit_name'] ?? '') ?: '-' ?></td>
                                    <td class="text-uppercase text-secondary"><?= htmlspecialchars($m['frequency_name'] ?? '') ?: '-' ?></td>
                                    <td class="text-uppercase text-secondary"><?= htmlspecialchars($m['meal_name'] ?? '') ?: '-' ?></td>
                                    <td class="text-secondary"><?= htmlspecialchars($m['default_qty'] ?? '') ?: '-' ?></td>
                                    <td class="text-uppercase text-secondary"><?= htmlspecialchars($m['duration_name'] ?? '') ?: '-' ?></td>
                                    <td class="text-center">
                                        <a href="?toggle_status=<?= (int)$m['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                            <?= $is_act ? 'ACT' : 'INACT' ?>
                                        </a>
                                    </td>
                                    <td class="text-end pe-2">
                                        <button type="button" class="btn btn-outline-primary btn-sm py-0 px-1" onclick='editRow(<?= json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="EDIT">
                                            <i class="bi bi-pencil" style="font-size: 10px;"></i>
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

<!-- AJAX QUICK ADD MODAL -->
<div class="modal fade" id="quickAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light py-2 px-3">
                <h6 class="modal-title fw-bold text-primary" id="quickAddTitle">Add New</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <form id="quickAddForm" onsubmit="saveQuickAdd(event)">
                    <input type="hidden" id="qa_type">
                    <div class="mb-3">
                        <label class="form-label mb-1" style="font-size: 11px; font-weight: 600; color: #6c757d;">NAME / TITLE *</label>
                        <input type="text" id="qa_name" class="form-control form-control-sm text-uppercase" required>
                    </div>
                    <div class="text-end border-top pt-2">
                        <button type="button" class="btn btn-light btn-sm px-3 fw-semibold border" data-bs-dismiss="modal">CANCEL</button>
                        <button type="submit" class="btn btn-primary btn-sm px-4 fw-semibold" id="qa_btn">SAVE</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// ---------- EXISTING FUNCTIONS ----------
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

    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT MEDICINE';
    document.getElementById('btnSubmit').innerText = 'UPDATE';
    document.getElementById('medicine_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-capsule text-primary me-1"></i> ADD MEDICINE';
    document.getElementById('btnSubmit').innerText = 'SAVE';
    document.getElementById('status').checked = true;
    setTimeout(() => { document.getElementById('medicine_name').focus(); }, 10);
}

function filterTable() {
    const input = document.getElementById('liveSearch');
    const filter = input.value.toUpperCase();
    const tbody = document.getElementById('tableBody');
    const tr = tbody.getElementsByTagName('tr');
    let visibleCount = 0;

    for (let i = 0; i < tr.length; i++) {
        if (tr[i].id === 'noRecordsRow') continue; 

        const textValue = tr[i].textContent || tr[i].innerText;
        if (textValue.toUpperCase().indexOf(filter) > -1) {
            tr[i].style.display = '';
            visibleCount++;
        } else {
            tr[i].style.display = 'none';
        }
    }
    document.getElementById('recordCount').innerText = visibleCount;
}

// ---------- QUICK ADD & REFRESH FUNCTIONS ----------
let qaModalInstance = null;

document.addEventListener("DOMContentLoaded", function() {
    qaModalInstance = new bootstrap.Modal(document.getElementById('quickAddModal'));
});

function openQuickAdd(type, title) {
    document.getElementById('qa_type').value = type;
    document.getElementById('quickAddTitle').innerText = title;
    document.getElementById('qa_name').value = '';
    qaModalInstance.show();
    setTimeout(() => { document.getElementById('qa_name').focus(); }, 500);
}

async function saveQuickAdd(e) {
    e.preventDefault();
    const type = document.getElementById('qa_type').value;
    const name = document.getElementById('qa_name').value;
    const btn  = document.getElementById('qa_btn');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> SAVING...';

    try {
        const formData = new FormData();
        formData.append('ajax_quick_add', '1');
        formData.append('type', type);
        formData.append('name', name);

        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            qaModalInstance.hide();
            await refreshDropdown(type); // Auto refresh the list
            
            // Auto select newly added item
            let selectId = type + '_id';
            if (type === 'duration') selectId = 'default_duration_id';
            document.getElementById(selectId).value = data.id;
            
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'Added Successfully',
                    text: data.name,
                    timer: 1500,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
            }
        } else {
            if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: 'Oops...', text: data.message });
        }
    } catch (error) {
        if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong.' });
        console.error(error);
    } finally {
        btn.disabled = false;
        btn.innerText = 'SAVE';
    }
}

async function refreshDropdown(type) {
    const btnId = 'btn_ref_' + type;
    const btn = document.getElementById(btnId);
    if(btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="width: 0.8rem; height: 0.8rem;"></span>';
    }

    try {
        const formData = new FormData();
        formData.append('ajax_refresh', '1');
        formData.append('type', type);

        const res = await fetch('', { method: 'POST', body: formData });
        const result = await res.json();

        if (result.success) {
            let selectId = type + '_id';
            if (type === 'duration') selectId = 'default_duration_id';
            
            const selectEl = document.getElementById(selectId);
            const currentVal = selectEl.value; // Store selected value
            
            // Clear items
            selectEl.innerHTML = '<option value="">SELECT</option>';
            
            // Repopulate
            result.data.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.name + (parseInt(item.status) !== 1 ? ' (INACTIVE)' : '');
                if (parseInt(item.status) !== 1) opt.className = 'text-muted';
                selectEl.appendChild(opt);
            });
            
            // Restore selection if item still exists
            selectEl.value = currentVal;
            
            if (typeof Swal !== 'undefined' && event && event.type === 'click') {
                Swal.fire({ icon: 'success', title: 'Refreshed', timer: 1000, showConfirmButton: false, toast: true, position: 'top-end' });
            }
        } else {
            if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: 'Error', text: result.message });
        }
    } catch(e) {
        console.error(e);
    } finally {
        if(btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i>';
        }
    }
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
