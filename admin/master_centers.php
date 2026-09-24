<?php
// admin/master_centers.php
$page_title = "Master Centers / Branches";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

// 1. Status Toggle
if (isset($_GET['toggle_status'])) {
    $toggle_id = (int)$_GET['toggle_status'];
    $current   = (int)($_GET['current'] ?? 1);
    $new_st    = ($current === 1) ? 0 : 1;

    try {
        $stmt = $tenant_pdo->prepare("UPDATE master_centers SET status = ? WHERE center_id = ?");
        $stmt->execute([$new_st, $toggle_id]);
        set_flash_msg("Center status updated!");
    } catch (PDOException $e) {
        set_flash_err("Status update failed: " . $e->getMessage());
    }
    header("Location: master_centers.php");
    exit;
}

// 2. Save / Update Center
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // FULL CAPITALIZATION - Database saving format
    $center_name    = strtoupper(trim($_POST['center_name'] ?? ''));
    $center_code    = strtoupper(trim($_POST['center_code'] ?? ''));
    $contact_person = ucwords(strtolower(trim($_POST['contact_person'] ?? '')));
    $contact_no     = trim($_POST['contact_no'] ?? '');
    $address        = trim($_POST['address'] ?? '');
    $status         = isset($_POST['status']) ? 1 : 0;
    $center_id      = (int)($_POST['center_id'] ?? 0);

    if (empty($center_name) || empty($center_code)) {
        set_flash_err("Center Name aur Code zaroori hain!");
    } else {
        if ($_POST['action'] === 'save_center') {
            try {
                $stmt = $tenant_pdo->prepare("
                    INSERT INTO master_centers (center_name, center_code, contact_person, contact_no, address, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$center_name, $center_code, $contact_person, $contact_no, $address, $status]);
                set_flash_msg("Center / Branch add ho gaya!");
                header("Location: master_centers.php");
                exit;
            } catch (PDOException $e) {
                set_flash_err("Center Code pehle se maujood hai!");
            }
        } elseif ($_POST['action'] === 'update_center') {
            try {
                $stmt = $tenant_pdo->prepare("
                    UPDATE master_centers 
                    SET center_name = ?, center_code = ?, contact_person = ?, contact_no = ?, address = ?, status = ?
                    WHERE center_id = ?
                ");
                $stmt->execute([$center_name, $center_code, $contact_person, $contact_no, $address, $status, $center_id]);
                set_flash_msg("Center updated successfully!");
                header("Location: master_centers.php");
                exit;
            } catch (PDOException $e) {
                set_flash_err("Update error: " . $e->getMessage());
            }
        }
    }
}

$centers = $tenant_pdo->query("SELECT * FROM master_centers ORDER BY center_id DESC")->fetchAll();
require_once __DIR__ . '/layout_header.php';
?>

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
                        <!-- text-uppercase and autofocus -->
                        <input type="text" name="center_name" id="center_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. MAIN OPD WING" required autofocus>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold text-secondary mb-1">Center Code *</label>
                            <input type="text" name="center_code" id="center_code" class="form-control form-control-sm text-uppercase" placeholder="CEN-01" required>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold text-secondary mb-1">Contact Person</label>
                            <!-- text-capitalize for Name -->
                            <input type="text" name="contact_person" id="contact_person" class="form-control form-control-sm text-capitalize" placeholder="Manager Name">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Contact No.</label>
                        <input type="text" name="contact_no" id="contact_no" class="form-control form-control-sm" placeholder="9876543210">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Address</label>
                        <textarea name="address" id="address" class="form-control form-control-sm" rows="2" placeholder="Complete address..."></textarea>
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
                                <?php foreach ($centers as $c): ?>
                                    <tr>
                                        <td class="ps-3"><span class="badge bg-light text-primary border font-monospace"><?= htmlspecialchars($c['center_code']) ?></span></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($c['center_name']) ?></td>
                                        <td class="text-capitalize"><?= htmlspecialchars($c['contact_person'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($c['contact_no'] ?: '-') ?></td>
                                        <td>
                                            <a href="?toggle_status=<?= $c['center_id'] ?>&current=<?= $c['status'] ?>" class="badge text-decoration-none <?= (int)$c['status'] === 1 ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= (int)$c['status'] === 1 ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <!-- JS edit trigger instad of page reload -->
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
    document.getElementById('center_name').focus(); // Auto-focus on edit
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
    document.getElementById('center_name').focus(); // Auto-focus on reset
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>