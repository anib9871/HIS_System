<?php
// admin/master_specializations.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Doctor Specializations";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_specialization'])) {
    // FULL CAPITALIZATION - Text hamesha uppercase mein save hoga
    $specialization_name = strtoupper(trim($_POST['specialization_name'] ?? ''));
    $status              = isset($_POST['status']) ? 1 : 0;
    $edit_id             = (int)($_POST['edit_id'] ?? 0);

    if (empty($specialization_name)) {
        set_flash_err("Specialization Name is required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_specializations SET specialization_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$specialization_name, $status, $edit_id, $org_id, $center_id]);
                set_flash_msg("Specialization updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_specializations (org_id, center_id, specialization_name, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id, $specialization_name, $status]);
                set_flash_msg("Specialization added successfully.");
            }
            header("Location: master_specializations.php");
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
    $tenant_pdo->prepare("UPDATE master_specializations SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    header("Location: master_specializations.php");
    exit;
}

$specializations = $tenant_pdo->prepare("SELECT * FROM master_specializations WHERE org_id = ? AND center_id = ? ORDER BY id DESC");
$specializations->execute([$org_id, $center_id]);
$specializations = $specializations->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle"><i class="bi bi-heart-pulse text-primary me-1"></i> Add Specialization</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_specialization" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Specialization Name *</label>
                        <!-- 'text-uppercase' for visual capitals and 'autofocus' for keeping the cursor ready -->
                        <input type="text" name="specialization_name" id="specialization_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. CARDIOLOGY" required autofocus>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">Save Specialization</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-list-task text-primary me-1"></i> Specializations List</span>
                <span class="badge bg-light text-secondary border"><?= count($specializations) ?> Records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="width: 60px;">#</th>
                                <th>Specialization</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($specializations)): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No specializations found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($specializations as $s): ?>
                                    <?php $is_act = (int)$s['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $s['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($s['specialization_name']) ?></td>
                                        <td>
                                            <a href="?toggle_status=<?= $s['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($s) ?>)'><i class="bi bi-pencil"></i></button>
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
    document.getElementById('specialization_name').value = d.specialization_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Specialization';
    document.getElementById('btnSubmit').innerText = 'Update Specialization';
    document.getElementById('specialization_name').focus(); // Cursor focus ho jayega automatically
}
function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-heart-pulse text-primary me-1"></i> Add Specialization';
    document.getElementById('btnSubmit').innerText = 'Save Specialization';
    document.getElementById('specialization_name').focus(); // Reset pe bhi cursor clear hoke lag jayega
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>