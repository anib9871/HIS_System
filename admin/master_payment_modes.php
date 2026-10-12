<?php
// admin/master_payment_modes.php

// Session start karein agar nahi hai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/tenant_db.php';

$page_title = "Payment Mode Master";

// ==============================================================
// 1. AJAX Handler: Check if Payment Mode Name Already Exists
// ==============================================================
if (isset($_GET['check_duplicate'])) {
    header('Content-Type: application/json');
    $name = ucwords(strtolower(trim($_GET['name'] ?? '')));$edit_id = (int)($_GET['edit_id'] ?? 0);$name_exists = false;

    if (!empty($name)) {
        $stmt =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_payment_modes WHERE mode_name = ? AND id != ?");
        $stmt->execute([$name,$edit_id]);
        $name_exists =$stmt->fetchColumn() > 0;
    }

    echo json_encode(['name_exists' => $name_exists]);
    exit;
}

// --- 2. HANDLE STATUS TOGGLE (Active/Inactive) ---
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)$_GET['st'];
    $new_st = ($st === 1) ? 0 : 1;
    
    try {
        $tenant_pdo->prepare("UPDATE master_payment_modes SET status = ? WHERE id = ?")->execute([$new_st, $id]);$_SESSION['swal_success'] = "Payment mode status updated successfully.";
    } catch (PDOException $e) {$_SESSION['swal_error'] = "Status update failed.";
    }
    header("Location: master_payment_modes.php");
    exit;
}

// --- 3. HANDLE ADD / UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_payment'])) {
    // First letter of each word capital (Title Case)
    $mode_name = ucwords(strtolower(trim($_POST['mode_name'] ?? '')));
    $status    = isset($_POST['status']) ? 1 : 0;
    $edit_id   = (int)($_POST['edit_id'] ?? 0);

    if (empty($mode_name)) {$_SESSION['swal_error'] = "Payment mode name cannot be empty.";
    } else {
        try {
            // Backend Duplicate Validation
            $dup_chk =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_payment_modes WHERE mode_name = ? AND id != ?");
            $dup_chk->execute([$mode_name,$edit_id]);
            
            if ($dup_chk->fetchColumn() > 0) {$_SESSION['swal_error'] = "This payment mode already exists!";
            } else {
                if ($edit_id > 0) {
                    // Update
                    $stmt =$tenant_pdo->prepare("UPDATE master_payment_modes SET mode_name = ?, status = ? WHERE id = ?");
                    $stmt->execute([$mode_name,$status, $edit_id]);$_SESSION['swal_success'] = "Payment mode updated successfully.";
                } else {
                    // Insert
                    $stmt =$tenant_pdo->prepare("INSERT INTO master_payment_modes (mode_name, status) VALUES (?, ?)");
                    $stmt->execute([$mode_name, $status]);$_SESSION['swal_success'] = "New payment mode added successfully.";
                }
            }
            header("Location: master_payment_modes.php");
            exit;
        } catch (PDOException $e) {$_SESSION['swal_error'] = "Database Error: " . $e->getMessage();
        }
    }
}

// --- 4. FETCH DATA ---
$modes = [];
try {
    $modes =$tenant_pdo->query("SELECT * FROM master_payment_modes ORDER BY id DESC")->fetchAll();
} catch (Exception $e) {}

require_once __DIR__ . '/layout_header.php';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* Custom Interactive Toggle & Edit Button */
.form-switch .form-check-input {
    width: 2.4em;
    height: 1.25em;
    cursor: pointer;
}
.btn-action-edit {
    background-color: #e0f2fe;
    color: #0284c7;
    border: none;
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: 0.2s;
    cursor: pointer;
}
.btn-action-edit:hover {
    background-color: #0284c7;
    color: #fff;
}
</style>

