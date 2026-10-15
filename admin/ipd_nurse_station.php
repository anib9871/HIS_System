<?php
// admin/ipd_nurse_station.php
$page_title = "Nurse Station (IPD)";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$user_id = (int)($_SESSION['user_id'] ?? 1); // Nurse ID
$selected_ipd_id = (int)($_GET['ipd_id'] ?? 0);
$err = '';
$success = '';

/* ==========================================================
   SAVE ACTIONS (GIVE MEDICINE & RECORD VITALS)
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['nurse_action'] ?? '';
    $ipd_id = (int)($_POST['ipd_id'] ?? 0);

    if ($action === 'give_medicine' && $ipd_id > 0) {
        $med_name = trim($_POST['medicine_name'] ?? '');
        $dose = trim($_POST['dose'] ?? '');
        $given_time = !empty($_POST['given_time']) ? $_POST['given_time'] : date('Y-m-d H:i:s');
        $remarks = trim($_POST['remarks'] ?? '');

        try {
            $tenant_pdo->prepare("INSERT INTO ipd_medication_logs (org_id, center_id, ipd_id, medicine_name, dose_given, given_time, given_by, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                       ->execute([$org_id, $center_id, $ipd_id, $med_name, $dose, $given_time, $user_id, $remarks]);
            $_SESSION['swal_success'] = "Medicine marked as given!";
            header("Location: ipd_nurse_station.php?ipd_id=$ipd_id"); exit;
        } catch (Exception $e) { $err = $e->getMessage(); }
    }

    if ($action === 'save_vitals' && $ipd_id > 0) {
        $vitals_raw = $_POST['vitals'] ?? [];
        $vitals_json = json_encode($vitals_raw, JSON_UNESCAPED_UNICODE);
        $recorded_time = !empty($_POST['recorded_time']) ? $_POST['recorded_time'] : date('Y-m-d H:i:s');

        try {
            $tenant_pdo->prepare("INSERT INTO ipd_nursing_vitals (org_id, center_id, ipd_id, vitals_json, recorded_time, recorded_by) VALUES (?, ?, ?, ?, ?, ?)")
                       ->execute([$org_id, $center_id, $ipd_id, $vitals_json, $recorded_time, $user_id]);
            $_SESSION['swal_success'] = "Nursing Vitals recorded!";
            header("Location: ipd_nurse_station.php?ipd_id=$ipd_id"); exit;
        } catch (Exception $e) { $err = $e->getMessage(); }
    }
}

/* ==========================================================
   FETCH ADMITTED PATIENTS (WARD QUEUE)
   ========================================================== */
