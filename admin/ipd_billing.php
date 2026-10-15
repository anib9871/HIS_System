<?php
// admin/ipd_billing.php
$page_title = "IPD Billing & Discharge";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$user_id = (int)($_SESSION['user_id'] ?? 1);
$selected_ipd_id = (int)($_GET['ipd_id'] ?? 0);
$err = '';
$success = '';

/* ==========================================================
   SAVE ACTIONS (ADD CHARGE & DISCHARGE)
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['billing_action'] ?? '';
    $ipd_id = (int)($_POST['ipd_id'] ?? 0);

    // ACTION: ADD NEW SERVICE/CHARGE
    if ($action === 'add_charge' && $ipd_id > 0) {
        $service_date = !empty($_POST['service_date']) ? $_POST['service_date'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
        $service_name = trim(strtoupper($_POST['service_name'] ?? ''));
        $qty = (int)($_POST['quantity'] ?? 1);
        $rate = (float)($_POST['rate'] ?? 0);
        $amount = $qty * $rate;

        if ($service_name !== '' && $amount >= 0) {
            try {
                $tenant_pdo->prepare("INSERT INTO ipd_billing_services (org_id, center_id, ipd_id, service_date, service_name, quantity, rate, amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                           ->execute([$org_id, $center_id, $ipd_id, $service_date, $service_name, $qty, $rate, $amount, $user_id]);
                $_SESSION['swal_success'] = "Charge added to patient's bill.";
                header("Location: ipd_billing.php?ipd_id=" . $ipd_id);
                exit;
            } catch (Exception $e) { $err = $e->getMessage(); }
        } else {
            $err = "Please enter valid service details and rate.";
        }
    }

    // ACTION: DISCHARGE PATIENT
    if ($action === 'discharge_patient' && $ipd_id > 0) {
        $discharge_date = date('Y-m-d H:i:s');
        try {
            // Status 0 makes the patient 'Discharged' and automatically frees up their bed
            $tenant_pdo->prepare("UPDATE ipd_admissions SET status = 0, discharge_date = ? WHERE ipd_id = ? AND org_id = ? AND center_id = ?")
                       ->execute([$discharge_date, $ipd_id, $org_id, $center_id]);
            
            $_SESSION['swal_success'] = "Patient Discharged Successfully! Bed is now available.";
            header("Location: ipd_billing.php?ipd_id=" . $ipd_id);
            exit;
        } catch (Exception $e) { $err = $e->getMessage(); }
    }
}

/* ==========================================================
   FETCH PATIENTS (ADMITTED & RECENTLY DISCHARGED)
   ========================================================== */
