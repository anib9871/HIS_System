<?php
// admin/master_room_categories.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Room Category Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_category'])) {
    $category_name = trim($_POST['category_name'] ?? '');
    $status        = isset($_POST['status']) ? 1 : 0;
    $edit_id       = (int)($_POST['edit_id'] ?? 0);

    if (empty($category_name)) {
        set_flash_err("Category Name is required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_room_categories SET category_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$category_name, $status, $edit_id, $org_id, $center_id]);
                set_flash_msg("Category updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_room_categories (org_id, center_id, category_name, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id, $category_name, $status]);
                set_flash_msg("Category added successfully.");
            }
            header("Location: master_room_categories.php");
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
    $tenant_pdo->prepare("UPDATE master_room_categories SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    header("Location: master_room_categories.php");
    exit;
}

$categories = $tenant_pdo->prepare("SELECT * FROM master_room_categories WHERE org_id = ? AND center_id = ? ORDER BY id DESC");
$categories->execute([$org_id, $center_id]);
$categories = $categories->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle"><i class="bi bi-tag text-primary me-1"></i> Add Room Category</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_category" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Category Name *</label>
                        <input type="text" name="category_name" id="category_name" class="form-control form-control-sm" placeholder="e.g. General Ward / ICU / Deluxe" required>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">Save Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-list-task text-primary me-1"></i> Room Categories Directory</span>
                <span class="badge bg-light text-secondary border"><?= count($categories) ?> Records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">#</th>
                                <th>Category Name</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($categories)): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No categories found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($categories as $c): ?>
                                    <?php $is_act = (int)$c['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $c['id'] ?></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($c['category_name']) ?></td>
                                        <td>
                                            <a href="?toggle_status=<?= $c['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($c) ?>)'><i class="bi bi-pencil"></i></button>
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
    document.getElementById('category_name').value = d.category_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Room Category';
    document.getElementById('btnSubmit').innerText = 'Update Category';
}
function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-tag text-primary me-1"></i> Add Room Category';
    document.getElementById('btnSubmit').innerText = 'Save Category';
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>