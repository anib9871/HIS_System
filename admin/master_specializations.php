<?php
// admin/master_specializations.php

// Session start karein agar nahi hai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/tenant_db.php';

$page_title = "Doctor Specializations";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// ==============================================================
// 1. AJAX Handler: Check if Specialization Name Already Exists
// ==============================================================
if (isset($_GET['check_duplicate'])) {
    header('Content-Type: application/json');
    $name = strtoupper(trim($_GET['name'] ?? ''));$edit_id = (int)($_GET['edit_id'] ?? 0);$name_exists = false;

    if (!empty($name)) {
        $stmt =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_specializations WHERE specialization_name = ? AND id != ? AND org_id = ? AND center_id = ?");
        $stmt->execute([$name,$edit_id, $org_id,$center_id]);
        $name_exists =$stmt->fetchColumn() > 0;
    }

    echo json_encode(['name_exists' => $name_exists]);
    exit;
}

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_specialization'])) {
    // FULL CAPITALIZATION - Text hamesha uppercase mein save hoga
    $specialization_name = strtoupper(trim($_POST['specialization_name'] ?? ''));
    $status              = isset($_POST['status']) ? 1 : 0;
    $edit_id             = (int)($_POST['edit_id'] ?? 0);

    if (empty($specialization_name)) {$_SESSION['swal_error'] = "Specialization Name is required.";
    } else {
        try {
            // Backend Duplicate Validation
            $dup_chk =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_specializations WHERE specialization_name = ? AND id != ? AND org_id = ? AND center_id = ?");
            $dup_chk->execute([$specialization_name,$edit_id, $org_id,$center_id]);
            
            if ($dup_chk->fetchColumn() > 0) {$_SESSION['swal_error'] = "This Specialization already exists!";
            } else {
                if ($edit_id > 0) {
                    $stmt =$tenant_pdo->prepare("UPDATE master_specializations SET specialization_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                    $stmt->execute([$specialization_name,$status, $edit_id,$org_id, $center_id]);$_SESSION['swal_success'] = "Specialization updated successfully.";
                } else {
                    $stmt =$tenant_pdo->prepare("INSERT INTO master_specializations (org_id, center_id, specialization_name, status) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$org_id, $center_id,$specialization_name, $status]);$_SESSION['swal_success'] = "Specialization added successfully.";
                }
            }
            header("Location: master_specializations.php");
            exit;
        } catch (PDOException $e) {$_SESSION['swal_error'] = "Database Error: " . $e->getMessage();
        }
    }
}

// Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_specializations SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id,$org_id, $center_id]);$_SESSION['swal_success'] = "Status updated successfully.";
    header("Location: master_specializations.php");
    exit;
}

$specializations =$tenant_pdo->prepare("SELECT * FROM master_specializations WHERE org_id = ? AND center_id = ? ORDER BY id DESC");
$specializations->execute([$org_id,$center_id]);
$specializations =$specializations->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
                        <!-- Added onkeyup for live duplication check -->
                        <input type="text" name="specialization_name" id="specialization_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. CARDIOLOGY" required autofocus onkeyup="checkDuplicate()">
                        <span id="name_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> This Specialization already exists!</span>
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
                <div class="d-flex align-items-center gap-2">
                    <!-- Added Search Bar -->
                    <input type="text" id="searchSpecInput" class="form-control form-control-sm" placeholder="Search Specialization..." style="max-width: 180px;">
                    <span class="badge bg-light text-secondary border"><?= count($specializations) ?> Records</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <!-- Added id to table -->
                    <table class="table table-hover align-middle mb-0 small" id="specTable">
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
                                <?php foreach ($specializations as$s): ?>
                                    <?php $is_act = (int)$s['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $s['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($s['specialization_name']) ?></td>
                                        <td>
                                            <!-- SweetAlert integration for status toggle -->
                                            <a href="javascript:void(0);" onclick="confirmToggle(<?= $s['id'] ?>, <?=$is_act ? 1 : 0 ?>)" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
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
// --- Live Table Search ---
document.getElementById('searchSpecInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#specTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

// --- Live AJAX Duplicate Check ---
let typingTimer;
function checkDuplicate() {
    clearTimeout(typingTimer);
    let nameInput = document.getElementById('specialization_name').value.trim();
    let editId = document.getElementById('edit_id').value;
    
    let nameError = document.getElementById('name_error');
    let btnSubmit = document.getElementById('btnSubmit');

    if (nameInput.length === 0) {
        nameError.style.display = 'none';
        btnSubmit.disabled = false;
        return;
    }

    typingTimer = setTimeout(function() {
        fetch(`master_specializations.php?check_duplicate=1&name=${encodeURIComponent(nameInput)}&edit_id=${editId}`)
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
    document.getElementById('specialization_name').value = d.specialization_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Specialization';
    document.getElementById('btnSubmit').innerText = 'Update Specialization';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('specialization_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-heart-pulse text-primary me-1"></i> Add Specialization';
    document.getElementById('btnSubmit').innerText = 'Save Specialization';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('specialization_name').focus();
}

// --- SweetAlert for Status Toggle ---
function confirmToggle(id, currentStatus) {
    let actionText = currentStatus === 1 ? "Deactivate" : "Activate";
    let actionColor = currentStatus === 1 ? "#d33" : "#28a745";
    
    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to ${actionText} this specialization?`,
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
