<?php
// admin/master_beds.php
require_once __DIR__ . '/../config/tenant_db.php';

// Session start karein agar nahi hai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Bed Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 1); // User ID tracking ke liye

// 1. AJAX Handler: Check if Bed No Already Exists in the selected Room
if (isset($_GET['check_name'])) {
    header('Content-Type: application/json');
    $name = strtoupper(trim($_GET['check_name']));
    $r_id = (int)($_GET['r_id'] ?? 0);
    $edit_id = (int)($_GET['edit_id'] ?? 0);
    
    $stmt =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_beds WHERE bed_no = ? AND room_id = ? AND org_id = ? AND center_id = ? AND id != ?");
    $stmt->execute([$name, $r_id,$org_id, $center_id,$edit_id]);
    $exists =$stmt->fetchColumn() > 0;
    
    echo json_encode(['exists' => $exists]);
    exit;
}

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_bed'])) {
    $room_id     = (int)($_POST['room_id'] ?? 0);
    // FULL CAPITALIZATION - Text hamesha uppercase mein save hoga
    $bed_no      = strtoupper(trim($_POST['bed_no'] ?? ''));
    $status      = isset($_POST['status']) ? 1 : 0;
    $edit_id     = (int)($_POST['edit_id'] ?? 0);

    if ($room_id === 0 or empty($bed_no)) {$_SESSION['swal_error'] = "Room selection and Bed No are required.";
    } else {
        try {
            if ($edit_id > 0) {
                $stmt =$tenant_pdo->prepare("UPDATE master_beds SET room_id = ?, bed_no = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$room_id, $bed_no,$status, $edit_id,$org_id, $center_id]);$_SESSION['swal_success'] = "Bed updated successfully.";
            } else {
                $stmt =$tenant_pdo->prepare("INSERT INTO master_beds (org_id, center_id, room_id, bed_no, status, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id,$room_id, $bed_no,$status, $created_by]);$_SESSION['swal_success'] = "Bed added successfully.";
            }
            header("Location: master_beds.php");
            exit;
        } catch (PDOException $e) {$_SESSION['swal_error'] = "Database Error: " . $e->getMessage();
        }
    }
}

// Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_beds SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id,$org_id, $center_id]);$_SESSION['swal_success'] = "Status changed successfully.";
    header("Location: master_beds.php");
    exit;
}

// Fetch Master Data for Dropdowns
$buildings =$tenant_pdo->prepare("SELECT id, building_name FROM master_buildings WHERE org_id = ? AND center_id = ? AND status = 1");
$buildings->execute([$org_id,$center_id]);
$buildings =$buildings->fetchAll() ?: [];

$blocks =$tenant_pdo->prepare("SELECT id, building_id, block_name FROM master_blocks WHERE org_id = ? AND center_id = ? AND status = 1");
$blocks->execute([$org_id,$center_id]);
$blocks =$blocks->fetchAll() ?: [];

$floors =$tenant_pdo->prepare("SELECT id, building_id, block_id, floor_name FROM master_floors WHERE org_id = ? AND center_id = ? AND status = 1");
$floors->execute([$org_id,$center_id]);
$floors =$floors->fetchAll() ?: [];

$rooms =$tenant_pdo->prepare("SELECT id, building_id, block_id, floor_id, room_no FROM master_rooms WHERE org_id = ? AND center_id = ? AND status = 1");
$rooms->execute([$org_id,$center_id]);
$rooms =$rooms->fetchAll() ?: [];

