<?php
// admin/master_days.php

// Session start karein agar nahi hai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/tenant_db.php';

$page_title = "Days Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// ==============================================================
// 1. AJAX Handler: Check if Day Name Already Exists
// ==============================================================
if (isset($_GET['check_duplicate'])) {
    header('Content-Type: application/json');
    $name = strtoupper(trim($_GET['name'] ?? ''));$edit_id = (int)($_GET['edit_id'] ?? 0);$name_exists = false;

    if (!empty($name)) {
        $stmt =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_days WHERE day_name = ? AND id != ? AND org_id = ? AND center_id = ?");
        $stmt->execute([$name,$edit_id, $org_id,$center_id]);
        $name_exists =$stmt->fetchColumn() > 0;
    }

    echo json_encode(['name_exists' => $name_exists]);
    exit;
}

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_day'])) {
    // FULL CAPITALIZATION - Text hamesha uppercase mein save hoga
    $day_name = strtoupper(trim($_POST['day_name'] ?? ''));
    $status   = isset($_POST['status']) ? 1 : 0;
    $edit_id  = (int)($_POST['edit_id'] ?? 0);

    if (empty($day_name)) {$_SESSION['swal_error'] = "Day Name is required.";
    } else {
        try {
            // Backend Duplicate Validation
            $dup_chk =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_days WHERE day_name = ? AND id != ? AND org_id = ? AND center_id = ?");
            $dup_chk->execute([$day_name,$edit_id, $org_id,$center_id]);
            
            if ($dup_chk->fetchColumn() > 0) {$_SESSION['swal_error'] = "This Day already exists!";
            } else {
                if ($edit_id > 0) {
                    $stmt =$tenant_pdo->prepare("UPDATE master_days SET day_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                    $stmt->execute([$day_name,$status, $edit_id,$org_id, $center_id]);$_SESSION['swal_success'] = "Day updated successfully.";
                } else {
                    $stmt =$tenant_pdo->prepare("INSERT INTO master_days (org_id, center_id, day_name, status) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$org_id, $center_id,$day_name, $status]);$_SESSION['swal_success'] = "Day added successfully.";
                }
            }
            header("Location: master_days.php");
            exit;
        } catch (PDOException $e) {$_SESSION['swal_error'] = "Database Error: " . $e->getMessage();
        }
    }
}

// Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_days SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id,$org_id, $center_id]);$_SESSION['swal_success'] = "Status updated successfully.";
    header("Location: master_days.php");
    exit;
}

$days =$tenant_pdo->prepare("SELECT * FROM master_days WHERE org_id = ? AND center_id = ? ORDER BY id ASC");
$days->execute([$org_id,$center_id]);
$days =$days->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="row g-3">
    <!-- Form Card -->
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle"><i class="bi bi-calendar-event text-primary me-1"></i> Add / Edit Day</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_day" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">Day Name *</label>
                        <!-- Added onkeyup for live duplication check -->
                        <input type="text" name="day_name" id="day_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. MONDAY" required autofocus onkeyup="checkDuplicate()">
                        <span id="name_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> This Day already exists!</span>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">Save Day</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-calendar3 text-primary me-1"></i> Days List</span>
                <div class="d-flex align-items-center gap-2">
                    <!-- Added Search Bar -->
                    <input type="text" id="searchDayInput" class="form-control form-control-sm" placeholder="Search Day..." style="max-width: 180px;">
                    <span class="badge bg-light text-secondary border"><?= count($days) ?> Days</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <!-- Added id to table -->
                    <table class="table table-hover align-middle mb-0 small" id="dayTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="width: 70px;">#</th>
                                <th>Day Name</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($days)): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No days configured.</td></tr>
                            <?php else: ?>
                                <?php foreach ($days as$d): ?>
                                    <?php $is_act = (int)$d['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $d['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($d['day_name']) ?></td>
                                        <td>
                                            <!-- SweetAlert integration for status toggle -->
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
document.getElementById('searchDayInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#dayTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

// --- Live AJAX Duplicate Check ---
let typingTimer;
function checkDuplicate() {
    clearTimeout(typingTimer);
    let nameInput = document.getElementById('day_name').value.trim();
    let editId = document.getElementById('edit_id').value;
    
    let nameError = document.getElementById('name_error');
    let btnSubmit = document.getElementById('btnSubmit');

    if (nameInput.length === 0) {
        nameError.style.display = 'none';
        btnSubmit.disabled = false;
        return;
    }

    typingTimer = setTimeout(function() {
        fetch(`master_days.php?check_duplicate=1&name=${encodeURIComponent(nameInput)}&edit_id=${editId}`)
            .then(response => response.json())
            .then(data => {
                if (data.name_exists) {
                    nameError.style.display = 'block';
                    btnSubmit.disabled = true;
                } else {
                    nameError.style.display = 'none';
                    btnSubmit.disabled = false;
                }
            })
            .catch(error => console.error('Error:', error));
    }, 300);
}

function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('day_name').value = d.day_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Day';
    document.getElementById('btnSubmit').innerText = 'Update Day';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('day_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-calendar-event text-primary me-1"></i> Add / Edit Day';
    document.getElementById('btnSubmit').innerText = 'Save Day';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('day_name').focus();
}

// --- SweetAlert for Status Toggle ---
function confirmToggle(id, currentStatus) {
    let actionText = currentStatus === 1 ? "Deactivate" : "Activate";
    let actionColor = currentStatus === 1 ? "#d33" : "#28a745";
    
    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to ${actionText} this day?`,
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
