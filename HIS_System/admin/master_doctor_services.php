<?php
// admin/master_doctor_services.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Doctor Service Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$user_id = (int)($_SESSION['user_id'] ?? 1);

// Save / Update Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_doc_service'])) {
    $service_name = strtoupper(trim($_POST['service_name'] ?? ''));
    $status       = isset($_POST['status']) ? 1 : 0;
    $edit_id      = (int)($_POST['edit_id'] ?? 0);

    if (empty($service_name)) {
        set_flash_err("Service Name is required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_doctor_services SET service_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$service_name, $status, $edit_id, $org_id, $center_id]);
                set_flash_msg("Doctor service updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_doctor_services (org_id, center_id, service_name, status, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id, $service_name, $status, $user_id]);
                set_flash_msg("New doctor service added successfully.");
            }
            header("Location: master_doctor_services.php");
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}

// Toggle Status Handler
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_doctor_services SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    header("Location: master_doctor_services.php");
    exit;
}

// Fetch Records
$records = $tenant_pdo->prepare("SELECT * FROM master_doctor_services WHERE org_id = ? AND center_id = ? ORDER BY id DESC");
$records->execute([$org_id, $center_id]);
$service_list = $records->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <!-- FORM COLUMN -->
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-gear-fill text-primary me-1"></i> Add Doctor Service
                </span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_doc_service" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Service Name *</label>
                        <input type="text" name="service_name" id="service_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. OPD CONSULTATION" required autofocus oninput="this.value = this.value.toUpperCase();">
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">Save Service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TABLE LIST COLUMN -->
    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-list-columns text-primary me-1"></i> Doctor Services List</span>
                <input type="text" id="searchInput" class="form-control form-control-sm w-50" placeholder="Search service...">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small" id="serviceTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="width: 50px;">#</th>
                                <th>Service Name</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($service_list)): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No doctor services found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($service_list as $row): ?>
                                    <?php $is_act = ((int)$row['status'] === 1); ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $row['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($row['service_name']) ?></td>
                                        <td>
                                            <a href="?toggle_status=<?= $row['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(JSON.parse(this.dataset.row))' data-row='<?= json_encode($row) ?>'>
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
// Live Search
document.getElementById('searchInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#serviceTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('service_name').value = d.service_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Doctor Service';
    document.getElementById('btnSubmit').innerText = 'Update Service';
    document.getElementById('service_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('service_name').value = '';
    document.getElementById('status').checked = true;
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-gear-fill text-primary me-1"></i> Add Doctor Service';
    document.getElementById('btnSubmit').innerText = 'Save Service';
    document.getElementById('service_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>