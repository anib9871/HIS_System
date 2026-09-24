<?php
// admin/opd_registration.php
$page_title = "OPD Registration & Billing";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php'; 

// ==============================================================
// SMART FINANCIAL YEAR LOGIC
// ==============================================================
function getCurrentFY($tenant_pdo, $org_id, $center_id, $visit_date = null) {
    if (!$visit_date) $visit_date = date('Y-m-d');
    $time = strtotime($visit_date);
    $m = (int)date('m', $time);
    $y = (int)date('y', $time);
    $Y_full = (int)date('Y', $time);
    
    if ($m >= 4) {
        $fy_code = sprintf("%02d%02d", $y, $y + 1); 
    } else {
        $fy_code = sprintf("%02d%02d", $y - 1, $y); 
    }
    return $fy_code;
}

// Format FY code to include hyphen (e.g. 2627 -> 26-27)
function formatFYWithHyphen($fy_code) {
    if(strlen($fy_code) == 4) {
        return substr($fy_code, 0, 2) . '-' . substr($fy_code, 2, 2);
    }
    return $fy_code;
}

// AUTO-ADD REQUIRED COLUMNS
try {
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS department_id INT NULL AFTER doctor_id");
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS service_id INT NULL AFTER department_id");
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS category_id INT NULL AFTER service_id");
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS insurance_id INT NULL AFTER category_id");
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS payment_mode VARCHAR(50) DEFAULT 'Cash'");
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(10,2) DEFAULT 0");
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS change_return DECIMAL(10,2) DEFAULT 0");
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS payment_breakdown TEXT NULL");
    
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS remark TEXT NULL");
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) DEFAULT 0");
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER discount_amount");
    $tenant_pdo->exec("ALTER TABLE opd_visits ADD COLUMN IF NOT EXISTS receipt_no BIGINT UNSIGNED NULL AFTER token_no");

    // Existing records were using token_no as the visible bill/receipt serial.
    // Preserve those old receipt numbers in the new receipt_no column.
    $tenant_pdo->exec("
        UPDATE opd_visits
        SET receipt_no = token_no
        WHERE receipt_no IS NULL
    ");

    // Backfill discount percentage for old rows.
    $tenant_pdo->exec("
        UPDATE opd_visits
        SET discount_percent = CASE
            WHEN consultation_fee > 0 THEN ROUND((discount_amount / consultation_fee) * 100, 2)
            ELSE 0
        END
        WHERE discount_percent = 0
    ");

    // Normalize all old/new rows: 1 = Paid, 2 = Partial, 3 = Not Paid.
    $tenant_pdo->exec("
        UPDATE opd_visits
        SET payment_status = CASE
            WHEN GREATEST(consultation_fee - discount_amount, 0) = 0 THEN 1
            WHEN amount_paid >= GREATEST(consultation_fee - discount_amount, 0) THEN 1
            WHEN amount_paid > 0 THEN 2
            ELSE 3
        END
    ");

    $tenant_pdo->exec("ALTER TABLE opd_visits MODIFY COLUMN payment_status TINYINT UNSIGNED NOT NULL DEFAULT 3");
    $tenant_pdo->exec("ALTER TABLE opd_visits MODIFY COLUMN status VARCHAR(50) DEFAULT 'Waiting'");
    $tenant_pdo->exec("ALTER TABLE org_profile ADD COLUMN IF NOT EXISTS receipt_instructions TEXT NULL");
} catch(Exception $e) {}

$msg = "";
$err = "";
$token_generated = null;
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$org_name = $_SESSION['org_name'] ?? 'City Care Multispeciality Hospital';

// Fetch Center Address
$center_name = "Main Branch";
$center_address = "";
try {
    $c_stmt = $tenant_pdo->prepare("SELECT center_name, address FROM master_centers WHERE center_id = ?");
    $c_stmt->execute([$center_id]);
    $c_info = $c_stmt->fetch(PDO::FETCH_ASSOC);
    if ($c_info) {
        $center_name = $c_info['center_name'];
        $center_address = $c_info['address'];
    }
} catch(Exception $e){}

// Fetch instructions
$receipt_instructions = "1. Please wait for your turn in the waiting area.\n2. This receipt is valid for 1 day only.";
try {
    $org_stmt = $tenant_pdo->query("SELECT receipt_instructions FROM org_profile LIMIT 1");
    $fetched_inst = $org_stmt->fetchColumn();
    if (!empty(trim($fetched_inst))) $receipt_instructions = trim($fetched_inst);
} catch (Exception $e) {}

// --- SECURE UHID GENERATOR ---
function generateSecureUHID($tenant_pdo, $center_id, $fy_code) {
    $org_prefix = 'MAX';
    try {
        $org_stmt = $tenant_pdo->query("SELECT mnemonic FROM org_profile LIMIT 1");
        $fetch_org = $org_stmt->fetchColumn();
        if (!empty($fetch_org)) $org_prefix = strtoupper(trim($fetch_org));
    } catch (Exception $e) {}

    $center_code = 'LKO';
    try {
        $center_stmt = $tenant_pdo->prepare("SELECT center_code FROM master_centers WHERE center_id = ?");
        $center_stmt->execute([$center_id]);
        $fetch_center = $center_stmt->fetchColumn();
        if (!empty($fetch_center)) $center_code = strtoupper(trim($fetch_center));
    } catch (Exception $e) {}

    $uhid_base = "{$org_prefix}-{$center_code}-{$fy_code}-";
    $stmt = $tenant_pdo->prepare("SELECT uhid FROM opd_patients WHERE uhid LIKE ? ORDER BY patient_id DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([$uhid_base . '%']);
    $last_uhid = $stmt->fetchColumn();

    $next_serial = $last_uhid ? ((int)str_replace($uhid_base, '', $last_uhid) + 1) : 1;
    return $uhid_base . $next_serial;
}

// ==============================================================
 // PATIENT LOOKUP AJAX
 // Reuses the Patient Master created from Appointment or Registration.
 // ==============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_lookup') {
    header('Content-Type: application/json; charset=utf-8');

    $mobile_lookup = preg_replace('/\D+/', '', $_GET['mobile'] ?? '');

    if (strlen($mobile_lookup) < 10) {
        echo json_encode(['found' => false]);
        exit;
    }

    try {
        $stmt = $tenant_pdo->prepare("
            SELECT patient_id, uhid, fullname, gender, age, mobile
            FROM opd_patients
            WHERE org_id = ?
              AND center_id = ?
              AND (mobile = ? OR mobile LIKE ?)
            ORDER BY patient_id DESC
            LIMIT 1
        ");
        $stmt->execute([$org_id, $center_id, $mobile_lookup, $mobile_lookup . ',%']);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(
            $patient
                ? ['found' => true, 'patient' => $patient]
                : ['found' => false]
        );
    } catch (Exception $e) {
        echo json_encode(['found' => false]);
    }
    exit;
}

// Handle Edit Fetch
$edit_data = null;
$edit_visit_id = (int)($_GET['edit_visit'] ?? 0);
if ($edit_visit_id > 0) {
    try {
        $edit_stmt = $tenant_pdo->prepare("
            SELECT v.*, p.uhid, p.fullname, p.mobile, p.age, p.gender 
            FROM opd_visits v 
            JOIN opd_patients p ON v.patient_id = p.patient_id 
            WHERE v.visit_id = ? AND v.org_id = ? AND v.center_id = ?
        ");
        $edit_stmt->execute([$edit_visit_id, $org_id, $center_id]);
        $edit_data = $edit_stmt->fetch();
    } catch (Exception $e) {}
}

// --- SAVE REGISTRATION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_patient'])) {
    $fullname = ucwords(strtolower(trim($_POST['fullname'] ?? '')));
    $gender   = $_POST['gender'] ?? 'Male';
    $age      = (int)($_POST['age'] ?? 0);
    
    // Multiple mobile numbers handle logic
    $mobile_post = $_POST['mobile'] ?? [];
    $mobile = is_array($mobile_post) ? implode(', ', array_filter(array_map('trim', $mobile_post), fn($v) => $v !== '')) : trim((string)$mobile_post);
    
    $visit_date    = !empty($_POST['visit_date']) ? $_POST['visit_date'] : date('Y-m-d');
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $doctor_id     = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;
    $service_id    = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
    $category_id   = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $insurance_id  = !empty($_POST['insurance_id']) ? (int)$_POST['insurance_id'] : null;
    
    $remark        = trim($_POST['remark'] ?? '');
    $fee           = (float)($_POST['consultation_fee'] ?? 0);
    $discount_percent = (float)($_POST['discount_percent'] ?? 0);
    $discount      = (float)($_POST['modal_discount'] ?? 0);
    $total_paid    = (float)($_POST['modal_total_paid'] ?? 0);
    $change_ret    = (float)($_POST['modal_change_return'] ?? 0);
    $pay_breakdown = trim($_POST['payment_breakdown_json'] ?? '');
    
    if ($discount_percent < 0) $discount_percent = 0;
    if ($discount_percent > 100) $discount_percent = 100;

    if ($fee > 0 && $discount <= 0 && $discount_percent > 0) {
        $discount = round(($fee * $discount_percent) / 100, 2);
    }
    if ($fee > 0) {
        $discount = min(max($discount, 0), $fee);
        if ($discount_percent <= 0 && $discount > 0) {
            $discount_percent = round(($discount / $fee) * 100, 2);
        }
    } else {
        $discount = 0;
        $discount_percent = 0;
    }

    $payable = $fee - $discount;
    if ($payable < 0) $payable = 0;

    $pay_mode   = trim($_POST['summary_payment_mode'] ?? "CASH: ₹{$payable}");
    $post_edit_id  = (int)($_POST['post_edit_id'] ?? 0);

    // 1 = Paid, 2 = Partial, 3 = Not Paid.
    if ($payable <= 0) $pay_status = 1;
    elseif ($total_paid >= $payable) $pay_status = 1;
    elseif ($total_paid > 0) $pay_status = 2;
    else $pay_status = 3;

    $current_fy_code = getCurrentFY($tenant_pdo, $org_id, $center_id, $visit_date);
    $display_fy = formatFYWithHyphen($current_fy_code);

    if (!empty($fullname) && !empty($mobile)) {
        try {
            $tenant_pdo->beginTransaction();

            if ($post_edit_id > 0) {
                // UPDATE EXISTING RECORD
                $up_visit = $tenant_pdo->prepare("SELECT patient_id FROM opd_visits WHERE visit_id = ? AND org_id = ?");
                $up_visit->execute([$post_edit_id, $org_id]);
                $patient_id = $up_visit->fetchColumn();

                $tenant_pdo->prepare("UPDATE opd_patients SET fullname = ?, gender = ?, age = ?, mobile = ? WHERE patient_id = ? AND org_id = ?")
                           ->execute([$fullname, $gender, $age, $mobile, $patient_id, $org_id]);

                $tenant_pdo->prepare("
                    UPDATE opd_visits 
                    SET visit_date = ?, doctor_id = ?, department_id = ?, service_id = ?, category_id = ?, insurance_id = ?, 
                        consultation_fee = ?, discount_amount = ?, discount_percent = ?, remark = ?, payment_mode = ?, amount_paid = ?, change_return = ?, 
                        payment_breakdown = ?, payment_status = ? 
                    WHERE visit_id = ? AND org_id = ?
                ")->execute([$visit_date, $doctor_id, $department_id, $service_id, $category_id, $insurance_id, $fee, $discount, $discount_percent, $remark, $pay_mode, $total_paid, $change_ret, $pay_breakdown, $pay_status, $post_edit_id, $org_id]);

                $tenant_pdo->commit();
                if (function_exists('set_flash_msg')) set_flash_msg("OPD Record updated successfully!");
                header("Location: opd_registration.php");
                exit;
            } else {
                // INSERT NEW RECORD
                $primary_mobile = explode(',', $mobile)[0]; 
                
                $check_stmt = $tenant_pdo->prepare("SELECT patient_id, uhid FROM opd_patients WHERE mobile LIKE ? AND org_id = ? LIMIT 1 FOR UPDATE");
                $check_stmt->execute([trim($primary_mobile) . '%', $org_id]);
                $existing_patient = $check_stmt->fetch();

                if ($existing_patient) {
                    $patient_id = $existing_patient['patient_id'];
                    $uhid = $existing_patient['uhid'];
                    $tenant_pdo->prepare("UPDATE opd_patients SET mobile = ? WHERE patient_id = ? AND org_id = ?")->execute([$mobile, $patient_id, $org_id]);
                } else {
                    $uhid = generateSecureUHID($tenant_pdo, $center_id, $current_fy_code);
                    $stmt = $tenant_pdo->prepare("
                        INSERT INTO opd_patients (org_id, center_id, uhid, fullname, gender, age, mobile, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([$org_id, $center_id, $uhid, $fullname, $gender, $age, $mobile]);
                    $patient_id = $tenant_pdo->lastInsertId();
                }

                // ==========================================================
                // SEPARATE TOKEN NO. AND RECEIPT NO.
                //
                // TOKEN NO.   = DAILY OPD QUEUE TOKEN (1, 2, 3...)
                // RECEIPT NO. = FINANCIAL-YEAR BILL/RECEIPT SERIAL (1, 2, 3...)
                // ==========================================================

                // Daily token: new token for the patient's queue position.
                // Existing historical rows remain untouched.
                $token_stmt = $tenant_pdo->prepare("
                    SELECT COUNT(*)
                    FROM opd_visits
                    WHERE visit_date = ?
                      AND org_id = ?
                      AND center_id = ?
                ");
                $token_stmt->execute([$visit_date, $org_id, $center_id]);
                $next_token = ((int)$token_stmt->fetchColumn()) + 1;

                // Financial-year receipt number.
                $visitTs = strtotime($visit_date);
                $visitYear = (int)date('Y', $visitTs);
                $visitMonth = (int)date('m', $visitTs);

                if ($visitMonth >= 4) {
                    $fyStartDate = $visitYear . '-04-01';
                    $fyEndDate   = ($visitYear + 1) . '-03-31';
                } else {
                    $fyStartDate = ($visitYear - 1) . '-04-01';
                    $fyEndDate   = $visitYear . '-03-31';
                }

                // Receipt No. is independent from Token No.
                // It continues for the complete financial year.
                $receipt_stmt = $tenant_pdo->prepare("
                    SELECT COALESCE(MAX(receipt_no), 0)
                    FROM opd_visits
                    WHERE visit_date BETWEEN ? AND ?
                      AND org_id = ?
                      AND center_id = ?
                ");
                $receipt_stmt->execute([$fyStartDate, $fyEndDate, $org_id, $center_id]);
                $last_receipt_no = (int)$receipt_stmt->fetchColumn();
                $next_receipt_no = $last_receipt_no + 1;

                $v_stmt = $tenant_pdo->prepare("
                    INSERT INTO opd_visits (org_id, center_id, patient_id, doctor_id, department_id, service_id, category_id, insurance_id, token_no, receipt_no, visit_date, consultation_fee, discount_amount, discount_percent, remark, payment_mode, amount_paid, change_return, payment_breakdown, payment_status, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Waiting')
                ");
                $v_stmt->execute([$org_id, $center_id, $patient_id, $doctor_id, $department_id, $service_id, $category_id, $insurance_id, $next_token, $next_receipt_no, $visit_date, $fee, $discount, $discount_percent, $remark, $pay_mode, $total_paid, $change_ret, $pay_breakdown, $pay_status]);

                $tenant_pdo->commit();
                
                $display_token = (string)$next_token;
                $display_receipt = $display_fy . '/' . str_pad($next_receipt_no, 2, '0', STR_PAD_LEFT);
                $doc_name = "General OPD";
                if ($doctor_id > 0) {
                    $d_stmt = $tenant_pdo->prepare("SELECT full_name FROM master_doctors WHERE id = ? AND org_id = ?");
                    $d_stmt->execute([$doctor_id, $org_id]);
                    $doc_name = $d_stmt->fetchColumn() ?: $doc_name;
                }

                // Prepare Token Generated Data for Modal
                $token_generated = [
                    'token'        => $next_token,
                    'token_display'=> $display_token,
                    'receipt_no'   => $next_receipt_no,
                    'receipt_display' => $display_receipt,
                    'uhid'         => $uhid,
                    'name'         => $fullname,
                    'age_gender'   => $age . ' Yrs / ' . $gender,
                    'mobile'       => $mobile,
                    'doctor'       => $doc_name,
                    'fee'          => $fee,
                    'discount'     => $discount,
                    'discount_percent' => $discount_percent,
                    'payable'      => $payable,
                    'mode'         => $pay_mode,
                    'payment_breakdown' => $pay_breakdown,
                    'paid'         => $total_paid,
                    'status'       => $pay_status,
                    'return'       => $change_ret,
                    'date_time'    => date('d-m-Y h:i A'),
                    'remark'       => $remark
                ];
            }
        } catch (Exception $e) {
            if ($tenant_pdo->inTransaction()) $tenant_pdo->rollBack();
            $err = "Registration Error: " . $e->getMessage();
        }
    } else {
        $err = "Please fill in all mandatory fields.";
    }
}

// --- FETCH MULTI-TENANT DROPDOWN DATA ---
$departments = $tenant_pdo->query("SELECT id, dept_name FROM master_departments WHERE org_id = $org_id AND center_id = $center_id AND status = 1")->fetchAll();
$doctors = $tenant_pdo->query("SELECT id, full_name, department_id FROM master_doctors WHERE org_id = $org_id AND center_id = $center_id AND status = 1")->fetchAll();
$services = $tenant_pdo->query("SELECT id, service_name FROM master_doctor_services WHERE org_id = $org_id AND center_id = $center_id AND status = 1")->fetchAll();
$categories = $tenant_pdo->query("SELECT id, category_name FROM master_categories WHERE org_id = $org_id AND center_id = $center_id AND status = 1")->fetchAll();
$insurances = $tenant_pdo->query("SELECT id, category_id, insurance_name FROM insurance WHERE org_id = $org_id AND center_id = $center_id AND status = 1 AND insurance_name IS NOT NULL")->fetchAll();

$tariffsStmt = $tenant_pdo->query("SELECT doctor_id, service_id, category_id, insurance_id, rate FROM doctor_tariff_master WHERE org_id = $org_id AND center_id = $center_id AND status = 1");
$tariffs = $tariffsStmt->fetchAll(PDO::FETCH_ASSOC);

$today_date = date('Y-m-d');
$today_visits = [];
try {
    // BILLING LIST: show every OPD bill for this organisation/center.
    // New bills appear at the top; older bills remain available below.
    $tv_stmt = $tenant_pdo->prepare("
        SELECT v.*, p.uhid, p.fullname, p.mobile, p.age, p.gender, d.full_name as doctor_name
        FROM opd_visits v
        JOIN opd_patients p ON v.patient_id = p.patient_id
        LEFT JOIN master_doctors d ON v.doctor_id = d.id
        WHERE v.org_id = ? AND v.center_id = ?
        ORDER BY v.visit_id DESC
    ");
    $tv_stmt->execute([$org_id, $center_id]);
    $today_visits = $tv_stmt->fetchAll() ?: [];
} catch (Exception $e) {}

require_once __DIR__ . '/layout_header.php';
?>

<style>
/* =========================================================
   GLOBAL UI: DISPLAY TEXT IN UPPERCASE
   User-entered text fields are also normalized to uppercase
   by JavaScript, except numeric/date/mobile fields.
   ========================================================= */
body,
.card,
.card-header,
.card-body,
.form-label,
.form-select,
.form-control,
.form-check-label,
.btn,
.badge,
.table,
.modal,
.modal-title,
.modal-body,
.modal-footer,
.alert,
.small,
.text-muted,
.text-secondary,
.text-primary,
.text-danger,
.text-success,
label,
th,
td {
    text-transform: uppercase;
}

input[type="number"],
input[type="date"],
input[inputmode="numeric"],
.mob-input,
#visit_date_display {
    text-transform: none;
}

.visit-date-picker-wrap {
    position: relative;
}

.native-date-picker {
    position: absolute;
    right: 0;
    top: 0;
    width: 38px;
    height: 31px;
    opacity: 0;
    cursor: pointer;
    z-index: 10;
    pointer-events: auto;
}

/* Receipt is always uppercase for printed/displayed content. */
.receipt-preview-wrap,
.receipt-preview-wrap * {
    text-transform: uppercase;
}

@media print {
    @page { size: A5 portrait; margin: 0; }
    body * { visibility: hidden; }
    #hospitalReceipt, #hospitalReceipt * { visibility: visible; }
    #hospitalReceipt { 
        position: absolute; left: 0; top: 0; width: 148mm; height: 210mm; /* Strict A5 Size */
        margin: 0; padding: 6mm; box-sizing: border-box; background: #fff; 
        border: none !important; box-shadow: none !important; overflow: hidden;
    }
    .no-print { display: none !important; }
}
.tariff-box { background-color: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 15px; text-align: center; }
.modal-a5 {
    width: 158mm;
    max-width: calc(100vw - 24px);
    margin: 20px auto;
}
.modal-a5 .modal-content {
    max-height: calc(100vh - 40px);
    overflow: hidden;
    border-radius: 10px;
}
.modal-a5 .modal-body {
    overflow: auto;
    padding: 8px;
    background: #e9ecef;
}
.receipt-preview-wrap {
    width: 148mm;
    height: 210mm;
    max-width: none;
    min-width: 148mm;
    margin: 0 auto;
    padding: 8mm;
    background: #fff;
    box-shadow: 0 2px 14px rgba(0,0,0,.18);
    overflow: hidden;
    box-sizing: border-box;
}
.receipt-preview-wrap .receipt-sheet {
    width: 100%;
    max-width: none;
    min-height: 100%;
    margin: 0;
    font-family: Arial, sans-serif;
    color: #111;
    font-size: 10.5px;
}
.receipt-preview-wrap .receipt-sheet { width:100%; max-width:138mm; margin:0 auto; font-family:Arial,sans-serif; color:#111; font-size:11px; }
.receipt-preview-wrap .receipt-header { text-align:center; border-bottom:2px solid #111; padding-bottom:8px; margin-bottom:9px; }
.receipt-preview-wrap .hospital { font-size:18px; font-weight:800; text-transform:uppercase; }
.receipt-preview-wrap .center { font-size:13px; font-weight:700; margin-top:2px; }
.receipt-preview-wrap .address { font-size:9px; color:#555; margin-top:2px; }
.receipt-preview-wrap .receipt-title { font-size:11px; font-weight:800; margin-top:7px; }
.receipt-preview-wrap .token-box { display:flex; justify-content:space-between; align-items:center; border:1px solid #aaa; background:#f7f7f7; padding:7px; margin-bottom:9px; }
.receipt-preview-wrap .small-label { font-size:8px; font-weight:700; color:#666; }
.receipt-preview-wrap .token { font-size:22px; font-weight:800; }
.receipt-preview-wrap .date-block { text-align:right; font-size:9px; line-height:1.5; }
.receipt-preview-wrap .receipt-table { width:100%; border-collapse:collapse; }
.receipt-preview-wrap .receipt-table th,.receipt-preview-wrap .receipt-table td { border:1px solid #bbb; padding:5px; vertical-align:top; }
.receipt-preview-wrap .info-table th { width:23%; text-align:left; background:#f1f3f5; font-weight:700; }
.receipt-preview-wrap .billing-table td:first-child { width:65%; }
.receipt-preview-wrap .section-title { font-size:10px; font-weight:800; margin:9px 0 4px; border-bottom:1px solid #222; padding-bottom:2px; }
.receipt-preview-wrap .amount { text-align:right; font-weight:700; white-space:nowrap; }
.receipt-preview-wrap .discount { color:#c62828; }
.receipt-preview-wrap .grand td { font-weight:800; background:#f3f4f6; }
.receipt-preview-wrap .instructions { margin-top:12px; padding-top:7px; border-top:1px dashed #999; font-size:9px; line-height:1.45; }

@media (max-width: 800px) {
    .modal-a5 {
        width: calc(100vw - 16px);
        max-width: calc(100vw - 16px);
        margin: 8px auto;
    }
    .modal-a5 .modal-content {
        max-height: calc(100vh - 16px);
    }
    .modal-a5 .modal-body {
        padding: 4px;
    }
    .receipt-preview-wrap {
        width: 148mm;
        height: 210mm;
        transform-origin: top left;
        /* The modal can scroll horizontally/vertically on smaller screens. */
    }
}
</style>

<?php if (!empty($err)): ?>
    <div class="alert alert-danger py-2 px-3 mb-3 fw-bold no-print"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<?php if ($token_generated): ?>
    <!-- SUCCESS POPUP MODAL FOR NEW REGISTRATION -->
    <div class="modal fade" id="successPrintModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-body p-4 text-center">
                    <div class="mb-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem;"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">Registration Successful!</h5>
                    <p class="text-muted small mb-3">Patient: <?= htmlspecialchars($token_generated['name']) ?></p>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="bg-light rounded-3 p-2 border">
                                <span class="d-block small text-muted fw-bold mb-1">TOKEN NO.</span>
                                <h4 class="fw-bold text-primary mb-0"><?= htmlspecialchars($token_generated['token_display']) ?></h4>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="bg-light rounded-3 p-2 border">
                                <span class="d-block small text-muted fw-bold mb-1">RECEIPT NO.</span>
                                <h4 class="fw-bold text-success mb-0"><?= htmlspecialchars($token_generated['receipt_display']) ?></h4>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between text-start small mb-1">
                        <span class="text-secondary">Consultation Fee:</span>
                        <span class="fw-bold">₹<?= number_format($token_generated['fee'], 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-start small mb-1">
                        <span class="text-danger">Discount:</span>
                        <span class="fw-bold text-danger">- ₹<?= number_format($token_generated['discount'], 2) ?> (<?= number_format($token_generated['discount_percent'], 2) ?>%)</span>
                    </div>
                    <div class="d-flex justify-content-between text-start small mb-1">
                        <span class="text-primary">Final Payable:</span>
                        <span class="fw-bold text-primary">₹<?= number_format($token_generated['payable'], 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-start small mb-3 pb-2 border-bottom">
                        <span class="text-secondary">Amount Paid (<?= htmlspecialchars($token_generated['mode']) ?>):</span>
                        <span class="fw-bold text-success">₹<?= number_format($token_generated['paid'], 2) ?></span>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-primary fw-bold" onclick="showGeneratedReceipt()"><i class="bi bi-printer me-2"></i> View Receipt</button>
                        <button type="button" class="btn btn-light border fw-semibold" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- REGISTRATION FORM -->
<div class="row g-3 no-print">
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-person-plus text-primary me-2"></i><?= $edit_data ? 'Edit Patient Registration #' . $edit_data['visit_id'] : 'New Patient Registration' ?></h6>
                <?php if ($edit_data): ?>
                    <a href="opd_registration.php" class="btn btn-sm btn-outline-secondary py-0">Cancel Edit</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-3">
                <form method="POST" id="opdForm">
                    <input type="hidden" name="register_patient" value="1">
                    <input type="hidden" name="post_edit_id" value="<?= $edit_data['visit_id'] ?? 0 ?>">
                    
                    <input type="hidden" name="consultation_fee" id="consultation_fee" value="<?= $edit_data['consultation_fee'] ?? 0 ?>">
                    <input type="hidden" name="modal_discount" id="modal_discount" value="<?= $edit_data['discount_amount'] ?? 0 ?>">
                    <input type="hidden" name="discount_percent" id="hidden_discount_percent" value="<?= $edit_data['discount_percent'] ?? 0 ?>">
                    <input type="hidden" name="modal_total_paid" id="modal_total_paid" value="<?= $edit_data['amount_paid'] ?? 0 ?>">
                    <input type="hidden" name="modal_change_return" id="modal_change_return" value="<?= $edit_data['change_return'] ?? 0 ?>">
                    <input type="hidden" name="payment_breakdown_json" id="payment_breakdown_json" value="<?= htmlspecialchars($edit_data['payment_breakdown'] ?? '') ?>">
                    <input type="hidden" name="summary_payment_mode" id="summary_payment_mode" value="<?= htmlspecialchars($edit_data['payment_mode'] ?? 'CASH: ₹0') ?>">

                    <!-- PATIENT INFO ROW -->
                    <div class="row g-2 mb-3 border-bottom pb-3">
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold text-secondary">Visit Date *</label>
                            <div class="visit-date-picker-wrap">
                                <div class="input-group input-group-sm">
                                    <input type="hidden" name="visit_date" id="visit_date"
                                           value="<?= htmlspecialchars($edit_data['visit_date'] ?? date('Y-m-d')) ?>">
                                    <input type="text" id="visit_date_display"
                                           class="form-control text-dark fw-bold"
                                           value="<?= htmlspecialchars(!empty($edit_data['visit_date']) ? date('d-m-Y', strtotime($edit_data['visit_date'])) : date('d-m-Y')) ?>"
                                           placeholder="DD-MM-YYYY" maxlength="10"
                                           autocomplete="off" inputmode="numeric" required>
                                    <button type="button" class="btn btn-outline-primary"
                                            id="visit_date_calendar_btn"
                                            title="Select Visit Date"
                                            onclick="openVisitDatePicker()">
                                        <i class="bi bi-calendar3"></i>
                                    </button>
                                </div>

                                <!-- Native calendar control. Its clickable area sits on the calendar icon. -->
                                <input type="date" id="visit_date_picker"
                                       class="native-date-picker"
                                       value="<?= htmlspecialchars($edit_data['visit_date'] ?? date('Y-m-d')) ?>"
                                       aria-label="Select Visit Date">
                            </div>
                            <small class="text-muted" style="font-size:10px;"></small>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold text-secondary">UHID</label>
                            <input type="text" class="form-control form-control-sm bg-light font-monospace text-muted" value="<?= htmlspecialchars($edit_data['uhid'] ?? 'Auto Generated') ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Patient Full Name * <span class="text-muted fw-normal">(AUTO-FILLED FROM MOBILE)</span></label>
                            <input type="text" name="fullname" id="registration_fullname" class="form-control form-control-sm text-capitalize" value="<?= htmlspecialchars($edit_data['fullname'] ?? '') ?>" placeholder="Patient Name" required autofocus>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Gender *</label>
                            <?php $current_gender = $edit_data['gender'] ?? ''; ?>
                            <select name="gender" id="gender" class="form-select form-select-sm" required>
                                <option value="" disabled <?= $current_gender === '' ? 'selected' : '' ?>>-- Select Gender --</option>
                                <option value="Male" <?= $current_gender === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $current_gender === 'Female' ? 'selected' : '' ?>>Female</option>
                                <option value="Other" <?= $current_gender === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label small fw-semibold">Age *</label>
                            <input type="number" name="age" id="registration_age" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['age'] ?? '') ?>" placeholder="Age" required>
                        </div>
                        
                        <!-- MOBILE NUMBERS SECTION -->
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Mobile Number(s) *</label>
                            <div id="mobile_wrapper">
                                <?php 
                                $saved_mobs = array_filter(array_map('trim', explode(',', $edit_data['mobile'] ?? '')));
                                if(empty($saved_mobs)) $saved_mobs = [''];
                                $i = 0;
                                foreach($saved_mobs as $mob): 
                                    $len = strlen($mob);
                                ?>
                                <div class="input-group input-group-sm mb-1 mob-row">
                                    <input type="text" name="mobile[]" id="registration_mobile" class="form-control form-control-sm mob-input" value="<?= htmlspecialchars($mob) ?>" placeholder="Mobile No" maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, ''); updateMobCount(this, <?= $i ?>)" <?= $i===0?'required':'' ?>>
                                    <span class="input-group-text <?= $len == 10 ? 'text-success fw-bold' : 'text-muted' ?>" id="mob_count_<?= $i ?>" style="font-size:0.7rem; min-width: 45px; text-align:center;"><?= $len ?>/10</span>
                                    <?php if($i === 0): ?>
                                        <button type="button" class="btn btn-outline-primary" onclick="addMobileField()"><i class="bi bi-plus-lg"></i></button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-danger" onclick="this.closest('.mob-row').remove()"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
                                </div>
                                <?php $i++; endforeach; ?>
                            </div>
                        </div>
                                    <div id="registration_patient_status" class="small mt-1 text-muted"></div>
                                    <input type="hidden" id="registration_patient_id" name="patient_id">
                                    <input type="hidden" id="registration_patient_uhid" name="patient_uhid">
                    </div>

                    <!-- TARIFF & MASTERS ROW -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold text-primary">Department</label>
                                    <select name="department_id" id="department_id" class="form-select form-select-sm" onchange="filterDoctors()">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?= $dept['id'] ?>" <?= (isset($edit_data['department_id']) && $edit_data['department_id'] == $dept['id']) ? 'selected' : '' ?>><?= htmlspecialchars($dept['dept_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold text-primary">Doctor *</label>
                                    <select name="doctor_id" id="doctor_id" class="form-select form-select-sm" required onchange="calculateTariff()">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($doctors as $doc): ?>
                                            <option value="<?= $doc['id'] ?>" data-dept="<?= $doc['department_id'] ?>" <?= (isset($edit_data['doctor_id']) && $edit_data['doctor_id'] == $doc['id']) ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold text-primary">Service *</label>
                                    <select name="service_id" id="service_id" class="form-select form-select-sm" required onchange="calculateTariff()">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($services as $srv): ?>
                                            <option value="<?= $srv['id'] ?>" <?= (isset($edit_data['service_id']) && $edit_data['service_id'] == $srv['id']) ? 'selected' : '' ?>><?= htmlspecialchars($srv['service_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold text-primary">Category *</label>
                                    <select name="category_id" id="category_id" class="form-select form-select-sm" required onchange="filterInsurances()">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= $cat['id'] ?>" <?= (isset($edit_data['category_id']) && $edit_data['category_id'] == $cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold text-primary">Insurance / Provider</label>
                                    <select name="insurance_id" id="insurance_id" class="form-select form-select-sm" onchange="calculateTariff()">
                                        <option value="">-- Base Category (No specific insurance) --</option>
                                        <?php foreach ($insurances as $ins): ?>
                                            <option value="<?= $ins['id'] ?>" data-cat="<?= $ins['category_id'] ?>" <?= (isset($edit_data['insurance_id']) && $edit_data['insurance_id'] == $ins['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ins['insurance_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold text-secondary">Remarks (Optional)</label>
                                    <input type="text" name="remark" class="form-control form-control-sm" placeholder="Any remark..." value="<?= htmlspecialchars($edit_data['remark'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- FEE DISPLAY & PAYMENT ACTION -->
                        <div class="col-md-4">
                            <div class="tariff-box h-100 d-flex flex-column align-items-center justify-content-center">
                                <span class="d-block text-secondary small fw-bold mb-1">Applicable Tariff Fee</span>
                                <?php 
                                    $disp_fee = ($edit_data['consultation_fee'] ?? 0) - ($edit_data['discount_amount'] ?? 0);
                                    if($disp_fee < 0) $disp_fee = 0;
                                ?>
                                <h1 class="fw-bold text-dark mb-3" id="display_tariff_fee">₹<?= number_format($disp_fee, 2) ?></h1>
                                
                                <button type="button" class="btn btn-outline-primary btn-sm w-100 fw-semibold mb-2" data-bs-toggle="modal" data-bs-target="#paymentModal">
                                    <i class="bi bi-wallet2 me-1"></i> <span id="paymentSummaryText"><?= htmlspecialchars($edit_data['payment_mode'] ?? 'Collect Payment') ?></span>
                                </button>
                                
                                <button type="submit" class="btn btn-success btn-sm w-100 fw-bold shadow-sm py-2">
                                    <i class="bi <?= $edit_data ? 'bi-check-lg' : 'bi-ticket-detailed' ?> me-1"></i> <?= $edit_data ? 'Update Registration' : 'Register & Save' ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Today's OPD Registrations -->
    <div class="col-12 mt-2">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="py-2 px-3 border-bottom d-flex justify-content-between align-items-center gap-3 bg-white rounded-top-3">
                <h6 class="fw-bold text-dark m-0 small"><i class="bi bi-list-columns-reverse text-primary me-2"></i>OPD Billing / All Bills</h6>
                <input type="text" id="searchParcheInput" class="form-control form-control-sm w-25" placeholder="Search name, UHID, mobile...">
            </div>
            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-hover align-middle mb-0 small" id="parcheTable">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-3">Token / Receipt</th>
                            <th>Bill Date</th>
                            <th>Patient Info</th>
                            <th>UHID / Mobile</th>
                            <th>Fee / Mode</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($today_visits)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No OPD bills found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($today_visits as $tv): ?>
                                <?php 
                                    $list_fy = getCurrentFY($tenant_pdo, $org_id, $center_id, $tv['visit_date']);
                                    $list_fy_formatted = formatFYWithHyphen($list_fy);
                                    $list_token = $list_fy_formatted . '/' . str_pad($tv['token_no'], 2, '0', STR_PAD_LEFT);
                                ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-bold fs-6 text-primary">T-<?= htmlspecialchars($tv['token_no']) ?></div>
                                        <div class="text-success" style="font-size:0.68rem;">
                                            R-<?= htmlspecialchars($list_fy_formatted . '/' . str_pad((int)($tv['receipt_no'] ?? 0), 2, '0', STR_PAD_LEFT)) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= !empty($tv['visit_date']) ? date('d-m-Y', strtotime($tv['visit_date'])) : '' ?></div>
                                        <?php if (!empty($tv['created_at'])): ?>
                                            <small class="text-muted"><?= date('h:i A', strtotime($tv['created_at'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark text-capitalize"><?= htmlspecialchars($tv['fullname'] ?? '') ?></div>
                                        <small class="text-muted"><?= $tv['age'] ?? '' ?> Yrs / <?= $tv['gender'] ?? '' ?></small>
                                    </td>
                                    <td>
                                        <div class="font-monospace text-secondary" style="font-size: 0.72rem;"><?= htmlspecialchars($tv['uhid'] ?? '') ?></div>
                                        <small class="text-dark">
                                            <?php 
                                            $mobs = explode(',', $tv['mobile']); 
                                            echo htmlspecialchars(trim($mobs[0])) . (count($mobs)>1 ? " <span title='".htmlspecialchars($tv['mobile'])."' class='badge bg-light text-dark border'>+".(count($mobs)-1)."</span>" : "");
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if($tv['discount_amount'] > 0): ?>
                                            <div class="fw-bold text-success">
                                                <del class="text-muted small">₹<?= number_format($tv['consultation_fee'], 2) ?></del> 
                                                ₹<?= number_format($tv['consultation_fee'] - $tv['discount_amount'], 2) ?>
                                            </div>
                                            <div class="text-danger" style="font-size:0.65rem;">(Disc: ₹<?= number_format($tv['discount_amount'], 2) ?> / <?= number_format($tv['discount_percent'] ?? 0, 2) ?>%)</div>
                                        <?php else: ?>
                                            <div class="fw-bold text-success">₹<?= number_format($tv['consultation_fee'] ?? 0, 2) ?></div>
                                        <?php endif; ?>
                                        <span class="badge bg-light text-secondary border text-truncate d-inline-block mt-1" style="font-size: 0.65rem; max-width:120px;" title="<?= htmlspecialchars($tv['payment_mode'] ?? '') ?>"><?= htmlspecialchars($tv['payment_mode'] ?? '') ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php
    $listStatusCode = (int)($tv['payment_status'] ?? 3);
    $listStatusLabel = $listStatusCode === 1 ? 'Paid' : ($listStatusCode === 2 ? 'Partial' : 'Not Paid');
    $listStatusClass = $listStatusCode === 1 ? 'bg-success-subtle text-success border-success' : ($listStatusCode === 2 ? 'bg-warning-subtle text-warning border-warning' : 'bg-danger-subtle text-danger border-danger');
?>
<span class="badge <?= $listStatusClass ?> border px-2" style="font-size: 0.7rem;"><?= $listStatusLabel ?></span>
                                    </td>
                                    <td class="text-end pe-3">
                                        <button class="btn btn-sm btn-outline-dark py-0 px-2" title="Print Receipt" onclick="printExistingParcha(
    <?= htmlspecialchars(json_encode($tv['fullname'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['uhid'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode(($tv['age'] ?? '') . ' Yrs / ' . ($tv['gender'] ?? '')), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['mobile'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['doctor_name'] ?? 'General OPD'), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['consultation_fee'] ?? 0), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['discount_amount'] ?? 0), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['discount_percent'] ?? 0), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['payment_mode'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode((string)($tv['token_no'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($list_fy_formatted . '/' . str_pad((int)($tv['receipt_no'] ?? 0), 2, '0', STR_PAD_LEFT)), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['remark'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode(!empty($tv['created_at']) ? date('d-m-Y h:i A', strtotime($tv['created_at'])) : date('d-m-Y h:i A', strtotime($tv['visit_date'] ?? 'now'))), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['payment_breakdown'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['amount_paid'] ?? 0), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['change_return'] ?? 0), ENT_QUOTES, 'UTF-8') ?>,
    <?= htmlspecialchars(json_encode($tv['payment_status'] ?? 3), ENT_QUOTES, 'UTF-8') ?>
)" >
    <i class="bi bi-printer"></i>
                                        </button>
                                        <a href="opd_registration.php?edit_visit=<?= $tv['visit_id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2 ms-1" title="Edit Registration">
                                            <i class="bi bi-pencil"></i>
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

<!-- PAYMENT MODAL -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0 shadow rounded-3">
            <div class="modal-header bg-light py-2 px-3 border-bottom">
                <h6 class="modal-title fw-bold text-dark m-0"><i class="bi bi-wallet2 text-primary me-2"></i>Payment Details</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-semibold text-secondary small">Tariff Amount:</span>
                    <strong class="text-dark" id="modalTariffAmount">₹0.00</strong>
                </div>
                <?php if ($edit_data): ?>
                <?php
                    $savedStatusCode = (int)($edit_data['payment_status'] ?? 3);
                    $savedStatusLabel = $savedStatusCode === 1 ? 'Paid' : ($savedStatusCode === 2 ? 'Partial' : 'Not Paid');
                ?>
                <div class="bg-light border rounded-2 p-2 mb-3">
                    <div class="small fw-bold text-dark mb-1">Previously Saved</div>
                    <div class="d-flex justify-content-between small">
                        <span class="text-secondary">Discount:</span>
                        <strong class="text-danger">₹<?= number_format((float)($edit_data['discount_amount'] ?? 0), 2) ?> (<?= number_format((float)($edit_data['discount_percent'] ?? 0), 2) ?>%)</strong>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span class="text-secondary">Payment Mode:</span>
                        <strong class="text-dark"><?= htmlspecialchars($edit_data['payment_mode'] ?? 'Not Saved') ?></strong>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span class="text-secondary">Saved Status:</span>
                        <strong class="<?= $savedStatusCode === 1 ? 'text-success' : ($savedStatusCode === 2 ? 'text-warning' : 'text-danger') ?>"><?= $savedStatusLabel ?></strong>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="row g-2 mb-3 pb-2 border-bottom">
                    <div class="col-6">
                        <span class="fw-semibold text-danger small">Discount (%):</span>
                        <input type="number" id="modal_discount_percent" class="form-control form-control-sm text-end text-danger fw-bold" value="<?= htmlspecialchars($edit_data['discount_percent'] ?? 0) ?>" placeholder="0" min="0" max="100" step="0.01" oninput="calculateDiscountFromPercent()">
                    </div>
                    <div class="col-6">
                        <span class="fw-semibold text-danger small">Discount (₹):</span>
                        <input type="number" id="modal_discount_input" class="form-control form-control-sm text-end text-danger fw-bold" value="<?= $edit_data['discount_amount'] ?? 0 ?>" oninput="handleDiscountChange()">
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-3 bg-primary bg-opacity-10 p-2 rounded">
                    <span class="fw-bold text-primary">Final Payable:</span>
                    <h5 class="fw-bold text-primary m-0" id="modalPayableAmount">₹0.00</h5>
                </div>
                
                <!-- CASH PAYMENT BLOCK -->
                <div class="mb-2 pb-2 border-bottom">
                    <div class="form-check mb-1">
                        <input class="form-check-input pay-check" type="checkbox" id="check_cash" <?= !$edit_data ? 'checked' : '' ?> onchange="togglePaymentInputs('cash')">
                        <label class="form-check-label fw-bold text-secondary small" for="check_cash">Cash Payment</label>
                    </div>
                    <div id="div_input_cash" style="<?= !$edit_data ? 'block' : 'none' ?>;" class="ps-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white fw-bold">₹</span>
                            <input type="number" id="amt_cash" class="form-control pay-amt fw-bold text-dark" value="<?= $edit_data['amount_paid'] ?? 0 ?>" placeholder="0" onkeyup="calculateModalTotals(false)">
                        </div>
                    </div>
                </div>

                <!-- UPI PAYMENT BLOCK -->
                <div class="mb-2 pb-2 border-bottom">
                    <div class="form-check mb-1">
                        <input class="form-check-input pay-check" type="checkbox" id="check_upi" onchange="togglePaymentInputs('upi')">
                        <label class="form-check-label fw-bold text-secondary small" for="check_upi">UPI Payment</label>
                    </div>
                    <div id="div_input_upi" style="display:none;" class="ps-4">
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text bg-white fw-bold">₹</span>
                            <input type="number" id="amt_upi" class="form-control pay-amt fw-bold text-dark" value="0" placeholder="0" onkeyup="calculateModalTotals(false)">
                        </div>
                        <input type="text" id="utr_upi" class="form-control form-control-sm font-monospace" style="font-size: 0.75rem;" placeholder="Ref / UTR No.">
                    </div>
                </div>

                <!-- CARD PAYMENT BLOCK -->
                <div class="mb-2 pb-2 border-bottom">
                    <div class="form-check mb-1">
                        <input class="form-check-input pay-check" type="checkbox" id="check_card" onchange="togglePaymentInputs('card')">
                        <label class="form-check-label fw-bold text-secondary small" for="check_card">Card Payment</label>
                    </div>
                    <div id="div_input_card" style="display:none;" class="ps-4">
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text bg-white fw-bold">₹</span>
                            <input type="number" id="amt_card" class="form-control pay-amt fw-bold text-dark" value="0" placeholder="0" onkeyup="calculateModalTotals(false)">
                        </div>
                        <input type="text" id="utr_card" class="form-control form-control-sm font-monospace" style="font-size: 0.75rem;" placeholder="Txn Ref No.">
                    </div>
                </div>

                <!-- NET BANKING BLOCK -->
                <div class="mb-2">
                    <div class="form-check mb-1">
                        <input class="form-check-input pay-check" type="checkbox" id="check_net" onchange="togglePaymentInputs('net')">
                        <label class="form-check-label fw-bold text-secondary small" for="check_net">Net Banking</label>
                    </div>
                    <div id="div_input_net" style="display:none;" class="ps-4">
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text bg-white fw-bold">₹</span>
                            <input type="number" id="amt_net" class="form-control pay-amt fw-bold text-dark" value="0" placeholder="0" onkeyup="calculateModalTotals(false)">
                        </div>
                        <input type="text" id="utr_net" class="form-control form-control-sm font-monospace" style="font-size: 0.75rem;" placeholder="UTR No.">
                    </div>
                </div>

                <div class="bg-light p-2 rounded-2 small border mb-3 mt-3">
                    <div class="d-flex justify-content-between mb-1"><span class="text-secondary fw-semibold">Total Paid:</span><strong class="text-success" id="modalTotalPaid">₹<?= number_format($edit_data['amount_paid'] ?? 0, 2) ?></strong></div>
                    <div class="d-flex justify-content-between"><span class="text-danger fw-semibold">Return Change:</span><strong class="text-danger" id="modalReturnChange">₹<?= number_format($edit_data['change_return'] ?? 0, 2) ?></strong></div>
                </div>
                <button type="button" class="btn btn-primary btn-sm w-100 fw-semibold py-1.5" data-bs-dismiss="modal" onclick="applyModalPayment()">Save Payment Details</button>
            </div>
        </div>
    </div>
</div>

<div id="reprintSlip" class="d-none"></div>

<!-- RECEIPT PREVIEW MODAL -->
<div class="modal fade" id="receiptPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-a5">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header py-2 px-3 bg-dark text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-receipt me-2"></i>OPD Receipt Preview <span class="badge bg-light text-dark ms-2"></span></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-3">
                <div id="receiptPreviewContent" class="receipt-preview-wrap"></div>
            </div>
            <div class="modal-footer py-2 px-3">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary fw-bold" onclick="printReceiptFromPreview()">
                    <i class="bi bi-printer me-1"></i> Print A5 Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let registrationLookupTimer = null;

async function lookupRegistrationPatient() {
    const mobileEl = document.getElementById('registration_mobile');
    const nameEl = document.getElementById('registration_fullname');
    const ageEl = document.getElementById('registration_age');
    const genderEl = document.getElementById('gender');
    const patientIdEl = document.getElementById('registration_patient_id');
    const uhidEl = document.getElementById('registration_patient_uhid');
    const statusEl = document.getElementById('registration_patient_status');

    if (!mobileEl) return;

    const mobile = mobileEl.value.replace(/\D/g, '');
    if (mobile.length < 10) return;

    statusEl.textContent = 'SEARCHING PATIENT...';
    statusEl.className = 'small mt-1 text-muted';

    try {
        const response = await fetch(
            'opd_registration.php?ajax=patient_lookup&mobile=' + encodeURIComponent(mobile),
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        );
        const data = await response.json();

        if (data.found && data.patient) {
            nameEl.value = (data.patient.fullname || '').toUpperCase();
            ageEl.value = data.patient.age || '';
            genderEl.value = data.patient.gender || '';
            patientIdEl.value = data.patient.patient_id || '';
            uhidEl.value = data.patient.uhid || '';

            statusEl.textContent = 'EXISTING PATIENT FOUND — ' + (data.patient.uhid || '');
            statusEl.className = 'small mt-1 text-success fw-bold';
        } else {
            patientIdEl.value = '';
            uhidEl.value = '';
            statusEl.textContent = 'NEW PATIENT';
            statusEl.className = 'small mt-1 text-primary fw-bold';
        }
    } catch (error) {
        statusEl.textContent = '';
        statusEl.className = 'small mt-1 text-muted';
    }
}

function lookupRegistrationPatientDebounced() {
    clearTimeout(registrationLookupTimer);
    const mobileEl = document.getElementById('registration_mobile');
    if (!mobileEl) return;

    mobileEl.value = mobileEl.value.replace(/\D/g, '').slice(0, 10);

    if (mobileEl.value.length === 10) {
        registrationLookupTimer = setTimeout(lookupRegistrationPatient, 250);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const mobileEl = document.getElementById('registration_mobile');
    if (mobileEl) mobileEl.addEventListener('blur', lookupRegistrationPatient);
});

<script>
// =====================================================================
// DATE UI STANDARD: ALWAYS DD-MM-YYYY (independent of Windows/browser locale)
// Database / PHP continues to use ISO YYYY-MM-DD.
// =====================================================================
function formatDisplayDateDMY(isoDate) {
    if (!isoDate) return '';
    const parts = String(isoDate).split('-');
    if (parts.length !== 3) return '';
    return `${parts[2].padStart(2,'0')}-${parts[1].padStart(2,'0')}-${parts[0]}`;
}

function formatIsoDate(dateText) {
    const m = String(dateText || '').trim().match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
    if (!m) return '';

    const dd = m[1].padStart(2,'0');
    const mm = m[2].padStart(2,'0');
    const yyyy = m[3];

    const d = new Date(`${yyyy}-${mm}-${dd}T00:00:00`);
    if (
        Number.isNaN(d.getTime()) ||
        d.getFullYear() !== Number(yyyy) ||
        d.getMonth() + 1 !== Number(mm) ||
        d.getDate() !== Number(dd)
    ) return '';

    return `${yyyy}-${mm}-${dd}`;
}

function setupVisitDateField() {
    const display = document.getElementById('visit_date_display');
    const hidden = document.getElementById('visit_date');
    const picker = document.getElementById('visit_date_picker');

    if (!display || !hidden || !picker) return;

    function syncFromIso(iso) {
        if (!iso) {
            hidden.value = '';
            display.value = '';
            picker.value = '';
            return;
        }

        hidden.value = iso;
        picker.value = iso;
        display.value = formatDisplayDateDMY(iso);
        display.classList.remove('is-invalid');
    }

    // Initial state: always display DD-MM-YYYY.
    syncFromIso(hidden.value || picker.value);

    // Manual typing: DD-MM-YYYY -> ISO for database.
    display.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9-]/g, '').slice(0, 10);

        const iso = formatIsoDate(this.value);
        if (iso) {
            hidden.value = iso;
            picker.value = iso;
            this.classList.remove('is-invalid');
        } else {
            hidden.value = '';
            this.classList.add('is-invalid');
        }
    });

    display.addEventListener('blur', function () {
        const iso = formatIsoDate(this.value);

        if (!iso) {
            this.classList.add('is-invalid');
            return;
        }

        syncFromIso(iso);
    });

    // Calendar selection -> DD-MM-YYYY display + ISO hidden value.
    picker.addEventListener('change', function () {
        if (this.value) {
            syncFromIso(this.value);
        }
    });
}

function openVisitDatePicker() {
    const picker = document.getElementById('visit_date_picker');
    if (!picker) return;

    try {
        picker.focus({ preventScroll: true });
    } catch (e) {
        picker.focus();
    }

    try {
        if (typeof picker.showPicker === 'function') {
            picker.showPicker();
        } else {
            picker.click();
        }
    } catch (e) {
        // The native input is positioned over the calendar button,
        // so a normal user click will still open it in Chrome/Edge.
        picker.click();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    setupVisitDateField();
});

function normalizeTypedTextToUppercase(root = document) {
    const fields = root.querySelectorAll(
        'input[type="text"]:not(#visit_date_display), textarea, select'
    );

    fields.forEach(field => {
        // Do not alter search/date/mobile numeric fields.
        if (
            field.id === 'searchParcheInput' ||
            field.id === 'visit_date_display' ||
            field.classList.contains('mob-input') ||
            field.inputMode === 'numeric'
        ) return;

        field.addEventListener('input', function () {
            const start = this.selectionStart;
            const end = this.selectionEnd;
            this.value = this.value.toUpperCase();
            if (document.activeElement === this && start !== null && end !== null) {
                try { this.setSelectionRange(start, end); } catch (e) {}
            }
        });

        // Normalize values already loaded in edit mode.
        if (field.value) {
            field.value = field.value.toUpperCase();
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    normalizeTypedTextToUppercase();
});

const dbTariffs = <?= json_encode($tariffs) ?>;
const orgName = <?= json_encode($org_name) ?>;
const centerName = <?= json_encode($center_name) ?>;
const centerAddress = <?= json_encode($center_address) ?>;
const receiptInstructions = <?= json_encode(nl2br(htmlspecialchars($receipt_instructions))) ?>;

let mobIndexCounter = 1000;

function addMobileField() {
    mobIndexCounter++;
    let wrapper = document.getElementById('mobile_wrapper');
    let html = `
        <div class="input-group input-group-sm mb-1 mob-row">
            <input type="text" name="mobile[]" class="form-control form-control-sm mob-input" placeholder="Alternate Mobile" maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, ''); updateMobCount(this, ${mobIndexCounter})">
            <span class="input-group-text text-muted" id="mob_count_${mobIndexCounter}" style="font-size:0.7rem; min-width: 45px; text-align:center;">0/10</span>
            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.mob-row').remove()"><i class="bi bi-trash"></i></button>
        </div>
    `;
    wrapper.insertAdjacentHTML('beforeend', html);
}

function updateMobCount(input, index) {
    let countSpan = document.getElementById('mob_count_' + index);
    if(countSpan) {
        let len = input.value.length;
        countSpan.innerText = len + '/10';
        if(len === 10) {
            countSpan.classList.remove('text-muted');
            countSpan.classList.add('text-success', 'fw-bold');
        } else {
            countSpan.classList.remove('text-success', 'fw-bold');
            countSpan.classList.add('text-muted');
        }
    }
}

function filterDoctors() {
    let deptId = document.getElementById('department_id').value;
    let docSelect = document.getElementById('doctor_id');
    for (let i = 0; i < docSelect.options.length; i++) {
        let opt = docSelect.options[i];
        if (opt.value === "") continue;
        if (!deptId || opt.getAttribute('data-dept') === deptId) opt.style.display = '';
        else opt.style.display = 'none';
    }
    docSelect.value = "";
    calculateTariff();
}

function filterInsurances() {
    const catSelect = document.getElementById('category_id');
    const insSelect = document.getElementById('insurance_id');
    const catId = catSelect.value;
    const currentValue = insSelect.value;

    // Save the complete provider list once.
    if (!insSelect.dataset.allOptions) {
        insSelect.dataset.allOptions = JSON.stringify(
            Array.from(insSelect.options).map(o => ({
                value: o.value,
                text: o.textContent,
                cat: o.getAttribute('data-cat') || ''
            }))
        );
    }

    const allOptions = JSON.parse(insSelect.dataset.allOptions);
    insSelect.innerHTML = '';

    if (!catId) {
        const base = document.createElement('option');
        base.value = '';
        base.textContent = '-- Select Category First --';
        insSelect.appendChild(base);
        calculateTariff();
        return;
    }

    // Do not hide <option> with CSS. Rebuild the list instead.
    const matching = allOptions.filter(o => o.value !== '' && String(o.cat) === String(catId));

    if (matching.length === 0) {
        const base = document.createElement('option');
        base.value = '';
        base.textContent = '-- Base Category (No specific insurance) --';
        insSelect.appendChild(base);
    } else if (matching.length === 1) {
        // Exactly one provider -> automatically select it.
        const o = document.createElement('option');
        o.value = matching[0].value;
        o.textContent = matching[0].text;
        o.setAttribute('data-cat', matching[0].cat);
        o.selected = true;
        insSelect.appendChild(o);
    } else {
        // Multiple providers -> keep Base Category and let user choose.
        const base = document.createElement('option');
        base.value = '';
        base.textContent = '-- Base Category (No specific insurance) --';
        insSelect.appendChild(base);

        matching.forEach(item => {
            const o = document.createElement('option');
            o.value = item.value;
            o.textContent = item.text;
            o.setAttribute('data-cat', item.cat);
            if (String(item.value) === String(currentValue)) o.selected = true;
            insSelect.appendChild(o);
        });
    }

    calculateTariff();
}

function calculateTariff() {
    let docId = document.getElementById('doctor_id').value;
    let srvId = document.getElementById('service_id').value;
    let catId = document.getElementById('category_id').value;
    let insId = document.getElementById('insurance_id').value;

    let finalRate = 0;
    if (docId && srvId && catId) {
        let exactMatch = dbTariffs.find(t => t.doctor_id == docId && t.service_id == srvId && t.category_id == catId && t.insurance_id == insId);
        if (!exactMatch && insId) exactMatch = dbTariffs.find(t => t.doctor_id == docId && t.service_id == srvId && t.category_id == catId && (t.insurance_id == null || t.insurance_id == ''));
        if (exactMatch) finalRate = parseFloat(exactMatch.rate);
    }

    document.getElementById('consultation_fee').value = finalRate;
    document.getElementById('modalTariffAmount').innerText = '₹' + finalRate.toFixed(2);
    
    <?php if (!$edit_data): ?>
        document.getElementById('modal_discount_input').value = 0;
        document.getElementById('modal_discount_percent').value = 0;
        syncDiscountHiddenFields();
        if (document.getElementById('check_cash').checked) document.getElementById('amt_cash').value = finalRate;
        calculateModalTotals(false);
    <?php else: ?>
        syncDiscountHiddenFields();
        calculateModalTotals(false);
    <?php endif; ?>
}

<?php if ($edit_data): ?>
try {
    ['cash', 'upi', 'card', 'net'].forEach(m => {
        document.getElementById('check_'+m).checked = false;
        document.getElementById('amt_'+m).value = 0;
        document.getElementById('div_input_'+m).style.display = 'none';
    });

    let savedBreakdown = {};
    const savedBreakdownRaw = <?= json_encode($edit_data['payment_breakdown'] ?? '') ?>;

    if (savedBreakdownRaw) {
        try {
            savedBreakdown = JSON.parse(savedBreakdownRaw);
            if (typeof savedBreakdown === 'string') savedBreakdown = JSON.parse(savedBreakdown);
        } catch(parseErr) {
            savedBreakdown = {};
        }
    }

    if (savedBreakdown.Cash) {
        document.getElementById('check_cash').checked = true;
        document.getElementById('amt_cash').value = savedBreakdown.Cash.amount || 0;
        document.getElementById('div_input_cash').style.display = 'block';
    }
    if (savedBreakdown.UPI) {
        document.getElementById('check_upi').checked = true;
        document.getElementById('amt_upi').value = savedBreakdown.UPI.amount || 0;
        document.getElementById('utr_upi').value = savedBreakdown.UPI.utr || '';
        document.getElementById('div_input_upi').style.display = 'block';
    }
    if (savedBreakdown.Card) {
        document.getElementById('check_card').checked = true;
        document.getElementById('amt_card').value = savedBreakdown.Card.amount || 0;
        document.getElementById('utr_card').value = savedBreakdown.Card.utr || '';
        document.getElementById('div_input_card').style.display = 'block';
    }
    if (savedBreakdown.NetBanking) {
        document.getElementById('check_net').checked = true;
        document.getElementById('amt_net').value = savedBreakdown.NetBanking.amount || 0;
        document.getElementById('utr_net').value = savedBreakdown.NetBanking.utr || '';
        document.getElementById('div_input_net').style.display = 'block';
    }

    // Old records may have payment_mode/amount_paid but no JSON breakdown.
    if (Object.keys(savedBreakdown).length === 0) {
        const savedMode = <?= json_encode($edit_data['payment_mode'] ?? '') ?>;
        const savedPaid = parseFloat(<?= json_encode($edit_data['amount_paid'] ?? 0) ?>) || 0;

        if (/UPI/i.test(savedMode)) {
            document.getElementById('check_upi').checked = true;
            document.getElementById('amt_upi').value = savedPaid;
            document.getElementById('div_input_upi').style.display = 'block';
        } else if (/CARD/i.test(savedMode)) {
            document.getElementById('check_card').checked = true;
            document.getElementById('amt_card').value = savedPaid;
            document.getElementById('div_input_card').style.display = 'block';
        } else if (/NET/i.test(savedMode)) {
            document.getElementById('check_net').checked = true;
            document.getElementById('amt_net').value = savedPaid;
            document.getElementById('div_input_net').style.display = 'block';
        } else if (savedPaid > 0) {
            document.getElementById('check_cash').checked = true;
            document.getElementById('amt_cash').value = savedPaid;
            document.getElementById('div_input_cash').style.display = 'block';
        }
    }

    let editFee = parseFloat(document.getElementById('consultation_fee').value) || 0;
    let editDisc = parseFloat(document.getElementById('modal_discount_input').value) || 0;
    let savedPercent = parseFloat(<?= json_encode($edit_data['discount_percent'] ?? 0) ?>) || 0;
    if (savedPercent > 0) {
        document.getElementById('modal_discount_percent').value = savedPercent.toFixed(2);
    } else if (editFee > 0 && editDisc > 0) {
        document.getElementById('modal_discount_percent').value = ((editDisc / editFee) * 100).toFixed(2);
    } else {
        document.getElementById('modal_discount_percent').value = 0;
    }

    filterInsurances();
    syncDiscountHiddenFields();    calculateModalTotals(false);
} catch(e) {
    console.error('Edit payment restore error:', e);
}
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    const opdForm = document.getElementById('opdForm');
    if (opdForm) {
        opdForm.addEventListener('submit', function() {
            if (typeof syncDiscountHiddenFields === 'function') {
                syncDiscountHiddenFields();
            }
        });
    }

    const cat = document.getElementById('category_id');
    if (cat && cat.value) filterInsurances();
});

function syncDiscountHiddenFields() {
    const discount = parseFloat(document.getElementById('modal_discount_input').value) || 0;
    const discountPercent = parseFloat(document.getElementById('modal_discount_percent').value) || 0;
    document.getElementById('modal_discount').value = discount.toFixed(2);
    document.getElementById('hidden_discount_percent').value = discountPercent.toFixed(2);
}

function calculateDiscountFromPercent() {
    let fee = parseFloat(document.getElementById('consultation_fee').value) || 0;
    let pct = parseFloat(document.getElementById('modal_discount_percent').value) || 0;
    if(pct < 0) pct = 0;
    if(pct > 100) pct = 100;
    
    let discAmt = (fee * pct) / 100;
    document.getElementById('modal_discount_input').value = discAmt.toFixed(2);
    syncDiscountHiddenFields();
    handleDiscountChange();
}

function handleDiscountChange() {
    let fee = parseFloat(document.getElementById('consultation_fee').value) || 0;
    let discountInput = document.getElementById('modal_discount_input');
    let discountPercent = document.getElementById('modal_discount_percent');
    
    let discount = parseFloat(discountInput.value) || 0;
    if(discount < 0) { discount = 0; discountInput.value = 0; }
    if(discount > fee) { discount = fee; discountInput.value = fee; }
    
    if (fee > 0 && document.activeElement === discountInput) {
        let pct = (discount / fee) * 100;
        discountPercent.value = pct.toFixed(2);
    }
    
    
    syncDiscountHiddenFields();
let payable = fee - discount;

    let checkedModes = document.querySelectorAll('.pay-check:checked');
    if (checkedModes.length === 1) {
        let modeName = checkedModes[0].id.replace('check_', '');
        document.getElementById('amt_' + modeName).value = payable.toFixed(2);
    } else if (checkedModes.length === 0) {
        document.getElementById('check_cash').checked = true;
        document.getElementById('div_input_cash').style.display = 'block';
        document.getElementById('amt_cash').value = payable.toFixed(2);
    }

    calculateModalTotals(false);
}

function togglePaymentInputs(changedMode) {
    let isChecked = document.getElementById('check_' + changedMode).checked;
    document.getElementById('div_input_' + changedMode).style.display = isChecked ? 'block' : 'none';
    
    if (!isChecked) {
        document.getElementById('amt_' + changedMode).value = 0;
    } else {
        let fee = parseFloat(document.getElementById('consultation_fee').value) || 0;
        let discount = parseFloat(document.getElementById('modal_discount_input').value) || 0;
        let payable = fee - discount;
        if(payable < 0) payable = 0;

        let currentTotalExceptChanged = 0;
        ['cash', 'upi', 'card', 'net'].forEach(m => {
            if(m !== changedMode && document.getElementById('check_' + m).checked) {
                currentTotalExceptChanged += (parseFloat(document.getElementById('amt_' + m).value) || 0);
            }
        });

        let remaining = payable - currentTotalExceptChanged;
        document.getElementById('amt_' + changedMode).value = remaining > 0 ? remaining : 0;
    }
    calculateModalTotals(false);
}

function calculateModalTotals(fromDiscountChange) {
    let fee = parseFloat(document.getElementById('consultation_fee').value) || 0;
    let discountInput = document.getElementById('modal_discount_input');
    
    let discount = parseFloat(discountInput.value) || 0;
    if(discount < 0) { discount = 0; discountInput.value = 0; }
    
    let payable = fee - discount;
    if(payable < 0) payable = 0;
    
    // UPDATE APPLICABLE TARIFF UI
    document.getElementById('display_tariff_fee').innerText = '₹' + payable.toFixed(2);
    document.getElementById('modalPayableAmount').innerText = '₹' + payable.toFixed(2);

    let cash = document.getElementById('check_cash').checked ? (parseFloat(document.getElementById('amt_cash').value) || 0) : 0;
    let upi = document.getElementById('check_upi').checked ? (parseFloat(document.getElementById('amt_upi').value) || 0) : 0;
    let card = document.getElementById('check_card').checked ? (parseFloat(document.getElementById('amt_card').value) || 0) : 0;
    let net = document.getElementById('check_net').checked ? (parseFloat(document.getElementById('amt_net').value) || 0) : 0;

    let totalPaid = cash + upi + card + net;
    let returnChange = totalPaid - payable;

    document.getElementById('modalTotalPaid').innerText = '₹' + totalPaid.toFixed(2);
    document.getElementById('modalReturnChange').innerText = '₹' + (returnChange > 0 ? returnChange : 0).toFixed(2);
}

function applyModalPayment() {
    // Payment modal is outside #opdForm, so copy discount into the hidden form fields.
    syncDiscountHiddenFields();
    calculateModalTotals();
    let fee = parseFloat(document.getElementById('consultation_fee').value) || 0;
    let discount = parseFloat(document.getElementById('modal_discount_input').value) || 0;
    let payable = fee - discount; if(payable < 0) payable = 0;

    let cash = document.getElementById('check_cash').checked ? (parseFloat(document.getElementById('amt_cash').value) || 0) : 0;
    let upi = document.getElementById('check_upi').checked ? (parseFloat(document.getElementById('amt_upi').value) || 0) : 0;
    let card = document.getElementById('check_card').checked ? (parseFloat(document.getElementById('amt_card').value) || 0) : 0;
    let net = document.getElementById('check_net').checked ? (parseFloat(document.getElementById('amt_net').value) || 0) : 0;

    let totalPaid = cash + upi + card + net;
    let returnChange = totalPaid - payable;

    let modes = [];
    let breakdown = {};
    
    if (cash > 0) { modes.push('CASH: ₹'+cash); breakdown.Cash = { amount: cash }; }
    if (upi > 0) { let utr = document.getElementById('utr_upi').value; modes.push('UPI: ₹'+upi); breakdown.UPI = { amount: upi, utr: utr }; }
    if (card > 0) { let utr = document.getElementById('utr_card').value; modes.push('CARD: ₹'+card); breakdown.Card = { amount: card, utr: utr }; }
    if (net > 0) { let utr = document.getElementById('utr_net').value; modes.push('NET: ₹'+net); breakdown.NetBanking = { amount: net, utr: utr }; }

    let summaryStr = modes.length > 0 ? modes.join(' | ') : 'Unpaid (₹0)';

    document.getElementById('paymentSummaryText').innerText = summaryStr;
    document.getElementById('modal_total_paid').value = totalPaid;
    document.getElementById('modal_change_return').value = returnChange > 0 ? returnChange : 0;
    document.getElementById('payment_breakdown_json').value = JSON.stringify(breakdown);
    document.getElementById('summary_payment_mode').value = summaryStr;
}

<?php if ($token_generated): ?>
document.addEventListener("DOMContentLoaded", function() {
    var myModal = new bootstrap.Modal(document.getElementById('successPrintModal'));
    myModal.show();
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.pathname);
    }
});

function showGeneratedReceipt() {
    openReceiptPreview(
        <?= json_encode($token_generated['name']) ?>,
        <?= json_encode($token_generated['uhid']) ?>,
        <?= json_encode($token_generated['age_gender']) ?>,
        <?= json_encode($token_generated['mobile']) ?>,
        <?= json_encode($token_generated['doctor']) ?>,
        <?= json_encode($token_generated['fee']) ?>,
        <?= json_encode($token_generated['discount']) ?>,
        <?= json_encode($token_generated['discount_percent']) ?>,
        <?= json_encode($token_generated['mode']) ?>,
        <?= json_encode($token_generated['token_display']) ?>,
        <?= json_encode($token_generated['receipt_display']) ?>,
        <?= json_encode($token_generated['remark']) ?>,
        <?= json_encode($token_generated['date_time']) ?>,
        <?= json_encode($token_generated['payment_breakdown']) ?>,
        <?= json_encode($token_generated['paid']) ?>,
        <?= json_encode($token_generated['return']) ?>,
        <?= json_encode($token_generated['status']) ?>
    );
}
<?php endif; ?>

let currentReceiptHtml = '';

function openReceiptPreview(name, uhid, ageGender, mobile, doctor, fee, discount, discountPercent, mode, token, receiptNo, remark, datetime, paymentBreakdownRaw, amountPaid, changeReturn, statusCode) {
    const data = {name, uhid, ageGender, mobile, doctor, fee, discount, discountPercent, mode, token, receiptNo, remark, datetime, paymentBreakdownRaw, amountPaid, changeReturn, statusCode};
    currentReceiptHtml = buildReceiptHtml(data);
    document.getElementById('receiptPreviewContent').innerHTML = currentReceiptHtml;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('receiptPreviewModal')).show();
}

function buildReceiptHtml(data) {
    const upper = (v) => String(v ?? '').toUpperCase();
    const safe = (v) => upper(v).replace(/[&<>"']/g, c => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
    }[c]));

    const fee = parseFloat(data.fee) || 0;
    const discount = parseFloat(data.discount) || 0;
    const discountPercent = parseFloat(data.discountPercent) || 0;
    const amountPaid = parseFloat(data.amountPaid) || 0;
    const changeReturn = parseFloat(data.changeReturn) || 0;
    const payable = Math.max(0, fee - discount);
    const balance = Math.max(0, payable - amountPaid);
    const allMobiles = safe(data.mobile).replace(/,\s*/g, ' | ');

    let breakdown = {};
    try {
        if (data.paymentBreakdownRaw) {
            breakdown = JSON.parse(data.paymentBreakdownRaw);
            if (typeof breakdown === 'string') breakdown = JSON.parse(breakdown);
        }
    } catch(e) {}

    const paymentRows = [];
    if (breakdown.Cash) paymentRows.push(`<tr><td>Cash</td><td class="amount">₹${(parseFloat(breakdown.Cash.amount)||0).toFixed(2)}</td></tr>`);
    if (breakdown.UPI) paymentRows.push(`<tr><td>UPI${breakdown.UPI.utr ? ` <small>(Ref: ${safe(breakdown.UPI.utr)})</small>` : ''}</td><td class="amount">₹${(parseFloat(breakdown.UPI.amount)||0).toFixed(2)}</td></tr>`);
    if (breakdown.Card) paymentRows.push(`<tr><td>Card${breakdown.Card.utr ? ` <small>(Ref: ${safe(breakdown.Card.utr)})</small>` : ''}</td><td class="amount">₹${(parseFloat(breakdown.Card.amount)||0).toFixed(2)}</td></tr>`);
    if (breakdown.NetBanking) paymentRows.push(`<tr><td>Net Banking${breakdown.NetBanking.utr ? ` <small>(UTR: ${safe(breakdown.NetBanking.utr)})</small>` : ''}</td><td class="amount">₹${(parseFloat(breakdown.NetBanking.amount)||0).toFixed(2)}</td></tr>`);

    if (!paymentRows.length && amountPaid > 0) {
        paymentRows.push(`<tr><td>${safe(data.mode || 'Cash')}</td><td class="amount">₹${amountPaid.toFixed(2)}</td></tr>`);
    }

    let statusCodeNum = Number(data.statusCode);
    if (![1,2,3].includes(statusCodeNum)) {
        statusCodeNum = payable <= 0 ? 1 : (amountPaid >= payable ? 1 : (amountPaid > 0 ? 2 : 3));
    }
    const statusLabel = statusCodeNum === 1 ? 'Paid' : (statusCodeNum === 2 ? 'Partial' : 'Not Paid');

    const remarkRow = data.remark
        ? `<tr><th>Remarks</th><td colspan="3">${safe(data.remark)}</td></tr>` : '';

    return `
    <div class="receipt-sheet">
        <div class="receipt-header">
            <div class="hospital">${safe(orgName)}</div>
            <div class="center">${safe(centerName)}</div>
            <div class="address">${safe(centerAddress)}</div>
            <div class="receipt-title">OPD REGISTRATION & CONSULTATION RECEIPT</div>
        </div>

        <div class="token-box">
            <div>
                <div class="small-label">TOKEN NO.</div>
                <div class="token">${safe(data.token)}</div>
                <div style="font-size:10px;font-weight:700;margin-top:4px;">RECEIPT NO. ${safe(data.receiptNo)}</div>
            </div>
            <div class="date-block"><b>Registration Date</b><br>${safe(data.datetime)}</div>
        </div>

        <table class="receipt-table info-table">
            <tr><th>UHID / ID</th><td colspan="3">${safe(data.uhid)}</td></tr>
            <tr><th>Patient Name</th><td colspan="3">${safe(data.name)}</td></tr>
            <tr><th>Age / Gender</th><td>${safe(data.ageGender)}</td><th>Mobile</th><td>${allMobiles}</td></tr>
            <tr><th>Consulting Doctor</th><td colspan="3">${safe(data.doctor)}</td></tr>
            ${remarkRow}
        </table>

        <div class="section-title">BILLING DETAILS</div>
        <table class="receipt-table billing-table">
            <tr><td>Consultation Fee</td><td class="amount">₹${fee.toFixed(2)}</td></tr>
            <tr><td>Discount</td><td class="amount discount">- ₹${discount.toFixed(2)} (${discountPercent.toFixed(2)}%)</td></tr>
            <tr class="grand"><td>Final Payable</td><td class="amount">₹${payable.toFixed(2)}</td></tr>
        </table>

        <div class="section-title">PAYMENT DETAILS</div>
        <table class="receipt-table billing-table">
            ${paymentRows.join('') || '<tr><td>Payment Mode</td><td class="amount">Not Paid</td></tr>'}
            <tr><td>Total Amount Paid</td><td class="amount">₹${amountPaid.toFixed(2)}</td></tr>
            <tr><td>Balance Due</td><td class="amount">₹${balance.toFixed(2)}</td></tr>
            <tr><td>Change Returned</td><td class="amount">₹${Math.max(0, changeReturn).toFixed(2)}</td></tr>
            <tr class="grand"><td>Payment Status</td><td class="amount">${statusLabel}</td></tr>
        </table>

        <div class="instructions"><b>Instructions</b><br>${receiptInstructions}</div>
        
    </div>`;
}

function printReceiptFromPreview() {
    if (!currentReceiptHtml) return;
    const printWindow = window.open('', '_blank', 'width=900,height=950');
    if (!printWindow) {
        alert('Please allow pop-ups for printing the receipt.');
        return;
    }
    printWindow.document.open();
    printWindow.document.write(`<!doctype html><html><head><title>OPD Receipt</title>
<style>
@page{size:A5 portrait;margin:8mm}
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;color:#111;background:#fff;font-size:11px}
.receipt-sheet{width:100%;max-width:138mm;margin:0 auto}
.receipt-header{text-align:center;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:9px}
.hospital{font-size:18px;font-weight:800;text-transform:uppercase}
.center{font-size:13px;font-weight:700;margin-top:2px}
.address{font-size:9px;color:#555;margin-top:2px}
.receipt-title{font-size:11px;font-weight:800;margin-top:7px}
.token-box{display:flex;justify-content:space-between;align-items:center;border:1px solid #aaa;background:#f7f7f7;padding:7px;margin-bottom:9px}
.small-label{font-size:8px;font-weight:700;color:#666}
.token{font-size:22px;font-weight:800}
.date-block{text-align:right;font-size:9px;line-height:1.5}
.receipt-table{width:100%;border-collapse:collapse}
.receipt-table th,.receipt-table td{border:1px solid #bbb;padding:5px;vertical-align:top}
.info-table th{width:23%;text-align:left;background:#f1f3f5;font-weight:700}
.billing-table td:first-child{width:65%}
.section-title{font-size:10px;font-weight:800;margin:9px 0 4px;border-bottom:1px solid #222;padding-bottom:2px}
.amount{text-align:right;font-weight:700;white-space:nowrap}
.discount{color:#c62828}
.grand td{font-weight:800;background:#f3f4f6}
.billing-table small{color:#666;font-weight:400}
.instructions{margin-top:12px;padding-top:7px;border-top:1px dashed #999;font-size:9px;line-height:1.45}
</style></head><body>${currentReceiptHtml}</body></html>`);
    printWindow.document.close();
    printWindow.onload = function() {
        setTimeout(() => { printWindow.focus(); printWindow.print(); }, 250);
    };
}

// Keep compatibility with the existing print button signature.
function printExistingParcha(name, uhid, ageGender, mobile, doctor, fee, discount, discountPercent, mode, token, receiptNo, remark, datetime, paymentBreakdownRaw, amountPaid, changeReturn, statusCode) {
    openReceiptPreview(name, uhid, ageGender, mobile, doctor, fee, discount, discountPercent, mode, token, receiptNo, remark, datetime, paymentBreakdownRaw, amountPaid, changeReturn, statusCode);
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>