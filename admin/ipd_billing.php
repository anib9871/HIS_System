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
        $service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
        $qty = (int)($_POST['quantity'] ?? 1);
        $rate = (float)($_POST['rate'] ?? 0);
        $amount = $qty * $rate;

        if ($service_id && $amount >= 0) {
            try {
                $tenant_pdo->prepare("INSERT INTO ipd_billing_services (org_id, center_id, ipd_id, service_id, service_date, quantity, rate, amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                           ->execute([$org_id, $center_id, $ipd_id, $service_id, $service_date, $qty, $rate, $amount, $user_id]);
                $_SESSION['swal_success'] = "Charge added to patient's bill.";
                header("Location: ipd_billing.php?ipd_id=" . $ipd_id);
                exit;
            } catch (Exception $e) { $err = $e->getMessage(); }
        } else {
            $err = "Please select a valid service and enter the rate.";
        }
    }

    // ACTION: DISCHARGE PATIENT
    if ($action === 'discharge_patient' && $ipd_id > 0) {
        $discharge_date = date('Y-m-d H:i:s');
        try {
            $tenant_pdo->prepare("UPDATE ipd_admissions SET status = 0, discharge_date = ? WHERE ipd_id = ? AND org_id = ? AND center_id = ?")
                       ->execute([$discharge_date, $ipd_id, $org_id, $center_id]);
            
            $_SESSION['swal_success'] = "Patient Discharged Successfully! Bed is now available.";
            header("Location: ipd_billing.php?ipd_id=" . $ipd_id);
            exit;
        } catch (Exception $e) { $err = $e->getMessage(); }
    }
}

/* ==========================================================
   FETCH MASTERS & PATIENTS
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

$master_services = [];
try {
    $master_services = $tenant_pdo->query("SELECT id, service_name FROM master_doctor_services WHERE org_id = $org_id AND status = 1 ORDER BY service_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

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

        $bStmt = $tenant_pdo->prepare("
            SELECT bs.*, mds.service_name 
            FROM ipd_billing_services bs
            LEFT JOIN master_doctor_services mds ON bs.service_id = mds.id
            WHERE bs.ipd_id = ? AND bs.org_id = ? 
            ORDER BY bs.service_date ASC
        ");
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
body, .card, .form-control, .form-select, .btn, .badge, th, td, label { text-transform: uppercase; }
input[type="number"], input[type="date"] { text-transform: none; }
.patient-list-item { cursor: pointer; transition: 0.2s; border-left: 4px solid transparent; }
.patient-list-item:hover { background-color: #f8fbff; border-left-color: #cbd5e1; }
.patient-list-item.active { background-color: #eff6ff; border-left-color: #0d6efd; }
.compact-input { min-height: 28px; height: 28px; font-size: 11px; padding: 2px 8px; }
.dd-patient-banner { background:linear-gradient(135deg,#eff6ff,#ffffff); border:1px solid #dbeafe; border-radius:9px; padding:12px 15px; margin-bottom: 1rem; }
.bill-summary-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; height: 100%; }
.bill-val { font-size: 20px; font-weight: 900; margin-top: 5px; }

/* Hide print elements on screen */
.print-only { display: none; }

/* ==========================================================
   CLEAN & PROFESSIONAL PRINT CSS
   ========================================================== */