$patients_list = [];
try {
    $q = $tenant_pdo->prepare("
        SELECT a.*, p.uhid, p.fullname, p.gender, p.age, bd.bed_no, r.room_no, d.full_name AS doctor_name 
        FROM ipd_admissions a
        JOIN patient_master p ON p.patient_id = a.patient_id
        JOIN master_beds bd ON a.bed_id = bd.id
        JOIN master_rooms r ON bd.room_id = r.id
        LEFT JOIN master_doctors d ON d.id = a.doctor_id
        WHERE a.org_id = ? AND a.center_id = ? 
        ORDER BY a.status DESC, a.admission_date DESC 
        LIMIT 50
    ");
    $q->execute([$org_id, $center_id]);
    $patients_list = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

/* ==========================================================
   SELECTED PATIENT DETAILS & BILLING DATA
   ========================================================== */
$selected = null;
$bill_items = [];
$total_charges = 0;
$advance_paid = 0;
$balance_due = 0;

if ($selected_ipd_id > 0) {
    foreach ($patients_list as $pt) {
        if ((int)$pt['ipd_id'] === $selected_ipd_id) { $selected = $pt; break; }
    }

    if ($selected) {
        $advance_paid = (float)$selected['advance_paid'];

        // Fetch Bill Items
        $bStmt = $tenant_pdo->prepare("SELECT * FROM ipd_billing_services WHERE ipd_id = ? AND org_id = ? ORDER BY service_date ASC");
        $bStmt->execute([$selected_ipd_id, $org_id]);
        $bill_items = $bStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bill_items as $item) {
            $total_charges += (float)$item['amount'];
        }

        $balance_due = $total_charges - $advance_paid;
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<style>
body, .card, .form-control, .btn, .badge, th, td, label { text-transform: uppercase; }
input[type="number"], input[type="date"] { text-transform: none; }
.patient-list-item { cursor: pointer; transition: 0.2s; border-left: 4px solid transparent; }
.patient-list-item:hover { background-color: #f8fbff; border-left-color: #cbd5e1; }
.patient-list-item.active { background-color: #eff6ff; border-left-color: #0d6efd; }
.compact-input { min-height: 28px; height: 28px; font-size: 11px; padding: 2px 8px; }
.dd-patient-banner { background:linear-gradient(135deg,#eff6ff,#ffffff); border:1px solid #dbeafe; border-radius:9px; padding:9px 10px; margin-bottom: .55rem; }
.bill-summary-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; }
.bill-val { font-size: 18px; font-weight: 900; }
</style>

<div class="row g-3">
    <!-- LEFT: PATIENTS LIST -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-receipt text-primary me-2"></i>IPD BILLING</h6>
            </div>
            <div class="card-body p-0" style="max-height: 85vh; overflow-y: auto;">
                <div class="p-2 border-bottom">
                    <input type="text" id="patientSearch" class="form-control form-control-sm compact-input" placeholder="SEARCH PATIENT...">
                </div>
                <div class="list-group list-group-flush" id="patientListContainer">
                    <?php foreach($patients_list as $pt): ?>
                        <a href="?ipd_id=<?= $pt['ipd_id'] ?>" class="list-group-item list-group-item-action patient-list-item p-3 <?= $selected_ipd_id == $pt['ipd_id'] ? 'active' : '' ?>">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold text-dark" style="font-size: 12px;"><?= htmlspecialchars($pt['fullname']) ?></span>
                                <?php if((int)$pt['status'] === 1): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning" style="font-size: 8px;">ADMITTED</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success border border-success" style="font-size: 8px;">DISCHARGED</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-secondary" style="font-size: 10px;">
                                <?= htmlspecialchars($pt['ipd_no']) ?> | <?= htmlspecialchars($pt['room_no'] . '-' . $pt['bed_no']) ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT: BILLING DASHBOARD -->
    <div class="col-md-9">
        <?php if(!$selected): ?>
            <div class="card border-0 shadow-sm h-100 d-flex justify-content-center align-items-center bg-light">
                <div class="text-center text-muted">
                    <i class="bi bi-calculator display-4 text-primary"></i>
                    <h6 class="mt-3 fw-bold text-secondary">SELECT A PATIENT</h6>
                    <p class="small">Choose a patient to view or manage their IPD Bill.</p>
                </div>
            </div>
        <?php else: ?>
            <?php $is_admitted = ((int)$selected['status'] === 1); ?>
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body" style="padding:.65rem;">
                    
                    <!-- PATIENT BANNER -->
                    <div class="dd-patient-banner d-flex justify-content-between gap-3 flex-wrap align-items-center">
                        <div>
                            <div class="fw-bold fs-5 text-dark"><?= htmlspecialchars($selected['fullname']) ?></div>
                            <div class="text-primary font-monospace fw-bold" style="font-size: 11px;"><?= htmlspecialchars($selected['ipd_no']) ?> | <?= htmlspecialchars($selected['uhid']) ?></div>
                            <div class="small text-muted mt-1" style="font-size:9px;">
                                ADMITTED: <?= date('d-m-Y h:i A', strtotime($selected['admission_date'])) ?>
                                <?php if(!$is_admitted): ?>
                                    <br><span class="text-success fw-bold">DISCHARGED: <?= date('d-m-Y h:i A', strtotime($selected['discharge_date'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end d-flex flex-column align-items-end">
                            <div class="fw-bold text-danger fs-6">BED: <?= htmlspecialchars($selected['room_no'] . ' - ' . $selected['bed_no']) ?></div>
                            <div class="small text-dark fw-bold mb-1" style="font-size:9px;">DR. <?= htmlspecialchars($selected['doctor_name']) ?></div>
                            
                            <?php if($is_admitted): ?>
                                <button type="button" class="btn btn-sm btn-danger fw-bold mt-1" onclick="confirmDischarge()" style="font-size:10px; padding:4px 10px;">
                                    <i class="bi bi-box-arrow-right me-1"></i> INITIATE DISCHARGE
                                </button>
                                <form id="dischargeForm" method="POST" class="d-none">
                                    <input type="hidden" name="billing_action" value="discharge_patient">
                                    <input type="hidden" name="ipd_id" value="<?= $selected_ipd_id ?>">
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-success fw-bold mt-1" onclick="window.print()" style="font-size:10px; padding:4px 10px;">
                                    <i class="bi bi-printer me-1"></i> PRINT FINAL BILL
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- FINANCIAL SUMMARY -->
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <div class="bill-summary-box text-center">
                                <div class="text-muted small fw-bold mb-1" style="font-size: 10px;">TOTAL CHARGES</div>
                                <div class="bill-val text-dark">₹<?= number_format($total_charges, 2) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="bill-summary-box text-center">
                                <div class="text-muted small fw-bold mb-1" style="font-size: 10px;">ADVANCE DEPOSIT</div>
                                <div class="bill-val text-success">₹<?= number_format($advance_paid, 2) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="bill-summary-box text-center border-primary bg-primary-subtle">
                                <div class="text-primary small fw-bold mb-1" style="font-size: 10px;">BALANCE DUE</div>
                                <div class="bill-val <?= $balance_due > 0 ? 'text-danger' : 'text-success' ?>">
                                    ₹<?= number_format($balance_due, 2) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ADD NEW CHARGE (Only if Admitted) -->
                    <?php if($is_admitted): ?>
                        <div class="card border shadow-none mb-3">
                            <div class="card-header bg-light py-2 px-3 border-bottom">
                                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-plus-circle text-primary me-2"></i>ADD SERVICE / CHARGE</h6>
                            </div>
                            <div class="card-body p-2">
                                <form method="POST">
                                    <input type="hidden" name="billing_action" value="add_charge">
                                    <input type="hidden" name="ipd_id" value="<?= $selected_ipd_id ?>">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-2">
                                            <label class="form-label" style="font-size:9px; font-weight:800;">DATE</label>
                                            <input type="date" name="service_date" class="form-control compact-input fw-bold" value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label" style="font-size:9px; font-weight:800;">SERVICE NAME *</label>
                                            <input type="text" name="service_name" class="form-control compact-input text-uppercase" placeholder="E.G. ROOM RENT, CBC TEST" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label" style="font-size:9px; font-weight:800;">QTY *</label>
                                            <input type="number" name="quantity" id="calc_qty" class="form-control compact-input" value="1" min="1" required onkeyup="calcAmount()">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label" style="font-size:9px; font-weight:800;">RATE (₹) *</label>
                                            <input type="number" name="rate" id="calc_rate" class="form-control compact-input" value="0" min="0" step="0.01" required onkeyup="calcAmount()">
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-primary compact-input w-100 fw-bold">ADD CHARGE</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ITEMIZED BILL TABLE -->
                    <div class="card border shadow-none">
                        <div class="card-header bg-white py-2 px-3 border-bottom">
                            <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-list-columns-reverse text-secondary me-2"></i>ITEMIZED BILL DETAILS</h6>
                        </div>
                        <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-hover table-sm mb-0" style="font-size: 11px;">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th class="ps-3">DATE</th>
                                        <th>SERVICE PARTICULARS</th>
                                        <th class="text-center">QTY</th>
                                        <th class="text-end">RATE</th>
                                        <th class="text-end pe-3">AMOUNT</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($bill_items)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-4 fw-bold">NO SERVICES BILLED YET.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($bill_items as $item): ?>
                                            <tr>
                                                <td class="ps-3 fw-bold text-secondary"><?= date('d-M-Y', strtotime($item['service_date'])) ?></td>
                                                <td class="fw-bold text-dark"><?= htmlspecialchars($item['service_name']) ?></td>
                                                <td class="text-center"><?= (int)$item['quantity'] ?></td>
                                                <td class="text-end">₹<?= number_format($item['rate'], 2) ?></td>
                                                <td class="text-end pe-3 fw-bold text-dark">₹<?= number_format($item['amount'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
<?php if (isset($_SESSION['swal_success'])): ?>
    Swal.fire({icon: 'success', title: 'Success', text: '<?= $_SESSION['swal_success'] ?>', timer: 2000, showConfirmButton: false});
    <?php unset($_SESSION['swal_success']); ?>
<?php endif; ?>

<?php if ($err): ?>
    Swal.fire({icon: 'error', title: 'Error', text: '<?= $err ?>'});
<?php endif; ?>

function confirmDischarge() {
    Swal.fire({
        title: 'Discharge Patient?',
        text: "This will finalize the bill and free up the bed. Are you sure?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Discharge Now!'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('dischargeForm').submit();
        }
    })
}

// Simple search for left panel
document.getElementById('patientSearch')?.addEventListener('keyup', function() {
    let filter = this.value.toUpperCase();
    let items = document.querySelectorAll('.patient-list-item');
    items.forEach(item => {
        let text = item.innerText.toUpperCase();
        item.style.display = text.indexOf(filter) > -1 ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>