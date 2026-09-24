<?php
// admin/master_blocks.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Blocks Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_block'])) {
    $building_id = (int)($_POST['building_id'] ?? 0);
    // FULL CAPITALIZATION - Text hamesha uppercase mein save hoga
    $block_name  = strtoupper(trim($_POST['block_name'] ?? ''));
    $status      = isset($_POST['status']) ? 1 : 0;
    $edit_id     = (int)($_POST['edit_id'] ?? 0);

    if ($building_id === 0 || empty($block_name)) {
        set_flash_err("Building and Block Name are required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_blocks SET building_id = ?, block_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$building_id, $block_name, $status, $edit_id, $org_id, $center_id]);
                set_flash_msg("Block updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_blocks (org_id, center_id, building_id, block_name, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id, $building_id, $block_name, $status]);
                set_flash_msg("Block added successfully.");
            }
            header("Location: master_blocks.php");
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}

// Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_blocks SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    header("Location: master_blocks.php");
    exit;
}

$buildings = $tenant_pdo->prepare("SELECT id, building_name FROM master_buildings WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY building_name ASC");
$buildings->execute([$org_id, $center_id]);
$buildings = $buildings->fetchAll() ?: [];

$blocks = $tenant_pdo->prepare("
    SELECT bk.*, b.building_name 
    FROM master_blocks bk 
    JOIN master_buildings b ON bk.building_id = b.id 
    WHERE bk.org_id = ? AND bk.center_id = ? 
    ORDER BY bk.id DESC
");
$blocks->execute([$org_id, $center_id]);
$blocks = $blocks->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <!-- Form Card (Left Side) -->
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle"><i class="bi bi-grid text-primary me-1"></i> Add Block</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_block" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Building *</label>
                        <!-- autofocus to ensure cursor is ready here -->
                        <select name="building_id" id="building_id" class="form-select form-select-sm" required autofocus>
                            <option value="">-- Choose Building --</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['building_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Block Name *</label>
                        <!-- text-uppercase class added -->
                        <input type="text" name="block_name" id="block_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. BLOCK A" required>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">Save Block</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Table Card (Right Side) -->
    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-list-task text-primary me-1"></i> Blocks Directory</span>
                <span class="badge bg-light text-secondary border"><?= count($blocks) ?> Records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="width: 60px;">#</th>
                                <th>Block Name</th>
                                <th>Building</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($blocks)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No blocks found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($blocks as $bk): ?>
                                    <?php $is_act = (int)$bk['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $bk['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($bk['block_name']) ?></td>
                                        <td><span class="badge bg-light text-secondary border"><?= htmlspecialchars($bk['building_name']) ?></span></td>
                                        <td>
                                            <a href="?toggle_status=<?= $bk['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($bk) ?>)'><i class="bi bi-pencil"></i></button>
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
function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('building_id').value = d.building_id;
    document.getElementById('block_name').value = d.block_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Block';
    document.getElementById('btnSubmit').innerText = 'Update Block';
    document.getElementById('building_id').focus(); // Cursor ready for edit
}
function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-grid text-primary me-1"></i> Add Block';
    document.getElementById('btnSubmit').innerText = 'Save Block';
    document.getElementById('building_id').focus(); // Cursor ready on reset
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>