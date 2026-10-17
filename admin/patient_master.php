<?php
// admin/patient_master.php
$page_title = "Patient Master";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php'; 

if (session_status() === PHP_SESSION_NONE) session_start();
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// ==============================================================
// 1. SMART FINANCIAL YEAR & UHID GENERATION
// ==============================================================
function getCurrentFY($tenant_pdo, $org_id, $center_id, $visit_date = null) {
    if (!$visit_date) $visit_date = date('Y-m-d');
    $time = strtotime($visit_date);
    $m = (int)date('m', $time); $y = (int)date('y', $time);
    if ($m >= 4) return sprintf("%02d%02d", $y, $y + 1); 
    return sprintf("%02d%02d", $y - 1, $y); 
}

function generateSecureUHID($tenant_pdo, $center_id, $fy_code) {
    $org_prefix = 'MAX'; $center_code = 'LKO';
    try {
        if ($f = $tenant_pdo->query("SELECT mnemonic FROM org_profile LIMIT 1")->fetchColumn()) $org_prefix = strtoupper(trim($f));
        $center_stmt = $tenant_pdo->prepare("SELECT center_code FROM master_centers WHERE center_id = ?");
        $center_stmt->execute([$center_id]);
        if ($c = $center_stmt->fetchColumn()) $center_code = strtoupper(trim($c));
    } catch (Exception $e) {}
    $uhid_base = "{$org_prefix}-{$center_code}-{$fy_code}-";
    $stmt = $tenant_pdo->prepare("SELECT uhid FROM patient_master WHERE uhid LIKE ? ORDER BY patient_id DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([$uhid_base . '%']);
    $last_uhid = $stmt->fetchColumn();
    return $uhid_base . ($last_uhid ? ((int)str_replace($uhid_base, '', $last_uhid) + 1) : 1);
}

// ==============================================================
// 2. FORM SUBMISSION (ADD / EDIT PATIENT)
// ==============================================================
$err = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_patient'])) {
    $edit_id   = (int)($_POST['patient_id'] ?? 0);
    $fullname  = ucwords(strtolower(trim($_POST['fullname'] ?? '')));
    $gender    = trim($_POST['gender'] ?? 'Male');
    $dob       = !empty($_POST['dob']) ? trim($_POST['dob']) : null;
    $age       = (int)($_POST['age'] ?? 0);
    $age_unit  = trim($_POST['age_unit'] ?? 'Yrs');
    $mobile    = trim($_POST['mobile'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $status    = isset($_POST['status']) ? 1 : 0;

    if (empty($fullname) || empty($mobile)) {$err = "Fullname and Mobile Number are required.";
    } else {
        try {
            $tenant_pdo->beginTransaction();
            if ($edit_id > 0) {
                // Update Existing
                $stmt =$tenant_pdo->prepare("UPDATE patient_master SET fullname=?, gender=?, dob=?, age=?, age_unit=?, mobile=?, address=?, status=? WHERE patient_id=? AND org_id=?");
                $stmt->execute([$fullname, $gender,$dob, $age,$age_unit, $mobile,$address, $status,$edit_id, $org_id]);$msg = "Patient details updated successfully!";
            } else {
                // Insert New
                $current_fy_code = getCurrentFY($tenant_pdo, $org_id,$center_id);
                $uhid = generateSecureUHID($tenant_pdo, $center_id,$current_fy_code);
                
                $stmt =$tenant_pdo->prepare("INSERT INTO patient_master (org_id, center_id, uhid, fullname, gender, dob, age, age_unit, mobile, address, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$org_id,$center_id, $uhid,$fullname, $gender,$dob, $age,$age_unit, $mobile,$address, $status]);$msg = "Patient registered successfully! UHID: " . $uhid;
            }
            $tenant_pdo->commit();
            if(function_exists('set_flash_msg')) set_flash_msg($msg);
            header("Location: patient_master.php"); exit;
        } catch (Exception $e) {$tenant_pdo->rollBack();
            $err = "Error: " . $e->getMessage();
        }
    }
}

// ==============================================================
// 3. FETCH EDIT DATA
// ==============================================================
$edit_data = null;
$is_edit = false;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt =$tenant_pdo->prepare("SELECT * FROM patient_master WHERE patient_id = ? AND org_id = ?");
    $stmt->execute([$edit_id,$org_id]);
    $edit_data =$stmt->fetch(PDO::FETCH_ASSOC);
    if ($edit_data)$is_edit = true;
}

// ==============================================================
// 4. FETCH ALL PATIENTS FOR LISTING
// ==============================================================
$patients =$tenant_pdo->query("SELECT * FROM patient_master WHERE org_id = $org_id ORDER BY patient_id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/layout_header.php';
?>

<style>
/* Global Uppercase UI styling */
body, .card, .card-header, .card-body, .form-label, .form-select, .form-control,
.btn, .badge, .table, th, td { text-transform: uppercase; }
input[type="number"], input[type="date"], input[inputmode="numeric"], .mob-input, #dob_display { text-transform: none; }
.visit-date-picker-wrap { position: relative; }
.native-date-picker { position: absolute; right: 0; top: 0; width: 38px; height: 31px; opacity: 0; cursor: pointer; z-index: 10; }
.patient-table-wrap { max-height: 70vh; overflow-y: auto; }
</style>

<?php if (!empty($err)): ?>
    <div id="errorMessage" class="d-none"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<div class="row g-3">
    <!-- LEFT SIDE: FORM (ADD / EDIT) -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-3 <?= $is_edit ? 'border-top border-warning border-3' : 'border-top border-primary border-3' ?>">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark small">
                    <?php if($is_edit): ?>
                        <i class="bi bi-pencil-square text-warning me-2"></i>Edit Patient
                    <?php else: ?>
                        <i class="bi bi-person-plus-fill text-primary me-2"></i>Add New Patient
                    <?php endif; ?>
                </h6>
                <?php if($is_edit): ?>
                    <a href="patient_master.php" class="btn btn-sm btn-outline-secondary py-0" style="font-size:10px; font-weight:bold;">CANCEL</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-3">
                <form method="POST" id="patientForm">
                    <input type="hidden" name="save_patient" value="1">
                    <?php if($is_edit): ?>
                        <input type="hidden" name="patient_id" value="<?= $edit_data['patient_id'] ?>">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 10px;">UHID (AUTO GENERATED)</label>
                            <input type="text" class="form-control form-control-sm bg-light text-success fw-bold font-monospace" value="<?= htmlspecialchars($edit_data['uhid']) ?>" readonly>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 10px;">FULL NAME *</label>
                        <input type="text" name="fullname" class="form-control form-control-sm text-uppercase" value="<?= $is_edit ? htmlspecialchars($edit_data['fullname']) : '' ?>" placeholder="ENTER PATIENT NAME" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 10px;">MOBILE NUMBER *</label>
                            <input type="text" name="mobile" class="form-control form-control-sm mob-input" value="<?= $is_edit ? htmlspecialchars($edit_data['mobile']) : '' ?>" placeholder="10-DIGIT MOBILE NO" maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 10px;">GENDER *</label>
                            <select name="gender" class="form-select form-select-sm" required>
                                <option value="Male" <?= ($is_edit &&$edit_data['gender']=='Male') ? 'selected' : '' ?>>MALE</option>
                                <option value="Female" <?= ($is_edit &&$edit_data['gender']=='Female') ? 'selected' : '' ?>>FEMALE</option>
                                <option value="Other" <?= ($is_edit &&$edit_data['gender']=='Other') ? 'selected' : '' ?>>OTHER</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 10px;">DOB / AGE *</label>
                        <div class="d-flex align-items-center gap-1">
                            <div class="visit-date-picker-wrap flex-grow-1" style="min-width: 0;">
                                <div class="input-group input-group-sm w-100">
                                    <input type="hidden" name="dob" id="dob" value="<?= $is_edit ? htmlspecialchars($edit_data['dob'] ?? '') : '' ?>">
                                    <input type="text" id="dob_display" class="form-control text-dark fw-bold px-1 text-center" value="<?= ($is_edit && !empty($edit_data['dob'])) ? date('d-m-Y', strtotime($edit_data['dob'])) : '' ?>" placeholder="DD-MM-YYYY" maxlength="10" autocomplete="off" inputmode="text">
                                    <button type="button" class="btn btn-outline-secondary px-2" id="dob_calendar_btn" title="Select Date of Birth" onclick="openDobPicker()"><i class="bi bi-calendar3"></i></button>
                                </div>
                                <input type="date" id="dob_picker" class="native-date-picker" value="<?= $is_edit ? htmlspecialchars($edit_data['dob'] ?? '') : '' ?>" max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="input-group input-group-sm" style="width: 120px; flex-shrink: 0;">
                                <input type="number" id="age" name="age" class="form-control form-control-sm px-1 text-center" value="<?= $is_edit ? (int)$edit_data['age'] : '' ?>" placeholder="AGE" min="0" max="150" step="1" required>
                                <select name="age_unit" id="age_unit" class="form-select form-select-sm px-1 text-center border-start-0" style="background-color: #f8f9fa;">
                                    <option value="Yrs" <?= ($is_edit &&$edit_data['age_unit']=='Yrs') ? 'selected' : '' ?>>YRS</option>
                                    <option value="Mos" <?= ($is_edit &&$edit_data['age_unit']=='Mos') ? 'selected' : '' ?>>MOS</option>
                                    <option value="Dys" <?= ($is_edit &&$edit_data['age_unit']=='Dys') ? 'selected' : '' ?>>DYS</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 10px;">ADDRESS</label>
                        <textarea name="address" class="form-control form-control-sm text-uppercase" rows="2" placeholder="FULL ADDRESS"><?= $is_edit ? htmlspecialchars($edit_data['address']) : '' ?></textarea>
                    </div>

                    <div class="mb-3 d-flex align-items-center">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="status" name="status" <?= (!$is_edit ||$edit_data['status'] == 1) ? 'checked' : '' ?>>
                            <label class="form-check-label small fw-bold ms-1" for="status">ACTIVE PATIENT</label>
                        </div>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary btn-sm fw-bold">
                            <i class="bi bi-save me-1"></i> <?= $is_edit ? 'UPDATE PATIENT' : 'SAVE PATIENT' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- RIGHT SIDE: TABLE LISTING -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-people-fill text-primary me-2"></i>Registered Patients List</h6>
                <div class="d-flex gap-2">
                    <div class="input-group input-group-sm" style="width: 250px;">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input type="text" id="searchTable" class="form-control" placeholder="SEARCH NAME, UHID, MOBILE...">
                    </div>
                    <span class="badge bg-secondary d-flex align-items-center"><?= count($patients) ?> RECORDS</span>
                </div>
            </div>
            <div class="card-body p-0 patient-table-wrap">
                <table class="table table-hover align-middle mb-0 small" id="dataTable">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-3">UHID</th>
                            <th>Patient Name</th>
                            <th>Gender / Age</th>
                            <th>Mobile</th>
                            <th>Status</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($patients)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted fw-bold">NO PATIENTS FOUND.</td></tr>
                        <?php else: ?>
                            <?php foreach ($patients as$p): ?>
                            <tr class="<?= ($is_edit && $edit_id ==$p['patient_id']) ? 'table-warning' : '' ?>">
                                <td class="ps-3 fw-bold text-primary font-monospace"><?= htmlspecialchars($p['uhid']) ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($p['fullname']) ?></td>
                                <td>
                                    <?= htmlspecialchars($p['gender']) ?> 
                                    <span class="text-muted">| <?= (int)$p['age'] . ' ' . htmlspecialchars($p['age_unit']) ?></span>
                                </td>
                                <td class="fw-semibold"><?= htmlspecialchars($p['mobile']) ?></td>
                                <td>
                                    <?php if ($p['status'] == 1): ?>
                                        <span class="badge bg-success-subtle text-success border border-success px-2 py-1">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger px-2 py-1">INACTIVE</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <a href="?edit=<?= $p['patient_id'] ?>" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center justify-content-center" style="width: 28px; height: 28px;" title="Edit Patient">
                                        <i class="bi bi-pencil" style="font-size: 12px;"></i>
                                    </a>
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

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Search Table Logic
document.getElementById('searchTable')?.addEventListener('keyup', function() {
    let filter = this.value.toUpperCase();
    let rows = document.querySelectorAll('#dataTable tbody tr');
    rows.forEach(row => {
        if(row.innerText.toUpperCase().includes(filter)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});

// Alerts Logic
document.addEventListener('DOMContentLoaded', function () {
    const errorNode = document.getElementById('errorMessage');
    if (errorNode && errorNode.textContent.trim()) {
        Swal.fire({ icon: 'error', title: 'Error', text: errorNode.textContent.trim() });
    }
});

<?php if (isset($_SESSION['swal_success'])): ?>
    Swal.fire({icon: 'success', title: 'Success!', text: '<?= $_SESSION['swal_success'] ?>', timer:2000, showConfirmButton:false});
    <?php unset($_SESSION['swal_success']); ?>
<?php endif; ?>

// ==========================================
// Date of Birth & Age Calculation Logic
// ==========================================
function formatDisplayDateDMY(isoDate) {
    if (!isoDate) return ''; const parts = String(isoDate).split('-'); if (parts.length !== 3) return ''; return `${parts[2]}-${parts[1].padStart(2, '0')}-${parts[0]}`;
}

function formatIsoDate(dateText) {
    const value = String(dateText || '').trim(); let m = value.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/); if (!m) return ''; return `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
}

function setupDobField() {
    const display = document.getElementById('dob_display'); const hidden = document.getElementById('dob'); const picker = document.getElementById('dob_picker');
    if (!display || !hidden || !picker) return;

    function syncFromIso(iso) { hidden.value = iso || ''; picker.value = iso || ''; display.value = iso ? formatDisplayDateDMY(iso) : ''; calculateAgeFromDobUI(); }
    display.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9-]/g, '').slice(0, 10); const iso = formatIsoDate(this.value);
        if (iso) { hidden.value = iso; picker.value = iso; calculateAgeFromDobUI(); } else { hidden.value = ''; }
    });
    display.addEventListener('blur', function () { const iso = formatIsoDate(this.value); if (iso) syncFromIso(iso); });
    picker.addEventListener('change', function () { if (this.value) syncFromIso(this.value); });
}

function openDobPicker() {
    const picker = document.getElementById('dob_picker'); if (picker) { try { picker.showPicker(); } catch (e) { picker.click(); } }
}

function setupAgeField() {
    const ageInput = document.getElementById('age'); const dobInput = document.getElementById('dob'); const dobDisplay = document.getElementById('dob_display'); const dobPicker = document.getElementById('dob_picker'); const ageUnit = document.getElementById('age_unit');
    if (!ageInput || !dobInput) return;
    ageInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 3);
        if (this.value === '') { dobInput.value = ''; if (dobDisplay) dobDisplay.value = ''; if (dobPicker) dobPicker.value = ''; return; }
        
        // Reverse calculate DOB based on Age and Unit
        const age = parseInt(this.value, 10); if (Number.isNaN(age) || age < 0 || age > 150) return;
        const unit = ageUnit.value;
        const base = new Date();
        let dob = new Date();
        
        if (unit === 'Yrs') {
            dob = new Date(base.getFullYear() - age, base.getMonth(), base.getDate());
        } else if (unit === 'Mos') {
            dob = new Date(base.getFullYear(), base.getMonth() - age, base.getDate());
        } else if (unit === 'Dys') {
            dob = new Date(base.getFullYear(), base.getMonth(), base.getDate() - age);
        }

        const iso = [dob.getFullYear(), String(dob.getMonth() + 1).padStart(2, '0'), String(dob.getDate()).padStart(2, '0')].join('-');
        dobInput.value = iso; if (dobPicker) dobPicker.value = iso; if (dobDisplay) dobDisplay.value = formatDisplayDateDMY(iso);
    });
    
    ageUnit.addEventListener('change', function() {
        if(ageInput.value !== '') {
            let evt = new Event('input');
            ageInput.dispatchEvent(evt);
        }
    });
}

function calculateAgeFromDobUI() {
    const dobInput = document.getElementById('dob'); const ageInput = document.getElementById('age'); const ageUnit = document.getElementById('age_unit');
    if (!dobInput || !ageInput || !ageUnit) return null;
    const dobValue = String(dobInput.value || '').trim(); if (!dobValue) { ageInput.value = ''; return null; }
    const dob = new Date(dobValue + 'T00:00:00'); if (Number.isNaN(dob.getTime())) return null;
    const today = new Date(); 
    
    let ageY = today.getFullYear() - dob.getFullYear();
    let ageM = today.getMonth() - dob.getMonth();
    let ageD = today.getDate() - dob.getDate();

    if (ageD < 0) { ageM--; }
    if (ageM < 0) { ageY--; ageM += 12; }
    
    if (ageY > 0) {
        ageInput.value = String(ageY);
        ageUnit.value = 'Yrs';
    } else if (ageM > 0) {
        ageInput.value = String(ageM);
        ageUnit.value = 'Mos';
    } else {
        ageD = Math.max(0, ageD); // Avoid negative days
        ageInput.value = String(ageD);
        ageUnit.value = 'Dys';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    setupDobField(); setupAgeField(); 
    if(!document.getElementById('dob').value) {
        // Only run UI calc on fresh load if empty
    }
    
    // Auto-uppercase inputs
    document.querySelectorAll('input[type="text"]:not(#dob_display):not(.mob-input), textarea').forEach(field => {
        field.addEventListener('input', function () { 
            const start = this.selectionStart; 
            const end = this.selectionEnd; 
            this.value = this.value.toUpperCase(); 
            if (document.activeElement === this && start !== null && end !== null) {
                try { this.setSelectionRange(start, end); } catch (e) {} 
            }
        });
    });
});

// Prevent Double Submission
document.getElementById('patientForm')?.addEventListener('submit', function(e) {
    let btn = this.querySelector('button[type="submit"]');
    if (btn) {
        if (btn.disabled) { e.preventDefault(); return false; }
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
    }
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>