<?php
// admin/master_financial_year.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Financial Year Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$user_id = (int)($_SESSION['user_id'] ?? 1);

// ==============================================================
// SMART AUTO-CREATE CURRENT FINANCIAL YEAR ON PAGE LOAD
// ==============================================================
try {
    $m = (int)date('m');
    $y = (int)date('y');
    $Y_full = (int)date('Y');
    
    if ($m >= 4) {
        $auto_fy_code = sprintf("%02d%02d", $y, $y + 1); // 2627
        $auto_start = "{$Y_full}-04-01";
        $auto_end = ($Y_full + 1) . "-03-31";
    } else {
        $auto_fy_code = sprintf("%02d%02d", $y - 1, $y); // 2526
        $auto_start = ($Y_full - 1) . "-04-01";
        $auto_end = "{$Y_full}-03-31";
    }

    // Check if current FY exists
    $chk_stmt = $tenant_pdo->prepare("SELECT id FROM master_financial_year WHERE fy_name = ? AND org_id = ? AND center_id = ? LIMIT 1");
    $chk_stmt->execute([$auto_fy_code, $org_id, $center_id]);
    
    if (!$chk_stmt->fetch()) {
        // Unset old currents
        $tenant_pdo->prepare("UPDATE master_financial_year SET is_current = 0 WHERE org_id = ? AND center_id = ?")->execute([$org_id, $center_id]);
        // Insert new FY automatically
        $ins = $tenant_pdo->prepare("INSERT INTO master_financial_year (org_id, center_id, fy_name, start_date, end_date, is_current, status) VALUES (?, ?, ?, ?, ?, 1, 1)");
        $ins->execute([$org_id, $center_id, $auto_fy_code, $auto_start, $auto_end]);
    }
} catch(Exception $e) {}


// 1. Handle Form Submit (Save / Update for Old/Backdated Entries)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_fy'])) {
    $fy_name    = strtoupper(trim($_POST['fy_name'] ?? ''));
    $start_date = $_POST['start_date'] ?? '';
    $end_date   = $_POST['end_date'] ?? '';
    $is_current = isset($_POST['is_current']) ? 1 : 0;
    $status     = isset($_POST['status']) ? 1 : 0;
    $edit_id    = (int)($_POST['edit_id'] ?? 0);

    if (empty($fy_name) || empty($start_date) || empty($end_date)) {
        set_flash_err("Financial Year Name, Start Date and End Date are mandatory.");
    } else {
        try {
            $tenant_pdo->beginTransaction();

            if ($is_current === 1) {
                $tenant_pdo->prepare("UPDATE master_financial_year SET is_current = 0 WHERE org_id = ? AND center_id = ?")->execute([$org_id, $center_id]);
            }

            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_financial_year SET fy_name = ?, start_date = ?, end_date = ?, is_current = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$fy_name, $start_date, $end_date, $is_current, $status, $edit_id, $org_id, $center_id]);
                set_flash_msg("Financial Year updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_financial_year (org_id, center_id, fy_name, start_date, end_date, is_current, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id, $fy_name, $start_date, $end_date, $is_current, $status, $user_id]);
                set_flash_msg("New Financial Year added successfully.");
            }
            
            $tenant_pdo->commit();
            header("Location: master_financial_year.php");
            exit;
        } catch (PDOException $e) {
            $tenant_pdo->rollBack();
            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}

// 2. Status Toggle Handlers
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_financial_year SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    header("Location: master_financial_year.php");
    exit;
}

