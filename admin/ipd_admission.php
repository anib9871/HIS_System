<?php
// admin/ipd_admission.php
$page_title = "IPD Admission & Live Bed Dashboard";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php'; 

if (session_status() === PHP_SESSION_NONE) session_start();
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 1);

// ==============================================================
// 0. AJAX: ADD NEW MASTER DATA FROM POP-UP
// ==============================================================
if (isset($_POST['ajax_add_master'])) {
    header('Content-Type: application/json; charset=utf-8');
    $type = trim($_POST['type'] ?? '');
    $name = trim(strtoupper($_POST['name'] ?? ''));
    $parent_id = (int)($_POST['parent_id'] ?? 0);
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Name cannot be empty.']);
        exit;
    }
    
    try {
        $id = 0;
        if ($type === 'department') {
            $stmt = $tenant_pdo->prepare("INSERT INTO master_departments (org_id, dept_name, status) VALUES (?, ?, 1)");
            $stmt->execute([$org_id, $name]);
            $id = $tenant_pdo->lastInsertId();
        } elseif ($type === 'doctor') {
            $stmt = $tenant_pdo->prepare("INSERT INTO master_doctors (org_id, full_name, department_id, status) VALUES (?, ?, ?, 1)");
            $stmt->execute([$org_id, $name, $parent_id]);
            $id = $tenant_pdo->lastInsertId();
        } elseif ($type === 'service') {
            $stmt = $tenant_pdo->prepare("INSERT INTO master_doctor_services (org_id, service_name, status) VALUES (?, ?, 1)");
            $stmt->execute([$org_id, $name]);
            $id = $tenant_pdo->lastInsertId();
        } elseif ($type === 'category') {
            $stmt = $tenant_pdo->prepare("INSERT INTO master_categories (org_id, category_name, status) VALUES (?, ?, 1)");
            $stmt->execute([$org_id, $name]);
            $id = $tenant_pdo->lastInsertId();
        } elseif ($type === 'insurance') {
            $stmt = $tenant_pdo->prepare("INSERT INTO insurance (org_id, insurance_name, category_id, status) VALUES (?, ?, ?, 1)");
            $stmt->execute([$org_id, $name, $parent_id]);
            $id = $tenant_pdo->lastInsertId();
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid master type.']);
            exit;
        }
        
        echo json_encode(['success' => true, 'id' => $id, 'name' => $name, 'parent_id' => $parent_id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// ==============================================================
// 0. AUTO-UPDATE SCHEMA FOR CATEGORY, INSURANCE & SERVICE
// ==============================================================
try {
    $tenant_pdo->exec("ALTER TABLE ipd_admissions ADD COLUMN IF NOT EXISTS service_id INT NULL AFTER doctor_id");
    $tenant_pdo->exec("ALTER TABLE ipd_admissions ADD COLUMN IF NOT EXISTS category_id INT NULL AFTER admission_type");
    $tenant_pdo->exec("ALTER TABLE ipd_admissions ADD COLUMN IF NOT EXISTS insurance_id INT NULL AFTER category_id");
} catch (Exception $e) {}

// ==============================================================
// 0. PRINT ADMISSION RECEIPT LOGIC (Runs if ?action=print)
// ==============================================================
if (isset($_GET['action']) && $_GET['action'] === 'print' && isset($_GET['ipd_id'])) {
    $ipd_id = (int)$_GET['ipd_id'];
    $stmt = $tenant_pdo->prepare("
        SELECT a.*, p.uhid, p.fullname, p.age, p.gender, p.mobile, p.address,
               bd.bed_no, r.room_no, b.building_name,
               d.full_name AS doctor_name, dept.dept_name
        FROM ipd_admissions a
        JOIN patient_master p ON p.patient_id = a.patient_id
        JOIN master_beds bd ON a.bed_id = bd.id
        JOIN master_rooms r ON bd.room_id = r.id
        JOIN master_buildings b ON r.building_id = b.id
        LEFT JOIN master_doctors d ON d.id = a.doctor_id
        LEFT JOIN master_departments dept ON dept.id = a.department_id
        WHERE a.ipd_id = ? AND a.org_id = ? AND a.center_id = ?
    ");
    $stmt->execute([$ipd_id, $org_id, $center_id]);
    $adm = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$adm) die("Admission Record Not Found.");
    $hospital_name = $_SESSION['org_name'] ?? 'HOSPITAL NAME';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>IPD Admission - <?= htmlspecialchars($adm['ipd_no']) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; text-transform: uppercase; background: #fff; color: #000; }
            .print-container { max-width: 800px; margin: 0 auto; padding: 20px; }
            .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
            .header h3 { margin: 0; font-weight: bold; font-size: 20px; }
            .section-title { font-weight: bold; border-bottom: 1px solid #ccc; padding-bottom: 5px; margin: 15px 0 10px; }
            .info-table { width: 100%; margin-bottom: 15px; }
            .info-table td { padding: 4px 8px; vertical-align: top; border-bottom: 1px solid #f8f9fa; }
            .lbl { font-weight: bold; color: #555; width: 140px; }
            @media print { .no-print { display: none !important; } }
        </style>
    </head>
    <body onload="window.print()">
        <div class="print-container border">
            <div class="text-end mb-2 no-print">
                <button onclick="window.print()" class="btn btn-dark btn-sm fw-bold">PRINT FORM</button>
                <button onclick="window.close()" class="btn btn-secondary btn-sm fw-bold">CLOSE</button>
            </div>
            <div class="header">
                <h3><?= htmlspecialchars($hospital_name) ?></h3>
                <div class="mt-1 fw-bold text-secondary">IPD ADMISSION RECORD & ADVANCE RECEIPT</div>
            </div>
            <table class="info-table">
                <tr>
                    <td class="lbl">IPD NO:</td><td class="fw-bold fs-6"><?= htmlspecialchars($adm['ipd_no']) ?></td>
                    <td class="lbl">ADMISSION DATE:</td><td class="fw-bold text-danger"><?= date('d-M-Y h:i A', strtotime($adm['admission_date'])) ?></td>
                </tr>
                <tr>
                    <td class="lbl">PATIENT NAME:</td><td class="fw-bold fs-6"><?= htmlspecialchars($adm['fullname']) ?></td>
                    <td class="lbl">UHID:</td><td class="fw-bold"><?= htmlspecialchars($adm['uhid']) ?></td>
                </tr>
                <tr>
                    <td class="lbl">AGE / GENDER:</td><td><?= htmlspecialchars($adm['age']) ?> YRS / <?= htmlspecialchars($adm['gender']) ?></td>
                    <td class="lbl">MOBILE:</td><td><?= htmlspecialchars($adm['mobile']) ?></td>
                </tr>
            </table>
            <div class="section-title">CLINICAL & WARD DETAILS</div>
            <table class="info-table">
                <tr>
                    <td class="lbl">DEPARTMENT:</td><td><?= htmlspecialchars($adm['dept_name'] ?? 'N/A') ?></td>
                    <td class="lbl">WARD / BED:</td><td class="fw-bold fs-6 text-primary"><?= htmlspecialchars($adm['building_name'] . ' - ' . $adm['room_no'] . ' (BED ' . $adm['bed_no'] . ')') ?></td>
                </tr>
                <tr>
                    <td class="lbl">ADMITTING DOCTOR:</td><td class="fw-bold">DR. <?= htmlspecialchars($adm['doctor_name'] ?? 'N/A') ?></td>
                    <td class="lbl">ADMISSION TYPE:</td><td><?= htmlspecialchars($adm['admission_type']) ?></td>
                </tr>
            </table>
            <div class="section-title">ADVANCE DEPOSIT RECEIPT</div>
            <table class="info-table">
                <tr>
                    <td class="lbl">RECEIPT NO:</td><td><?= htmlspecialchars($adm['receipt_no']) ?></td>
                    <td class="lbl">ADVANCE PAID:</td><td class="fw-bold fs-5">Rs. <?= number_format($adm['advance_paid'], 2) ?></td>
                </tr>
                <tr>
                    <td class="lbl">PAYMENT MODE:</td><td colspan="3"><?= htmlspecialchars($adm['payment_mode']) ?></td>
                </tr>
            </table>
            <div class="row mt-5 pt-5">
                <div class="col-6 text-center"><hr style="width: 70%; margin: auto; border-color: #000;"><div class="mt-2 fw-bold text-muted" style="font-size: 10px;">SIGNATURE OF PATIENT / RELATIVE</div></div>
                <div class="col-6 text-center"><hr style="width: 70%; margin: auto; border-color: #000;"><div class="mt-2 fw-bold text-muted" style="font-size: 10px;">AUTHORIZED SIGNATORY</div></div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit; 
}

// ==============================================================
// 1. SMART FINANCIAL YEAR & CORE FUNCTIONS
// ==============================================================
function getCurrentFY($tenant_pdo, $org_id, $center_id, $visit_date = null) {
    if (!$visit_date) $visit_date = date('Y-m-d');
    $time = strtotime($visit_date);
    $m = (int)date('m', $time); $y = (int)date('y', $time);
    if ($m >= 4) return sprintf("%02d%02d", $y, $y + 1); 
    return sprintf("%02d%02d", $y - 1, $y); 
}
function formatFYWithHyphen($fy_code) { return (strlen($fy_code) == 4) ? substr($fy_code, 0, 2).'-'.substr($fy_code, 2, 2) : $fy_code; }
function calculateAgeFromDob($dob, $as_of_date = null) {
    $dob = trim((string)$dob); if ($dob === '') return null;
    $dobObj = DateTime::createFromFormat('!Y-m-d', $dob);
    if (!$dobObj || $dobObj->format('Y-m-d') !== $dob) return null;
    $asOfObj = DateTime::createFromFormat('!Y-m-d', $as_of_date ?: date('Y-m-d')) ?: new DateTime('today');
    return ($dobObj > $asOfObj) ? null : (int)$dobObj->diff($asOfObj)->y;
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

$msg = ""; $err = "";

// ============================================================
// 2. AJAX: FIND REGISTERED PATIENT BY MOBILE
// ============================================================
if (isset($_GET['lookup_patient_mobile'])) {
    header('Content-Type: application/json; charset=utf-8');
    $lookup_mobile = preg_replace('/\D+/', '', (string)($_GET['lookup_patient_mobile'] ?? ''));
    if (strlen($lookup_mobile) !== 10) { echo json_encode(['success' => false, 'patients' => []]); exit; }
    try {
        $lookup_stmt = $tenant_pdo->prepare("SELECT patient_id, uhid, fullname, mobile, dob, age, gender FROM patient_master WHERE org_id = ? AND center_id = ? AND status = 1 AND (mobile = ? OR FIND_IN_SET(?, REPLACE(mobile, ' ', '')) > 0) ORDER BY patient_id DESC");
        $lookup_stmt->execute([$org_id, $center_id, $lookup_mobile, $lookup_mobile]);
        $patients = $lookup_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($patients as &$p) {
            $calcAge = calculateAgeFromDob($p['dob'] ?? '');
            if ($calcAge !== null) $p['age'] = $calcAge;
        }
        echo json_encode(['success' => true, 'patients' => $patients]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ============================================================
// 3A. SAVE IPD REGISTRATION (NEW ADMISSION)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admit_patient'])) {
    $fullname = ucwords(strtolower(trim($_POST['fullname'] ?? '')));
    $gender   = $_POST['gender'] ?? 'Male';
    $dob      = trim((string)($_POST['dob'] ?? ''));
    $mobile_post = $_POST['mobile'] ?? [];
    $mobile = is_array($mobile_post) ? implode(', ', array_filter(array_map('trim', $mobile_post), fn($v) => $v !== '')) : trim((string)$mobile_post);
    
    $admission_date = !empty($_POST['visit_date']) ? $_POST['visit_date'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
    $age = calculateAgeFromDob($dob, date('Y-m-d', strtotime($admission_date)));
    
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $doctor_id     = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;
    $service_id    = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
    $bed_id        = !empty($_POST['bed_id']) ? (int)$_POST['bed_id'] : null;
    
    $admission_type= 'Planned'; // Hardcoded since removed from UI
    $category_id   = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $insurance_id  = !empty($_POST['insurance_id']) ? (int)$_POST['insurance_id'] : null;
    
    $advance_paid    = (float)($_POST['modal_total_paid'] ?? 0);
    $pay_mode        = trim($_POST['summary_payment_mode'] ?? 'Unpaid (₹0)');
    $pay_breakdown   = trim($_POST['payment_breakdown_json'] ?? '');
    $payment_mode_id = !empty($_POST['primary_payment_mode_id']) ? (int)$_POST['primary_payment_mode_id'] : null;

    $current_fy_code = getCurrentFY($tenant_pdo, $org_id, $center_id, date('Y-m-d', strtotime($admission_date)));
    $existing_patient_id = (int)($_POST['existing_patient_id'] ?? 0);

    if (empty($fullname) || empty($mobile) || $dob === '' || empty($bed_id)) {
        $err = "Please fill all mandatory fields and select a bed.";
    } else {
        try {
            $tenant_pdo->beginTransaction();
            if ($existing_patient_id > 0) {
                $patient_id = $existing_patient_id;
                $tenant_pdo->prepare("UPDATE patient_master SET fullname=?, dob=?, gender=?, age=?, mobile=? WHERE patient_id=? AND org_id=?")
                           ->execute([$fullname, $dob, $gender, $age, $mobile, $patient_id, $org_id]);
            } else {
                $uhid = generateSecureUHID($tenant_pdo, $center_id, $current_fy_code);
                $stmt = $tenant_pdo->prepare("INSERT INTO patient_master (org_id, center_id, uhid, fullname, dob, gender, age, mobile, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$org_id, $center_id, $uhid, $fullname, $dob, $gender, $age, $mobile]);
                $patient_id = (int)$tenant_pdo->lastInsertId();
            }

            $ipd_stmt = $tenant_pdo->prepare("SELECT COUNT(*) FROM ipd_admissions WHERE org_id = ? AND center_id = ? AND admission_date >= ?");
            $ipd_stmt->execute([$org_id, $center_id, date('Y') . '-01-01']); 
            $ipd_serial = ((int)$ipd_stmt->fetchColumn()) + 1;
            
            // Format IPD No. like IPD-26-27/1
            $ipd_no = "IPD-" . formatFYWithHyphen($current_fy_code) . "/" . $ipd_serial;

            $receipt_stmt = $tenant_pdo->query("SELECT COALESCE(MAX(receipt_no), 0) FROM ipd_admissions WHERE org_id=$org_id");
            $next_receipt_no = ((int)$receipt_stmt->fetchColumn()) + 1;

            $adm_stmt = $tenant_pdo->prepare("
                INSERT INTO ipd_admissions (org_id, center_id, patient_id, ipd_no, receipt_no, admission_date, department_id, doctor_id, service_id, bed_id, admission_type, category_id, insurance_id, advance_paid, payment_mode_id, payment_mode, payment_breakdown, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ");
            $adm_stmt->execute([$org_id, $center_id, $patient_id, $ipd_no, $next_receipt_no, $admission_date, $department_id, $doctor_id, $service_id, $bed_id, $admission_type, $category_id, $insurance_id, $advance_paid, $payment_mode_id, $pay_mode, $pay_breakdown, $created_by]);
            
            $tenant_pdo->commit();
            if(function_exists('set_flash_msg')) set_flash_msg("Patient Admitted Successfully! IPD No: " . $ipd_no);
            header("Location: ipd_admission.php"); exit;
        } catch (Exception $e) { $tenant_pdo->rollBack(); $err = "Admission Error: " . $e->getMessage(); }
    }
}

// ============================================================
// 3B. UPDATE IPD REGISTRATION (INLINE EDIT)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_full_admission'])) {
    $edit_ipd_id     = (int)$_POST['edit_ipd_id'];
    $edit_patient_id = (int)$_POST['edit_patient_id'];
    
    $fullname = ucwords(strtolower(trim($_POST['fullname'] ?? '')));
    $gender   = $_POST['gender'] ?? 'Male';
    $dob      = trim((string)($_POST['dob'] ?? ''));
    $mobile_post = $_POST['mobile'] ?? [];
    $mobile = is_array($mobile_post) ? implode(', ', array_filter(array_map('trim', $mobile_post), fn($v) => $v !== '')) : trim((string)$mobile_post);
    
    $admission_date = !empty($_POST['visit_date']) ? $_POST['visit_date'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
    $age = calculateAgeFromDob($dob, date('Y-m-d', strtotime($admission_date)));
    
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $doctor_id     = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;
    $service_id    = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
    $bed_id        = !empty($_POST['bed_id']) ? (int)$_POST['bed_id'] : null;
    $admission_type= 'Planned';
    $category_id   = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $insurance_id  = !empty($_POST['insurance_id']) ? (int)$_POST['insurance_id'] : null;

    try {
        $tenant_pdo->beginTransaction();
        
        $tenant_pdo->prepare("UPDATE patient_master SET fullname=?, dob=?, gender=?, age=?, mobile=? WHERE patient_id=? AND org_id=?")
                   ->execute([$fullname, $dob, $gender, $age, $mobile, $edit_patient_id, $org_id]);
                   
        $tenant_pdo->prepare("UPDATE ipd_admissions SET admission_date=?, department_id=?, doctor_id=?, service_id=?, bed_id=?, admission_type=?, category_id=?, insurance_id=? WHERE ipd_id=? AND org_id=?")
                   ->execute([$admission_date, $department_id, $doctor_id, $service_id, $bed_id, $admission_type, $category_id, $insurance_id, $edit_ipd_id, $org_id]);
                   
        $tenant_pdo->commit();
        if(function_exists('set_flash_msg')) set_flash_msg("Admission Updated Successfully!");
        header("Location: ipd_admission.php"); exit;
    } catch (Exception $e) { $tenant_pdo->rollBack(); $err = "Update Error: " . $e->getMessage(); }
}

// ============================================================
// 4. FETCH EDIT DATA IF ?edit_id IS SET
// ============================================================
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit_adm = null;
$is_edit = false;

if ($edit_id > 0) {
    $stmt = $tenant_pdo->prepare("
        SELECT a.*, p.fullname, p.uhid, p.mobile, p.dob, p.gender, p.age, r.room_no, bd.bed_no
        FROM ipd_admissions a
        JOIN patient_master p ON a.patient_id = p.patient_id
        JOIN master_beds bd ON a.bed_id = bd.id
        JOIN master_rooms r ON bd.room_id = r.id
        WHERE a.ipd_id = ? AND a.org_id = ? AND a.center_id = ?
    ");
    $stmt->execute([$edit_id, $org_id, $center_id]);
    $edit_adm = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($edit_adm) { $is_edit = true; }
}

// ============================================================
// 5. EXACT SCHEMA MATCH FETCHING
// ============================================================
$departments = $tenant_pdo->query("SELECT id, dept_name FROM master_departments WHERE org_id = $org_id AND status = 1 ORDER BY dept_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$doctors = $tenant_pdo->query("SELECT id, full_name, department_id FROM master_doctors WHERE org_id = $org_id AND status = 1 ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$services = [];
try { 
    $services = $tenant_pdo->query("SELECT id, service_name FROM master_doctor_services WHERE org_id = $org_id AND status = 1 ORDER BY service_name ASC")->fetchAll(PDO::FETCH_ASSOC); 
} catch (Exception $e) {}

$categories = [];
try { 
    $categories = $tenant_pdo->query("SELECT id, category_name FROM master_categories WHERE org_id = $org_id AND status = 1 ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC); 
} catch (Exception $e) {}

$insurances = [];
try { 
    $insurances = $tenant_pdo->query("SELECT id, insurance_name, category_id FROM insurance WHERE org_id = $org_id AND status = 1 ORDER BY insurance_name ASC")->fetchAll(PDO::FETCH_ASSOC); 
} catch (Exception $e) {}

$payment_modes = $tenant_pdo->query("SELECT id, mode_name FROM master_payment_modes WHERE status = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$default_mode_id = !empty($payment_modes) ? $payment_modes[0]['id'] : 0;
$default_mode_name = !empty($payment_modes) ? strtoupper($payment_modes[0]['mode_name']) : 'CASH';

// Ensure the currently assigned bed is available in the dropdown if editing
$current_bed_id = $is_edit ? (int)$edit_adm['bed_id'] : 0;
$available_beds = $tenant_pdo->prepare("
    SELECT b.building_name, bd.id, bd.bed_no, r.room_no 
    FROM master_beds bd 
    JOIN master_rooms r ON bd.room_id = r.id 
    JOIN master_buildings b ON r.building_id = b.id 
    WHERE bd.status = 1 AND bd.org_id = ? 
    AND (bd.id NOT IN (SELECT bed_id FROM ipd_admissions WHERE status = 1 AND org_id = ?) OR bd.id = ?)
    ORDER BY b.building_name, r.room_no, bd.bed_no
");
$available_beds->execute([$org_id, $org_id, $current_bed_id]);
$beds = $available_beds->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);

$today_admissions = $tenant_pdo->query("
    SELECT a.*, p.uhid, p.fullname, p.mobile, bd.bed_no, r.room_no, ins.insurance_name 
    FROM ipd_admissions a
    JOIN patient_master p ON a.patient_id = p.patient_id
    JOIN master_beds bd ON a.bed_id = bd.id
    JOIN master_rooms r ON bd.room_id = r.id
    LEFT JOIN insurance ins ON a.insurance_id = ins.id
    WHERE a.org_id = $org_id AND a.status = 1
    ORDER BY a.ipd_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 6. DASHBOARD STATISTICS & BED STATUS QUERY
// ============================================================

try { 
    $tenant_pdo->exec("ALTER TABLE master_rooms ADD COLUMN IF NOT EXISTS category_id INT NULL AFTER building_id");
} catch (Exception $e) { }

$room_categories = [];
try { 
    $room_categories = $tenant_pdo->query("SELECT id, category_name FROM master_room_categories WHERE org_id = $org_id AND status = 1")->fetchAll(PDO::FETCH_ASSOC); 
} catch (Exception $e) { }

$bed_status_query = $tenant_pdo->prepare("
    SELECT 
        b.building_name AS ward_name, 
        r.room_no, 
        bd.id AS bed_id, 
        bd.bed_no, 
        dept.dept_name AS department_name,
        a.ipd_no,
        a.ipd_id,
        p.fullname AS patient_name,
        p.age,
        p.gender,
        a.admission_date,
        d.full_name AS doctor_name,
        rc.category_name AS room_category_name,
        rc.id AS room_category_id
    FROM master_beds bd
    JOIN master_rooms r ON bd.room_id = r.id
    JOIN master_buildings b ON r.building_id = b.id
    LEFT JOIN master_room_categories rc ON r.category_id = rc.id
    LEFT JOIN ipd_admissions a ON bd.id = a.bed_id AND a.status = 1 AND a.org_id = bd.org_id
    LEFT JOIN patient_master p ON a.patient_id = p.patient_id
    LEFT JOIN master_doctors d ON a.doctor_id = d.id
    LEFT JOIN master_departments dept ON a.department_id = dept.id
    WHERE bd.org_id = ? AND bd.status = 1
    ORDER BY b.building_name, rc.category_name, r.room_no, bd.bed_no
");
$bed_status_query->execute([$org_id]);
$all_beds_status = $bed_status_query->fetchAll(PDO::FETCH_ASSOC);

$total_beds = count($all_beds_status);
$occupied_beds = 0;
foreach($all_beds_status as $bed) {
    if(!empty($bed['patient_name'])) $occupied_beds++;
}
$available_beds = $total_beds - $occupied_beds;

require_once __DIR__ . '/layout_header.php';
?>

<style>
/* =========================================================
   GLOBAL UI: DISPLAY TEXT IN UPPERCASE
   ========================================================= */
body, .card, .card-header, .card-body, .form-label, .form-select, .form-control,
.form-check-label, .btn, .badge, .table, .modal, .modal-title, .modal-body,
.modal-footer, .alert, .small, .text-muted, .text-secondary, .text-primary,
.text-danger, .text-success, label, th, td { text-transform: uppercase; }

input[type="number"], input[type="date"], input[type="datetime-local"], input[inputmode="numeric"],
.mob-input, #visit_date_display { text-transform: none; }
.tariff-box { background-color: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 15px; text-align: center; }
.tariff-box-ipd { background-color: #f0fdf4; border: 1px dashed #86efac; } 

.visit-date-picker-wrap { position: relative; }
.native-date-picker { position: absolute; right: 0; top: 0; width: 38px; height: 31px; opacity: 0; cursor: pointer; z-index: 10; pointer-events: auto; }
#patientSelectionModal .modal-content { border: 0; border-radius: 14px; box-shadow: 0 15px 45px rgba(15, 23, 42, .22); }
#patientSelectionModal .modal-header { background: #eff6ff; border-bottom: 1px solid #dbeafe; }
.patient-popup-item { width: 100%; text-align: left; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 10px 12px; margin-bottom: 8px; transition: .15s ease; cursor: pointer; }
.patient-popup-item:hover { border-color: #60a5fa; background: #f8fbff; transform: translateY(-1px); }
.patient-popup-item .name { font-weight: 900; font-size: 12px; color: #0f172a; }
.patient-popup-item .meta { font-size: 10px; color: #64748b; margin-top: 3px; }
.patient-popup-item .uhid { font-size: 10px; color: #2563eb; font-weight: 800; margin-top: 4px; }

/* Dashboard Cards UI */
.nav-pills .nav-link { font-weight: 600; color: #475569; border-radius: 8px; }
.nav-pills .nav-link.active { background-color: #0284c7; color: #fff; }
.bed-stats-box { border-radius: 10px; padding: 15px; color: #fff; display: flex; align-items: center; gap: 15px; }
.status-blink { animation: statusBlink 1.5s infinite; font-weight: 800 !important; }
@keyframes statusBlink { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
</style>

<?php if (!empty($err)): ?>
    <div id="opdRegistrationErrorMessage" class="d-none"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<!-- TOP TABS NAVIGATION & FILTERS -->
<div class="bg-white p-2 rounded-3 shadow-sm border mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <!-- Tabs (Left) -->
    <ul class="nav nav-pills" id="pills-tab" role="tablist" style="margin: 0;">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= !$is_edit ? 'active' : '' ?> px-4" id="nav-dashboard-tab" data-bs-toggle="pill" data-bs-target="#nav-dashboard" type="button" role="tab"><i class="bi bi-grid-fill me-2"></i>Live Bed Dashboard</button>
        </li>
        <li class="nav-item d-none" role="presentation">
            <button class="nav-link <?= $is_edit ? 'active' : '' ?> px-4" id="nav-admission-tab" data-bs-toggle="pill" data-bs-target="#nav-admission" type="button" role="tab"><i class="bi bi-hospital me-2"></i>Admission Desk & List</button>
        </li>
    </ul>

    <!-- Filters (Right) -->
    <div class="d-flex gap-2 align-items-center <?= $is_edit ? 'd-none' : '' ?>">
        <select id="filterStatus" class="form-select form-select-sm w-auto border-secondary fw-bold" onchange="filterBedTable()">
            <option value="">All Status</option>
            <option value="occupied">Occupied</option>
            <option value="available">Available</option>
        </select>
        <select id="filterRoomCategory" class="form-select form-select-sm w-auto fw-bold" onchange="filterBedTable()">
            <option value="">All Categories</option>
            <?php foreach($room_categories as $rcat): ?>
                <option value="<?= $rcat['id'] ?>"><?= htmlspecialchars($rcat['category_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="searchBed" class="form-control form-control-sm w-auto" style="min-width: 220px;" placeholder="Search Bed, Room, Patient..." onkeyup="filterBedTable()">
    </div>
</div>

<div class="tab-content" id="pills-tabContent">

    <!-- ========================================== -->
    <!-- TAB 1: LIVE BED DASHBOARD (LIST VIEW) -->
    <!-- ========================================== -->
    <div class="tab-pane fade <?= !$is_edit ? 'show active' : '' ?>" id="nav-dashboard" role="tabpanel">
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="bed-stats-box bg-dark shadow-sm">
                    <i class="bi bi-building fs-1 opacity-75"></i>
                    <div>
                        <div class="fs-6 text-uppercase fw-semibold opacity-75">Total Bed Capacity</div>
                        <div class="fs-3 fw-bold"><?= $total_beds ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bed-stats-box bg-success shadow-sm">
                    <i class="bi bi-check-circle fs-1 opacity-75"></i>
                    <div>
                        <div class="fs-6 text-uppercase fw-semibold opacity-75">Available / Empty</div>
                        <div class="fs-3 fw-bold"><?= $available_beds ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bed-stats-box bg-danger shadow-sm">
                    <i class="bi bi-person-bed fs-1 opacity-75"></i>
                    <div>
                        <div class="fs-6 text-uppercase fw-semibold opacity-75">Occupied / Admitted</div>
                        <div class="fs-3 fw-bold"><?= $occupied_beds ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <h6 class="mb-0 fw-bold text-dark small text-nowrap"><i class="bi bi-hospital-fill text-primary me-2"></i>Live Ward & Bed Status</h6>
            </div>
            <div class="table-responsive" style="max-height: 500px;">
                <table class="table table-hover align-middle mb-0 small" id="bedTable">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-3">Room No.</th>
                            <th>Bed No.</th>
                            <th>Department</th>
                            <th>Patient Details</th>
                            <th>Admitting Doctor</th>
                            <th>Admission Date</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($all_beds_status)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No beds found. Add beds in master settings.</td></tr>
                        <?php else: ?>
                            <?php 
                            $current_group = "";
                            foreach($all_beds_status as $room): 
                                // Grouping Logic by Category & Ward
                                $group_name = strtoupper($room['ward_name'] . ' - ' . ($room['room_category_name'] ?? 'GENERAL WARD'));
                                if ($current_group !== $group_name):
                                    $current_group = $group_name;
                            ?>
                                <tr class="table-secondary group-header border-top border-dark" data-category-id="<?= $room['room_category_id'] ?? '' ?>">
                                    <td colspan="7" class="fw-bold text-dark py-2 ps-3">
                                        <i class="bi bi-diagram-3-fill text-primary me-2"></i><?= htmlspecialchars($group_name) ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            
                                <tr class="bed-row <?= !empty($room['patient_name']) ? 'table-danger bg-opacity-10' : '' ?>" data-category-id="<?= $room['room_category_id'] ?? '' ?>" data-status="<?= !empty($room['patient_name']) ? 'occupied' : 'available' ?>">
                                    <td class="ps-3 fw-semibold text-secondary"><?= htmlspecialchars($room['room_no']) ?></td>
                                    <td class="fw-bold text-primary font-monospace"><?= htmlspecialchars($room['bed_no']) ?></td>
                                    <td class="fw-bold text-secondary"><?= htmlspecialchars($room['department_name'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php if(!empty($room['patient_name'])): ?>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($room['patient_name']) ?></div>
                                            <div class="text-muted" style="font-size: 10px;">
                                                <span class="text-danger fw-bold">#<?= htmlspecialchars($room['ipd_no']) ?></span> | 
                                                <?= !empty($room['age']) ? $room['age'].' Yrs' : '' ?> <?= !empty($room['gender']) ? '• '.$room['gender'] : '' ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">Empty</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold text-secondary"><?= !empty($room['doctor_name']) ? 'DR. '.htmlspecialchars($room['doctor_name']) : '-' ?></td>
                                    <td class="text-muted font-monospace"><?= !empty($room['admission_date']) ? date('d-M-Y h:i A', strtotime($room['admission_date'])) : '-' ?></td>
                                    <td class="text-end pe-3">
                                        <?php if(empty($room['patient_name'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 fw-bold d-inline-flex align-items-center" style="font-size: 10px;" onclick="admitToBed(<?= $room['bed_id'] ?>)">
                                                <i class="bi bi-plus-circle me-1"></i> ADMIT
                                            </button>
                                        <?php else: ?>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-primary dropdown-toggle py-0 px-2 fw-bold" style="font-size: 10px;" type="button" data-bs-toggle="dropdown">
                                                    MANAGE PATIENT
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-1" style="font-size: 11px;">
                                                    <li><a class="dropdown-item fw-bold py-2 text-dark" href="ipd_nurse_station.php?ipd_id=<?= $room['ipd_id'] ?>"><i class="bi bi-heart-pulse text-danger me-2"></i>Nurse Station</a></li>
                                                    <li><a class="dropdown-item fw-bold py-2 text-dark" href="ipd_billing.php?ipd_id=<?= $room['ipd_id'] ?>"><i class="bi bi-receipt text-primary me-2"></i>Billing & Discharge</a></li>
                                                    <li><a class="dropdown-item fw-bold py-2 text-dark" href="ipd_discharge_summary.php?ipd_id=<?= $room['ipd_id'] ?>"><i class="bi bi-journal-text text-success me-2"></i>Discharge Summary</a></li>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- TAB 2: ADMISSION DESK & LIST -->
    <!-- ========================================== -->
    <div class="tab-pane fade <?= $is_edit ? 'show active' : '' ?>" id="nav-admission" role="tabpanel">
        <div class="row g-3">
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-3 <?= $is_edit ? 'border-top border-warning border-3' : '' ?>">
                    <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                        <?php if($is_edit): ?>
                            <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-pencil-square text-warning me-2 fs-6"></i>EDIT ADMISSION - <span class="text-primary"><?= htmlspecialchars($edit_adm['ipd_no']) ?></span></h6>
                            <a href="ipd_admission.php" class="btn btn-sm btn-outline-secondary fw-bold px-2 py-0" style="font-size: 10px;">CANCEL EDIT</a>
                        <?php else: ?>
                            <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-hospital text-primary me-2"></i>New IPD Admission</h6>
                            <button type="button" class="btn btn-sm btn-outline-dark fw-bold px-2 py-0" style="font-size: 10px;" onclick="document.getElementById('nav-dashboard-tab').click()">
                                <i class="bi bi-arrow-left me-1"></i> BACK TO BEDS
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-3">
                        <form method="POST" id="opdForm">
                            <?php if($is_edit): ?>
                                <input type="hidden" name="update_full_admission" value="1">
                                <input type="hidden" name="edit_ipd_id" value="<?= $edit_adm['ipd_id'] ?>">
                                <input type="hidden" name="edit_patient_id" value="<?= $edit_adm['patient_id'] ?>">
                            <?php else: ?>
                                <input type="hidden" name="admit_patient" value="1">
                                <input type="hidden" name="existing_patient_id" id="existing_patient_id" value="0">
                                <input type="hidden" name="modal_total_paid" id="modal_total_paid" value="0">
                                <input type="hidden" name="payment_breakdown_json" id="payment_breakdown_json" value="">
                                <input type="hidden" name="summary_payment_mode" id="summary_payment_mode" value="<?= htmlspecialchars($default_mode_name) ?>: ₹0">
                                <input type="hidden" name="primary_payment_mode_id" id="primary_payment_mode_id" value="<?= $default_mode_id ?>">
                            <?php endif; ?>

                            <!-- Patient Info Row -->
                            <div class="d-flex flex-wrap gap-2 mb-3 pb-3 border-bottom">
                                <div style="flex: 1 1 13%; min-width: 120px;">
                                    <label class="form-label small fw-semibold text-secondary mb-1">Admission Date *</label>
                                    <div class="visit-date-picker-wrap">
                                        <?php $def_date = $is_edit ? date('Y-m-d', strtotime($edit_adm['admission_date'])) : date('Y-m-d'); ?>
                                        <input type="hidden" name="visit_date" id="visit_date" value="<?= $def_date ?>">
                                        <input type="date" id="visit_date_display" class="form-control form-control-sm text-dark fw-bold" value="<?= $def_date ?>" required>
                                    </div>
                                </div>
                                <div style="flex: 1 1 17%; min-width: 150px;">
                                    <label class="form-label small fw-semibold mb-1">Mobile Number(s) *</label>
                                    <div id="mobile_wrapper" style="width:100%;">
                                        <?php 
                                            $mobs = $is_edit && !empty($edit_adm['mobile']) ? explode(',', $edit_adm['mobile']) : [''];
                                            foreach($mobs as $idx => $m):
                                                $m = trim($m);
                                        ?>
                                        <div class="input-group input-group-sm mb-1 mob-row">
                                            <input type="text" name="mobile[]" <?= $idx===0?'id="primary_mobile"':'' ?> class="form-control form-control-sm mob-input" value="<?= htmlspecialchars($m) ?>" placeholder="Mobile No" maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, ''); updateMobCount(this, <?= $idx ?>); <?= $idx===0?'lookupPatientByMobile(this.value)':'' ?>" required>
                                            <span class="input-group-text <?= strlen($m)==10?'text-success fw-bold':'text-muted' ?>" id="mob_count_<?= $idx ?>" style="font-size:0.7rem; min-width:45px; text-align:center;"><?= strlen($m) ?>/10</span>
                                            <?php if($idx===0): ?>
                                                <button type="button" class="btn btn-outline-primary" onclick="addMobileField()"><i class="bi bi-plus-lg"></i></button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-danger" onclick="this.closest('.mob-row').remove()"><i class="bi bi-trash"></i></button>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div style="flex: 1 1 17%; min-width: 150px;">
                                    <label class="form-label small fw-semibold mb-1">Patient Full Name *</label>
                                    <input type="text" name="fullname" class="form-control form-control-sm text-capitalize" value="<?= $is_edit ? htmlspecialchars($edit_adm['fullname']) : '' ?>" placeholder="Patient Name" required>
                                </div>
                                <div style="flex: 1 1 14%; min-width: 130px;">
                                    <label class="form-label small fw-semibold text-secondary mb-1">UHID</label>
                                    <input type="text" id="patient_uhid" class="form-control form-control-sm bg-light font-monospace <?= $is_edit ? 'text-success fw-bold' : 'text-muted' ?>" value="<?= $is_edit ? htmlspecialchars($edit_adm['uhid']) : '' ?>" placeholder="AUTO GENERATED" readonly>
                                </div>
                                <div style="flex: 1 1 10%; min-width: 90px;">
                                    <label class="form-label small fw-semibold mb-1">Gender *</label>
                                    <select name="gender" id="gender" class="form-select form-select-sm px-2" required>
                                        <option value="" disabled <?= !$is_edit ? 'selected' : '' ?>>Select</option>
                                        <option value="Male" <?= ($is_edit && $edit_adm['gender']=='Male') ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= ($is_edit && $edit_adm['gender']=='Female') ? 'selected' : '' ?>>Female</option>
                                        <option value="Other" <?= ($is_edit && $edit_adm['gender']=='Other') ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                                <div style="flex: 1 1 23%; min-width: 200px;">
                                    <label class="form-label small fw-semibold mb-1">DOB / Age *</label>
                                    <div class="d-flex align-items-center gap-1">
                                        <div class="visit-date-picker-wrap flex-grow-1" style="min-width: 0;">
                                            <div class="input-group input-group-sm w-100">
                                                <input type="hidden" name="dob" id="dob" value="<?= $is_edit ? htmlspecialchars($edit_adm['dob']) : '' ?>">
                                                <input type="text" id="dob_display" class="form-control text-dark fw-bold px-1 text-center" value="<?= $is_edit && !empty($edit_adm['dob']) ? date('d-m-Y', strtotime($edit_adm['dob'])) : '' ?>" placeholder="DD-MM-YYYY" maxlength="10" autocomplete="off" inputmode="text">
                                                <button type="button" class="btn btn-outline-primary px-2" id="dob_calendar_btn" title="Select Date of Birth" onclick="openDobPicker()"><i class="bi bi-calendar3"></i></button>
                                            </div>
                                            <input type="date" id="dob_picker" class="native-date-picker" value="<?= $is_edit ? htmlspecialchars($edit_adm['dob']) : '' ?>" max="<?= date('Y-m-d') ?>">
                                        </div>
                                        <div class="input-group input-group-sm" style="width: 85px; flex-shrink: 0;">
                                            <input type="number" id="age" name="age" class="form-control form-control-sm px-1 text-center" value="<?= $is_edit ? (int)$edit_adm['age'] : '' ?>" placeholder="Age" min="0" max="150" step="1">
                                            <span class="input-group-text px-1" style="font-size: 10px;">YRS</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Clinic Info & Advance Row -->
                            <div class="row g-2 mb-2">
                                <div class="col-md-8">
                                    <!-- Row 1 of inputs -->
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <label class="form-label small fw-semibold text-primary mb-0">Department</label>
                                                <button type="button" class="btn btn-sm py-0 px-1 btn-outline-primary" style="font-size: 10px; line-height: 1;" onclick="openMasterPopup('department', 'Add New Department')" title="Quick Add"><i class="bi bi-plus-lg"></i></button>
                                            </div>
                                            <select name="department_id" id="department_id" class="form-select form-select-sm" onchange="filterDoctors()">
                                                <option value="">-- Select --</option>
                                                <?php foreach ($departments as $dept): ?>
                                                    <option value="<?= $dept['id'] ?>" <?= ($is_edit && $edit_adm['department_id']==$dept['id']) ? 'selected' : '' ?>><?= htmlspecialchars($dept['dept_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <label class="form-label small fw-semibold text-primary mb-0">Admitting Doctor *</label>
                                                <button type="button" class="btn btn-sm py-0 px-1 btn-outline-primary" style="font-size: 10px; line-height: 1;" onclick="openMasterPopup('doctor', 'Add New Doctor')" title="Quick Add"><i class="bi bi-plus-lg"></i></button>
                                            </div>
                                            <select name="doctor_id" id="doctor_id" class="form-select form-select-sm" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($doctors as $doc): ?>
                                                    <option value="<?= $doc['id'] ?>" data-dept="<?= $doc['department_id'] ?>" <?= ($is_edit && $edit_adm['doctor_id']==$doc['id']) ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <label class="form-label small fw-semibold text-primary mb-0">Service *</label>
                                                <button type="button" class="btn btn-sm py-0 px-1 btn-outline-primary" style="font-size: 10px; line-height: 1;" onclick="openMasterPopup('service', 'Add New Service')" title="Quick Add"><i class="bi bi-plus-lg"></i></button>
                                            </div>
                                            <select name="service_id" id="service_id" class="form-select form-select-sm" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($services as $srv): 
                                                    $s_name = trim(strtoupper($srv['service_name']));
                                                    if(empty($s_name) || $s_name === '-- SELECT --' || $s_name === 'SELECT') continue;
                                                ?>
                                                    <option value="<?= $srv['id'] ?>" <?= ($is_edit && ($edit_adm['service_id'] ?? 0)==$srv['id']) ? 'selected' : '' ?>><?= htmlspecialchars($srv['service_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <!-- Row 2 of inputs -->
                                    <div class="row g-2 mt-3">
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <label class="form-label small fw-semibold text-primary mb-0">Category *</label>
                                                <button type="button" class="btn btn-sm py-0 px-1 btn-outline-primary" style="font-size: 10px; line-height: 1;" onclick="openMasterPopup('category', 'Add New Category')" title="Quick Add"><i class="bi bi-plus-lg"></i></button>
                                            </div>
                                            <select name="category_id" id="category_id" class="form-select form-select-sm" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?= $cat['id'] ?>" <?= ($is_edit && $edit_adm['category_id']==$cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <label class="form-label small fw-semibold text-primary mb-0">Insurance / Provider</label>
                                                <button type="button" class="btn btn-sm py-0 px-1 btn-outline-primary" style="font-size: 10px; line-height: 1;" onclick="openMasterPopup('insurance', 'Add New Insurance')" title="Quick Add"><i class="bi bi-plus-lg"></i></button>
                                            </div>
                                            <select name="insurance_id" id="insurance_id" class="form-select form-select-sm">
                                                <option value="">-- BASE (NO INSURANCE) --</option>
                                                <?php foreach ($insurances as $ins): ?>
                                                    <option value="<?= $ins['id'] ?>" data-cat="<?= $ins['category_id'] ?>" <?= ($is_edit && $edit_adm['insurance_id']==$ins['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ins['insurance_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold text-success mb-1">Assign Bed / Room *</label>
                                            <select name="bed_id" class="form-select form-select-sm border-success fw-bold" required>
                                                <option value="">-- Select Bed --</option>
                                                <?php foreach ($beds as $building_name => $bed_list): ?>
                                                    <optgroup label="<?= htmlspecialchars($building_name) ?>">
                                                        <?php foreach ($bed_list as $b): ?>
                                                            <option value="<?= $b['id'] ?>" <?= ($is_edit && $edit_adm['bed_id']==$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['room_no'] . ' - ' . $b['bed_no']) ?></option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <?php if($is_edit): ?>
                                        <div class="tariff-box tariff-box-ipd h-100 d-flex flex-column align-items-center justify-content-center bg-light border-warning border p-2">
                                            <span class="d-block text-secondary small fw-bold mb-1">Advance Deposit Paid</span>
                                            <h3 class="text-success fw-bold m-0">₹<?= number_format($edit_adm['advance_paid'], 2) ?></h3>
                                            <span class="badge bg-white text-dark border mt-1 mb-auto"><?= htmlspecialchars($edit_adm['payment_mode']) ?></span>
                                            <button type="submit" class="btn btn-warning btn-sm w-100 fw-bold shadow-sm py-2 mt-2">
                                                <i class="bi bi-save me-1"></i> UPDATE ADMISSION
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="tariff-box tariff-box-ipd h-100 d-flex flex-column align-items-center justify-content-center p-2">
                                            <span class="d-block text-secondary small fw-bold mb-1">Initial Advance Deposit</span>
                                            <div class="input-group mb-2 px-3">
                                                <span class="input-group-text fw-bold">₹</span>
                                                <input type="number" id="input_advance" class="form-control text-center fw-bold fs-5" placeholder="0.00" value="0.00" onkeyup="updateSummaryMode()">
                                            </div>
                                            <button type="button" class="btn btn-outline-success btn-sm w-100 fw-semibold mb-2" data-bs-toggle="modal" data-bs-target="#paymentModal" style="font-size: 11px;">
                                                <i class="bi bi-wallet2 me-1"></i> <span id="paymentSummaryText"><?= htmlspecialchars($default_mode_name) ?>: ₹0.00</span>
                                            </button>
                                            <button type="submit" class="btn btn-success btn-sm w-100 fw-bold shadow-sm py-2">
                                                <i class="bi bi-check-circle-fill me-1"></i> Admit Patient
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- CURRENT ADMITTED PATIENTS TABLE -->
            <div class="col-12 mt-2">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="py-2 px-3 border-bottom bg-white rounded-top-3 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold text-dark m-0 small"><i class="bi bi-list-check text-primary me-2"></i>Currently Admitted Patients</h6>
                        <input type="text" id="searchAdmitted" class="form-control form-control-sm w-25" placeholder="Search Name, UHID, IPD No...">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small" id="admittedPatientsTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">IPD No.</th>
                                    <th>Date / Time</th>
                                    <th>Patient Details</th>
                                    <th>Bed Allocated</th>
                                    <th>Advance Paid</th>
                                    <th>Status</th>
                                    <th class="text-end pe-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($today_admissions)): ?>
                                    <tr><td colspan="7" class="text-center py-4 text-muted">No admitted patients.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($today_admissions as $adm): ?>
                                    <tr class="<?= ($edit_id == $adm['ipd_id']) ? 'table-warning' : '' ?>">
                                        <td class="ps-3 fw-bold text-primary font-monospace"><?= htmlspecialchars($adm['ipd_no']) ?></td>
                                        <td><?= date('d-m-Y h:i A', strtotime($adm['admission_date'])) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($adm['fullname']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($adm['uhid']) ?> | <?= htmlspecialchars($adm['mobile']) ?></small><br>
                                            <!-- Insurance Display -->
                                            <span class="badge bg-info-subtle text-info border border-info px-1 mt-1" style="font-size: 9px;">
                                                <i class="bi bi-shield-check me-1"></i><?= !empty($adm['insurance_name']) ? htmlspecialchars($adm['insurance_name']) : 'CASH / BASE' ?>
                                            </span>
                                        </td>
                                        <td><span class="badge bg-primary-subtle text-primary border border-primary px-2"><?= htmlspecialchars($adm['room_no'] . ' - ' . $adm['bed_no']) ?></span></td>
                                        <td>
                                            <div class="fw-bold text-success">₹<?= number_format($adm['advance_paid'], 2) ?></div>
                                            <small class="text-secondary"><?= htmlspecialchars($adm['payment_mode']) ?></small>
                                        </td>
                                        <td>
                                            <?php if((int)$adm['status'] === 1): ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning px-2">Admitted</span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success px-2">Discharged</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3 align-middle">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-dark dropdown-toggle py-1 px-2 fw-bold" style="font-size: 10px;" type="button" data-bs-toggle="dropdown">
                                                    OPTIONS
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-1" style="font-size: 11px;">
                                                    <li><a class="dropdown-item fw-bold py-2 text-dark" href="ipd_nurse_station.php?ipd_id=<?= $adm['ipd_id'] ?>"><i class="bi bi-heart-pulse text-danger me-2"></i>Nurse Station</a></li>
                                                    <li><a class="dropdown-item fw-bold py-2 text-dark" href="ipd_billing.php?ipd_id=<?= $adm['ipd_id'] ?>"><i class="bi bi-receipt text-primary me-2"></i>Billing & Discharge</a></li>
                                                    <li><a class="dropdown-item fw-bold py-2 text-dark" href="ipd_discharge_summary.php?ipd_id=<?= $adm['ipd_id'] ?>"><i class="bi bi-journal-text text-success me-2"></i>Discharge Summary</a></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item fw-bold py-2 text-warning bg-warning-subtle" href="?edit_id=<?= $adm['ipd_id'] ?>"><i class="bi bi-pencil me-2"></i>Edit Admission</a></li>
                                                    <li><a class="dropdown-item fw-bold py-2 text-secondary bg-light" href="?action=print&ipd_id=<?= $adm['ipd_id'] ?>" target="_blank"><i class="bi bi-printer me-2"></i>Print Receipt</a></li>
                                                </ul>
                                            </div>
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
</div>

<!-- ============================================================== -->
<!-- MODALS (PAYMENT, SEARCH & MASTER QUICK ADD) -->
<!-- ============================================================== -->

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow rounded-3">
            <div class="modal-header bg-light py-2 px-3 border-bottom">
                <h6 class="modal-title fw-bold m-0"><i class="bi bi-wallet2 text-primary me-2"></i>Advance Payment</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <?php foreach($payment_modes as $pm): ?>
                    <?php 
                        $pm_id = $pm['id'];
                        $pm_name = htmlspecialchars(strtoupper($pm['mode_name']));
                        $is_cash = (strtolower($pm['mode_name']) === 'cash'); 
                    ?>
                    <div class="mb-2 pb-2 border-bottom">
                        <div class="form-check mb-1">
                            <input class="form-check-input pay-check" type="checkbox" id="check_mode_<?= $pm_id ?>" value="<?= $pm_id ?>" data-name="<?= $pm_name ?>" <?= $pm_id == $default_mode_id ? 'checked' : '' ?> onchange="togglePaymentInputs(<?= $pm_id ?>)">
                            <label class="form-check-label fw-bold small" for="check_mode_<?= $pm_id ?>"><?= $pm_name ?></label>
                        </div>
                        <div id="div_input_mode_<?= $pm_id ?>" class="ps-4" style="display: <?= $pm_id == $default_mode_id ? 'block' : 'none' ?>;">
                            <div class="input-group input-group-sm mb-1">
                                <span class="input-group-text bg-white fw-bold">₹</span>
                                <input type="number" id="amt_mode_<?= $pm_id ?>" class="form-control pay-amt fw-bold text-dark" placeholder="0" onkeyup="calculateModalTotals()">
                            </div>
                            <?php if(!$is_cash): ?>
                                <input type="text" id="ref_mode_<?= $pm_id ?>" class="form-control form-control-sm font-monospace mt-1" style="font-size: 0.75rem;" placeholder="Ref / UTR No.">
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="bg-light p-2 rounded-2 small border mb-3 mt-2 d-flex justify-content-between">
                    <span class="text-secondary fw-semibold">Total:</span><strong class="text-success" id="modalTotalPaid">₹0.00</strong>
                </div>
                <button type="button" class="btn btn-primary btn-sm w-100 fw-semibold" data-bs-dismiss="modal" onclick="applyModalPayment()">Confirm Deposit</button>
            </div>
        </div>
    </div>
</div>

<!-- Patient Selection Modal -->
<div class="modal fade" id="patientSelectionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content overflow-hidden">
            <div class="modal-header py-3">
                <div><div class="fw-bold text-primary">REGISTERED PATIENTS FOUND</div><div class="small text-muted">MULTIPLE FAMILY MEMBERS.</div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3"><div id="patientSelectionList"></div></div>
        </div>
    </div>
</div>

<!-- Quick Add Master Modal -->
<div class="modal fade" id="quickMasterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-3">
            <div class="modal-header bg-light py-2 px-3 border-bottom">
                <h6 class="modal-title fw-bold text-primary m-0" id="quickMasterTitle">Add New Master</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <form id="quickMasterForm" onsubmit="submitQuickMaster(event)">
                    <input type="hidden" id="quickMasterType" name="type">
                    <input type="hidden" id="quickMasterParentId" name="parent_id">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Name *</label>
                        <input type="text" id="quickMasterName" name="name" class="form-control form-control-sm text-uppercase" required placeholder="Enter Name">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold" id="quickMasterSaveBtn">SAVE</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// =========================================================
// QUICK ADD MASTER FUNCTIONALITY
// =========================================================
function openMasterPopup(type, title) {
    let parentId = 0;
    
    // Dependency Checks
    if (type === 'doctor') {
        let deptId = document.getElementById('department_id').value;
        if (!deptId) {
            Swal.fire({icon: 'warning', title: 'Action Required', text: 'Please select a Department first before adding a Doctor.'});
            return;
        }
        parentId = deptId;
    } else if (type === 'insurance') {
        let catId = document.getElementById('category_id').value;
        if (!catId) {
            Swal.fire({icon: 'warning', title: 'Action Required', text: 'Please select a Category first before adding Insurance.'});
            return;
        }
        parentId = catId;
    }

    document.getElementById('quickMasterType').value = type;
    document.getElementById('quickMasterParentId').value = parentId;
    document.getElementById('quickMasterTitle').innerText = title;
    document.getElementById('quickMasterName').value = '';
    
    let m = new bootstrap.Modal(document.getElementById('quickMasterModal'));
    m.show();
    
    document.getElementById('quickMasterModal').addEventListener('shown.bs.modal', function () {
        document.getElementById('quickMasterName').focus();
    }, {once: true});
}

function submitQuickMaster(e) {
    e.preventDefault();
    let btn = document.getElementById('quickMasterSaveBtn');
    let form = document.getElementById('quickMasterForm');
    let type = document.getElementById('quickMasterType').value;
    let formData = new FormData(form);
    formData.append('ajax_add_master', '1');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Saving...';

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = 'SAVE';
        
        if (data.success) {
            let selectEl = document.getElementById(type + '_id');
            if (selectEl) {
                let opt = document.createElement('option');
                opt.value = data.id;
                opt.text = data.name.toUpperCase();
                opt.selected = true;
                
                if (type === 'doctor') opt.setAttribute('data-dept', data.parent_id);
                if (type === 'insurance') opt.setAttribute('data-cat', data.parent_id);
                
                selectEl.appendChild(opt);
                
                // Trigger change to update UI dependencies
                let evt = new Event('change');
                selectEl.dispatchEvent(evt);
            }
            bootstrap.Modal.getInstance(document.getElementById('quickMasterModal')).hide();
            
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: data.name + ' Added Successfully!',
                showConfirmButton: false,
                timer: 2000
            });
        } else {
            Swal.fire({icon: 'error', title: 'Error', text: data.message});
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = 'SAVE';
        Swal.fire({icon: 'error', title: 'Error', text: 'Network request failed. Please try again.'});
    });
}


// =========================================================
// Category to Insurance Automation Logic
// =========================================================
document.getElementById('category_id')?.addEventListener('change', function() {
    let catId = this.value;
    let catText = this.options[this.selectedIndex]?.text.toUpperCase().trim() || '';
    let insSelect = document.getElementById('insurance_id');
    
    if(!insSelect) return;
    
    let visibleCount = 0;
    let autoSelectVal = "";
    
    for(let i = 0; i < insSelect.options.length; i++) {
        let opt = insSelect.options[i];
        
        if(opt.value === "") {
            opt.style.display = '';
            continue;
        }
        
        let optCat = opt.getAttribute('data-cat');
        if(catId === "" || optCat == catId) {
            opt.style.display = '';
            visibleCount++;
            autoSelectVal = opt.value;
        } else {
            opt.style.display = 'none';
        }
    }
    
    if (catText === 'GENERAL' || catText === 'NORMAL') {
        insSelect.value = ""; 
    } else if (visibleCount === 1) {
        insSelect.value = autoSelectVal; 
    } else {
        insSelect.value = ""; 
    }
});

// Filtering Doctors by Department
function filterDoctors() {
    let deptId = document.getElementById('department_id').value; 
    let docSelect = document.getElementById('doctor_id');
    for (let i = 0; i < docSelect.options.length; i++) {
        let opt = docSelect.options[i]; if (opt.value === "") continue; 
        opt.style.display = (!deptId || opt.getAttribute('data-dept') === deptId) ? '' : 'none';
    } 
    docSelect.value = "";
}

// Function for Custom Category, Status & Text Search in Bed Table
function filterBedTable() {
    let textVal = (document.getElementById('searchBed')?.value || '').toLowerCase();
    let catVal = document.getElementById('filterRoomCategory')?.value || '';
    let statusVal = document.getElementById('filterStatus')?.value || '';

    document.querySelectorAll('#bedTable tbody tr.bed-row').forEach(row => {
        let matchesText = row.innerText.toLowerCase().includes(textVal);
        let matchesCat = catVal === '' || row.getAttribute('data-category-id') === catVal;
        let matchesStatus = statusVal === '' || row.getAttribute('data-status') === statusVal;
        
        row.style.display = (matchesText && matchesCat && matchesStatus) ? '' : 'none';
    });

    // Hide/Show Group Headers based on Category Filter
    document.querySelectorAll('#bedTable tbody tr.group-header').forEach(header => {
        let matchesCat = catVal === '' || header.getAttribute('data-category-id') === catVal;
        header.style.display = matchesCat ? '' : 'none';
    });
}

// Direct Admit Button logic
function admitToBed(bedId) {
    let tabBtn = document.querySelector('button[data-bs-target="#nav-admission"]');
    if (tabBtn) {
        let tabInstance = bootstrap.Tab.getOrCreateInstance(tabBtn);
        tabInstance.show();
    }
    
    let bedSelect = document.querySelector('select[name="bed_id"]');
    if (bedSelect) {
        bedSelect.value = bedId;
        bedSelect.focus();
        bedSelect.classList.add('border-danger', 'bg-danger-subtle');
        setTimeout(() => {
            bedSelect.classList.remove('border-danger', 'bg-danger-subtle');
        }, 1500);
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}


// Search for Admitted Patients Table
document.getElementById('searchAdmitted')?.addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    document.querySelectorAll('#admittedPatientsTable tbody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

document.addEventListener('DOMContentLoaded', function () {
    const errorNode = document.getElementById('opdRegistrationErrorMessage');
    if (errorNode && errorNode.textContent.trim()) {
        Swal.fire({ icon: 'error', title: 'Unable to Complete', text: errorNode.textContent.trim(), confirmButtonColor: '#2563eb' });
    }
});

<?php if (isset($_SESSION['swal_success'])): ?>
    Swal.fire({icon: 'success', title: 'Success!', text: '<?= $_SESSION['swal_success'] ?>', timer:2000, showConfirmButton:false});
    <?php unset($_SESSION['swal_success']); ?>
<?php endif; ?>

const defaultModeId = <?= json_encode($default_mode_id) ?>;
const defaultModeName = <?= json_encode($default_mode_name) ?>;

function togglePaymentInputs(modeId) {
    let isChecked = document.getElementById('check_mode_' + modeId).checked;
    document.getElementById('div_input_mode_' + modeId).style.display = isChecked ? 'block' : 'none';
    if (!isChecked) { document.getElementById('amt_mode_' + modeId).value = ''; } 
    else {
        let advance = parseFloat(document.getElementById('input_advance').value) || 0;
        let currentTotal = 0;
        document.querySelectorAll('.pay-check:checked').forEach(chk => { if(chk.value != modeId) currentTotal += parseFloat(document.getElementById('amt_mode_' + chk.value).value) || 0; });
        let remaining = advance - currentTotal;
        document.getElementById('amt_mode_' + modeId).value = remaining > 0 ? remaining : 0;
    }
    calculateModalTotals();
}

function calculateModalTotals() {
    let totalPaid = 0;
    document.querySelectorAll('.pay-check:checked').forEach(chk => { totalPaid += parseFloat(document.getElementById('amt_mode_' + chk.value).value) || 0; });
    document.getElementById('modalTotalPaid').innerText = '₹' + totalPaid.toFixed(2);
}

function updateSummaryMode() {
    let advance = document.getElementById('input_advance').value || '0';
    let defaultCheck = document.getElementById('check_mode_' + defaultModeId);
    let defaultAmtInput = document.getElementById('amt_mode_' + defaultModeId);
    
    if(defaultCheck && defaultAmtInput) {
        defaultCheck.checked = true;
        document.getElementById('div_input_mode_' + defaultModeId).style.display = 'block';
        defaultAmtInput.value = advance;
    }
    
    document.getElementById('paymentSummaryText').innerText = defaultModeName + ': ₹' + parseFloat(advance).toFixed(2);
    document.getElementById('summary_payment_mode').value = defaultModeName + ': ₹' + parseFloat(advance).toFixed(2);
    document.getElementById('modal_total_paid').value = advance;
    document.getElementById('primary_payment_mode_id').value = defaultModeId;
    
    let directBreakdown = {};
    directBreakdown[defaultModeName] = { amount: parseFloat(advance), ref: '', mode_id: defaultModeId };
    document.getElementById('payment_breakdown_json').value = JSON.stringify(directBreakdown);
    calculateModalTotals();
}

function applyModalPayment() {
    let totalPaid = 0; let modesSummary = []; let breakdown = {}; let primaryModeId = defaultModeId; let maxAmt = 0;

    document.querySelectorAll('.pay-check:checked').forEach(chk => {
        let id = chk.value; let name = chk.getAttribute('data-name');
        let amt = parseFloat(document.getElementById('amt_mode_' + id).value) || 0;
        let refInput = document.getElementById('ref_mode_' + id); let ref = refInput ? refInput.value : '';

        if (amt > 0) {
            totalPaid += amt;
            let summTxt = name + ': ₹' + amt; if(ref) summTxt += ' (Ref: '+ref+')';
            modesSummary.push(summTxt); breakdown[name] = { amount: amt, ref: ref, mode_id: id };
            if(amt > maxAmt) { maxAmt = amt; primaryModeId = id; }
        }
    });

    let advanceStr = document.getElementById('input_advance').value;
    if (modesSummary.length === 0 && parseFloat(advanceStr) > 0) { updateSummaryMode(); return; }

    let summaryStr = modesSummary.length > 0 ? modesSummary.join(' | ') : 'Unpaid (₹0)';
    document.getElementById('input_advance').value = totalPaid.toFixed(2);
    document.getElementById('paymentSummaryText').innerText = summaryStr;
    document.getElementById('summary_payment_mode').value = summaryStr;
    document.getElementById('modal_total_paid').value = totalPaid;
    document.getElementById('primary_payment_mode_id').value = primaryModeId;
    document.getElementById('payment_breakdown_json').value = JSON.stringify(breakdown);
}

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
    const ageInput = document.getElementById('age'); const dobInput = document.getElementById('dob'); const dobDisplay = document.getElementById('dob_display'); const dobPicker = document.getElementById('dob_picker');
    if (!ageInput || !dobInput) return;
    ageInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 3);
        if (this.value === '') { dobInput.value = ''; if (dobDisplay) dobDisplay.value = ''; if (dobPicker) dobPicker.value = ''; return; }
        const age = parseInt(this.value, 10); if (Number.isNaN(age) || age < 0 || age > 150) return;
        const visitIso = document.getElementById('visit_date')?.value || ''; const base = visitIso ? new Date(visitIso + 'T00:00:00') : new Date();
        const dob = new Date(base.getFullYear() - age, base.getMonth(), base.getDate());
        const iso = [dob.getFullYear(), String(dob.getMonth() + 1).padStart(2, '0'), String(dob.getDate()).padStart(2, '0')].join('-');
        dobInput.value = iso; if (dobPicker) dobPicker.value = iso; if (dobDisplay) dobDisplay.value = formatDisplayDateDMY(iso);
    });
}

function calculateAgeFromDobUI() {
    const dobInput = document.getElementById('dob'); const ageInput = document.getElementById('age'); if (!dobInput || !ageInput) return null;
    const dobValue = String(dobInput.value || '').trim(); if (!dobValue) { ageInput.value = ''; return null; }
    const dob = new Date(dobValue + 'T00:00:00'); if (Number.isNaN(dob.getTime())) return null;
    const today = new Date(); let age = today.getFullYear() - dob.getFullYear();
    if (today.getMonth() - dob.getMonth() < 0 || (today.getMonth() - dob.getMonth() === 0 && today.getDate() < dob.getDate())) age--;
    if (age >= 0 && age <= 150) { ageInput.value = String(age); }
    return age;
}

let mobIndexCounter = 1000;
function addMobileField() {
    mobIndexCounter++; let wrapper = document.getElementById('mobile_wrapper');
    let html = `
        <div class="input-group input-group-sm mb-1 mob-row">
            <input type="text" name="mobile[]" class="form-control form-control-sm mob-input" placeholder="Alternate Mobile" maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, ''); updateMobCount(this, ${mobIndexCounter})">
            <span class="input-group-text text-muted" id="mob_count_${mobIndexCounter}" style="font-size:0.7rem; min-width: 45px; text-align:center;">0/10</span>
            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.mob-row').remove()"><i class="bi bi-trash"></i></button>
        </div>`;
    wrapper.insertAdjacentHTML('beforeend', html);
}

function updateMobCount(input, index) {
    let countSpan = document.getElementById('mob_count_' + index);
    if(countSpan) {
        let len = input.value.length; countSpan.innerText = len + '/10';
        if(len === 10) { countSpan.classList.remove('text-muted'); countSpan.classList.add('text-success', 'fw-bold'); } 
        else { countSpan.classList.remove('text-success', 'fw-bold'); countSpan.classList.add('text-muted'); }
    }
}

let patientLookupTimer = null; let patientLookupCache = {};
function escapePatientHtml(value) { return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c])); }
function clearPatientSelection() {
    document.getElementById('existing_patient_id').value = ''; document.getElementById('patient_uhid').value = ''; document.getElementById('patient_uhid').className = 'form-control form-control-sm bg-light font-monospace text-muted'; document.querySelector('input[name="fullname"]').value = ''; document.getElementById('dob').value = ''; document.getElementById('dob_display').value = ''; document.getElementById('age').value = ''; document.getElementById('gender').value = '';
}
function fillExistingPatient(patient) {
    document.getElementById('existing_patient_id').value = patient.patient_id || ''; let uhidEl = document.getElementById('patient_uhid'); uhidEl.value = patient.uhid || ''; uhidEl.className = 'form-control form-control-sm bg-light font-monospace text-success fw-bold';
    document.querySelector('input[name="fullname"]').value = patient.fullname || ''; document.getElementById('dob').value = patient.dob || ''; document.getElementById('dob_display').value = patient.dob ? formatDisplayDateDMY(patient.dob) : ''; calculateAgeFromDobUI();
    let g = String(patient.gender || '').trim().toLowerCase(); document.getElementById('gender').value = g === 'male' ? 'Male' : (g === 'female' ? 'Female' : (g ? 'Other' : ''));
    if (patient.mobile) {
        const mobiles = String(patient.mobile).split(',').map(v => v.trim()).filter(Boolean); const rows = document.querySelectorAll('#mobile_wrapper .mob-row');
        if (rows.length && mobiles.length) { rows[0].querySelector('.mob-input').value = mobiles[0]; updateMobCount(rows[0].querySelector('.mob-input'), 0); }
    }
}
function renderPatientLookupResults(patients) {
    patientLookupCache = {}; patients.forEach(p => patientLookupCache[String(p.patient_id)] = p);
    if (!patients.length) { clearPatientSelection(); return; } if (patients.length === 1) { fillExistingPatient(patients[0]); return; }
    const list = document.getElementById('patientSelectionList');
    if (list) {
        list.innerHTML = patients.map(p => `<button type="button" class="patient-popup-item" data-patient-id="${escapePatientHtml(p.patient_id)}"><div class="name">${escapePatientHtml(p.fullname)}</div><div class="meta">${escapePatientHtml(p.gender || '')} ${p.age ? ' • ' + escapePatientHtml(p.age) + ' YRS' : ''}</div><div class="uhid">UHID: ${escapePatientHtml(p.uhid || '')}</div></button>`).join('');
        list.querySelectorAll('.patient-popup-item').forEach(btn => { btn.addEventListener('click', function() { fillExistingPatient(patientLookupCache[this.dataset.patientId]); bootstrap.Modal.getInstance(document.getElementById('patientSelectionModal')).hide(); }); });
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('patientSelectionModal')).show();
}

function lookupPatientByMobile(mobile) {
    clearTimeout(patientLookupTimer); mobile = String(mobile || '').replace(/\D/g, ''); if (mobile.length !== 10) { document.getElementById('existing_patient_id').value = ''; return; }
    patientLookupTimer = setTimeout(function() { fetch('ipd_admission.php?lookup_patient_mobile=' + encodeURIComponent(mobile), { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(res => res.json()).then(data => { if(data.success) renderPatientLookupResults(data.patients || []); }).catch(err => console.error(err)); }, 300);
}

// Preserve tabs on reload
document.addEventListener("DOMContentLoaded", function() {
    let hash = window.location.hash; 
    if (hash) { 
        let triggerEl = document.querySelector(`button[data-bs-target="${hash}"]`); 
        if (triggerEl) {
            setTimeout(function() { triggerEl.click(); }, 50);
        }
    }
    
    let tabButtons = document.querySelectorAll('button[data-bs-toggle="pill"]');
    tabButtons.forEach(btn => {
        btn.addEventListener('shown.bs.tab', function(e) {
            if(e.target && e.target.getAttribute('data-bs-target')) {
                window.location.hash = e.target.getAttribute('data-bs-target');
            }
        });
    });
});

document.addEventListener('DOMContentLoaded', function () {
    setupDobField(); setupAgeField(); calculateAgeFromDobUI();
    
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


// =========================================================
// Prevent Multiple Form Submissions (Double Click Lock)
// =========================================================
document.getElementById('opdForm')?.addEventListener('submit', function(e) {
    let submitBtn = this.querySelector('button[type="submit"]');
    if (submitBtn) {
        if (submitBtn.disabled) {
            e.preventDefault();
            return false;
        }
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Please Wait...';
    }
});

</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
