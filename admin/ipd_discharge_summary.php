<?php
// admin/ipd_discharge_summary.php
$page_title = "Discharge Summary Builder";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$user_id = (int)($_SESSION['user_id'] ?? 1);
$selected_ipd_id = (int)($_GET['ipd_id'] ?? 0);
$auto_print = isset($_GET['print']) && $_GET['print'] == '1';
$err = ''; $success = '';

/* ==========================================================
   SAVE DISCHARGE SUMMARY
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_summary'])) {
    $ipd_id = (int)$_POST['ipd_id'];
    $patient_id = (int)$_POST['patient_id'];
    $doctor_id = (int)$_POST['doctor_id'];
    $final_diagnosis = trim($_POST['final_diagnosis'] ?? '');
    $clinical_findings = trim($_POST['clinical_findings'] ?? '');
    $course_in_hospital = trim($_POST['course_in_hospital'] ?? '');
    $discharge_condition = trim($_POST['discharge_condition'] ?? 'Stable');
    $discharge_advice = trim($_POST['discharge_advice'] ?? '');
    $meds_json = trim($_POST['prescription_items_json'] ?? '[]');
    $follow_up_date = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;

    try {
        $check = $tenant_pdo->prepare("SELECT id FROM ipd_discharge_summary WHERE ipd_id = ? AND org_id = ?");
        $check->execute([$ipd_id, $org_id]);
        if ($check->fetch()) {
            $stmt = $tenant_pdo->prepare("
                UPDATE ipd_discharge_summary 
                SET final_diagnosis=?, clinical_findings=?, course_in_hospital=?, discharge_condition=?, discharge_advice=?, discharge_medications_json=?, follow_up_date=?
                WHERE ipd_id=? AND org_id=?
            ");
            $stmt->execute([$final_diagnosis, $clinical_findings, $course_in_hospital, $discharge_condition, $discharge_advice, $meds_json, $follow_up_date, $ipd_id, $org_id]);
        } else {
            $stmt = $tenant_pdo->prepare("
                INSERT INTO ipd_discharge_summary 
                (org_id, center_id, ipd_id, patient_id, doctor_id, final_diagnosis, clinical_findings, course_in_hospital, discharge_condition, discharge_advice, discharge_medications_json, follow_up_date, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$org_id, $center_id, $ipd_id, $patient_id, $doctor_id, $final_diagnosis, $clinical_findings, $course_in_hospital, $discharge_condition, $discharge_advice, $meds_json, $follow_up_date, $user_id]);
        }
        
        $_SESSION['swal_success'] = "Discharge Summary Saved Successfully!";
        header("Location: ipd_discharge_summary.php?ipd_id=" . $ipd_id);
        exit;
    } catch (Exception $e) { $err = $e->getMessage(); }
}

/* ==========================================================
   FETCH ALL PATIENTS FOR LIST VIEW
   ========================================================== */
