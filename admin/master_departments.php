<?php
// admin/master_departments.php

// Session start karein agar nahi hai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/tenant_db.php';

$page_title = "Department Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// ==============================================================
// 1. AJAX Handler: Check if Department Name or Prefix Already Exists
// ==============================================================
if (isset($_GET['check_duplicate'])) {
    header('Content-Type: application/json');
    $name = ucwords(strtolower(trim($_GET['name'] ?? '')));
    $prefix = strtoupper(trim($_GET['prefix'] ?? ''));
    $edit_id = (int)($_GET['edit_id'] ?? 0);
    
    $name_exists = false;
    $prefix_exists = false;

    if (!empty($name)) {
        $stmt = $tenant_pdo->prepare("SELECT COUNT(*) FROM master_departments WHERE dept_name = ? AND id != ? AND org_id = ? AND center_id = ?");
        $stmt->execute([$name, $edit_id, $org_id, $center_id]);
        $name_exists = $stmt->fetchColumn() > 0;
    }

    if (!empty($prefix)) {
        $stmt = $tenant_pdo->prepare("SELECT COUNT(*) FROM master_departments WHERE dept_prefix = ? AND id != ? AND org_id = ? AND center_id = ?");
        $stmt->execute([$prefix, $edit_id, $org_id, $center_id]);
        $prefix_exists = $stmt->fetchColumn() > 0;
    }
    
    echo json_encode(['name_exists' => $name_exists, 'prefix_exists' => $prefix_exists]);
    exit;
}

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_dept'])) {
    // Auto-Capitalize: Name in Title Case, Prefix in UPPERCASE
    $dept_name   = ucwords(strtolower(trim($_POST['dept_name'] ?? '')));
    $dept_prefix = strtoupper(trim($_POST['dept_prefix'] ?? ''));
    $status      = isset($_POST['status']) ? 1 : 0;
    $edit_id     = (int)($_POST['edit_id'] ?? 0);

    if (empty($dept_name) || empty($dept_prefix)) {$_SESSION['swal_error'] = "Department Name and Prefix are required.";
    } else {
        try {
            // Backend Duplicate Validation
            $dup_chk =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_departments WHERE (dept_name = ? OR dept_prefix = ?) AND id != ? AND org_id = ? AND center_id = ?");
            $dup_chk->execute([$dept_name, $dept_prefix,$edit_id, $org_id,$center_id]);
            
            if ($dup_chk->fetchColumn() > 0) {$_SESSION['swal_error'] = "This Department Name or Prefix already exists!";
            } else {
                if ($edit_id > 0) {
                    $stmt =$tenant_pdo->prepare("UPDATE master_departments SET dept_name = ?, dept_prefix = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                    $stmt->execute([$dept_name, $dept_prefix,$status, $edit_id,$org_id, $center_id]);$_SESSION['swal_success'] = "Department updated successfully.";
                } else {
                    $stmt =$tenant_pdo->prepare("INSERT INTO master_departments (org_id, center_id, dept_name, dept_prefix, status) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$org_id,$center_id, $dept_name,$dept_prefix, $status]);$_SESSION['swal_success'] = "Department added successfully.";
                }
            }
            header("Location: master_departments.php");
            exit;
        } catch (PDOException $e) {$_SESSION['swal_error'] = "Database Error: " . $e->getMessage();
        }
    }
}

// Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_departments SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id,$org_id, $center_id]);$_SESSION['swal_success'] = "Department status updated successfully.";
    header("Location: master_departments.php");
    exit;
}

$departments =$tenant_pdo->prepare("SELECT * FROM master_departments WHERE org_id = ? AND center_id = ? ORDER BY id DESC");
$departments->execute([$org_id,$center_id]);
$departments =$departments->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
                            <input type="text" name="dept_name" id="dept_name" class="form-control form-control-sm text-capitalize" placeholder="e.g. Cardiology" required autofocus onkeyup="checkDuplicate()">
                            <span id="name_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> Name Already Exists!</span>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">Prefix *</label>
                            <input type="text" name="dept_prefix" id="dept_prefix" class="form-control form-control-sm font-monospace fw-bold text-uppercase" placeholder="CARD" maxlength="10" required onkeyup="checkDuplicate()">
                            <span id="prefix_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> Prefix Taken!</span>
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
                <div class="d-flex align-items-center gap-2">
                    <!-- Added Search Bar -->
                    <input type="text" id="searchDeptInput" class="form-control form-control-sm" placeholder="Search Department..." style="max-width: 180px;">
                    <span class="badge bg-light text-secondary border"><?= count($departments) ?> Records</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                    <!-- Added id to table -->
                    <table class="table table-hover align-middle mb-0 small" id="deptTable">
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
                                <?php foreach ($departments as$d): ?>
                                    <?php $is_act = (int)$d['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $d['id'] ?></td>
                                        <td class="fw-bold text-dark text-capitalize"><?= htmlspecialchars($d['dept_name']) ?></td>
                                        <td><span class="badge bg-light text-primary border font-monospace text-uppercase"><?= htmlspecialchars($d['dept_prefix']) ?></span></td>
                                        <td>
                                            <a href="javascript:void(0);" onclick="confirmToggle(<?= $d['id'] ?>, <?=$is_act ? 1 : 0 ?>)" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
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
// --- Live Table Search ---
document.getElementById('searchDeptInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#deptTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

// --- Live AJAX Duplicate Check (Name & Prefix) ---
let typingTimer;
function checkDuplicate() {
    clearTimeout(typingTimer);
    let nameInput = document.getElementById('dept_name').value.trim();
    let prefixInput = document.getElementById('dept_prefix').value.trim();
    let editId = document.getElementById('edit_id').value;
    
    let nameError = document.getElementById('name_error');
    let prefixError = document.getElementById('prefix_error');
    let btnSubmit = document.getElementById('btnSubmit');

    if (nameInput.length === 0 && prefixInput.length === 0) {
        nameError.style.display = 'none';
        prefixError.style.display = 'none';
        btnSubmit.disabled = false;
        return;
    }

    typingTimer = setTimeout(function() {
        fetch(`master_departments.php?check_duplicate=1&name=${encodeURIComponent(nameInput)}&prefix=${encodeURIComponent(prefixInput)}&edit_id=${editId}`)
            .then(response => response.json())
            .then(data => {
                let hasError = false;
                
                if (data.name_exists && nameInput.length > 0) {
                    nameError.style.display = 'block';
                    hasError = true;
                } else {
                    nameError.style.display = 'none';
                }

                if (data.prefix_exists && prefixInput.length > 0) {
                    prefixError.style.display = 'block';
                    hasError = true;
                } else {
                    prefixError.style.display = 'none';
                }

                btnSubmit.disabled = hasError;
            })
            .catch(error => console.error('Error:', error));
    }, 300);
}

function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('dept_name').value = d.dept_name;
    document.getElementById('dept_prefix').value = d.dept_prefix;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Department';
    document.getElementById('btnSubmit').innerText = 'Update Department';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('prefix_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('dept_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-building text-primary me-1"></i> Add Department';
    document.getElementById('btnSubmit').innerText = 'Save Department';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('prefix_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('dept_name').focus();
}

// --- SweetAlert for Status Toggle ---
function confirmToggle(id, currentStatus) {
    let actionText = currentStatus === 1 ? "Deactivate" : "Activate";
    let actionColor = currentStatus === 1 ? "#d33" : "#28a745";
    
    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to ${actionText} this department?`,
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