if (isset($_GET['set_current'])) {
    $id = (int)$_GET['set_current'];
    try {
        $tenant_pdo->beginTransaction();
        $tenant_pdo->prepare("UPDATE master_financial_year SET is_current = 0 WHERE org_id = ? AND center_id = ?")->execute([$org_id, $center_id]);
        $tenant_pdo->prepare("UPDATE master_financial_year SET is_current = 1 WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$id, $org_id, $center_id]);
        $tenant_pdo->commit();
        set_flash_msg("Current Financial Year updated successfully.");
    } catch (Exception $e) {
        $tenant_pdo->rollBack();
    }
    header("Location: master_financial_year.php");
    exit;
}

// Fetch Records
$records = $tenant_pdo->prepare("SELECT * FROM master_financial_year WHERE org_id = ? AND center_id = ? ORDER BY start_date DESC");
$records->execute([$org_id, $center_id]);
$fy_list = $records->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <!-- LEFT: FORM COLUMN -->
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-calendar-check text-primary me-1"></i> Add Financial Year
                </span>
            </div>
            <div class="card-body p-3">
                <div class="alert alert-info py-2 px-3 mb-3 small border-info">
                    <i class="bi bi-info-circle-fill me-1"></i> 
                    <strong>System Auto-generates</strong> the current Financial Year. Use this form only if you need to add <strong>Old/Backdated</strong> financial years for audits.
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action_fy" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Financial Year Code *</label>
                        <input type="text" name="fy_name" id="fy_name" class="form-control form-control-sm text-uppercase fw-bold" placeholder="E.G. 2425" required autofocus oninput="this.value = this.value.toUpperCase();">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Start Date *</label>
                        <input type="date" name="start_date" id="start_date" class="form-control form-control-sm" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">End Date *</label>
                        <input type="date" name="end_date" id="end_date" class="form-control form-control-sm" required>
                    </div>

                    <div class="bg-light p-2 rounded border mb-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="is_current" id="is_current">
                            <label class="form-check-label small fw-bold text-primary" for="is_current">Set as Current Active FY</label>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                            <label class="form-check-label small fw-semibold text-secondary" for="status">Enabled Status</label>
                        </div>
                    </div>

                    <div class="d-flex gap-2 pt-2 border-top">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">Save FY</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- RIGHT: TABLE LIST COLUMN -->
    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-list-columns text-primary me-1"></i> Financial Year List</span>
                <input type="text" id="searchInput" class="form-control form-control-sm w-50" placeholder="Search FY...">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small" id="fyTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="width: 50px;">#</th>
                                <th>FY Code</th>
                                <th>Duration</th>
                                <th class="text-center">Current</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fy_list)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No financial years found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($fy_list as $row): ?>
                                    <?php 
                                        $is_act = ((int)$row['status'] === 1); 
                                        $is_cur = ((int)$row['is_current'] === 1);
                                    ?>
                                    <tr class="<?= $is_cur ? 'table-primary bg-opacity-10' : '' ?>">
                                        <td class="ps-3 font-monospace">#<?= $row['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($row['fy_name']) ?></td>
                                        <td>
                                            <div class="small fw-semibold"><?= date('d M Y', strtotime($row['start_date'])) ?></div>
                                            <div class="small text-muted">to <?= date('d M Y', strtotime($row['end_date'])) ?></div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($is_cur): ?>
                                                <span class="badge bg-primary text-white"><i class="bi bi-star-fill me-1"></i> Current</span>
                                            <?php else: ?>
                                                <a href="?set_current=<?= $row['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:0.7rem;" title="Set as Current">Make Current</a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?toggle_status=<?= $row['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($row) ?>)' title="Edit">
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
// Live Search
document.getElementById('searchInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#fyTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('fy_name').value = d.fy_name;
    document.getElementById('start_date').value = d.start_date;
    document.getElementById('end_date').value = d.end_date;
    document.getElementById('is_current').checked = (parseInt(d.is_current) === 1);
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Financial Year';
    document.getElementById('btnSubmit').innerText = 'Update FY';
    document.getElementById('fy_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('fy_name').value = '';
    document.getElementById('start_date').value = '';
    document.getElementById('end_date').value = '';
    document.getElementById('is_current').checked = false;
    document.getElementById('status').checked = true;
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-calendar-check text-primary me-1"></i> Add Financial Year';
    document.getElementById('btnSubmit').innerText = 'Save FY';
    document.getElementById('fy_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>