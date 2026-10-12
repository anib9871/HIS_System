<?php
// admin/master_room_categories.php
require_once __DIR__ . '/../config/tenant_db.php';

// Session start karein agar nahi hai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Room Category Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// 1. AJAX Handler: Check if Category Name Already Exists
if (isset($_GET['check_name'])) {
    header('Content-Type: application/json');
    $name = strtoupper(trim($_GET['check_name']));
    $edit_id = (int)($_GET['edit_id'] ?? 0);
    
    $stmt =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_room_categories WHERE category_name = ? AND org_id = ? AND center_id = ? AND id != ?");
    $stmt->execute([$name,$org_id, $center_id,$edit_id]);
    $exists =$stmt->fetchColumn() > 0;
    
    echo json_encode(['exists' => $exists]);
    exit;
}

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_category'])) {
    // FULL CAPITALIZATION - Text uppercase mein save hoga
    $category_name = strtoupper(trim($_POST['category_name'] ?? ''));
    $status        = isset($_POST['status']) ? 1 : 0;
    $edit_id       = (int)($_POST['edit_id'] ?? 0);

    if (empty($category_name)) {$_SESSION['swal_error'] = "Category Name is required.";
    } else {
        try {
            if ($edit_id > 0) {
                $stmt =$tenant_pdo->prepare("UPDATE master_room_categories SET category_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$category_name,$status, $edit_id,$org_id, $center_id]);$_SESSION['swal_success'] = "Category updated successfully.";
            } else {
                $stmt =$tenant_pdo->prepare("INSERT INTO master_room_categories (org_id, center_id, category_name, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id,$category_name, $status]);$_SESSION['swal_success'] = "Category added successfully.";
            }
            header("Location: master_room_categories.php");
            exit;
        } catch (PDOException $e) {$_SESSION['swal_error'] = "Database Error: " . $e->getMessage();
        }
    }
}

// Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_room_categories SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id,$org_id, $center_id]);$_SESSION['swal_success'] = "Status changed successfully.";
    header("Location: master_room_categories.php");
    exit;
}

$categories =$tenant_pdo->prepare("SELECT * FROM master_room_categories WHERE org_id = ? AND center_id = ? ORDER BY id DESC");
$categories->execute([$org_id,$center_id]);
$categories =$categories->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
                        <!-- Added text-uppercase and checkName() onkeyup -->
                        <input type="text" name="category_name" id="category_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. GENERAL WARD / ICU / DELUXE" required autofocus onkeyup="checkName()">
                        <!-- Error Message Span -->
                        <span id="name_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> This category already exists!</span>
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
                                <?php foreach ($categories as$c): ?>
                                    <?php $is_act = (int)$c['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $c['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($c['category_name']) ?></td>
                                        <td>
                                            <!-- SweetAlert applied here on click -->
                                            <a href="javascript:void(0);" onclick="confirmToggle(<?= $c['id'] ?>, <?=$is_act ? 1 : 0 ?>)" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
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
// --- Edit and Reset ---
function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('category_name').value = d.category_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Room Category';
    document.getElementById('btnSubmit').innerText = 'Update Category';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('category_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-tag text-primary me-1"></i> Add Room Category';
    document.getElementById('btnSubmit').innerText = 'Save Category';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('category_name').focus();
}

// --- Live AJAX Name Check ---
let typingTimer;
function checkName() {
    clearTimeout(typingTimer);
    let nameInput = document.getElementById('category_name').value.trim();
    let editId = document.getElementById('edit_id').value;
    let errorSpan = document.getElementById('name_error');
    let btnSubmit = document.getElementById('btnSubmit');

    if (nameInput.length === 0) {
        errorSpan.style.display = 'none';
        btnSubmit.disabled = false;
        return;
    }

    typingTimer = setTimeout(function() {
        fetch(`master_room_categories.php?check_name=${encodeURIComponent(nameInput)}&edit_id=${editId}`)
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    errorSpan.style.display = 'block';
                    btnSubmit.disabled = true;
                } else {
                    errorSpan.style.display = 'none';
                    btnSubmit.disabled = false;
                }
            })
            .catch(error => console.error('Error:', error));
    }, 300);
}

// --- SweetAlert for Status Toggle ---
function confirmToggle(id, currentStatus) {
    let actionText = currentStatus === 1 ? "Deactivate" : "Activate";
    let actionColor = currentStatus === 1 ? "#d33" : "#28a745";
    
    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to ${actionText} this category?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: actionColor,
        cancelButtonColor: '#6c757d',
        confirmButtonText: `Yes, ${actionText} it!`
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `?toggle_status=${id}&st=${currentStatus}`;
        }
    });
}
</script>

<!-- SweetAlert Success/Error Handlers -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    <?php if (isset($_SESSION['swal_success'])): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '<?= addslashes($_SESSION['swal_success']) ?>',
            timer: 3000,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
        <?php unset($_SESSION['swal_success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['swal_error'])): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: '<?= addslashes($_SESSION['swal_error']) ?>',
            confirmButtonColor: '#d33'
        });
        <?php unset($_SESSION['swal_error']); ?>
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
