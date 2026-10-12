<?php
// admin/master_rooms.php
require_once __DIR__ . '/../config/tenant_db.php';

// Session start karein agar nahi hai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Room Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// 1. AJAX Handler: Check if Room No Already Exists in the selected Building & Floor
if (isset($_GET['check_name'])) {
    header('Content-Type: application/json');
    $name = strtoupper(trim($_GET['check_name']));
    $b_id = (int)($_GET['b_id'] ?? 0);
    $f_id = (int)($_GET['f_id'] ?? 0);
    $edit_id = (int)($_GET['edit_id'] ?? 0);
    
    $stmt =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_rooms WHERE room_no = ? AND building_id = ? AND floor_id = ? AND org_id = ? AND center_id = ? AND id != ?");
    $stmt->execute([$name,$b_id, $f_id,$org_id, $center_id,$edit_id]);
    $exists =$stmt->fetchColumn() > 0;
    
    echo json_encode(['exists' => $exists]);
    exit;
}

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_room'])) {$building_id = (int)($_POST['building_id'] ?? 0);$block_id    = !empty($_POST['block_id']) ? (int)$_POST['block_id'] : null;
    $floor_id    = (int)($_POST['floor_id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    // FULL CAPITALIZATION - Text hamesha uppercase mein save hoga
    $room_no     = strtoupper(trim($_POST['room_no'] ?? ''));
    $status      = isset($_POST['status']) ? 1 : 0;
    $edit_id     = (int)($_POST['edit_id'] ?? 0);

    // FIX: Using 'or' instead of symbol to prevent copy-paste corruption
    if ($building_id === 0 or $floor_id === 0 or$category_id === 0 or empty($room_no)) {$_SESSION['swal_error'] = "Building, Floor, Category and Room No are required.";
    } else {
        try {
            if ($edit_id > 0) {
                $stmt =$tenant_pdo->prepare("UPDATE master_rooms SET building_id = ?, block_id = ?, floor_id = ?, category_id = ?, room_no = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$building_id,$block_id, $floor_id,$category_id, $room_no,$status, $edit_id,$org_id, $center_id]);$_SESSION['swal_success'] = "Room updated successfully.";
            } else {
                $stmt =$tenant_pdo->prepare("INSERT INTO master_rooms (org_id, center_id, building_id, block_id, floor_id, category_id, room_no, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id,$building_id, $block_id,$floor_id, $category_id,$room_no, $status]);$_SESSION['swal_success'] = "Room added successfully.";
            }
            header("Location: master_rooms.php");
            exit;
        } catch (PDOException $e) {$_SESSION['swal_error'] = "Database Error: " . $e->getMessage();
        }
    }
}

// Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_rooms SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id,$org_id, $center_id]);$_SESSION['swal_success'] = "Status changed successfully.";
    header("Location: master_rooms.php");
    exit;
}

$buildings =$tenant_pdo->prepare("SELECT id, building_name FROM master_buildings WHERE org_id = ? AND center_id = ? AND status = 1");
$buildings->execute([$org_id,$center_id]);
$buildings =$buildings->fetchAll() ?: [];

$blocks =$tenant_pdo->prepare("SELECT id, building_id, block_name FROM master_blocks WHERE org_id = ? AND center_id = ? AND status = 1");
$blocks->execute([$org_id,$center_id]);
$blocks =$blocks->fetchAll() ?: [];

$floors =$tenant_pdo->prepare("SELECT id, building_id, block_id, floor_name FROM master_floors WHERE org_id = ? AND center_id = ? AND status = 1");
$floors->execute([$org_id,$center_id]);
$floors =$floors->fetchAll() ?: [];

$categories =$tenant_pdo->prepare("SELECT id, category_name FROM master_room_categories WHERE org_id = ? AND center_id = ? AND status = 1");
$categories->execute([$org_id,$center_id]);
$categories =$categories->fetchAll() ?: [];

$rooms =$tenant_pdo->prepare("
    SELECT r.*, b.building_name, bk.block_name, f.floor_name, c.category_name 
    FROM master_rooms r 
    JOIN master_buildings b ON r.building_id = b.id 
    LEFT JOIN master_blocks bk ON r.block_id = bk.id 
    JOIN master_floors f ON r.floor_id = f.id 
    JOIN master_room_categories c ON r.category_id = c.id 
    WHERE r.org_id = ? AND r.center_id = ? 
    ORDER BY r.id DESC
");
$rooms->execute([$org_id,$center_id]);
$rooms =$rooms->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
                        <select name="building_id" id="building_id" class="form-select form-select-sm" required onchange="filterDropdowns(); checkName();" autofocus>
                            <option value="">-- Choose Building --</option>
                            <?php foreach ($buildings as$b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['building_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Block (Optional)</label>
                        <select name="block_id" id="block_id" class="form-select form-select-sm" onchange="filterDropdowns(); checkName();">
                            <option value="">-- None / Common --</option>
                            <?php foreach ($blocks as$bk): ?>
                                <option value="<?= $bk['id'] ?>" data-bld="<?= $bk['building_id'] ?>"><?= htmlspecialchars($bk['block_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Floor *</label>
                        <select name="floor_id" id="floor_id" class="form-select form-select-sm" required onchange="checkName();">
                            <option value="">-- Choose Floor --</option>
                            <?php foreach ($floors as$f): ?>
                                <option value="<?= $f['id'] ?>" data-bld="<?= $f['building_id'] ?>" data-blk="<?= $f['block_id'] ?>"><?= htmlspecialchars($f['floor_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Room Category *</label>
                        <select name="category_id" id="category_id" class="form-select form-select-sm" required>
                            <option value="">-- Choose Category --</option>
                            <?php foreach ($categories as$c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Room No / Ward Code *</label>
                        <input type="text" name="room_no" id="room_no" class="form-control form-control-sm text-uppercase" placeholder="E.G. ROOM-101 / WARD-A" required onkeyup="checkName()">
                        <span id="name_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> This room already exists in the selected location!</span>
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
                                <?php foreach ($rooms as$r): ?>
                                    <?php $is_act = (int)$r['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-primary font-monospace text-uppercase"><?= htmlspecialchars($r['room_no']) ?></td>
                                        <td><span class="badge bg-light text-dark border text-uppercase"><?= htmlspecialchars($r['category_name']) ?></span></td>
                                        <td class="text-uppercase"><?= htmlspecialchars($r['floor_name']) ?></td>
                                        <td><small class="text-muted text-uppercase"><?= htmlspecialchars($r['building_name']) ?> <?= $r['block_name'] ? "({$r['block_name']})" : "" ?></small></td>
                                        <td>
                                            <a href="javascript:void(0);" onclick="confirmToggle(<?= $r['id'] ?>, <?=$is_act ? 1 : 0 ?>)" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
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
    
    let selectedBlkOpt = document.getElementById('block_id').selectedOptions[0];
    if(selectedBlkOpt && selectedBlkOpt.style.display === 'none') {
        document.getElementById('block_id').value = '';
        blkId = '';
    }

    document.querySelectorAll('#floor_id option').forEach(opt => {
        if (!opt.value) return;
        let matchBld = (opt.getAttribute('data-bld') === bldId || !bldId);
        let matchBlk = (!blkId || opt.getAttribute('data-blk') === blkId || !opt.getAttribute('data-blk'));
        opt.style.display = (matchBld && matchBlk) ? '' : 'none';
    });
    
    let selectedFlrOpt = document.getElementById('floor_id').selectedOptions[0];
    if(selectedFlrOpt && selectedFlrOpt.style.display === 'none') {
        document.getElementById('floor_id').value = '';
    }
}

function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('building_id').value = d.building_id;
    filterDropdowns();
    document.getElementById('block_id').value = d.block_id || '';
    filterDropdowns();
    document.getElementById('floor_id').value = d.floor_id;
    document.getElementById('category_id').value = d.category_id;
    document.getElementById('room_no').value = d.room_no;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Room';
    document.getElementById('btnSubmit').innerText = 'Update Room';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('building_id').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-door-open text-primary me-1"></i> Add Room';
    document.getElementById('btnSubmit').innerText = 'Save Room';
    
    setTimeout(() => filterDropdowns(), 50);
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('building_id').focus();
}

let typingTimer;
function checkName() {
    clearTimeout(typingTimer);
    let nameInput = document.getElementById('room_no').value.trim();
    let buildingId = document.getElementById('building_id').value;
    let floorId = document.getElementById('floor_id').value;
    let editId = document.getElementById('edit_id').value;
    let errorSpan = document.getElementById('name_error');
    let btnSubmit = document.getElementById('btnSubmit');

    if (nameInput.length === 0 || buildingId === "" || floorId === "") {
        errorSpan.style.display = 'none';
        btnSubmit.disabled = false;
        return;
    }

    typingTimer = setTimeout(function() {
        fetch(`master_rooms.php?check_name=${encodeURIComponent(nameInput)}&b_id=${buildingId}&f_id=${floorId}&edit_id=${editId}`)
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    errorSpan.style.display = 'block';
                    btnSubmit.disabled = true;
                } else {
                    errorSpan.style.display = 'none';
                    btnSubmit.disabled = false;
                }
            })
            .catch(error => console.error('Error:', error));
    }, 300);
}

function confirmToggle(id, currentStatus) {
    let actionText = currentStatus === 1 ? "Deactivate" : "Activate";
    let actionColor = currentStatus === 1 ? "#d33" : "#28a745";
    
    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to ${actionText} this room?`,
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
