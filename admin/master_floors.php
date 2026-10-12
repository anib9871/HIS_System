<?php
// admin/master_floors.php
require_once __DIR__ . '/../config/tenant_db.php';

// Session start karein agar nahi hai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Floor Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// 1. AJAX Handler: Check if Floor Name Already Exists in the selected Building & Block
if (isset($_GET['check_name'])) {
    header('Content-Type: application/json');
    $name = strtoupper(trim($_GET['check_name']));$b_id = (int)($_GET['b_id'] ?? 0);$blk_id = !empty($_GET['blk_id']) ? (int)$_GET['blk_id'] : null;
    $edit_id = (int)($_GET['edit_id'] ?? 0);
    
    // Agar block select kiya hai, toh us block mein check karo, warna wahan check karo jahan block IS NULL
    if ($blk_id) {
        $stmt =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_floors WHERE floor_name = ? AND building_id = ? AND block_id = ? AND org_id = ? AND center_id = ? AND id != ?");
        $stmt->execute([$name,$b_id, $blk_id,$org_id, $center_id,$edit_id]);
    } else {
        $stmt =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_floors WHERE floor_name = ? AND building_id = ? AND block_id IS NULL AND org_id = ? AND center_id = ? AND id != ?");
        $stmt->execute([$name, $b_id,$org_id, $center_id,$edit_id]);
    }
    $exists =$stmt->fetchColumn() > 0;
    
    echo json_encode(['exists' => $exists]);
    exit;
}

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_floor'])) {$building_id = (int)($_POST['building_id'] ?? 0);$block_id    = !empty($_POST['block_id']) ? (int)$_POST['block_id'] : null;
    // FULL CAPITALIZATION - Text hamesha uppercase mein save hoga
    $floor_name  = strtoupper(trim($_POST['floor_name'] ?? ''));
    $status      = isset($_POST['status']) ? 1 : 0;
    $edit_id     = (int)($_POST['edit_id'] ?? 0);

    if ($building_id === 0 || empty($floor_name)) {$_SESSION['swal_error'] = "Building and Floor Name are required.";
    } else {
        try {
            if ($edit_id > 0) {
                $stmt =$tenant_pdo->prepare("UPDATE master_floors SET building_id = ?, block_id = ?, floor_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$building_id,$block_id, $floor_name,$status, $edit_id,$org_id, $center_id]);$_SESSION['swal_success'] = "Floor updated successfully.";
            } else {
                $stmt =$tenant_pdo->prepare("INSERT INTO master_floors (org_id, center_id, building_id, block_id, floor_name, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$org_id, $center_id,$building_id, $block_id,$floor_name, $status]);$_SESSION['swal_success'] = "Floor added successfully.";
            }
            header("Location: master_floors.php");
            exit;
        } catch (PDOException $e) {$_SESSION['swal_error'] = "Database Error: " . $e->getMessage();
        }
    }
}

// Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_floors SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id,$org_id, $center_id]);$_SESSION['swal_success'] = "Status changed successfully.";
    header("Location: master_floors.php");
    exit;
}

$buildings =$tenant_pdo->prepare("SELECT id, building_name FROM master_buildings WHERE org_id = ? AND center_id = ? AND status = 1");
$buildings->execute([$org_id,$center_id]);
$buildings =$buildings->fetchAll() ?: [];

$blocks =$tenant_pdo->prepare("SELECT id, building_id, block_name FROM master_blocks WHERE org_id = ? AND center_id = ? AND status = 1");
$blocks->execute([$org_id,$center_id]);
$blocks =$blocks->fetchAll() ?: [];