// Fetch Beds Data for Directory Table
$beds =$tenant_pdo->prepare("
    SELECT bd.*, r.room_no, r.building_id, r.block_id, r.floor_id, b.building_name, bk.block_name, f.floor_name 
    FROM master_beds bd 
    JOIN master_rooms r ON bd.room_id = r.id 
    JOIN master_buildings b ON r.building_id = b.id 
    LEFT JOIN master_blocks bk ON r.block_id = bk.id 
    JOIN master_floors f ON r.floor_id = f.id 
    WHERE bd.org_id = ? AND bd.center_id = ? 
    ORDER BY bd.id DESC
");
$beds->execute([$org_id,$center_id]);
$beds =$beds->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle"><i class="bi bi-hospital text-primary me-1"></i> Add Bed</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_bed" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Building *</label>
                        <select id="building_id" class="form-select form-select-sm" required onchange="filterDropdowns(); checkName();" autofocus>
                            <option value="">-- Choose Building --</option>
                            <?php foreach ($buildings as$b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['building_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Block (Optional)</label>
                        <select id="block_id" class="form-select form-select-sm" onchange="filterDropdowns(); checkName();">
                            <option value="">-- None / Common --</option>
                            <?php foreach ($blocks as$bk): ?>
                                <option value="<?= $bk['id'] ?>" data-bld="<?= $bk['building_id'] ?>"><?= htmlspecialchars($bk['block_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Floor *</label>
                        <select id="floor_id" class="form-select form-select-sm" required onchange="filterDropdowns(); checkName();">
                            <option value="">-- Choose Floor --</option>
                            <?php foreach ($floors as$f): ?>
                                <option value="<?= $f['id'] ?>" data-bld="<?= $f['building_id'] ?>" data-blk="<?= $f['block_id'] ?>"><?= htmlspecialchars($f['floor_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Room / Ward *</label>
                        <select name="room_id" id="room_id" class="form-select form-select-sm" required onchange="checkName();">
                            <option value="">-- Choose Room --</option>
                            <?php foreach ($rooms as$r): ?>
                                <option value="<?= $r['id'] ?>" data-bld="<?= $r['building_id'] ?>" data-blk="<?= $r['block_id'] ?>" data-flr="<?= $r['floor_id'] ?>"><?= htmlspecialchars($r['room_no']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Bed No / Code *</label>
                        <input type="text" name="bed_no" id="bed_no" class="form-control form-control-sm text-uppercase" placeholder="E.G. BED-01 / ICU-1" required onkeyup="checkName()">
                        <span id="name_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> This Bed No already exists in the selected Room!</span>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">Save Bed</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark"><i class="bi bi-list-task text-primary me-1"></i> Beds Directory</span>
                <span class="badge bg-light text-secondary border"><?= count($beds) ?> Records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Bed No</th>
                                <th>Room / Ward</th>
                                <th>Floor</th>
                                <th>Building / Block</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($beds)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No beds configured.</td></tr>
                            <?php else: ?>
                                <?php foreach ($beds as$bd): ?>
                                    <?php $is_act = (int)$bd['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-primary font-monospace text-uppercase"><?= htmlspecialchars($bd['bed_no']) ?></td>
                                        <td class="text-uppercase fw-semibold"><?= htmlspecialchars($bd['room_no']) ?></td>
                                        <td class="text-uppercase"><?= htmlspecialchars($bd['floor_name']) ?></td>
                                        <td><small class="text-muted text-uppercase"><?= htmlspecialchars($bd['building_name']) ?> <?= $bd['block_name'] ? "({$bd['block_name']})" : "" ?></small></td>
                                        <td>
                                            <a href="javascript:void(0);" onclick="confirmToggle(<?= $bd['id'] ?>, <?=$is_act ? 1 : 0 ?>)" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($bd) ?>)'><i class="bi bi-pencil"></i></button>
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
    let flrId = document.getElementById('floor_id').value;

    // Filter Blocks
    document.querySelectorAll('#block_id option').forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (opt.getAttribute('data-bld') === bldId || !bldId) ? '' : 'none';
    });
    
    let selectedBlkOpt = document.getElementById('block_id').selectedOptions[0];
    if(selectedBlkOpt && selectedBlkOpt.style.display === 'none') {
        document.getElementById('block_id').value = '';
        blkId = '';
    }

    // Filter Floors
    document.querySelectorAll('#floor_id option').forEach(opt => {
        if (!opt.value) return;
        let matchBld = (opt.getAttribute('data-bld') === bldId || !bldId);
        let matchBlk = (!blkId || opt.getAttribute('data-blk') === blkId || !opt.getAttribute('data-blk'));
        opt.style.display = (matchBld && matchBlk) ? '' : 'none';
    });
    
    let selectedFlrOpt = document.getElementById('floor_id').selectedOptions[0];
    if(selectedFlrOpt && selectedFlrOpt.style.display === 'none') {
        document.getElementById('floor_id').value = '';
        flrId = '';
    }

    // Filter Rooms
    document.querySelectorAll('#room_id option').forEach(opt => {
        if (!opt.value) return;
        let matchBld = (opt.getAttribute('data-bld') === bldId || !bldId);
        let matchBlk = (!blkId || opt.getAttribute('data-blk') === blkId || !opt.getAttribute('data-blk'));
        let matchFlr = (!flrId || opt.getAttribute('data-flr') === flrId || !opt.getAttribute('data-flr'));
        opt.style.display = (matchBld && matchBlk && matchFlr) ? '' : 'none';
    });
    
    let selectedRmOpt = document.getElementById('room_id').selectedOptions[0];
    if(selectedRmOpt && selectedRmOpt.style.display === 'none') {
        document.getElementById('room_id').value = '';
    }
}

function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('building_id').value = d.building_id;
    filterDropdowns();
    document.getElementById('block_id').value = d.block_id || '';
    filterDropdowns();
    document.getElementById('floor_id').value = d.floor_id;
    filterDropdowns();
    document.getElementById('room_id').value = d.room_id;
    
    document.getElementById('bed_no').value = d.bed_no;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Bed';
    document.getElementById('btnSubmit').innerText = 'Update Bed';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('building_id').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-hospital text-primary me-1"></i> Add Bed';
    document.getElementById('btnSubmit').innerText = 'Save Bed';
    
    setTimeout(() => filterDropdowns(), 50);
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('building_id').focus();
}

let typingTimer;
function checkName() {
    clearTimeout(typingTimer);
    let nameInput = document.getElementById('bed_no').value.trim();
    let roomId = document.getElementById('room_id').value;
    let editId = document.getElementById('edit_id').value;
    let errorSpan = document.getElementById('name_error');
    let btnSubmit = document.getElementById('btnSubmit');

    if (nameInput.length === 0 || roomId === "") {
        errorSpan.style.display = 'none';
        btnSubmit.disabled = false;
        return;
    }

    typingTimer = setTimeout(function() {
        fetch(`master_beds.php?check_name=${encodeURIComponent(nameInput)}&r_id=${roomId}&edit_id=${editId}`)
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
        text: `Do you want to ${actionText} this bed?`,
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