$admitted_patients = [];
try {
    $q = $tenant_pdo->prepare("
        SELECT a.*, p.uhid, p.fullname, p.gender, p.age, bd.bed_no, r.room_no, d.full_name AS doctor_name 
        FROM ipd_admissions a
        JOIN patient_master p ON p.patient_id = a.patient_id
        JOIN master_beds bd ON a.bed_id = bd.id
        JOIN master_rooms r ON bd.room_id = r.id
        LEFT JOIN master_doctors d ON d.id = a.doctor_id
        WHERE a.org_id = ? AND a.center_id = ? AND a.status = 1
        ORDER BY r.room_no ASC, bd.bed_no ASC
    ");
    $q->execute([$org_id, $center_id]);
    $admitted_patients = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

/* ==========================================================
   SELECTED PATIENT DETAILS & CLINICAL DATA
   ========================================================== */
$selected = null;
$latest_rx_items = [];
$latest_notes = '';
$medication_logs = [];
$nursing_vitals = [];
$active_vitals = [];

if ($selected_ipd_id > 0) {
    foreach ($admitted_patients as $pt) {
        if ((int)$pt['ipd_id'] === $selected_ipd_id) { $selected = $pt; break; }
    }

    if ($selected) {
        // Fetch Latest Doctor's Orders
        $stmt = $tenant_pdo->prepare("SELECT prescription_json, doctor_notes, round_date FROM ipd_ward_rounds WHERE ipd_id = ? AND org_id = ? ORDER BY round_date DESC LIMIT 1");
        $stmt->execute([$selected_ipd_id, $org_id]);
        if ($latest = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $latest_notes = $latest['doctor_notes'];
            $dec = json_decode($latest['prescription_json'], true);
            if (isset($dec['items'])) $latest_rx_items = $dec['items'];
        }

        // Fetch Medication Logs
        $logStmt = $tenant_pdo->prepare("SELECT * FROM ipd_medication_logs WHERE ipd_id = ? AND org_id = ? ORDER BY given_time DESC LIMIT 20");
        $logStmt->execute([$selected_ipd_id, $org_id]);
        $medication_logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Nursing Vitals
        $nvStmt = $tenant_pdo->prepare("SELECT * FROM ipd_nursing_vitals WHERE ipd_id = ? AND org_id = ? ORDER BY recorded_time DESC LIMIT 10");
        $nvStmt->execute([$selected_ipd_id, $org_id]);
        $nursing_vitals = $nvStmt->fetchAll(PDO::FETCH_ASSOC);

        // Active Vitals Master
        $active_vitals = $tenant_pdo->query("SELECT vital_name, vital_key, unit, placeholder FROM master_vitals WHERE org_id = $org_id AND status = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<style>
body, .card, .form-control, .btn, .badge, th, td, label { text-transform: uppercase; }
input[type="datetime-local"], input[type="text"] { text-transform: none; }
.patient-list-item { cursor: pointer; transition: 0.2s; border-left: 4px solid transparent; }
.patient-list-item:hover { background-color: #f8fbff; border-left-color: #cbd5e1; }
.patient-list-item.active { background-color: #eff6ff; border-left-color: #0d6efd; }
.dd-patient-banner { background:linear-gradient(135deg,#eff6ff,#ffffff); border:1px solid #dbeafe; border-radius:9px; padding:9px 10px; margin-bottom: .55rem; }
.vital-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px; }
.vital-label { font-size: 9px; font-weight: 800; color: #64748b; margin-bottom: 3px; display: block; }
.compact-input { min-height: 28px; height: 28px; font-size: 11px; padding: 2px 8px; }
.rx-card { border: 1px solid #bfdbfe; background: #fff; border-radius: 8px; padding: 10px; margin-bottom: 8px; }
</style>

<div class="row g-3">
    <!-- LEFT: WARD QUEUE -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-people-fill text-primary me-2"></i>WARD PATIENTS</h6>
                <span class="badge bg-primary-subtle text-primary border"><?= count($admitted_patients) ?></span>
            </div>
            <div class="card-body p-0" style="max-height: 85vh; overflow-y: auto;">
                <?php if(empty($admitted_patients)): ?>
                    <div class="p-4 text-center text-muted small fw-bold">NO PATIENTS IN WARD</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach($admitted_patients as $pt): ?>
                            <a href="?ipd_id=<?= $pt['ipd_id'] ?>" class="list-group-item list-group-item-action patient-list-item p-3 <?= $selected_ipd_id == $pt['ipd_id'] ? 'active' : '' ?>">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark" style="font-size: 12px;"><?= htmlspecialchars($pt['fullname']) ?></span>
                                    <span class="badge bg-primary-subtle text-primary border border-primary" style="font-size: 9px;"><?= htmlspecialchars($pt['room_no'] . ' - ' . $pt['bed_no']) ?></span>
                                </div>
                                <div class="text-secondary" style="font-size: 10px;">
                                    UHID: <?= htmlspecialchars($pt['uhid']) ?> | DR. <?= htmlspecialchars(strtoupper($pt['doctor_name'] ?? 'N/A')) ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT: NURSE DASHBOARD -->
    <div class="col-md-9">
        <?php if(!$selected): ?>
            <div class="card border-0 shadow-sm h-100 d-flex justify-content-center align-items-center bg-light">
                <div class="text-center text-muted">
                    <i class="bi bi-clipboard2-pulse display-4 text-primary"></i>
                    <h6 class="mt-3 fw-bold text-secondary">SELECT A PATIENT</h6>
                    <p class="small">Choose a patient to manage medicines and vitals.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body" style="padding:.65rem;">
                    <!-- PATIENT BANNER -->
                    <div class="dd-patient-banner d-flex justify-content-between gap-3 flex-wrap align-items-center">
                        <div>
                            <div class="fw-bold fs-5 text-dark"><?= htmlspecialchars($selected['fullname']) ?></div>
                            <div class="text-primary font-monospace fw-bold" style="font-size: 11px;"><?= htmlspecialchars($selected['uhid']) ?></div>
                        </div>
                        <div class="text-end d-flex flex-column align-items-end">
                            <div class="fw-bold text-danger fs-6">BED: <?= htmlspecialchars($selected['room_no'] . ' - ' . $selected['bed_no']) ?></div>
                            <div class="small text-dark fw-bold mb-1" style="font-size:9px;">DR. <?= htmlspecialchars(strtoupper($selected['doctor_name'])) ?></div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <!-- DOCTOR'S ACTIVE ORDERS & MAR -->
                        <div class="col-md-6">
                            <div class="card border h-100">
                                <div class="card-header bg-light py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-capsule text-danger me-2"></i>ACTIVE DOCTOR ORDERS</h6>
                                </div>
                                <div class="card-body p-2" style="background: #f8fafc;">
                                    <?php if(empty($latest_rx_items)): ?>
                                        <div class="text-center text-muted small py-4 fw-bold">NO ACTIVE MEDICINES PRESCRIBED.</div>
                                    <?php else: ?>
                                        <?php foreach($latest_rx_items as $rx): ?>
                                            <div class="rx-card d-flex justify-content-between align-items-center">
                                                <div>
                                                    <div class="fw-bold text-primary" style="font-size: 12px;"><?= htmlspecialchars($rx['name'] ?? $rx['medicine_name']) ?></div>
                                                    <div class="text-dark fw-bold" style="font-size: 10px;"><?= htmlspecialchars($rx['dose']) ?></div>
                                                    <span class="badge bg-secondary mt-1" style="font-size: 8px;"><?= htmlspecialchars($rx['freq'] ?? $rx['frequency']) ?></span>
                                                </div>
                                                <button class="btn btn-sm btn-success fw-bold" style="font-size: 10px; padding: 4px 12px;" onclick="openGiveModal('<?= htmlspecialchars($rx['name'] ?? $rx['medicine_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($rx['dose'], ENT_QUOTES) ?>')">
                                                    <i class="bi bi-check2-circle"></i> GIVE
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- RECORD VITALS -->
                        <div class="col-md-6">
                            <div class="card border h-100">
                                <div class="card-header bg-light py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-heart-pulse text-danger me-2"></i>NURSING VITALS</h6>
                                </div>
                                <div class="card-body p-3">
                                    <form method="POST">
                                        <input type="hidden" name="nurse_action" value="save_vitals">
                                        <input type="hidden" name="ipd_id" value="<?= $selected_ipd_id ?>">
                                        <div class="mb-3">
                                            <label class="form-label" style="font-size: 9px; font-weight: 800;">RECORD TIME *</label>
                                            <input type="datetime-local" name="recorded_time" class="form-control compact-input fw-bold" value="<?= date('Y-m-d\TH:i') ?>" required>
                                        </div>
                                        <div class="row g-2 mb-3">
                                            <?php foreach($active_vitals as $v): ?>
                                                <div class="col-6">
                                                    <div class="vital-box">
                                                        <span class="vital-label"><?= htmlspecialchars($v['vital_name']) ?> <?= $v['unit'] ? '('.htmlspecialchars($v['unit']).')' : '' ?></span>
                                                        <input type="text" name="vitals[<?= htmlspecialchars($v['vital_key']) ?>]" class="form-control compact-input border-0 bg-white shadow-sm text-none text-uppercase" placeholder="<?= htmlspecialchars($v['placeholder']) ?>">
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold"><i class="bi bi-save me-1"></i> SAVE VITALS</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- HISTORY TIMELINES -->
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <div class="card border">
                                <div class="card-header bg-white py-2 px-3 border-bottom"><h6 class="mb-0 fw-bold small text-secondary">MEDICATION HISTORY</h6></div>
                                <div class="card-body p-0" style="max-height: 250px; overflow-y: auto;">
                                    <table class="table table-sm table-hover mb-0" style="font-size: 10px;">
                                        <thead class="table-light sticky-top"><tr><th>TIME</th><th>MEDICINE</th><th>DOSE</th></tr></thead>
                                        <tbody>
                                            <?php if(empty($medication_logs)): ?>
                                                <tr><td colspan="3" class="text-center text-muted py-2">NO RECORDS.</td></tr>
                                            <?php else: foreach($medication_logs as $log): ?>
                                                <tr>
                                                    <td class="text-nowrap fw-bold text-success"><?= date('d-m-Y H:i', strtotime($log['given_time'])) ?></td>
                                                    <td class="fw-bold"><?= htmlspecialchars($log['medicine_name']) ?></td>
                                                    <td><?= htmlspecialchars($log['dose_given']) ?></td>
                                                </tr>
                                            <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card border">
                                <div class="card-header bg-white py-2 px-3 border-bottom"><h6 class="mb-0 fw-bold small text-secondary">VITALS HISTORY</h6></div>
                                <div class="card-body p-2" style="max-height: 250px; overflow-y: auto;">
                                    <?php if(empty($nursing_vitals)): ?>
                                        <div class="text-center text-muted small py-2">NO VITALS RECORDED YET.</div>
                                    <?php else: foreach($nursing_vitals as $nv): ?>
                                        <div class="border rounded p-2 mb-2 bg-light">
                                            <div class="fw-bold text-primary mb-1" style="font-size: 10px;"><i class="bi bi-clock"></i> <?= date('d-m-Y h:i A', strtotime($nv['recorded_time'])) ?></div>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php 
                                                $vDecoded = json_decode($nv['vitals_json'], true); 
                                                if($vDecoded) {
                                                    foreach($vDecoded as $k => $v) {
                                                        if(trim($v) !== '') echo '<span class="badge bg-white text-dark border border-secondary" style="font-size:8px;"><strong>'.strtoupper(str_replace('_', ' ', $k)).':</strong> '.htmlspecialchars($v).'</span>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- GIVE MEDICINE MODAL -->
<div class="modal fade" id="giveMedicineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header py-2 bg-success text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-check-circle me-1"></i> GIVE MEDICINE</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <form method="POST">
                    <input type="hidden" name="nurse_action" value="give_medicine">
                    <input type="hidden" name="ipd_id" value="<?= $selected_ipd_id ?>">
                    
                    <div class="mb-2">
                        <label class="form-label small fw-bold">MEDICINE</label>
                        <input type="text" name="medicine_name" id="modal_med_name" class="form-control form-control-sm fw-bold text-primary bg-light" readonly>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">DOSE</label>
                        <input type="text" name="dose" id="modal_med_dose" class="form-control form-control-sm text-uppercase bg-light" readonly>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">GIVEN TIME *</label>
                        <input type="datetime-local" name="given_time" class="form-control form-control-sm fw-bold" value="<?= date('Y-m-d\TH:i') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">REMARKS (OPTIONAL)</label>
                        <input type="text" name="remarks" class="form-control form-control-sm text-uppercase" placeholder="E.g., After food">
                    </div>
                    <button type="submit" class="btn btn-success btn-sm w-100 fw-bold">MARK AS GIVEN</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
<?php if (isset($_SESSION['swal_success'])): ?>
    Swal.fire({icon: 'success', title: 'Saved!', text: '<?= $_SESSION['swal_success'] ?>', timer: 1500, showConfirmButton: false});
    <?php unset($_SESSION['swal_success']); ?>
<?php endif; ?>

function openGiveModal(medName, medDose) {
    document.getElementById('modal_med_name').value = medName;
    document.getElementById('modal_med_dose').value = medDose;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('giveMedicineModal')).show();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>