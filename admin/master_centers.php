<?php
// admin/master_centers.php

// Session start karein agar nahi hai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Master Centers / Branches";
require_once __DIR__ . '/../config/tenant_db.php';

// ==============================================================
// 1. AJAX Handler: Check if Center Name or Code Already Exists
// ==============================================================
if (isset($_GET['check_duplicate'])) {
    header('Content-Type: application/json');
    $name = strtoupper(trim($_GET['name'] ?? ''));$code = strtoupper(trim($_GET['code'] ?? ''));$edit_id = (int)($_GET['edit_id'] ?? 0);$name_exists = false;
    $code_exists = false;

    if (!empty($name)) {
        $stmt =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_centers WHERE center_name = ? AND center_id != ?");
        $stmt->execute([$name,$edit_id]);
        $name_exists =$stmt->fetchColumn() > 0;
    }

    if (!empty($code)) {
        $stmt =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_centers WHERE center_code = ? AND center_id != ?");
        $stmt->execute([$code,$edit_id]);
        $code_exists =$stmt->fetchColumn() > 0;
    }
    
    echo json_encode(['name_exists' => $name_exists, 'code_exists' =>$code_exists]);
    exit;
}

// 2. Status Toggle
if (isset($_GET['toggle_status'])) {
    $toggle_id = (int)$_GET['toggle_status'];
    $current   = (int)($_GET['current'] ?? 1);
    $new_st    = ($current === 1) ? 0 : 1;

    try {
        $stmt =$tenant_pdo->prepare("UPDATE master_centers SET status = ? WHERE center_id = ?");
        $stmt->execute([$new_st, $toggle_id]);$_SESSION['swal_success'] = "Center status updated!";
    } catch (PDOException $e) {$_SESSION['swal_error'] = "Status update failed: " . $e->getMessage();
    }
    header("Location: master_centers.php");
    exit;
}

// 3. Save / Update Center
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // FULL CAPITALIZATION - Database saving format
    $center_name    = strtoupper(trim($_POST['center_name'] ?? ''));
    $center_code    = strtoupper(trim($_POST['center_code'] ?? ''));
    $contact_person = ucwords(strtolower(trim($_POST['contact_person'] ?? '')));
    $contact_no     = trim($_POST['contact_no'] ?? '');
    $address        = trim($_POST['address'] ?? '');
    $status         = isset($_POST['status']) ? 1 : 0;
    $center_id      = (int)($_POST['center_id'] ?? 0);

    if (empty($center_name) || empty($center_code)) {$_SESSION['swal_error'] = "Center Name aur Code zaroori hain!";
    } else {
        if ($_POST['action'] === 'save_center') {
            try {
                $stmt =$tenant_pdo->prepare("
                    INSERT INTO master_centers (center_name, center_code, contact_person, contact_no, address, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$center_name, $center_code,$contact_person, $contact_no,$address, $status]);$_SESSION['swal_success'] = "Center / Branch add ho gaya!";
                header("Location: master_centers.php");
                exit;
            } catch (PDOException $e) {$_SESSION['swal_error'] = "Center Code pehle se maujood hai!";
            }
        } elseif ($_POST['action'] === 'update_center') {
            try {
                $stmt =$tenant_pdo->prepare("
                    UPDATE master_centers 
                    SET center_name = ?, center_code = ?, contact_person = ?, contact_no = ?, address = ?, status = ?
                    WHERE center_id = ?
                ");
                $stmt->execute([$center_name,$center_code, $contact_person,$contact_no, $address,$status, $center_id]);$_SESSION['swal_success'] = "Center updated successfully!";
                header("Location: master_centers.php");
                exit;
            } catch (PDOException $e) {$_SESSION['swal_error'] = "Update error: " . $e->getMessage();
            }
        }
    }
}

