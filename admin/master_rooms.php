<?php
// admin/master_rooms.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Room Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_room'])) {
    $building_id = (int)($_POST['building_id'] ?? 0);
    $block_id    = !empty($_POST['block_id']) ? (int)$_POST['block_id'] : null;
    $floor_id    = (int)($_POST['floor_id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    $room_no     = trim($_POST['room_no'] ?? ''); // Text type
    $status      = isset($_POST['status']) ? 1 : 0;
    $edit_id     = (int)($_POST['edit_id'] ?? 0);

    if ($building_id === 0 || $floor_id === 0 || $category_id === 0 || empty($room_no)) {
        set_flash_err("Building, Floor, Category and Room No are required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_rooms SET building_id = ?, block_id = ?, floor_id = ?, category_id = ?, room_no = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$building_id, $block_id, $floor_id, $category_id, $room_no, $status, $edit_id, $org_id, $center_id]);
                set_flash_msg("Room updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_rooms (org_id, center_id, building_id, block_id, floor_id, category_id, room_no, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id, $building_id, $block_id, $floor_id, $category_id, $room_no, $status]);
                set_flash_msg("Room added successfully.");
            }
            header("Location: master_rooms.php");
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
    $tenant_pdo->prepare("UPDATE master_rooms SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    header("Location: master_rooms.php");
    exit;
}

$buildings = $tenant_pdo->prepare("SELECT id, building_name FROM master_buildings WHERE org_id = ? AND center_id = ? AND status = 1");
$buildings->execute([$org_id, $center_id]);
$buildings = $buildings->fetchAll() ?: [];

$blocks = $tenant_pdo->prepare("SELECT id, building_id, block_name FROM master_blocks WHERE org_id = ? AND center_id = ? AND status = 1");
$blocks->execute([$org_id, $center_id]);
$blocks = $blocks->fetchAll() ?: [];

$floors = $tenant_pdo->prepare("SELECT id, building_id, block_id, floor_name FROM master_floors WHERE org_id = ? AND center_id = ? AND status = 1");
$floors->execute([$org_id, $center_id]);
$floors = $floors->fetchAll() ?: [];

$categories = $tenant_pdo->prepare("SELECT id, category_name FROM master_room_categories WHERE org_id = ? AND center_id = ? AND status = 1");
$categories->execute([$org_id, $center_id]);
$categories = $categories->fetchAll() ?: [];

$rooms = $tenant_pdo->prepare("
    SELECT r.*, b.building_name, bk.block_name, f.floor_name, c.category_name 
    FROM master_rooms r 
    JOIN master_buildings b ON r.building_id = b.id 
    LEFT JOIN master_blocks bk ON r.block_id = bk.id 
    JOIN master_floors f ON r.floor_id = f.id 
    JOIN master_room_categories c ON r.category_id = c.id 
    WHERE r.org_id = ? AND r.center_id = ? 
    ORDER BY r.id DESC
");
$rooms->execute([$org_id, $center_id]);
$rooms = $rooms->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle"><i class="bi bi-door-open text-primary me-1"></i> Add Room</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_room" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Building *</label>
                        <select name="building_id" id="building_id" class="form-select form-select-sm" required onchange="filterDropdowns()">
                            <option value="">-- Choose Building --</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['building_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Block (Optional)</label>
                        <select name="block_id" id="block_id" class="form-select form-select-sm" onchange="filterDropdowns()">
                            <option value="">-- None / Common --</option>
                            <?php foreach ($blocks as $bk): ?>
                                <option value="<?= $bk['id'] ?>" data-bld="<?= $bk['building_id'] ?>"><?= htmlspecialchars($bk['block_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Floor *</label>
                        <select name="floor_id" id="floor_id" class="form-select form-select-sm" required>
                            <option value="">-- Choose Floor --</option>
                            <?php foreach ($floors as $f): ?>
                                <option value="<?= $f['id'] ?>" data-bld="<?= $f['building_id'] ?>" data-blk="<?= $f['block_id'] ?>"><?= htmlspecialchars($f['floor_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Room Category *</label>
                        <select name="category_id" id="category_id" class="form-select form-select-sm" required>
                            <option value="">-- Choose Category --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Room No / Ward Code *</label>
                        <input type="text" name="room_no" id="room_no" class="form-control form-control-sm" placeholder="e.g. Room-101 / Ward-A" required>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">Save Room</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-list-task text-primary me-1"></i> Rooms Directory</span>
                <span class="badge bg-light text-secondary border"><?= count($rooms) ?> Records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Room No</th>
                                <th>Category</th>
                                <th>Floor</th>
                                <th>Building / Block</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rooms)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No rooms configured.</td></tr>
                            <?php else: ?>
                                <?php foreach ($rooms as $r): ?>
                                    <?php $is_act = (int)$r['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-primary font-monospace"><?= htmlspecialchars($r['room_no']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($r['category_name']) ?></span></td>
                                        <td><?= htmlspecialchars($r['floor_name']) ?></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($r['building_name']) ?> <?= $r['block_name'] ? "({$r['block_name']})" : "" ?></small></td>
                                        <td>
                                            <a href="?toggle_status=<?= $r['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($r) ?>)'><i class="bi bi-pencil"></i></button>
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
function filterDropdowns() {
    let bldId = document.getElementById('building_id').value;
    let blkId = document.getElementById('block_id').value;

    document.querySelectorAll('#block_id option').forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (opt.getAttribute('data-bld') === bldId || !bldId) ? '' : 'none';
    });

    document.querySelectorAll('#floor_id option').forEach(opt => {
        if (!opt.value) return;
        let matchBld = (opt.getAttribute('data-bld') === bldId || !bldId);
        let matchBlk = (!blkId || opt.getAttribute('data-blk') === blkId || !opt.getAttribute('data-blk'));
        opt.style.display = (matchBld && matchBlk) ? '' : 'none';
    });
}

function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('building_id').value = d.building_id;
    filterDropdowns();
    document.getElementById('block_id').value = d.block_id || '';
    document.getElementById('floor_id').value = d.floor_id;
    document.getElementById('category_id').value = d.category_id;
    document.getElementById('room_no').value = d.room_no;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Room';
    document.getElementById('btnSubmit').innerText = 'Update Room';
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-door-open text-primary me-1"></i> Add Room';
    document.getElementById('btnSubmit').innerText = 'Save Room';
    filterDropdowns();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>