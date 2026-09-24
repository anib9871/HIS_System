<?php
// admin/master_insurance_categories.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Category & Insurance Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$user_id = (int)($_SESSION['user_id'] ?? 1);

// Get Last Selected Category from URL if exists (to retain selection)
$last_cat = (int)($_GET['last_cat'] ?? 0);

// 1. Handle Category Form Submit (Uppercase & Save/Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_category'])) {
    $category_name = strtoupper(trim($_POST['category_name'] ?? ''));
    $status        = isset($_POST['cat_status']) ? 1 : 0;
    $edit_id       = (int)($_POST['edit_id'] ?? 0);

    if (empty($category_name)) {
        set_flash_err("Category Name is required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_categories SET category_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$category_name, $status, $edit_id, $org_id, $center_id]);
                set_flash_msg("Category updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_categories (org_id, center_id, category_name, status, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id, $category_name, $status, $user_id]);
                set_flash_msg("New Category added successfully.");
            }
            header("Location: master_insurance_categories.php");
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}

// 2. Handle Insurance Form Submit (Insurance Name is Optional, Category is Retained)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_insurance'])) {
    $category_id    = (int)($_POST['category_id'] ?? 0);
    $insurance_name = !empty($_POST['insurance_name']) ? strtoupper(trim($_POST['insurance_name'])) : null;
    $status         = isset($_POST['ins_status']) ? 1 : 0;
    $edit_ins_id    = (int)($_POST['edit_ins_id'] ?? 0);

    if ($category_id <= 0) {
        set_flash_err("Please select a valid Category.");
    } else {
        try {
            if ($edit_ins_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE insurance SET category_id = ?, insurance_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$category_id, $insurance_name, $status, $edit_ins_id, $org_id, $center_id]);
                set_flash_msg("Insurance record updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO insurance (org_id, center_id, category_id, insurance_name, status, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id, $category_id, $insurance_name, $status, $user_id]);
                set_flash_msg("Insurance record mapped successfully.");
            }
            // Retain the category in URL so it stays selected
            header("Location: master_insurance_categories.php?last_cat=" . $category_id);
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}

// 3. Status Toggle Handlers
if (isset($_GET['toggle_cat'])) {
    $id = (int)$_GET['toggle_cat'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_categories SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    header("Location: master_insurance_categories.php");
    exit;
}

if (isset($_GET['toggle_ins'])) {
    $id = (int)$_GET['toggle_ins'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE insurance SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    header("Location: master_insurance_categories.php");
    exit;
}

// Fetch Categories for Dropdown & Listing
$cat_records = $tenant_pdo->prepare("SELECT * FROM master_categories WHERE org_id = ? AND center_id = ? ORDER BY id DESC");
$cat_records->execute([$org_id, $center_id]);
$category_list = $cat_records->fetchAll() ?: [];

// Fetch Insurance Records with Category Name Join
$ins_records = $tenant_pdo->prepare("
    SELECT i.*, c.category_name 
    FROM insurance i 
    JOIN master_categories c ON i.category_id = c.id 
    WHERE i.org_id = ? AND i.center_id = ? 
    ORDER BY i.id DESC
");
$ins_records->execute([$org_id, $center_id]);
$insurance_list = $ins_records->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <!-- TOP ROW: FORMS SIDE BY SIDE (Left: Category, Right: Insurance) -->
    <div class="col-lg-6">
        <div class="card border shadow-sm mb-3">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="catFormTitle">
                    <i class="bi bi-folder-plus text-primary me-1"></i> Add Category
                </span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_category" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Category Name *</label>
                        <input type="text" name="category_name" id="category_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. DEPT, TPA, GENERAL" required oninput="this.value = this.value.toUpperCase();">
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="cat_status" id="cat_status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="cat_status">Active Status</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnCatSubmit">Save Category</button>
                        <button type="button" class="btn btn-light btn-sm border" onclick="resetCatForm()">Reset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border shadow-sm mb-3">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="insFormTitle">
                    <i class="bi bi-shield-plus text-success me-1"></i> Add Insurance Mapping
                </span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_insurance" value="1">
                    <input type="hidden" name="edit_ins_id" id="edit_ins_id" value="0">

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Select Category *</label>
                        <select name="category_id" id="ins_category_id" class="form-select form-select-sm" required>
                            <option value="">-- Choose Category --</option>
                            <?php foreach ($category_list as $cat): ?>
                                <?php if ((int)$cat['status'] === 1): ?>
                                    <!-- Keep the category selected if it matches the $last_cat parameter -->
                                    <option value="<?= $cat['id'] ?>" <?= ($last_cat === (int)$cat['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['category_name']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Insurance / Provider Name <span class="text-muted fw-normal">(Optional)</span></label>
                        <!-- Autofocus attribute added here -->
                        <input type="text" name="insurance_name" id="insurance_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. STAR HEALTH (Optional)" autofocus oninput="this.value = this.value.toUpperCase();">
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="ins_status" id="ins_status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="ins_status">Active Status</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success btn-sm flex-fill fw-semibold" id="btnInsSubmit">Save Insurance</button>
                        <button type="button" class="btn btn-light btn-sm border" onclick="resetInsForm()">Reset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- BOTTOM ROW: TABLES LISTING WITH SEARCH BARS -->
    <div class="col-lg-6">
        <div class="card border shadow-sm mb-3">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-list-columns text-primary me-1"></i> Categories List</span>
                <input type="text" id="searchCategoryInput" class="form-control form-control-sm w-50" placeholder="Search category...">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small" id="categoryTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="width: 50px;">#</th>
                                <th>Category Name</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($category_list)): ?>
                                <tr><td colspan="4" class="text-center py-3 text-muted">No categories found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($category_list as $row): ?>
                                    <?php $is_act = ((int)$row['status'] === 1); ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $row['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($row['category_name']) ?></td>
                                        <td>
                                            <a href="?toggle_cat=<?= $row['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editCategory(<?= json_encode($row) ?>)' title="Edit">
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

    <div class="col-lg-6">
        <div class="card border shadow-sm mb-3">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-shield-check text-success me-1"></i> Insurance Table List</span>
                <input type="text" id="searchInsuranceInput" class="form-control form-control-sm w-50" placeholder="Search insurance...">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small" id="insuranceTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="width: 50px;">#</th>
                                <th>Insurance Provider</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($insurance_list)): ?>
                                <tr><td colspan="5" class="text-center py-3 text-muted">No insurance records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($insurance_list as $ins): ?>
                                    <?php $is_ins_act = ((int)$ins['status'] === 1); ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $ins['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($ins['insurance_name'] ?: '-') ?></td>
                                        <td><span class="badge bg-secondary-subtle text-dark border"><?= htmlspecialchars($ins['category_name']) ?></span></td>
                                        <td>
                                            <a href="?toggle_ins=<?= $ins['id'] ?>&st=<?= $is_ins_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_ins_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_ins_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-success btn-sm py-0 px-2" onclick='editInsurance(<?= json_encode($ins) ?>)' title="Edit">
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
// Live Search for Categories Table
document.getElementById('searchCategoryInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#categoryTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

// Live Search for Insurance Table
document.getElementById('searchInsuranceInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#insuranceTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

function editCategory(c) {
    document.getElementById('edit_id').value = c.id;
    document.getElementById('category_name').value = c.category_name;
    document.getElementById('cat_status').checked = (parseInt(c.status) === 1);
    
    document.getElementById('catFormTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Category';
    document.getElementById('btnCatSubmit').innerText = 'Update Category';
    document.getElementById('category_name').focus();
}

function resetCatForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('category_name').value = '';
    document.getElementById('cat_status').checked = true;
    
    document.getElementById('catFormTitle').innerHTML = '<i class="bi bi-folder-plus text-primary me-1"></i> Add Category';
    document.getElementById('btnCatSubmit').innerText = 'Save Category';
    document.getElementById('category_name').focus();
}

function editInsurance(i) {
    document.getElementById('edit_ins_id').value = i.id;
    document.getElementById('ins_category_id').value = i.category_id;
    document.getElementById('insurance_name').value = i.insurance_name || '';
    document.getElementById('ins_status').checked = (parseInt(i.status) === 1);
    
    document.getElementById('insFormTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Insurance Mapping';
    document.getElementById('btnInsSubmit').innerText = 'Update Insurance';
    document.getElementById('insurance_name').focus();
}

function resetInsForm() {
    document.getElementById('edit_ins_id').value = '0';
    document.getElementById('ins_category_id').value = '';
    document.getElementById('insurance_name').value = '';
    document.getElementById('ins_status').checked = true;
    
    document.getElementById('insFormTitle').innerHTML = '<i class="bi bi-shield-plus text-success me-1"></i> Add Insurance Mapping';
    document.getElementById('btnInsSubmit').innerText = 'Save Insurance';
    
    // Clear the URL parameter so category doesn't stay selected after a hard refresh
    window.history.replaceState({}, document.title, window.location.pathname);
    document.getElementById('insurance_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>