@media print {
    /* Base print setup */
    @page { margin: 15mm; size: A4 portrait; }
    body { background: #fff !important; font-size: 10pt; color: #000; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; }
    
    /* Hide ALL unnecessary UI elements */
    .print-hide, .sidebar, .topbar, header, nav, footer, 
    .col-md-3, .btn, .add-charge-section, .card-header, 
    #patientSearch, .bill-summary-container { 
        display: none !important; 
    }
    
    /* Make main content area full width and clean */
    .col-md-9 { width: 100% !important; max-width: 100% !important; flex: 0 0 100% !important; padding: 0 !important; }
    .card { border: none !important; box-shadow: none !important; margin: 0 !important; }
    .card-body { padding: 0 !important; }
    
    /* Show print-only elements */
    .print-only { display: block !important; }
    
    /* Clean Invoice Header */
    .invoice-header { text-align: center; border-bottom: 2px solid #222; padding-bottom: 15px; margin-bottom: 20px; }
    .invoice-header h2 { margin: 0 0 5px 0; font-size: 24pt; font-weight: 900; letter-spacing: 1px; color: #111; }
    .invoice-header h6 { margin: 0; font-size: 11pt; font-weight: 600; color: #444; letter-spacing: 2px; }

    /* Clean Patient Info Section (No boxes, just lines) */
    .dd-patient-banner { 
        background: transparent !important; 
        border: none !important; 
        border-radius: 0 !important; 
        padding: 0 0 15px 0 !important; 
        margin-bottom: 20px !important;
        border-bottom: 1px dashed #aaa !important;
        display: flex !important;
        justify-content: space-between !important;
    }
    .dd-patient-banner .text-primary { color: #000 !important; }
    
    /* Table Styling (Clean borders, not heavy grids) */
    .table { width: 100% !important; border-collapse: collapse !important; margin-bottom: 20px !important; }
    .table th { border: none !important; border-top: 2px solid #222 !important; border-bottom: 2px solid #222 !important; padding: 10px 5px !important; font-weight: bold; font-size: 10pt; color: #000 !important; }
    .table td { border: none !important; border-bottom: 1px solid #eee !important; padding: 8px 5px !important; font-size: 10pt; color: #222 !important; }
    
    /* Totals Styling (No vertical lines, clean alignment) */
    .totals-row td { border-bottom: none !important; padding: 6px 5px !important; }
    .totals-row-top td { border-top: 2px solid #222 !important; padding-top: 15px !important; }
    .totals-row-final td { padding-bottom: 15px !important; border-bottom: 2px solid #222 !important; }
    
    .text-success, .text-danger { color: #000 !important; } /* Force black text for print */
    
    /* Signature Area */
    .print-signatures { display: flex; justify-content: space-between; margin-top: 80px; page-break-inside: avoid; }
    .print-signatures > div { width: 40%; text-align: center; }
    .print-signatures hr { border-top: 1px solid #000; margin: 0 0 10px 0; }
    .print-signatures span { font-size: 9pt; font-weight: 600; color: #444; }
}
</style>

<div class="row g-3">
    <!-- LEFT: PATIENTS LIST -->
    <div class="col-md-3 print-hide">
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
            <div class="card border-0 shadow-sm h-100 d-flex justify-content-center align-items-center bg-light print-hide">
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
                    
                    <!-- HOSPITAL PRINT HEADER (ONLY VISIBLE ON PAPER) -->
                    <div class="print-only invoice-header">
                        <h2><?= htmlspecialchars($_SESSION['org_name'] ?? 'HOSPITAL INVOICE') ?></h2>
                        <h6>FINAL IPD BILL & RECEIPT</h6>
                    </div>

                    <!-- PATIENT BANNER -->
                    <div class="dd-patient-banner">
                        <div>
                            <div class="fw-bold fs-5 text-dark" style="margin-bottom: 4px;"><?= htmlspecialchars($selected['fullname']) ?></div>
                            <div class="text-primary font-monospace fw-bold" style="font-size: 12px; margin-bottom: 6px;"><?= htmlspecialchars($selected['ipd_no']) ?> | <?= htmlspecialchars($selected['uhid']) ?></div>
                            <div class="small text-muted" style="font-size:10px; line-height: 1.4;">
                                <strong>ADMITTED:</strong> <?= date('d-M-Y h:i A', strtotime($selected['admission_date'])) ?>
                                <?php if(!$is_admitted): ?>
                                    <br><span class="text-success fw-bold"><strong>DISCHARGED:</strong> <?= date('d-M-Y h:i A', strtotime($selected['discharge_date'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end d-flex flex-column align-items-end justify-content-start">
                            <div class="fw-bold text-danger fs-6" style="margin-bottom: 4px;">BED: <?= htmlspecialchars($selected['room_no'] . ' - ' . $selected['bed_no']) ?></div>
                            <div class="small text-dark fw-bold mb-1" style="font-size:10px;">DR. <?= htmlspecialchars($selected['doctor_name']) ?></div>
                            
                            <?php if($is_admitted): ?>
                                <button type="button" class="btn btn-sm btn-danger fw-bold mt-2 print-hide" onclick="confirmDischarge()" style="font-size:10px; padding:6px 12px;">
                                    <i class="bi bi-box-arrow-right me-1"></i> INITIATE DISCHARGE
                                </button>
                                <form id="dischargeForm" method="POST" class="d-none">
                                    <input type="hidden" name="billing_action" value="discharge_patient">
                                    <input type="hidden" name="ipd_id" value="<?= $selected_ipd_id ?>">
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-success fw-bold mt-2 print-hide" onclick="window.print()" style="font-size:10px; padding:6px 12px;">
                                    <i class="bi bi-printer me-1"></i> PRINT FINAL BILL
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- FINANCIAL SUMMARY BOXES (HIDDEN IN PRINT) -->
                    <div class="row g-3 mb-4 bill-summary-container">
                        <div class="col-md-4 col-4">
                            <div class="bill-summary-box text-center">
                                <div class="text-muted small fw-bold mb-1">TOTAL CHARGES</div>
                                <div class="bill-val text-dark">₹<?= number_format($total_charges, 2) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-4">
                            <div class="bill-summary-box text-center">
                                <div class="text-muted small fw-bold mb-1">ADVANCE DEPOSIT</div>
                                <div class="bill-val text-success">₹<?= number_format($advance_paid, 2) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-4">
                            <div class="bill-summary-box text-center border-primary bg-primary-subtle">
                                <div class="text-primary small fw-bold mb-1">BALANCE DUE</div>
                                <div class="bill-val <?= $balance_due > 0 ? 'text-danger' : 'text-success' ?>">
                                    ₹<?= number_format($balance_due, 2) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ADD NEW CHARGE (Only if Admitted - Hidden in Print) -->
                    <?php if($is_admitted): ?>
                        <div class="card border shadow-none mb-4 add-charge-section">
                            <div class="card-header bg-light py-2 px-3 border-bottom">
                                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-plus-circle text-primary me-2"></i>ADD SERVICE / CHARGE</h6>
                            </div>
                            <div class="card-body p-3">
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
                                            <select name="service_id" class="form-select compact-input" required>
                                                <option value="">-- SELECT SERVICE --</option>
                                                <?php foreach($master_services as $ms): ?>
                                                    <option value="<?= $ms['id'] ?>"><?= htmlspecialchars($ms['service_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label" style="font-size:9px; font-weight:800;">QTY *</label>
                                            <input type="number" name="quantity" id="calc_qty" class="form-control compact-input" value="1" min="1" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label" style="font-size:9px; font-weight:800;">RATE (₹) *</label>
                                            <input type="number" name="rate" id="calc_rate" class="form-control compact-input" value="0" min="0" step="0.01" required>
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
                    <div class="card border-0 shadow-none">
                        <div class="card-header bg-white py-2 px-0 border-bottom mb-2 print-hide">
                            <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-list-columns-reverse text-secondary me-2"></i>ITEMIZED BILL DETAILS</h6>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-2">DATE</th>
                                        <th>SERVICE PARTICULARS</th>
                                        <th class="text-center">QTY</th>
                                        <th class="text-end">RATE</th>
                                        <th class="text-end pe-2">AMOUNT</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($bill_items)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-5 fw-bold">NO SERVICES BILLED YET.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($bill_items as $item): ?>
                                            <tr>
                                                <td class="ps-2 fw-semibold text-secondary align-middle"><?= date('d-M-Y', strtotime($item['service_date'])) ?></td>
                                                <td class="fw-bold text-dark align-middle"><?= htmlspecialchars($item['service_name'] ?? 'N/A') ?></td>
                                                <td class="text-center align-middle"><?= (int)$item['quantity'] ?></td>
                                                <td class="text-end align-middle">₹<?= number_format($item['rate'], 2) ?></td>
                                                <td class="text-end pe-2 fw-bold text-dark align-middle">₹<?= number_format($item['amount'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <!-- CLEAN TOTALS ROWS (No vertical lines in print) -->
                                        <tr class="totals-row totals-row-top">
                                            <td colspan="4" class="text-end fw-bold text-dark" style="font-size: 11pt;">TOTAL CHARGES:</td>
                                            <td class="text-end pe-2 fw-bold text-dark" style="font-size: 12pt;">₹<?= number_format($total_charges, 2) ?></td>
                                        </tr>
                                        <tr class="totals-row">
                                            <td colspan="4" class="text-end fw-bold text-success" style="font-size: 10pt;">ADVANCE PAID:</td>
                                            <td class="text-end pe-2 fw-bold text-success" style="font-size: 11pt;">₹<?= number_format($advance_paid, 2) ?></td>
                                        </tr>
                                        <tr class="totals-row totals-row-final">
                                            <td colspan="4" class="text-end fw-bold <?= $balance_due > 0 ? 'text-danger' : 'text-success' ?>" style="font-size: 12pt;">BALANCE DUE:</td>
                                            <td class="text-end pe-2 fw-bold <?= $balance_due > 0 ? 'text-danger' : 'text-success' ?>" style="font-size: 14pt;">₹<?= number_format($balance_due, 2) ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            
                            <!-- PRINT SIGNATURE AREA (ONLY IN PRINT) -->
                            <div class="print-only print-signatures">
                                <div>
                                    <hr>
                                    <span>PATIENT / RELATIVE SIGNATURE</span>
                                </div>
                                <div>
                                    <hr>
                                    <span>AUTHORIZED SIGNATORY</span>
                                </div>
                            </div>
                            
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
    Swal.fire({
        icon: 'success', 
        title: 'Success', 
        text: <?= json_encode($_SESSION['swal_success']) ?>, 
        timer: 2000, 
        showConfirmButton: false
    });
    <?php unset($_SESSION['swal_success']); ?>
<?php endif; ?>

<?php if (!empty($err)): ?>
    Swal.fire({
        icon: 'error', 
        title: 'Error', 
        text: <?= json_encode($err) ?>
    });
<?php endif; ?>

window.confirmDischarge = function() {
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
    });
};

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
