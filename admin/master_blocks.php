<?php
// admin/master_blocks.php
require_once __DIR__ . '/../config/tenant_db.php';

// Session start karein agar nahi hai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Blocks Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// 1. AJAX Handler: Check if Block Name Already Exists in the selected Building
if (isset($_GET['check_name'])) {
    header('Content-Type: application/json');
    $name = strtoupper(trim($_GET['check_name']));
    $b_id = (int)($_GET['b_id'] ?? 0);
    $edit_id = (int)($_GET['edit_id'] ?? 0);
    
    $stmt =$tenant_pdo->prepare("SELECT COUNT(*) FROM master_blocks WHERE block_name = ? AND building_id = ? AND org_id = ? AND center_id = ? AND id != ?");
    $stmt->execute([$name, $b_id,$org_id, $center_id,$edit_id]);
    $exists =$stmt->fetchColumn() > 0;
    
    echo json_encode(['exists' => $exists]);
    exit;
}

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_block'])) {
    $building_id = (int)($_POST['building_id'] ?? 0);
    // FULL CAPITALIZATION - Text hamesha uppercase mein save hoga
    $block_name  = strtoupper(trim($_POST['block_name'] ?? ''));
    $status      = isset($_POST['status']) ? 1 : 0;
    $edit_id     = (int)($_POST['edit_id'] ?? 0);

    if ($building_id === 0 || empty($block_name)) {$_SESSION['swal_error'] = "Building and Block Name are required.";
    } else {
        try {
            if ($edit_id > 0) {
                $stmt =$tenant_pdo->prepare("UPDATE master_blocks SET building_id = ?, block_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$building_id, $block_name,$status, $edit_id,$org_id, $center_id]);$_SESSION['swal_success'] = "Block updated successfully.";
            } else {
                $stmt =$tenant_pdo->prepare("INSERT INTO master_blocks (org_id, center_id, building_id, block_name, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$org_id,$center_id, $building_id,$block_name, $status]);$_SESSION['swal_success'] = "Block added successfully.";
            }
            header("Location: master_blocks.php");
            exit;
        } catch (PDOException $e) {$_SESSION['swal_error'] = "Database Error: " . $e->getMessage();
        }
    }
}

// Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_blocks SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id,$org_id, $center_id]);$_SESSION['swal_success'] = "Status changed successfully.";
    header("Location: master_blocks.php");
    exit;
}

$buildings =$tenant_pdo->prepare("SELECT id, building_name FROM master_buildings WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY building_name ASC");
$buildings->execute([$org_id,$center_id]);
$buildings =$buildings->fetchAll() ?: [];

$blocks =$tenant_pdo->prepare("
    SELECT bk.*, b.building_name 
    FROM master_blocks bk 
    JOIN master_buildings b ON bk.building_id = b.id 
    WHERE bk.org_id = ? AND bk.center_id = ? 
    ORDER BY bk.id DESC
");
$blocks->execute([$org_id,$center_id]);
$blocks =$blocks->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
                        <!-- Added onchange event for AJAX -->
                        <select name="building_id" id="building_id" class="form-select form-select-sm" required autofocus onchange="checkName()">
                            <option value="">-- Choose Building --</option>
                            <?php foreach ($buildings as$b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['building_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Block Name *</label>
                        <!-- Added onkeyup for AJAX check -->
                        <input type="text" name="block_name" id="block_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. BLOCK A" required onkeyup="checkName()">
                        <!-- Error Message Span -->
                        <span id="name_error" class="text-danger small mt-1 fw-bold" style="display: none;"><i class="bi bi-exclamation-triangle"></i> This block already exists in the selected building!</span>
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
                                <?php foreach ($blocks as$bk): ?>
                                    <?php $is_act = (int)$bk['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= $bk['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($bk['block_name']) ?></td>
                                        <td><span class="badge bg-light text-secondary border"><?= htmlspecialchars($bk['building_name']) ?></span></td>
                                        <td>
                                            <!-- SweetAlert applied here on click -->
                                            <a href="javascript:void(0);" onclick="confirmToggle(<?= $bk['id'] ?>, <?=$is_act ? 1 : 0 ?>)" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
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
// --- Edit and Reset ---
function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('building_id').value = d.building_id;
    document.getElementById('block_name').value = d.block_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> Edit Block';
    document.getElementById('btnSubmit').innerText = 'Update Block';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('building_id').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-grid text-primary me-1"></i> Add Block';
    document.getElementById('btnSubmit').innerText = 'Save Block';
    
    document.getElementById('name_error').style.display = 'none';
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('building_id').focus();
}

// --- Live AJAX Name Check ---
let typingTimer;
function checkName() {
    clearTimeout(typingTimer);
    let nameInput = document.getElementById('block_name').value.trim();
    let buildingId = document.getElementById('building_id').value;
    let editId = document.getElementById('edit_id').value;
    let errorSpan = document.getElementById('name_error');
    let btnSubmit = document.getElementById('btnSubmit');

    if (nameInput.length === 0 || buildingId === "") {
        errorSpan.style.display = 'none';
        btnSubmit.disabled = false;
        return;
    }

    typingTimer = setTimeout(function() {
        fetch(`master_blocks.php?check_name=${encodeURIComponent(nameInput)}&b_id=${buildingId}&edit_id=${editId}`)
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
        text: `Do you want to ${actionText} this block?`,
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
