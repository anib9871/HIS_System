<?php
// admin/master_states.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "State Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1); // Center ID added
$user_id = (int)($_SESSION['user_id'] ?? 1);

// 1. Auto-Seed All Indian States & UTs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seed_states'])) {
    $india_states = [
        'ANDHRA PRADESH', 'ARUNACHAL PRADESH', 'ASSAM', 'BIHAR', 'CHHATTISGARH',
        'GOA', 'GUJARAT', 'HARYANA', 'HIMACHAL PRADESH', 'JHARKHAND',
        'KARNATAKA', 'KERALA', 'MADHYA PRADESH', 'MAHARASHTRA', 'MANIPUR',
        'MEGHALAYA', 'MIZORAM', 'NAGALAND', 'ODISHA', 'PUNJAB',
        'RAJASTHAN', 'SIKKIM', 'TAMIL NADU', 'TELANGANA', 'TRIPURA',
        'UTTAR PRADESH', 'UTTARAKHAND', 'WEST BENGAL',
        'ANDAMAN AND NICOBAR ISLANDS', 'CHANDIGARH', 'DADRA AND NAGAR HAVELI AND DAMAN AND DIU',
        'LAKSHADWEEP', 'DELHI', 'PUDUCHERRY', 'LADAKH', 'JAMMU AND KASHMIR'
    ];
    
    try {
        // Center ID included in duplication check
        $existing_stmt = $tenant_pdo->prepare("SELECT UPPER(state_name) FROM master_states WHERE org_id = ? AND center_id = ?");
        $existing_stmt->execute([$org_id, $center_id]);
        $existing_states = $existing_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $stmt = $tenant_pdo->prepare("INSERT INTO master_states (org_id, center_id, state_name, status, created_by) VALUES (?, ?, ?, 1, ?)");
        $added = 0;

        foreach ($india_states as $state) {
            if (!in_array($state, $existing_states)) {
                $stmt->execute([$org_id, $center_id, $state, $user_id]);
                $added++;
            }
        }

        if ($added > 0) {
            set_flash_msg("{$added} States & UTs automatically added to the master.");
        } else {
            set_flash_msg("All States & UTs are already present.");
        }
    } catch (PDOException $e) {
        set_flash_err("Database Error: " . $e->getMessage());
    }
    header("Location: master_states.php");
    exit;
}

// 2. Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    try {
        // Center ID added to update condition
        $tenant_pdo->prepare("UPDATE master_states SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
        set_flash_msg("State status updated!");
    } catch (PDOException $e) {
        set_flash_err("Status update failed: " . $e->getMessage());
    }
    header("Location: master_states.php");
    exit;
}

// 3. Save / Update Single State
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_state'])) {
    $state_name = strtoupper(trim($_POST['state_name'] ?? ''));
    $status   = isset($_POST['status']) ? 1 : 0;
    $edit_id  = (int)($_POST['edit_id'] ?? 0);

    if (empty($state_name)) {
        set_flash_err("State Name is required.");
    } else {
        try {
            if ($edit_id > 0) {
                // Center ID added to update condition
                $stmt = $tenant_pdo->prepare("UPDATE master_states SET state_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$state_name, $status, $edit_id, $org_id, $center_id]);
                set_flash_msg("State updated successfully.");
            } else {
                // Center ID added to duplication check
                $chk = $tenant_pdo->prepare("SELECT id FROM master_states WHERE state_name = ? AND org_id = ? AND center_id = ?");
                $chk->execute([$state_name, $org_id, $center_id]);
                if ($chk->fetch()) {
                    set_flash_err("State '{$state_name}' is already added!");
                } else {
                    // Center ID added to insert
                    $stmt = $tenant_pdo->prepare("INSERT INTO master_states (org_id, center_id, state_name, status, created_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$org_id, $center_id, $state_name, $status, $user_id]);
                    set_flash_msg("State added successfully.");
                }
            }
            header("Location: master_states.php");
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}

// Fetch all states for the organization and center
$states = $tenant_pdo->prepare("SELECT * FROM master_states WHERE org_id = ? AND center_id = ? ORDER BY id DESC");
$states->execute([$org_id, $center_id]);
$states = $states->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <!-- Form Card -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom">
                <span class="fw-bold text-dark" id="formTitle"><i class="bi bi-map text-primary me-2"></i> Add / Edit State</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="master_states.php">
                    <input type="hidden" name="action_state" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">State Name *</label>
                        <input type="text" name="state_name" id="state_name" class="form-control form-control-sm text-uppercase" placeholder="e.g. UTTAR PRADESH" required autofocus>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="status" id="status" value="1" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">
                            <i class="bi bi-save me-1"></i> Save State
                        </button>
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-bold text-dark"><i class="bi bi-list-ul text-primary me-2"></i> States List</span>
                
                <div class="d-flex align-items-center gap-2">
                    <!-- AUTO-ADD ALL INDIA STATES BUTTON -->
                    <form method="POST" action="" class="m-0">
                        <input type="hidden" name="seed_states" value="1">
                        <button type="submit" class="btn btn-sm btn-success py-0 px-2 fw-semibold" style="font-size: 0.8rem;" title="Automatically add all Indian States & UTs">
                            <i class="bi bi-magic me-1"></i> Auto-Add 36 States & UTs
                        </button>
                    </form>
                    
                    <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search State..." style="width: 150px;">
                    <span class="badge bg-light text-secondary border"><?= count($states) ?> States</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 small" id="stateTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3" style="width: 70px;">#</th>
                                <th>State Name</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($states)): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No states found. Click "Auto-Add 36 States & UTs" to insert all.</td></tr>
                            <?php else: ?>
                                <?php foreach ($states as $s): ?>
                                    <?php $is_act = (int)$s['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace text-muted">#<?= $s['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($s['state_name']) ?></td>
                                        <td>
                                            <div class="form-check form-switch m-0" title="Toggle Status">
                                                <input class="form-check-input" type="checkbox" role="switch" 
                                                       <?= $is_act ? 'checked' : '' ?> 
                                                       onchange="window.location.href='master_states.php?toggle_status=<?= $s['id'] ?>&st=<?= $is_act ? 1 : 0 ?>'">
                                            </div>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($s) ?>)' title="Edit State">
                                                <i class="bi bi-pencil-fill" style="font-size: 0.85rem;"></i>
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
// Edit Data Logic
function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('state_name').value = d.state_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Edit State';
    document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-check-lg me-1"></i> Update State';
    document.getElementById('state_name').focus();
}

// Reset Form Logic
function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-map text-primary me-2"></i> Add / Edit State';
    document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-save me-1"></i> Save State';
    document.getElementById('state_name').focus();
}

// Live Search Filter Logic
document.getElementById('searchInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#stateTable tbody tr');
    rows.forEach(row => {
        if(row.innerText.toLowerCase().includes(val)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>