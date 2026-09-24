<?php
// admin/master_departments.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Department Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_dept'])) {
    // Auto-Capitalize: Name in Title Case, Prefix in UPPERCASE
    $dept_name   = ucwords(strtolower(trim($_POST['dept_name'] ?? '')));
    $dept_prefix = strtoupper(trim($_POST['dept_prefix'] ?? ''));
    $status      = isset($_POST['status']) ? 1 : 0;
    $edit_id     = (int)($_POST['edit_id'] ?? 0);

    if (empty($dept_name) || empty($dept_prefix)) {
        set_flash_err("Department Name and Prefix are required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_departments SET dept_name = ?, dept_prefix = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$dept_name, $dept_prefix, $status, $edit_id, $org_id, $center_id]);
                set_flash_msg("Department updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_departments (org_id, center_id, dept_name, dept_prefix, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id, $dept_name, $dept_prefix, $status]);
                set_flash_msg("Department added successfully.");
            }
            header("Location: master_departments.php");
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}

// Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_departments SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    header("Location: master_departments.php");
    exit;
}

$departments = $tenant_pdo->prepare("SELECT * FROM master_departments WHERE org_id = ? AND center_id = ? ORDER BY id DESC");
$departments->execute([$org_id, $center_id]);
$departments = $departments->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle"><i class="bi bi-building text-primary me-1"></i> Add Department</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_dept" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold text-secondary mb-1">Department Name *</label>
                            <input type="text" name="dept_name" id="dept_name" class="form-control form-control-sm text-capitalize" placeholder="e.g. Cardiology" required autofocus>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">Prefix *</label>
                            <input type="text" name="dept_prefix" id="dept_prefix" class="form-control form-control-sm font-monospace fw-bold text-uppercase" placeholder="CARD" maxlength="10" required>
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">Save Department</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-list-task text-primary me-1"></i> Departments List</span>
                <span class="badge bg-light text-secondary border"><?= count($departments) ?> Records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3" style="width: 60px;">#</th>
                                <th>Department Name</th>
                                <th>Prefix Code</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($departments)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No departments found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($departments as $d): ?>
                                    <?php $is_act = (int)$d['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $d['id'] ?></td>
                                        <td class="fw-bold text-dark text-capitalize"><?= htmlspecialchars($d['dept_name']) ?></td>
                                        <td><span class="badge bg-light text-primary border font-monospace text-uppercase"><?= htmlspecialchars($d['dept_prefix']) ?></span></td>
                                        <td>
                                            <a href="?toggle_status=<?= $d['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($d) ?>)'><i class="bi bi-pencil"></i></button>
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
    document.getElementById('edit_id').value = d.id;
    document.getElementById('dept_name').value = d.dept_name;
    document.getElementById('dept_prefix').value = d.dept_prefix;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Department';
    document.getElementById('btnSubmit').innerText = 'Update Department';
    document.getElementById('dept_name').focus();
}
function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-building text-primary me-1"></i> Add Department';
    document.getElementById('btnSubmit').innerText = 'Save Department';
    document.getElementById('dept_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>