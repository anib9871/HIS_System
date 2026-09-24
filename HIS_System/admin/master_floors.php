<?php
// admin/master_floors.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Floor Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_floor'])) {
    $building_id = (int)($_POST['building_id'] ?? 0);
    $block_id    = !empty($_POST['block_id']) ? (int)$_POST['block_id'] : null;
    $floor_name  = trim($_POST['floor_name'] ?? '');
    $status      = isset($_POST['status']) ? 1 : 0;
    $edit_id     = (int)($_POST['edit_id'] ?? 0);

    if ($building_id === 0 || empty($floor_name)) {
        set_flash_err("Building and Floor Name are required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_floors SET building_id = ?, block_id = ?, floor_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$building_id, $block_id, $floor_name, $status, $edit_id, $org_id, $center_id]);
                set_flash_msg("Floor updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_floors (org_id, center_id, building_id, block_id, floor_name, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id, $building_id, $block_id, $floor_name, $status]);
                set_flash_msg("Floor added successfully.");
            }
            header("Location: master_floors.php");
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
    $tenant_pdo->prepare("UPDATE master_floors SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    header("Location: master_floors.php");
    exit;
}

$buildings = $tenant_pdo->prepare("SELECT id, building_name FROM master_buildings WHERE org_id = ? AND center_id = ? AND status = 1");
$buildings->execute([$org_id, $center_id]);
$buildings = $buildings->fetchAll() ?: [];

$blocks = $tenant_pdo->prepare("SELECT id, building_id, block_name FROM master_blocks WHERE org_id = ? AND center_id = ? AND status = 1");
$blocks->execute([$org_id, $center_id]);
$blocks = $blocks->fetchAll() ?: [];

$floors = $tenant_pdo->prepare("
    SELECT f.*, b.building_name, bk.block_name 
    FROM master_floors f 
    JOIN master_buildings b ON f.building_id = b.id 
    LEFT JOIN master_blocks bk ON f.block_id = bk.id 
    WHERE f.org_id = ? AND f.center_id = ? 
    ORDER BY f.id DESC
");
$floors->execute([$org_id, $center_id]);
$floors = $floors->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle"><i class="bi bi-layers text-primary me-1"></i> Add Floor</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_floor" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Building *</label>
                        <select name="building_id" id="building_id" class="form-select form-select-sm" required onchange="filterBlocksByBuilding()">
                            <option value="">-- Choose Building --</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['building_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Block (Optional)</label>
                        <select name="block_id" id="block_id" class="form-select form-select-sm">
                            <option value="">-- None / Common --</option>
                            <?php foreach ($blocks as $bk): ?>
                                <option value="<?= $bk['id'] ?>" data-bld="<?= $bk['building_id'] ?>"><?= htmlspecialchars($bk['block_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Floor Name *</label>
                        <input type="text" name="floor_name" id="floor_name" class="form-control form-control-sm" placeholder="e.g. Ground Floor / 1st Floor" required>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">Save Floor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-list-task text-primary me-1"></i> Floors Directory</span>
                <span class="badge bg-light text-secondary border"><?= count($floors) ?> Records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">#</th>
                                <th>Floor Name</th>
                                <th>Building</th>
                                <th>Block</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($floors)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No floors found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($floors as $f): ?>
                                    <?php $is_act = (int)$f['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $f['id'] ?></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($f['floor_name']) ?></td>
                                        <td><span class="badge bg-light text-secondary border"><?= htmlspecialchars($f['building_name']) ?></span></td>
                                        <td><?= htmlspecialchars($f['block_name'] ?: '-') ?></td>
                                        <td>
                                            <a href="?toggle_status=<?= $f['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($f) ?>)'><i class="bi bi-pencil"></i></button>
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
function filterBlocksByBuilding() {
    let bldId = document.getElementById('building_id').value;
    let blockOpts = document.querySelectorAll('#block_id option');
    blockOpts.forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (opt.getAttribute('data-bld') === bldId || !bldId) ? '' : 'none';
    });
}

function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('building_id').value = d.building_id;
    filterBlocksByBuilding();
    document.getElementById('block_id').value = d.block_id || '';
    document.getElementById('floor_name').value = d.floor_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Floor';
    document.getElementById('btnSubmit').innerText = 'Update Floor';
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-layers text-primary me-1"></i> Add Floor';
    document.getElementById('btnSubmit').innerText = 'Save Floor';
    filterBlocksByBuilding();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>