<div class="row g-3">
    <!-- Left Side: Form -->
    <div class="col-xl-4 col-lg-5">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-wallet2 text-primary me-1"></i> Add Payment Mode
                </span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_payment" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">Payment Mode Name *</label>
                        <!-- Added onkeyup for live check and format input -->
                        <input type="text" name="mode_name" id="mode_name" class="form-control form-control-sm text-capitalize" placeholder="e.g. Cash, UPI, Card" required autofocus onkeyup="checkDuplicate(); formatTitleCase(this);">
                        <span id="name_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> This payment mode already exists!</span>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="mode_status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="mode_status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2 pt-2 border-top">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">
                            <i class="bi bi-check2-circle me-1"></i> Save Mode
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Side: Data Table -->
    <div class="col-xl-8 col-lg-7">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-list-check text-primary me-1"></i> Configured Payment Modes</span>
                <div class="d-flex align-items-center gap-2">
                    <!-- Added Search Bar -->
                    <input type="text" id="searchModeInput" class="form-control form-control-sm" placeholder="Search mode..." style="max-width: 180px;">
                    <span class="badge bg-light text-dark border"><?= count($modes) ?> Items</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <!-- Added id to table -->
                    <table class="table table-hover align-middle mb-0 small" id="modeTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">ID</th>
                                <th>Mode Name</th>
                                <th>Status</th>
                                <th class="text-center pe-3" style="width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($modes)): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No payment modes found. Add one from the form.</td></tr>
                            <?php else: ?>
                                <?php foreach ($modes as$m): ?>
                                    <?php $is_active = (int)$m['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3"><span class="badge bg-light text-secondary border font-monospace">#<?= $m['id'] ?></span></td>
                                        <td class="fw-bold text-dark text-capitalize"><?= htmlspecialchars($m['mode_name']) ?></td>
                                        <td>
                                            <!-- SweetAlert integration for status toggle -->
                                            <a href="javascript:void(0);" onclick="confirmToggle(<?= $m['id'] ?>, <?=$m['status'] ?>)" class="badge text-decoration-none <?= $is_active ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_active ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-center pe-3">
                                            <div class="d-flex align-items-center justify-content-center gap-2">
                                                <!-- Edit Button -->
                                                <button type="button" class="btn-action-edit" title="Edit Mode" onclick='editMode(<?= json_encode($m) ?>)'>
                                                    <i class="bi bi-pencil-fill" style="font-size: 0.85rem;"></i>
                                                </button>
                                            </div>
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
// --- Auto Format First Letter Capital (Title Case) while typing ---
function formatTitleCase(input) {
    let start = input.selectionStart;
    let end = input.selectionEnd;
    
    // Convert words to title case
    let val = input.value.toLowerCase().replace(/(?:^|\s)\S/g, function(a) { return a.toUpperCase(); });
    input.value = val;
    
    input.setSelectionRange(start, end);
}

// --- Live Table Search ---
document.getElementById('searchModeInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#modeTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

// --- Live AJAX Duplicate Check ---
let typingTimer;
function checkDuplicate() {
    clearTimeout(typingTimer);
    let nameInput = document.getElementById('mode_name').value.trim();
    let editId = document.getElementById('edit_id').value;
    
    let nameError = document.getElementById('name_error');
    let btnSubmit = document.getElementById('btnSubmit');

    if (nameInput.length === 0) {
        nameError.style.display = 'none';
        btnSubmit.disabled = false;
        return;
    }

    typingTimer = setTimeout(function() {
        fetch(`master_payment_modes.php?check_duplicate=1&name=${encodeURIComponent(nameInput)}&edit_id=${editId}`)
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

// Load Data into Form for Editing
function editMode(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('mode_name').value = data.mode_name;
    document.getElementById('mode_status').checked = (parseInt(data.status) === 1);
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Payment Mode';
    document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-check2-circle me-1"></i> Update Mode';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('mode_name').focus();
}

// Reset Form to Add Mode State
function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-wallet2 text-primary me-1"></i> Add Payment Mode';
    document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-check2-circle me-1"></i> Save Mode';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('mode_name').focus();
}

// --- SweetAlert for Status Toggle ---
function confirmToggle(id, currentStatus) {
    let actionText = currentStatus === 1 ? "Deactivate" : "Activate";
    let actionColor = currentStatus === 1 ? "#d33" : "#28a745";
    
    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to ${actionText} this payment mode?`,
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
            title: 'Oops...',
            text: '<?= addslashes($_SESSION['swal_error']) ?>',
            confirmButtonColor: '#d33'
        });
        <?php unset($_SESSION['swal_error']); ?>
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
