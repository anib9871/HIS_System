<?php
// admin/master_payment_modes.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Payment Mode Master";

// --- 1. HANDLE STATUS TOGGLE (Active/Inactive) ---
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)$_GET['st'];
    $new_st = ($st === 1) ? 0 : 1;
    
    try {
        $tenant_pdo->prepare("UPDATE master_payment_modes SET status = ? WHERE id = ?")->execute([$new_st, $id]);
        set_flash_msg("Payment mode status updated successfully.");
    } catch (PDOException $e) {
        set_flash_err("Status update failed.");
    }
    header("Location: master_payment_modes.php");
    exit;
}

// --- 2. HANDLE ADD / UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_payment'])) {
    $mode_name = trim($_POST['mode_name'] ?? '');
    $status    = isset($_POST['status']) ? 1 : 0;
    $edit_id   = (int)($_POST['edit_id'] ?? 0);

    if (empty($mode_name)) {
        set_flash_err("Payment mode name cannot be empty.");
    } else {
        try {
            if ($edit_id > 0) {
                // Update
                $stmt = $tenant_pdo->prepare("UPDATE master_payment_modes SET mode_name = ?, status = ? WHERE id = ?");
                $stmt->execute([$mode_name, $status, $edit_id]);
                set_flash_msg("Payment mode updated successfully.");
            } else {
                // Insert
                $stmt = $tenant_pdo->prepare("INSERT INTO master_payment_modes (mode_name, status) VALUES (?, ?)");
                $stmt->execute([$mode_name, $status]);
                set_flash_msg("New payment mode added successfully.");
            }
            header("Location: master_payment_modes.php");
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error (Possible duplicate name).");
        }
    }
}

// --- 3. FETCH DATA ---
$modes = [];
try {
    $modes = $tenant_pdo->query("SELECT * FROM master_payment_modes ORDER BY id DESC")->fetchAll();
} catch (Exception $e) {}

require_once __DIR__ . '/layout_header.php';
?>

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
                        <input type="text" name="mode_name" id="mode_name" class="form-control form-control-sm" placeholder="e.g. Cash, UPI, Card" required>
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
                <span class="badge bg-light text-dark border"><?= count($modes) ?> Items</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
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
                                <?php foreach ($modes as $m): ?>
                                    <?php $is_active = (int)$m['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3"><span class="badge bg-light text-secondary border font-monospace">#<?= $m['id'] ?></span></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($m['mode_name']) ?></td>
                                        <td>
                                            <span class="badge <?= $is_active ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' ?>">
                                                <?= $is_active ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td class="text-center pe-3">
                                            <div class="d-flex align-items-center justify-content-center gap-2">
                                                <!-- Edit Button -->
                                                <button type="button" class="btn-action-edit" title="Edit Mode" onclick='editMode(<?= json_encode($m) ?>)'>
                                                    <i class="bi bi-pencil-fill" style="font-size: 0.85rem;"></i>
                                                </button>
                                                <!-- Status Toggle Switch -->
                                                <div class="form-check form-switch m-0" title="Toggle Active/Inactive">
                                                    <input class="form-check-input" type="checkbox" role="switch" 
                                                           <?= $is_active ? 'checked' : '' ?> 
                                                           onchange="window.location.href='?toggle_status=<?= $m['id'] ?>&st=<?= $m['status'] ?>'">
                                                </div>
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
// Load Data into Form for Editing
function editMode(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('mode_name').value = data.mode_name;
    document.getElementById('mode_status').checked = (parseInt(data.status) === 1);
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Payment Mode';
    document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-check2-circle me-1"></i> Update Mode';
}

// Reset Form to Add Mode State
function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-wallet2 text-primary me-1"></i> Add Payment Mode';
    document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-check2-circle me-1"></i> Save Mode';
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>