$patients_list = [];
try {
    $q = $tenant_pdo->prepare("
        SELECT a.*, p.uhid, p.fullname, p.gender, p.age, bd.bed_no, r.room_no, d.full_name AS doctor_name, ds.id AS summary_id 
        FROM ipd_admissions a
        JOIN patient_master p ON p.patient_id = a.patient_id
        JOIN master_beds bd ON a.bed_id = bd.id
        JOIN master_rooms r ON bd.room_id = r.id
        LEFT JOIN master_doctors d ON d.id = a.doctor_id
        LEFT JOIN ipd_discharge_summary ds ON ds.ipd_id = a.ipd_id
        WHERE a.org_id = ? AND a.center_id = ? 
        ORDER BY a.ipd_id DESC
        LIMIT 100
    ");
    $q->execute([$org_id, $center_id]);
    $patients_list = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

/* ==========================================================
   FETCH ACTIVE MEDICINES FOR DATALIST
   ========================================================== */
$active_medicines = $tenant_pdo->query("SELECT medicine_name FROM master_medicines WHERE org_id = $org_id AND status = 1")->fetchAll(PDO::FETCH_ASSOC);

/* ==========================================================
   FETCH SINGLE SELECTED PATIENT DETAILS (IF ANY)
   ========================================================== */
$selected = null;
$summary_data = null;
$rx_items = [];
$total_charges = 0;
$advance_paid = 0;
$balance_due = 0;

if ($selected_ipd_id > 0) {
    foreach ($patients_list as $pt) {
        if ((int)$pt['ipd_id'] === $selected_ipd_id) { $selected = $pt; break; }
    }

    if ($selected) {
        // Fetch Summary Data
        $sStmt = $tenant_pdo->prepare("SELECT * FROM ipd_discharge_summary WHERE ipd_id = ? AND org_id = ? LIMIT 1");
        $sStmt->execute([$selected_ipd_id, $org_id]);
        $summary_data = $sStmt->fetch(PDO::FETCH_ASSOC);

        if ($summary_data && !empty($summary_data['discharge_medications_json'])) {
            $dec = json_decode($summary_data['discharge_medications_json'], true);
            if (is_array($dec)) $rx_items = $dec;
        } else {
            // Auto-fetch last diagnosis from ward rounds
            $wStmt = $tenant_pdo->prepare("SELECT diagnosis FROM ipd_ward_rounds WHERE ipd_id = ? AND diagnosis != '' ORDER BY id DESC LIMIT 1");
            $wStmt->execute([$selected_ipd_id]);
            if ($wd = $wStmt->fetch(PDO::FETCH_ASSOC)) {
                $summary_data['final_diagnosis'] = $wd['diagnosis'];
            }
        }

        // Fetch Billing Data
        $advance_paid = (float)$selected['advance_paid'];
        $bStmt = $tenant_pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM ipd_billing_services WHERE ipd_id = ? AND org_id = ?");
        $bStmt->execute([$selected_ipd_id, $org_id]);
        $total_charges = (float)$bStmt->fetchColumn();
        $balance_due = $total_charges - $advance_paid;
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<style>
body, .card, .form-control, .btn, .badge, th, td, label, select { text-transform: uppercase; }
input[type="date"], textarea { text-transform: none; }
.dd-patient-banner { background:linear-gradient(135deg,#f0fdf4,#ffffff); border:1px solid #bbf7d0; border-radius:9px; padding:12px 15px; margin-bottom: 1rem; }
.compact-input { min-height: 28px; height: 28px; font-size: 11px; padding: 2px 8px; }
.form-label { font-size: 10px; font-weight: 800; color: #475569; }
.print-only { display: none; }

/* ==========================================================
   CLEAN, COMPACT & SINGLE-PAGE PRINT CSS
   ========================================================== */
@media print {
    @page { margin: 8mm; size: A4 portrait; }
    body { background: #fff !important; font-size: 10pt; color: #000; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; line-height: 1.2; }
    
    .print-hide, .sidebar, .topbar, header, nav, footer, .btn, .card-header, .add-med-section { 
        display: none !important; 
    }
    
    .col-md-11, .col-lg-10 { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
    .card { border: none !important; box-shadow: none !important; margin: 0 !important; }
    .card-body { padding: 0 !important; }
    
    .print-only { display: block !important; }
    
    /* Compact Header */
    .invoice-header { text-align: center; border-bottom: 2px solid #222; padding-bottom: 5px; margin-bottom: 10px; }
    .invoice-header h2 { margin: 0; font-size: 16pt; font-weight: 900; color: #111; }
    .invoice-header h6 { margin: 2px 0 0 0; font-size: 10pt; font-weight: 600; color: #444; letter-spacing: 1px; }

    /* Compact Patient Banner */
    .dd-patient-banner { 
        background: transparent !important; border: none !important; border-radius: 0 !important; 
        padding: 0 0 5px 0 !important; margin-bottom: 10px !important; border-bottom: 1px dashed #aaa !important;
    }
    
    /* Remove padding from form elements */
    .form-control, .form-select { 
        border: none !important; background: transparent !important; padding: 0 !important; 
        font-weight: bold; font-size: 9.5pt !important; color: #000 !important; height: auto !important;
        resize: none; overflow: hidden; margin-bottom: 3px !important;
    }
    .form-label { font-size: 8pt; color: #555; margin-bottom: 1px; border-bottom: 1px solid #ddd; display: inline-block; padding-bottom: 1px; }
    
    /* Shrink Bootstrap Gaps */
    .row.g-4 { --bs-gutter-y: 0.5rem !important; }
    .mt-4 { margin-top: 10px !important; }
    .mb-3 { margin-bottom: 5px !important; }
    .p-3 { padding: 8px !important; }
    
    /* Compact Tables */
    .table { border-collapse: collapse !important; width: 100% !important; margin-bottom: 10px !important; }
    .table th, .table td { border: 1px solid #000 !important; padding: 4px 6px !important; font-size: 9pt !important; color: #000 !important; }
    .table th { background: #f8f9fa !important; font-weight: bold; }
    
    /* Compact Billing Summary */
    .print-bill-container { background: transparent !important; border: 1px solid #000 !important; border-radius: 0 !important; padding: 5px 10px !important; margin-top: 10px !important; }
    .print-bill-container h6 { font-size: 9pt; margin-bottom: 5px !important; }
    .print-bill-container .border-start, .print-bill-container .border-end { border-color: #000 !important; }
    .text-success, .text-danger { color: #000 !important; }
    
    /* Compact Signatures */
    .print-signatures { display: flex; justify-content: space-between; margin-top: 30px; page-break-inside: avoid; }
    .print-signatures > div { width: 35%; text-align: center; }
    .print-signatures hr { border-top: 1px solid #000; margin: 0 0 5px 0; }
    .print-signatures span { font-size: 8pt; }
}
</style>

<!-- FULL WIDTH CONTAINER -->
<div class="row justify-content-center">
    <div class="col-md-11 col-lg-10">
        
        <?php if(!$selected): ?>
            <!-- LIST VIEW (WHEN NO PATIENT IS SELECTED) -->
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-list-check text-primary me-2"></i>ALL DISCHARGE SUMMARIES</h6>
                    <input type="text" id="summarySearch" class="form-control form-control-sm w-25 compact-input" placeholder="SEARCH PATIENT OR IPD NO...">
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 75vh; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0 small" id="summaryTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th class="ps-3">IPD No.</th>
                                    <th>Patient Details</th>
                                    <th>Bed / Doctor</th>
                                    <th>Admission Status</th>
                                    <th>Summary Status</th>
                                    <th class="text-end pe-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($patients_list)): ?>
                                    <tr><td colspan="6" class="text-center py-4 text-muted fw-bold">NO PATIENTS FOUND.</td></tr>
                                <?php else: ?>
                                    <?php foreach($patients_list as $pt): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold text-primary font-monospace"><?= htmlspecialchars($pt['ipd_no']) ?></td>
                                            <td>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($pt['fullname']) ?></div>
                                                <div class="text-muted" style="font-size: 10px;"><?= htmlspecialchars($pt['uhid']) ?> | <?= !empty($pt['age']) ? $pt['age'].' Yrs' : '' ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-secondary"><?= htmlspecialchars($pt['room_no'] . ' - ' . $pt['bed_no']) ?></div>
                                                <div class="text-muted" style="font-size: 10px;">DR. <?= htmlspecialchars($pt['doctor_name'] ?? 'N/A') ?></div>
                                            </td>
                                            <td>
                                                <?php if((int)$pt['status'] === 1): ?>
                                                    <span class="badge bg-warning-subtle text-warning border border-warning px-2">ADMITTED</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success-subtle text-success border border-success px-2">DISCHARGED</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($pt['summary_id']): ?>
                                                    <span class="badge bg-success"><i class="bi bi-check2-all"></i> SAVED</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">PENDING</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="?ipd_id=<?= $pt['ipd_id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2 fw-bold" style="font-size: 10px;" title="Edit Summary">
                                                    <i class="bi bi-pencil me-1"></i> BUILD
                                                </a>
                                                <a href="?ipd_id=<?= $pt['ipd_id'] ?>&print=1" class="btn btn-sm btn-outline-dark py-1 px-2 fw-bold" style="font-size: 10px;" title="Print Summary">
                                                    <i class="bi bi-printer me-1"></i> PRINT
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
            
        <?php else: ?>
            <!-- SUMMARY BUILDER VIEW -->
            <div class="card border-0 shadow-sm mb-3 mt-3">
                <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center print-hide">
                    <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-journal-medical text-primary me-2"></i>DISCHARGE SUMMARY BUILDER</h6>
                    <a href="ipd_discharge_summary.php" class="btn btn-sm btn-outline-dark fw-bold px-3 py-1" style="font-size: 11px;"><i class="bi bi-arrow-left me-1"></i> BACK TO LIST</a>
                </div>
                <div class="card-body p-4">
                    
                    <!-- HOSPITAL PRINT HEADER -->
                    <div class="print-only invoice-header">
                        <h2><?= htmlspecialchars($_SESSION['org_name'] ?? 'HOSPITAL INVOICE') ?></h2>
                        <h6>CLINICAL DISCHARGE SUMMARY</h6>
                    </div>

                    <!-- PATIENT BANNER -->
                    <div class="dd-patient-banner d-flex justify-content-between gap-3 flex-wrap align-items-center">
                        <div>
                            <div class="fw-bold fs-5 text-dark"><?= htmlspecialchars($selected['fullname']) ?></div>
                            <div class="text-success font-monospace fw-bold" style="font-size: 12px;"><?= htmlspecialchars($selected['ipd_no']) ?> | <?= htmlspecialchars($selected['uhid']) ?></div>
                            <div class="small text-muted mt-1 print-only" style="font-size:10px;">
                                <strong>ADMITTED:</strong> <?= date('d-M-Y h:i A', strtotime($selected['admission_date'])) ?><br>
                                <strong>GENDER/AGE:</strong> <?= htmlspecialchars($selected['gender']) ?> / <?= htmlspecialchars($selected['age']) ?> YRS
                            </div>
                        </div>
                        <div class="text-end d-flex flex-column align-items-end">
                            <div class="fw-bold text-danger fs-6">BED: <?= htmlspecialchars($selected['room_no'] . ' - ' .$selected['bed_no']) ?></div>
                            <div class="small text-dark fw-bold mb-1" style="font-size:10px;">DR. <?= htmlspecialchars(strtoupper($selected['doctor_name'])) ?></div>
                            
                            <?php if($summary_data): ?>
                                <button class="btn btn-dark btn-sm fw-bold mt-1 print-hide" style="font-size:10px; padding:4px 10px;" onclick="window.print()"><i class="bi bi-printer me-1"></i> PRINT PARCHA</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="POST" id="dischargeForm">
                        <input type="hidden" name="save_summary" value="1">
                        <input type="hidden" name="ipd_id" value="<?= $selected_ipd_id ?>">
                        <input type="hidden" name="patient_id" value="<?= $selected['patient_id'] ?>">
                        <input type="hidden" name="doctor_id" value="<?= $selected['doctor_id'] ?: $user_id ?>">
                        <input type="hidden" id="prescription_items_json" name="prescription_items_json" value="<?= htmlspecialchars(json_encode($rx_items)) ?>">

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">FINAL DIAGNOSIS *</label>
                                <textarea name="final_diagnosis" class="form-control text-uppercase auto-resize" rows="2" required placeholder="E.G. ACUTE APPENDICITIS"><?= htmlspecialchars($summary_data['final_diagnosis'] ?? '') ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CLINICAL FINDINGS (ON ADMISSION)</label>
                                <textarea name="clinical_findings" class="form-control text-uppercase auto-resize" rows="2" placeholder="PRESENTING COMPLAINTS AND INITIAL VITALS..."><?= htmlspecialchars($summary_data['clinical_findings'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="col-md-12">
                                <label class="form-label">COURSE IN HOSPITAL *</label>
                                <textarea name="course_in_hospital" class="form-control text-uppercase auto-resize" rows="3" required placeholder="DETAIL THE TREATMENT GIVEN, SURGERIES PERFORMED, AND PATIENT'S RESPONSE..."><?= htmlspecialchars($summary_data['course_in_hospital'] ?? '') ?></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">DISCHARGE ADVICE / DIET</label>
                                <textarea name="discharge_advice" class="form-control text-uppercase auto-resize" rows="2" placeholder="E.G. SOFT DIET, AVOID HEAVY LIFTING..."><?= htmlspecialchars($summary_data['discharge_advice'] ?? '') ?></textarea>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">DISCHARGE CONDITION *</label>
                                <select name="discharge_condition" class="form-select text-uppercase fw-bold" required>
                                    <?php $cond = $summary_data['discharge_condition'] ?? 'Stable/Cured'; ?>
                                    <option value="Stable/Cured" <?= $cond=='Stable/Cured'?'selected':'' ?>>STABLE / CURED</option>
                                    <option value="LAMA" <?= $cond=='LAMA'?'selected':'' ?>>LAMA (LEFT AGAINST MEDICAL ADVICE)</option>
                                    <option value="Referred" <?= $cond=='Referred'?'selected':'' ?>>REFERRED TO HIGHER CENTER</option>
                                    <option value="Absconded" <?= $cond=='Absconded'?'selected':'' ?>>ABSCONDED</option>
                                    <option value="Death" <?= $cond=='Death'?'selected':'' ?>>DEATH</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">FOLLOW UP DATE</label>
                                <?php 
                                    $fu_date = $summary_data['follow_up_date'] ?? ''; 
                                    $display_date = $fu_date ? date('d-M-Y', strtotime($fu_date)) : '';
                                ?>
                                <input type="date" name="follow_up_date" class="form-control print-hide" value="<?= htmlspecialchars($fu_date) ?>">
                                <div class="print-only fw-bold mt-1"><?= $display_date ?></div>
                            </div>
                        </div>

                        <!-- DISCHARGE MEDICATIONS (MASTER SEARCH LIST) -->
                        <datalist id="medicineMasterList">
                            <?php foreach($active_medicines as $m): ?>
                                <option value="<?= htmlspecialchars($m['medicine_name'], ENT_QUOTES) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>

                        <div class="mt-4 border rounded p-3 bg-light print-med-container">
                            <h6 class="fw-bold text-dark small mb-2"><i class="bi bi-capsule text-primary me-2"></i>DISCHARGE MEDICATIONS (TO TAKE AT HOME)</h6>
                            <div class="row g-2 mb-2 add-med-section print-hide">
                                <div class="col-md-4">
                                    <input type="text" id="med_name" list="medicineMasterList" autocomplete="off" class="form-control compact-input text-uppercase" placeholder="SEARCH MEDICINE FROM MASTER">
                                </div>
                                <div class="col-md-2">
                                    <input type="text" id="med_dose" class="form-control compact-input text-uppercase" placeholder="DOSE">
                                </div>
                                <div class="col-md-3">
                                    <input type="text" id="med_freq" class="form-control compact-input text-uppercase" placeholder="FREQ. (E.G. 1-0-1)">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-primary compact-input w-100 fw-bold" onclick="addMedicine()">ADD MEDS</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered bg-white table-sm mb-0" style="font-size: 11px;" id="medTable">
                                    <thead class="table-light"><tr><th>MEDICINE</th><th>DOSE</th><th>FREQUENCY</th><th class="text-center print-hide">ACTION</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- BILLING SUMMARY SECTION -->
                        <div class="mt-4 border rounded p-3 bg-light print-bill-container">
                            <h6 class="fw-bold text-dark small mb-2"><i class="bi bi-receipt text-primary me-2"></i>BILLING SUMMARY</h6>
                            <div class="row g-3">
                                <div class="col-md-4 col-4 text-center">
                                    <div class="fw-bold text-muted" style="font-size: 10px;">TOTAL CHARGES</div>
                                    <div class="fs-6 fw-bold text-dark mt-1">₹<?= number_format($total_charges, 2) ?></div>
                                </div>
                                <div class="col-md-4 col-4 text-center border-start border-end">
                                    <div class="fw-bold text-muted" style="font-size: 10px;">ADVANCE PAID</div>
                                    <div class="fs-6 fw-bold text-success mt-1">₹<?= number_format($advance_paid, 2) ?></div>
                                </div>
                                <div class="col-md-4 col-4 text-center">
                                    <div class="fw-bold text-muted" style="font-size: 10px;">BALANCE DUE</div>
                                    <div class="fs-6 fw-bold <?= $balance_due > 0 ? 'text-danger' : 'text-success' ?> mt-1">₹<?= number_format($balance_due, 2) ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- PRINT SIGNATURE AREA (ONLY IN PRINT) -->
                        <div class="print-only print-signatures">
                            <div>
                                <hr>
                                <span>DOCTOR'S SIGNATURE</span>
                            </div>
                            <div>
                                <hr>
                                <span>PATIENT / RELATIVE SIGNATURE</span>
                            </div>
                        </div>

                        <div class="text-end mt-4 border-top pt-3 print-hide">
                            <button type="submit" class="btn btn-success fw-bold px-4 py-2"><i class="bi bi-check-circle me-2"></i> SAVE DISCHARGE SUMMARY</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
<?php if (isset($_SESSION['swal_success'])): ?>
    Swal.fire({icon: 'success', title: 'Saved!', text: '<?= $_SESSION['swal_success'] ?>', timer: 2000, showConfirmButton: false});
    <?php unset($_SESSION['swal_success']); ?>
<?php endif; ?>

<?php if ($err): ?>
    Swal.fire({icon: 'error', title: 'Error', text: '<?= $err ?>'});
<?php endif; ?>

<?php if ($auto_print): ?>
    window.onload = function() { window.print(); };
<?php endif; ?>

// Medication Logic
let medicineList = <?= json_encode($rx_items) ?>;

function addMedicine() {
    let name = document.getElementById('med_name').value.trim().toUpperCase();
    let dose = document.getElementById('med_dose').value.trim().toUpperCase();
    let freq = document.getElementById('med_freq').value.trim().toUpperCase();

    if(!name) { alert('Please enter medicine name'); return; }

    medicineList.push({name: name, dose: dose, freq: freq});
    renderMedicineTable();
    
    document.getElementById('med_name').value = '';
    document.getElementById('med_dose').value = '';
    document.getElementById('med_freq').value = '';
    document.getElementById('med_name').focus();
}

function removeMedicine(index) {
    medicineList.splice(index, 1);
    renderMedicineTable();
}

function renderMedicineTable() {
    let tbody = document.querySelector('#medTable tbody');
    let jsonField = document.getElementById('prescription_items_json');
    if (jsonField) { jsonField.value = JSON.stringify(medicineList); }

    if (!tbody) return;

    if(medicineList.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-2 fw-bold" style="font-size: 9pt;">NO MEDICINES ADDED.</td></tr>';
        return;
    }

    let html = '';
    medicineList.forEach((med, index) => {
        html += `<tr>
            <td class="fw-bold align-middle">${med.name}</td>
            <td class="align-middle">${med.dose}</td>
            <td class="align-middle">${med.freq}</td>
            <td class="text-center print-hide"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="removeMedicine(${index})"><i class="bi bi-trash"></i></button></td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

// Auto-resize textareas for print layout
function autoResizeTextarea(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
}

document.addEventListener('DOMContentLoaded', function() {
    renderMedicineTable();
    
    // Auto-resize all textareas
    document.querySelectorAll('.auto-resize').forEach(textarea => {
        autoResizeTextarea(textarea);
        textarea.addEventListener('input', function() { autoResizeTextarea(this); });
    });

    // Table Search Filter
    document.getElementById('summarySearch')?.addEventListener('keyup', function() {
        let filter = this.value.toUpperCase();
        let rows = document.querySelectorAll('#summaryTable tbody tr');
        rows.forEach(row => {
            let text = row.innerText.toUpperCase();
            row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
        });
    });
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