$floors =$tenant_pdo->prepare("
    SELECT f.*, b.building_name, bk.block_name 
    FROM master_floors f 
    JOIN master_buildings b ON f.building_id = b.id 
    LEFT JOIN master_blocks bk ON f.block_id = bk.id 
    WHERE f.org_id = ? AND f.center_id = ? 
    ORDER BY f.id DESC
");
$floors->execute([$org_id,$center_id]);
$floors =$floors->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
                        <!-- Added checkName() on change -->
                        <select name="building_id" id="building_id" class="form-select form-select-sm" required onchange="filterBlocksByBuilding(); checkName();" autofocus>
                            <option value="">-- Choose Building --</option>
                            <?php foreach ($buildings as$b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['building_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Select Block (Optional)</label>
                        <!-- Added checkName() on change -->
                        <select name="block_id" id="block_id" class="form-select form-select-sm" onchange="checkName()">
                            <option value="">-- None / Common --</option>
                            <?php foreach ($blocks as$bk): ?>
                                <option value="<?= $bk['id'] ?>" data-bld="<?= $bk['building_id'] ?>"><?= htmlspecialchars($bk['block_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Floor Name *</label>
                        <!-- Added text-uppercase and checkName() onkeyup -->
                        <input type="text" name="floor_name" id="floor_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. GROUND FLOOR / 1ST FLOOR" required onkeyup="checkName()">
                        <!-- Error Message Span -->
                        <span id="name_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> This floor already exists in the selected location!</span>
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
                                <?php foreach ($floors as$f): ?>
                                    <?php $is_act = (int)$f['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $f['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($f['floor_name']) ?></td>
                                        <td><span class="badge bg-light text-secondary border"><?= htmlspecialchars($f['building_name']) ?></span></td>
                                        <td><?= htmlspecialchars($f['block_name'] ?: '-') ?></td>
                                        <td>
                                            <!-- SweetAlert applied here on click -->
                                            <a href="javascript:void(0);" onclick="confirmToggle(<?= $f['id'] ?>, <?=$is_act ? 1 : 0 ?>)" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
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
        if (!opt.value) return; // Keep the default "-- None --" visible
        opt.style.display = (opt.getAttribute('data-bld') === bldId || !bldId) ? '' : 'none';
    });
    
    // Clear block selection if it belongs to a hidden option
    let selectedOpt = document.getElementById('block_id').selectedOptions[0];
    if(selectedOpt && selectedOpt.style.display === 'none') {
        document.getElementById('block_id').value = '';
    }
}

// --- Edit and Reset ---
function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('building_id').value = d.building_id;
    filterBlocksByBuilding();
    document.getElementById('block_id').value = d.block_id || '';
    document.getElementById('floor_name').value = d.floor_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Floor';
    document.getElementById('btnSubmit').innerText = 'Update Floor';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('building_id').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-layers text-primary me-1"></i> Add Floor';
    document.getElementById('btnSubmit').innerText = 'Save Floor';
    
    setTimeout(() => filterBlocksByBuilding(), 50); // Small delay to let form reset naturally
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('building_id').focus();
}

// --- Live AJAX Name Check ---
let typingTimer;
function checkName() {
    clearTimeout(typingTimer);
    let nameInput = document.getElementById('floor_name').value.trim();
    let buildingId = document.getElementById('building_id').value;
    let blockId = document.getElementById('block_id').value;
    let editId = document.getElementById('edit_id').value;
    let errorSpan = document.getElementById('name_error');
    let btnSubmit = document.getElementById('btnSubmit');

    if (nameInput.length === 0 || buildingId === "") {
        errorSpan.style.display = 'none';
        btnSubmit.disabled = false;
        return;
    }

    typingTimer = setTimeout(function() {
        fetch(`master_floors.php?check_name=${encodeURIComponent(nameInput)}&b_id=${buildingId}&blk_id=${blockId}&edit_id=${editId}`)
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

// --- SweetAlert for Status Toggle ---
function confirmToggle(id, currentStatus) {
    let actionText = currentStatus === 1 ? "Deactivate" : "Activate";
    let actionColor = currentStatus === 1 ? "#d33" : "#28a745";
    
    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to ${actionText} this floor?`,
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
