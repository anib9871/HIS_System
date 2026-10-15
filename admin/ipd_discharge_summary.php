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
$err = '';$success = '';

/* ==========================================================
   SAVE DISCHARGE SUMMARY
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_summary'])) {$ipd_id = (int)$_POST['ipd_id'];$patient_id = (int)$_POST['patient_id'];$doctor_id = (int)$_POST['doctor_id'];$final_diagnosis = trim($_POST['final_diagnosis'] ?? '');$clinical_findings = trim($_POST['clinical_findings'] ?? '');$course_in_hospital = trim($_POST['course_in_hospital'] ?? '');$discharge_condition = trim($_POST['discharge_condition'] ?? 'Stable');$discharge_advice = trim($_POST['discharge_advice'] ?? '');$meds_json = trim($_POST['prescription_items_json'] ?? '[]');$follow_up_date = !empty($_POST['follow_up_date']) ?$_POST['follow_up_date'] : null;

    try {
        $check =$tenant_pdo->prepare("SELECT id FROM ipd_discharge_summary WHERE ipd_id = ? AND org_id = ?");
        $check->execute([$ipd_id,$org_id]);
        if ($check->fetch()) {
            $stmt =$tenant_pdo->prepare("
                UPDATE ipd_discharge_summary 
                SET final_diagnosis=?, clinical_findings=?, course_in_hospital=?, discharge_condition=?, discharge_advice=?, discharge_medications_json=?, follow_up_date=?
                WHERE ipd_id=? AND org_id=?
            ");
            $stmt->execute([$final_diagnosis, $clinical_findings,$course_in_hospital, $discharge_condition,$discharge_advice, $meds_json,$follow_up_date, $ipd_id,$org_id]);
        } else {
            $stmt =$tenant_pdo->prepare("
                INSERT INTO ipd_discharge_summary 
                (org_id, center_id, ipd_id, patient_id, doctor_id, final_diagnosis, clinical_findings, course_in_hospital, discharge_condition, discharge_advice, discharge_medications_json, follow_up_date, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$org_id, $center_id,$ipd_id, $patient_id,$doctor_id, $final_diagnosis,$clinical_findings, $course_in_hospital,$discharge_condition, $discharge_advice,$meds_json, $follow_up_date,$user_id]);
        }
        
        $_SESSION['swal_success'] = "Discharge Summary Saved Successfully!";
        header("Location: ipd_discharge_summary.php?ipd_id=" . $ipd_id);
        exit;
    } catch (Exception $e) { $err =$e->getMessage(); }
}

/* ==========================================================
   FETCH PATIENTS & MASTERS
   ========================================================== */