$centers =$tenant_pdo->query("SELECT * FROM master_centers ORDER BY center_id DESC")->fetchAll();
require_once __DIR__ . '/layout_header.php';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="row g-3">
    <!-- Form Card (Left Side) -->
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-geo-alt text-primary me-1"></i> Add Branch / Center
                </span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="master_centers.php">
                    <input type="hidden" name="action" id="form_action" value="save_center">
                    <input type="hidden" name="center_id" id="center_id" value="0">

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Center Name *</label>
                        <!-- Added onkeyup for live validation -->
                        <input type="text" name="center_name" id="center_name" class="form-control form-control-sm text-uppercase" required autofocus onkeyup="checkDuplicate()">
                        <span id="name_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> This Center Name already exists!</span>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold text-secondary mb-1">Center Code *</label>
                            <!-- Added onkeyup for live validation -->
                            <input type="text" name="center_code" id="center_code" class="form-control form-control-sm text-uppercase" required onkeyup="checkDuplicate()">
                            <span id="code_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> Code Exists!</span>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold text-secondary mb-1">Contact Person</label>
                            <input type="text" name="contact_person" id="contact_person" class="form-control form-control-sm text-capitalize">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Contact No.</label>
                        <input type="text" name="contact_no" id="contact_no" class="form-control form-control-sm">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Address</label>
                        <textarea name="address" id="address" class="form-control form-control-sm" rows="2"></textarea>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="button" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">Save Center</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Table Card (Right Side) -->
    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-geo-alt-fill text-primary me-1"></i> Centers Directory</span>
                <input type="text" id="searchCenterInput" class="form-control form-control-sm w-auto" placeholder="Search center..." style="max-width: 180px;">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small" id="centerTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Code</th>
                                <th>Center Name</th>
                                <th>In-charge</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($centers)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No centers found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($centers as$c): ?>
                                    <tr>
                                        <td class="ps-3"><span class="badge bg-light text-primary border font-monospace text-uppercase"><?= htmlspecialchars($c['center_code']) ?></span></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($c['center_name']) ?></td>
                                        <td class="text-capitalize"><?= htmlspecialchars($c['contact_person'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($c['contact_no'] ?: '-') ?></td>
                                        <td>
                                            <!-- SweetAlert attached to toggle -->
                                            <a href="javascript:void(0);" onclick="confirmToggle(<?= $c['center_id'] ?>, <?=$c['status'] ?>)" class="badge text-decoration-none <?= (int)$c['status'] === 1 ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= (int)$c['status'] === 1 ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($c) ?>)' title="Edit">
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
// --- Live AJAX Duplicate Check (Name & Code) ---
let typingTimer;
function checkDuplicate() {
    clearTimeout(typingTimer);
    let nameInput = document.getElementById('center_name').value.trim();
    let codeInput = document.getElementById('center_code').value.trim();
    let editId = document.getElementById('center_id').value;
    
    let nameError = document.getElementById('name_error');
    let codeError = document.getElementById('code_error');
    let btnSubmit = document.getElementById('btnSubmit');

    if (nameInput.length === 0 && codeInput.length === 0) {
        nameError.style.display = 'none';
        codeError.style.display = 'none';
        btnSubmit.disabled = false;
        return;
    }

    typingTimer = setTimeout(function() {
        fetch(`master_centers.php?check_duplicate=1&name=${encodeURIComponent(nameInput)}&code=${encodeURIComponent(codeInput)}&edit_id=${editId}`)
            .then(response => response.json())
            .then(data => {
                let hasError = false;
                
                if (data.name_exists && nameInput.length > 0) {
                    nameError.style.display = 'block';
                    hasError = true;
                } else {
                    nameError.style.display = 'none';
                }

                if (data.code_exists && codeInput.length > 0) {
                    codeError.style.display = 'block';
                    hasError = true;
                } else {
                    codeError.style.display = 'none';
                }

                btnSubmit.disabled = hasError;
            })
            .catch(error => console.error('Error:', error));
    }, 300);
}

// Live Real-Time Search for Centers
document.getElementById('searchCenterInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#centerTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

// JavaScript to Handle Edit without page reload
function editRow(c) {
    document.getElementById('center_id').value = c.center_id;
    document.getElementById('form_action').value = 'update_center';
    document.getElementById('center_name').value = c.center_name;
    document.getElementById('center_code').value = c.center_code;
    document.getElementById('contact_person').value = c.contact_person;
    document.getElementById('contact_no').value = c.contact_no;
    document.getElementById('address').value = c.address;
    document.getElementById('status').checked = (parseInt(c.status) === 1);
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Center / Branch';
    document.getElementById('btnSubmit').innerText = 'Update Center';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('code_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('center_name').focus();
}

// Custom Reset Function
function resetForm() {
    document.getElementById('center_id').value = '0';
    document.getElementById('form_action').value = 'save_center';
    document.getElementById('center_name').value = '';
    document.getElementById('center_code').value = '';
    document.getElementById('contact_person').value = '';
    document.getElementById('contact_no').value = '';
    document.getElementById('address').value = '';
    document.getElementById('status').checked = true;

    document.getElementById('formTitle').innerHTML = '<i class="bi bi-geo-alt text-primary me-1"></i> Add Branch / Center';
    document.getElementById('btnSubmit').innerText = 'Save Center';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('code_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('center_name').focus();
}

// --- SweetAlert for Status Toggle ---
function confirmToggle(id, currentStatus) {
    let actionText = currentStatus === 1 ? "Deactivate" : "Activate";
    let actionColor = currentStatus === 1 ? "#d33" : "#28a745";
    
    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to ${actionText} this center?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: actionColor,
        cancelButtonColor: '#6c757d',
        confirmButtonText: `Yes, ${actionText} it!`
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `?toggle_status=${id}&current=${currentStatus}`;
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