$patients_list = [];
try {
    $q =$tenant_pdo->prepare("
        SELECT a.*, p.uhid, p.fullname, p.gender, p.age, bd.bed_no, r.room_no, d.full_name AS doctor_name, ds.id AS summary_id 
        FROM ipd_admissions a
        JOIN patient_master p ON p.patient_id = a.patient_id
        JOIN master_beds bd ON a.bed_id = bd.id
        JOIN master_rooms r ON bd.room_id = r.id
        LEFT JOIN master_doctors d ON d.id = a.doctor_id
        LEFT JOIN ipd_discharge_summary ds ON ds.ipd_id = a.ipd_id
        WHERE a.org_id = ? AND a.center_id = ? 
        ORDER BY a.status DESC, a.admission_date DESC
        LIMIT 50
    ");
    $q->execute([$org_id,$center_id]);
    $patients_list =$q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$active_medicines =$tenant_pdo->query("SELECT medicine_name FROM master_medicines WHERE org_id = $org_id AND status = 1")->fetchAll(PDO::FETCH_ASSOC);

/* ==========================================================
   SELECTED PATIENT DETAILS
   ========================================================== */
$selected = null;
$summary_data = null;
$rx_items = [];

if ($selected_ipd_id > 0) {
    foreach ($patients_list as$pt) {
        if ((int)$pt['ipd_id'] ===$selected_ipd_id) { $selected =$pt; break; }
    }

    if ($selected) {
        $sStmt =$tenant_pdo->prepare("SELECT * FROM ipd_discharge_summary WHERE ipd_id = ? AND org_id = ? LIMIT 1");
        $sStmt->execute([$selected_ipd_id,$org_id]);
        $summary_data =$sStmt->fetch(PDO::FETCH_ASSOC);

        if ($summary_data && !empty($summary_data['discharge_medications_json'])) {
            $dec = json_decode($summary_data['discharge_medications_json'], true);
            if (is_array($dec)) $rx_items =$dec;
        } else {
            // Auto-fetch last diagnosis from ward rounds
            $wStmt =$tenant_pdo->prepare("SELECT diagnosis FROM ipd_ward_rounds WHERE ipd_id = ? AND diagnosis != '' ORDER BY id DESC LIMIT 1");
            $wStmt->execute([$selected_ipd_id]);
            if ($wd =$wStmt->fetch(PDO::FETCH_ASSOC)) {
                $summary_data['final_diagnosis'] =$wd['diagnosis'];
            }
        }
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<style>
body, .card, .form-control, .btn, .badge, th, td, label, select { text-transform: uppercase; }
input[type="date"], textarea { text-transform: none; }
.patient-list-item { cursor: pointer; transition: 0.2s; border-left: 4px solid transparent; }
.patient-list-item:hover { background-color: #f8fbff; border-left-color: #cbd5e1; }
.patient-list-item.active { background-color: #eff6ff; border-left-color: #10b981; }
.dd-patient-banner { background:linear-gradient(135deg,#f0fdf4,#ffffff); border:1px solid #bbf7d0; border-radius:9px; padding:9px 10px; margin-bottom: .55rem; }
.compact-input { min-height: 28px; height: 28px; font-size: 11px; padding: 2px 8px; }
.form-label { font-size: 10px; font-weight: 800; color: #475569; }
</style>

<div class="row g-3">
    <!-- LEFT: PATIENTS LIST -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-file-earmark-medical text-success me-2"></i>DISCHARGE SUMMARIES</h6>
            </div>
            <div class="card-body p-0" style="max-height: 85vh; overflow-y: auto;">
                <div class="p-2 border-bottom">
                    <input type="text" id="patientSearch" class="form-control form-control-sm compact-input" placeholder="SEARCH PATIENT...">
                </div>
                <div class="list-group list-group-flush" id="patientListContainer">
                    <?php foreach($patients_list as$pt): ?>
                        <a href="?ipd_id=<?= $pt['ipd_id'] ?>" class="list-group-item list-group-item-action patient-list-item p-3 <?= $selected_ipd_id ==$pt['ipd_id'] ? 'active' : '' ?>">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold text-dark" style="font-size: 12px;"><?= htmlspecialchars($pt['fullname']) ?></span>
                                <?php if($pt['summary_id']): ?>
                                    <span class="badge bg-success" style="font-size: 8px;"><i class="bi bi-check2-all"></i> SAVED</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary" style="font-size: 8px;">PENDING</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-secondary" style="font-size: 10px;">
                                <?= htmlspecialchars($pt['ipd_no']) ?> | <?= htmlspecialchars($pt['room_no'] . '-' .$pt['bed_no']) ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT: SUMMARY BUILDER -->
    <div class="col-md-9">
        <?php if(!$selected): ?>
            <div class="card border-0 shadow-sm h-100 d-flex justify-content-center align-items-center bg-light">
                <div class="text-center text-muted">
                    <i class="bi bi-journal-text display-4 text-success"></i>
                    <h6 class="mt-3 fw-bold text-secondary">SELECT A PATIENT</h6>
                    <p class="small">Choose a patient to build their Discharge Summary.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body" style="padding:.65rem;">
                    
                    <div class="dd-patient-banner d-flex justify-content-between gap-3 flex-wrap align-items-center">
                        <div>
                            <div class="fw-bold fs-5 text-dark"><?= htmlspecialchars($selected['fullname']) ?></div>
                            <div class="text-success font-monospace fw-bold" style="font-size: 11px;"><?= htmlspecialchars($selected['ipd_no']) ?> | <?= htmlspecialchars($selected['uhid']) ?></div>
                        </div>
                        <div class="text-end d-flex flex-column align-items-end">
                            <div class="fw-bold text-danger fs-6">BED: <?= htmlspecialchars($selected['room_no'] . ' - ' .$selected['bed_no']) ?></div>
                            <div class="small text-dark fw-bold mb-1" style="font-size:9px;">DR. <?= htmlspecialchars(strtoupper($selected['doctor_name'])) ?></div>
                            
                            <?php if($summary_data): ?>
                                <button class="btn btn-dark btn-sm fw-bold mt-1" style="font-size:10px; padding:4px 10px;" onclick="window.print()"><i class="bi bi-printer me-1"></i> PRINT PARCHA</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="POST" id="dischargeForm">
                        <input type="hidden" name="save_summary" value="1">
                        <input type="hidden" name="ipd_id" value="<?= $selected_ipd_id ?>">
                        <input type="hidden" name="patient_id" value="<?= $selected['patient_id'] ?>">
                        <input type="hidden" name="doctor_id" value="<?= $selected['doctor_id'] ?:$user_id ?>">
                        <input type="hidden" id="prescription_items_json" name="prescription_items_json" value="<?= htmlspecialchars(json_encode($rx_items)) ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">FINAL DIAGNOSIS *</label>
                                <textarea name="final_diagnosis" class="form-control text-uppercase" rows="2" required placeholder="E.G. ACUTE APPENDICITIS"><?= htmlspecialchars($summary_data['final_diagnosis'] ?? '') ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CLINICAL FINDINGS (ON ADMISSION)</label>
                                <textarea name="clinical_findings" class="form-control text-uppercase" rows="2" placeholder="PRESENTING COMPLAINTS AND INITIAL VITALS..."><?= htmlspecialchars($summary_data['clinical_findings'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="col-md-12">
                                <label class="form-label">COURSE IN HOSPITAL *</label>
                                <textarea name="course_in_hospital" class="form-control text-uppercase" rows="4" required placeholder="DETAIL THE TREATMENT GIVEN, SURGERIES PERFORMED, AND PATIENT'S RESPONSE..."><?= htmlspecialchars($summary_data['course_in_hospital'] ?? '') ?></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">DISCHARGE ADVICE / DIET</label>
                                <textarea name="discharge_advice" class="form-control text-uppercase" rows="2" placeholder="E.G. SOFT DIET, AVOID HEAVY LIFTING..."><?= htmlspecialchars($summary_data['discharge_advice'] ?? '') ?></textarea>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">DISCHARGE CONDITION *</label>
                                <select name="discharge_condition" class="form-select text-uppercase" required>
                                    <?php $cond =$summary_data['discharge_condition'] ?? 'Stable/Cured'; ?>
                                    <option value="Stable/Cured" <?= $cond=='Stable/Cured'?'selected':'' ?>>STABLE / CURED</option>
                                    <option value="LAMA" <?= $cond=='LAMA'?'selected':'' ?>>LAMA (LEFT AGAINST MEDICAL ADVICE)</option>
                                    <option value="Referred" <?= $cond=='Referred'?'selected':'' ?>>REFERRED TO HIGHER CENTER</option>
                                    <option value="Absconded" <?= $cond=='Absconded'?'selected':'' ?>>ABSCONDED</option>
                                    <option value="Death" <?= $cond=='Death'?'selected':'' ?>>DEATH</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">FOLLOW UP DATE</label>
                                <input type="date" name="follow_up_date" class="form-control" value="<?= htmlspecialchars($summary_data['follow_up_date'] ?? '') ?>">
                            </div>
                        </div>

                        <!-- DISCHARGE MEDICATIONS (MASTER SEARCH LIST) -->
                        <datalist id="medicineMasterList">
                            <?php foreach($active_medicines as$m): ?>
                                <option value="<?= htmlspecialchars($m['medicine_name'], ENT_QUOTES) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>

                        <div class="mt-4 border rounded p-3 bg-light">
                            <h6 class="fw-bold text-dark small mb-2"><i class="bi bi-capsule text-primary me-2"></i>DISCHARGE MEDICATIONS (TO TAKE AT HOME)</h6>
                            <div class="row g-2 mb-2">
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
                                <table class="table table-bordered bg-white table-sm mb-0" style="font-size: 10px;" id="medTable">
                                    <thead class="table-light"><tr><th>MEDICINE</th><th>DOSE</th><th>FREQUENCY</th><th class="text-center">ACTION</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="text-end mt-3 border-top pt-3">
                            <button type="submit" class="btn btn-success fw-bold px-4"><i class="bi bi-check-circle me-1"></i> SAVE DISCHARGE SUMMARY</button>
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
    document.getElementById('prescription_items_json').value = JSON.stringify(medicineList);

    if(medicineList.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-2">NO MEDICINES ADDED.</td></tr>';
        return;
    }

    let html = '';
    medicineList.forEach((med, index) => {
        html += `<tr>
            <td class="fw-bold">${med.name}</td>
            <td>${med.dose}</td>
            <td>${med.freq}</td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="removeMedicine(${index})"><i class="bi bi-trash"></i></button></td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function() {
    renderMedicineTable();
    // Patient Search
    document.getElementById('patientSearch')?.addEventListener('keyup', function() {
        let filter = this.value.toUpperCase();
        let items = document.querySelectorAll('.patient-list-item');
        items.forEach(item => {
            let text = item.innerText.toUpperCase();
            item.style.display = text.indexOf(filter) > -1 ? '' : 'none';
        });
    });
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>