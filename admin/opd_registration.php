<?php
// admin/opd_registration.php
// Keep all receipt timestamps in India Standard Time.
date_default_timezone_set('Asia/Kolkata');
$page_title = "OPD Payment";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php'; 

// ==============================================================
// AJAX: REFRESH MASTERS (TPA, CATEGORY, TARIFF)
// ==============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'refresh_masters') {
    header('Content-Type: application/json; charset=utf-8');
    $org_id = (int)($_SESSION['org_id'] ?? 1);
    $center_id = (int)($_SESSION['center_id'] ?? 1);
    
    $cats = $tenant_pdo->query("SELECT id, category_name FROM master_categories WHERE org_id = $org_id AND center_id = $center_id AND status = 1")->fetchAll(PDO::FETCH_ASSOC);
    $ins = $tenant_pdo->query("SELECT id, category_id, insurance_name FROM insurance WHERE org_id = $org_id AND center_id = $center_id AND status = 1 AND insurance_name IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $tar = $tenant_pdo->query("SELECT doctor_id, service_id, category_id, insurance_id, rate FROM doctor_tariff_master WHERE org_id = $org_id AND center_id = $center_id AND status = 1")->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['categories' => $cats, 'insurances' => $ins, 'tariffs' => $tar]);
    exit;
}

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

// DOB is the source of truth for age.
function calculateAgeFromDob($dob, $as_of_date = null) {
    $dob = trim((string)$dob);
    if ($dob === '') return null;

    $dobObj = DateTime::createFromFormat('!Y-m-d', $dob);
    if (!$dobObj || $dobObj->format('Y-m-d') !== $dob) return null;

    $as_of_date = $as_of_date ?: date('Y-m-d');
    $asOfObj = DateTime::createFromFormat('!Y-m-d', $as_of_date);
    if (!$asOfObj || $asOfObj->format('Y-m-d') !== $as_of_date) $asOfObj = new DateTime('today');

    if ($dobObj > $asOfObj) return null;
    return (int)$dobObj->diff($asOfObj)->y;
}

// AUTO-ADD REQUIRED COLUMNS
try {
    try {
        $idxStmt = $tenant_pdo->query("SHOW INDEX FROM patient_master");
        $mobileUniqueIndexes = [];
        while ($idx = $idxStmt->fetch(PDO::FETCH_ASSOC)) {
            $keyName = (string)($idx['Key_name'] ?? '');
            $columnName = (string)($idx['Column_name'] ?? '');
            $nonUnique = (int)($idx['Non_unique'] ?? 1);

            if ($keyName !== 'PRIMARY' && $columnName === 'mobile' && $nonUnique === 0) {
                $mobileUniqueIndexes[$keyName] = true;
            }
        }

        foreach (array_keys($mobileUniqueIndexes) as $keyName) {
            $safeKeyName = str_replace('`', '``', $keyName);
            $tenant_pdo->exec("ALTER TABLE patient_master DROP INDEX `{$safeKeyName}`");
        }
    } catch (Exception $e) { }

    // Use INFORMATION_SCHEMA checks instead of ADD COLUMN IF NOT EXISTS,
    // which is unsupported by some MySQL/MariaDB versions and would stop the
    // entire migration block before appointment_id/token compatibility ran.
    $ensureColumn = function (string $table, string $column, string $definition) use ($tenant_pdo): void {
        $check = $tenant_pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $check->execute([$table, $column]);
        if ((int)$check->fetchColumn() === 0) {
            $tenant_pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    };

    $ensureColumn('patient_master', 'dob', 'DATE NULL');
    $ensureColumn('opd_visits', 'department_id', 'INT NULL');
    $ensureColumn('opd_visits', 'service_id', 'INT NULL');
    $ensureColumn('opd_visits', 'category_id', 'INT NULL');
    $ensureColumn('opd_visits', 'insurance_id', 'INT NULL');
    $ensureColumn('opd_visits', 'payment_date', 'DATE NULL');
    $ensureColumn('opd_visits', 'payment_mode', "VARCHAR(50) DEFAULT 'Cash'");
    $ensureColumn('opd_visits', 'amount_paid', 'DECIMAL(10,2) DEFAULT 0');
    $ensureColumn('opd_visits', 'change_return', 'DECIMAL(10,2) DEFAULT 0');
    $ensureColumn('opd_visits', 'payment_breakdown', 'TEXT NULL');
    $ensureColumn('opd_visits', 'remark', 'TEXT NULL');
    $ensureColumn('opd_visits', 'discount_amount', 'DECIMAL(10,2) DEFAULT 0');
    $ensureColumn('opd_visits', 'discount_percent', 'DECIMAL(5,2) NOT NULL DEFAULT 0');
    $ensureColumn('opd_visits', 'receipt_no', 'BIGINT UNSIGNED NULL');
    // Link an appointment to its visit so duplicate billing can be prevented.
    $ensureColumn('opd_visits', 'appointment_id', 'INT NULL');

    // Create the link index if absent. Existing duplicate non-NULL values are
    // handled safely by leaving the application-level duplicate guard in place.
    $appointmentIndexCheck = $tenant_pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opd_visits' AND INDEX_NAME = 'uq_opd_visits_appointment'");
    $appointmentIndexCheck->execute();
    if ((int)$appointmentIndexCheck->fetchColumn() === 0) {
        try {
            $tenant_pdo->exec("CREATE UNIQUE INDEX uq_opd_visits_appointment ON opd_visits (org_id, center_id, appointment_id)");
        } catch (Throwable $indexEx) {
            // Application-level duplicate check remains active if an index cannot be created.
        }
    }


    $tenant_pdo->exec("
        UPDATE opd_visits
        SET receipt_no = token_no
        WHERE receipt_no IS NULL
    ");

    $tenant_pdo->exec("
        UPDATE opd_visits
        SET discount_percent = CASE
            WHEN consultation_fee > 0 THEN ROUND((discount_amount / consultation_fee) * 100, 2)
            ELSE 0
        END
        WHERE discount_percent = 0
    ");

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
$embedded_mode = (isset($_GET['embedded']) && $_GET['embedded'] === '1');
$followup_validity_days = 7; // Follow-up validity window; adjust here if hospital policy differs.



// ============================================================
// AJAX: FIND REGISTERED PATIENT BY MOBILE NUMBER
// ============================================================
if (isset($_GET['lookup_patient_mobile'])) {
    header('Content-Type: application/json; charset=utf-8');

    $lookup_mobile = preg_replace('/\D+/', '', (string)($_GET['lookup_patient_mobile'] ?? ''));

    if (strlen($lookup_mobile) !== 10) {
        echo json_encode(['success' => false, 'patients' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $lookup_stmt = $tenant_pdo->prepare("
            SELECT patient_id, uhid, fullname, mobile, dob, age, gender
            FROM patient_master
            WHERE org_id = ? AND center_id = ? AND status = 1
              AND (mobile = ? OR FIND_IN_SET(?, REPLACE(mobile, ' ', '')) > 0)
            ORDER BY patient_id DESC
        ");

        $lookup_stmt->execute([$org_id, $center_id, $lookup_mobile, $lookup_mobile]);

        $patients = $lookup_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($patients as &$lookupPatient) {
            $calculatedAge = calculateAgeFromDob($lookupPatient['dob'] ?? '');
            if ($calculatedAge !== null) $lookupPatient['age'] = $calculatedAge;
        }
        unset($lookupPatient);

        echo json_encode(['success' => true, 'patients' => $patients], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'patients' => [], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

    exit;
}


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

// UHID is generated only during successful registration (not while booking an appointment), so unused appointments do not consume patient IDs.
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
    $stmt = $tenant_pdo->prepare("SELECT uhid FROM patient_master WHERE uhid LIKE ? ORDER BY patient_id DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([$uhid_base . '%']);
    $last_uhid = $stmt->fetchColumn();

    $next_serial = $last_uhid ? ((int)str_replace($uhid_base, '', $last_uhid) + 1) : 1;
    return $uhid_base . $next_serial;
}

// Handle Edit Fetch
$edit_data = null;
$edit_visit_id = (int)($_GET['edit_visit'] ?? 0);
$appointment_prefill = null;
$appointment_id = (int)($_GET['appointment_id'] ?? 0);

if ($appointment_id > 0 && $edit_visit_id <= 0) {
    try {
        $ap_stmt = $tenant_pdo->prepare("
            SELECT a.*, p.uhid AS master_uhid, p.fullname AS master_fullname, p.mobile AS master_mobile, p.dob AS master_dob, p.age AS master_age, p.gender AS master_gender
            FROM opd_appointments a
            LEFT JOIN patient_master p ON a.uhid = p.uhid
            WHERE a.appointment_id = ? AND a.org_id = ? AND a.center_id = ? LIMIT 1
        ");
        $ap_stmt->execute([$appointment_id, $org_id, $center_id]);
        $appointment_prefill = $ap_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Backfill legacy appointments that were created before UHID-at-appointment was enabled.
        if ($appointment_prefill && empty($appointment_prefill['master_uhid']) && empty($appointment_prefill['uhid'])) {
            $tenant_pdo->beginTransaction();
            $lockAppt = $tenant_pdo->prepare("SELECT appointment_id, uhid, patient_name, mobile, age, gender, appointment_date FROM opd_appointments WHERE appointment_id = ? AND org_id = ? AND center_id = ? LIMIT 1 FOR UPDATE");
            $lockAppt->execute([$appointment_id, $org_id, $center_id]);
            $lockedAppt = $lockAppt->fetch(PDO::FETCH_ASSOC);
            if ($lockedAppt && empty($lockedAppt['uhid'])) {
                $fyTs = strtotime((string)$lockedAppt['appointment_date']);
                $fyYear = (int)date('Y', $fyTs); $fyMonth = (int)date('m', $fyTs);
                $fyStart = $fyMonth >= 4 ? $fyYear : $fyYear - 1; $fyCode = substr((string)$fyStart, -2) . substr((string)($fyStart + 1), -2);
                $orgPrefix = 'MAX'; $centerCode = 'LKO';
                try { $x = $tenant_pdo->query("SELECT mnemonic FROM org_profile LIMIT 1")->fetchColumn(); if ($x) $orgPrefix = strtoupper(trim($x)); } catch (Exception $ignore) {}
                try { $x = $tenant_pdo->prepare("SELECT center_code FROM master_centers WHERE center_id = ?"); $x->execute([$center_id]); $v = $x->fetchColumn(); if ($v) $centerCode = strtoupper(trim($v)); } catch (Exception $ignore) {}
                $base = $orgPrefix . '-' . $centerCode . '-' . $fyCode . '-';
                $last = $tenant_pdo->prepare("SELECT uhid FROM patient_master WHERE org_id = ? AND center_id = ? AND uhid LIKE ? ORDER BY patient_id DESC LIMIT 1 FOR UPDATE");
                $last->execute([$org_id, $center_id, $base . '%']); $lastUhid = (string)($last->fetchColumn() ?: '');
                $serial = $lastUhid !== '' ? ((int)substr($lastUhid, strlen($base)) + 1) : 1; $newUhid = $base . $serial;
                $newPatient = $tenant_pdo->prepare("INSERT INTO patient_master (org_id, center_id, uhid, fullname, dob, gender, age, mobile, status) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 1)");
                $newPatient->execute([$org_id, $center_id, $newUhid, $lockedAppt['patient_name'], $lockedAppt['gender'], (int)$lockedAppt['age'], preg_replace('/\D+/', '', (string)$lockedAppt['mobile'])]);
                $tenant_pdo->prepare("UPDATE opd_appointments SET uhid = ? WHERE appointment_id = ? AND org_id = ? AND center_id = ? AND (uhid IS NULL OR uhid = '')")->execute([$newUhid, $appointment_id, $org_id, $center_id]);
            }
            $tenant_pdo->commit();
            // Re-fetch the appointment with the newly linked UHID/patient master details.
            $ap_stmt->execute([$appointment_id, $org_id, $center_id]);
            $appointment_prefill = $ap_stmt->fetch(PDO::FETCH_ASSOC) ?: $appointment_prefill;
        }
    } catch (Exception $e) { if ($tenant_pdo->inTransaction()) $tenant_pdo->rollBack(); }
}

// Existing-patient follow-up eligibility for the selected doctor (default validity: 7 days).
$followup_eligible = false;
$followup_last_date = '';
$prefill_uhid = trim((string)($appointment_prefill['master_uhid'] ?? $appointment_prefill['uhid'] ?? ''));
$prefill_doctor_id = (int)($appointment_prefill['doctor_id'] ?? 0);
if ($prefill_uhid !== '' && $prefill_doctor_id > 0) {
    try {
        $fu = $tenant_pdo->prepare("SELECT v.visit_date FROM opd_visits v JOIN patient_master p ON p.patient_id = v.patient_id WHERE p.uhid = ? AND v.org_id = ? AND v.center_id = ? AND v.doctor_id = ? AND v.visit_date <= CURDATE() AND v.visit_date >= DATE_SUB(CURDATE(), INTERVAL {$followup_validity_days} DAY) ORDER BY v.visit_date DESC, v.visit_id DESC LIMIT 1");
        $fu->execute([$prefill_uhid, $org_id, $center_id, $prefill_doctor_id]);
        $followup_last_date = (string)($fu->fetchColumn() ?: '');
        $followup_eligible = $followup_last_date !== '';
    } catch (Exception $e) { $followup_eligible = false; }
}

if ($edit_visit_id > 0) {
    try {
        $edit_stmt = $tenant_pdo->prepare("
            SELECT v.*, p.uhid, p.fullname, p.mobile, p.dob, p.age, p.gender 
            FROM opd_visits v 
            JOIN patient_master p ON v.patient_id = p.patient_id 
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
    $dob      = trim((string)($_POST['dob'] ?? ''));
    
    $age_post = trim((string)($_POST['age'] ?? ''));

    if ($dob === '' && $age_post !== '') {
        if (!preg_match('/^\\d{1,3}$/', $age_post)) {
            $err = "Please enter a valid age.";
        } else {
            $entered_age = (int)$age_post;

            if ($entered_age < 0 || $entered_age > 150) {
                $err = "Please enter a valid age between 0 and 150.";
            } else {
                $ageBaseDate = !empty($_POST['visit_date']) ? $_POST['visit_date'] : date('Y-m-d');
                $ageBaseObj = DateTime::createFromFormat('!Y-m-d', $ageBaseDate);

                if (!$ageBaseObj || $ageBaseObj->format('Y-m-d') !== $ageBaseDate) {
                    $ageBaseObj = new DateTime('today');
                }

                $dob = $ageBaseObj->modify("-{$entered_age} years")->format('Y-m-d');
            }
        }
    }

    $mobile_post = $_POST['mobile'] ?? ($appointment_prefill['master_mobile'] ?? ($appointment_prefill['mobile'] ?? []));
    $mobile = is_array($mobile_post) ? implode(', ', array_filter(array_map('trim', $mobile_post), fn($v) => $v !== '')) : trim((string)$mobile_post);
    
    $visit_date    = !empty($_POST['visit_date']) ? $_POST['visit_date'] : date('Y-m-d');
    $payment_date = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
    $paymentDateObj = DateTime::createFromFormat('!Y-m-d', $payment_date);
    if (!$paymentDateObj || $paymentDateObj->format('Y-m-d') !== $payment_date) {
        $err = "Please select a valid Payment Date.";
        $payment_date = date('Y-m-d');
    }

    $age = calculateAgeFromDob($dob, $visit_date);
    if ($age === null) {
        $err = "Please enter a valid Date of Birth or Age. DOB cannot be in the future.";
    }

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

    $pay_mode   = trim($_POST['summary_payment_mode'] ?? '');
    $post_edit_id  = (int)($_POST['post_edit_id'] ?? 0);
    $appointment_id = (int)($_POST['appointment_id'] ?? 0);

    $payment_mode_lower = strtolower(trim($pay_mode));
    $is_payment_unselected = (
        $pay_mode === '' ||
        $payment_mode_lower === 'unpaid (₹0)' ||
        preg_match('/^cash:\s*₹?0(?:\.00)?$/i', $pay_mode)
    );

    if ($is_payment_unselected && $total_paid <= 0 && $payable > 0) {
        $total_paid = $payable;
        $change_ret = 0;
        $pay_mode = 'CASH: ₹' . number_format($payable, 2, '.', '');
        $pay_breakdown = json_encode([
            'Cash' => ['amount' => $payable]
        ], JSON_UNESCAPED_UNICODE);
    }

    if ($payable <= 0) $pay_status = 1;
    elseif ($total_paid >= $payable) $pay_status = 1;
    elseif ($total_paid > 0) $pay_status = 2;
    else $pay_status = 3;

    $current_fy_code = getCurrentFY($tenant_pdo, $org_id, $center_id, $visit_date);
    $display_fy = formatFYWithHyphen($current_fy_code);

    if ($err === '' && !empty($fullname) && !empty($mobile) && $dob !== '') {
        try {
            $tenant_pdo->beginTransaction();

            if ($post_edit_id > 0) {
                // UPDATE EXISTING RECORD
                $up_visit = $tenant_pdo->prepare("SELECT patient_id FROM opd_visits WHERE visit_id = ? AND org_id = ?");
                $up_visit->execute([$post_edit_id, $org_id]);
                $patient_id = $up_visit->fetchColumn();

                $uhid_stmt = $tenant_pdo->prepare("SELECT uhid FROM patient_master WHERE patient_id = ? AND org_id = ? LIMIT 1");
                $uhid_stmt->execute([$patient_id, $org_id]);
                $patient_uhid = (string)$uhid_stmt->fetchColumn();
                if ($patient_uhid === '') {
                    throw new Exception("Patient UHID was not found.");
                }

                $tenant_pdo->prepare("UPDATE patient_master SET fullname = ?, dob = ?, gender = ?, age = ?, mobile = ? WHERE patient_id = ? AND org_id = ?")
                           ->execute([$fullname, $dob, $gender, $age, $mobile, $patient_id, $org_id]);

                $tenant_pdo->prepare("
                    UPDATE opd_visits 
                    SET visit_date = ?, payment_date = ?, doctor_id = ?, department_id = ?, service_id = ?, category_id = ?, insurance_id = ?, 
                        consultation_fee = ?, discount_amount = ?, discount_percent = ?, remark = ?, payment_mode = ?, amount_paid = ?, change_return = ?, 
                        payment_breakdown = ?, payment_status = ? 
                    WHERE visit_id = ? AND org_id = ?
                ")->execute([$visit_date, $payment_date, $doctor_id, $department_id, $service_id, $category_id, $insurance_id, $fee, $discount, $discount_percent, $remark, $pay_mode, $total_paid, $change_ret, $pay_breakdown, $pay_status, $post_edit_id, $org_id]);

                if ($appointment_id > 0) {
                    $tenant_pdo->prepare("
                        UPDATE opd_appointments
                        SET status = 'Checked In', uhid = ?
                        WHERE appointment_id = ? AND org_id = ? AND center_id = ?
                    ")->execute([$patient_uhid, $appointment_id, $org_id, $center_id]);
                }

                $tenant_pdo->commit();
                if (function_exists('set_flash_msg')) set_flash_msg("OPD Record updated successfully!");
                header("Location: opd_registration.php" . ($embedded_mode ? "?embedded=1&saved=1" : ""));
                exit;
            } else {
                // INSERT NEW REGISTRATION
                $patient_id = 0;
                $uhid = '';

                if ($appointment_id > 0) {
                    $appt_lock = $tenant_pdo->prepare("
                        SELECT appointment_id, uhid, status
                        FROM opd_appointments
                        WHERE appointment_id = ? AND org_id = ? AND center_id = ?
                        LIMIT 1 FOR UPDATE
                    ");
                    $appt_lock->execute([$appointment_id, $org_id, $center_id]);
                    $appt_row = $appt_lock->fetch(PDO::FETCH_ASSOC);

                    if (!$appt_row) {
                        throw new Exception("Appointment not found.");
                    }

                    // One appointment can create at most one OPD visit/bill.
                    $dupVisit = $tenant_pdo->prepare("SELECT visit_id, receipt_no FROM opd_visits WHERE org_id = ? AND center_id = ? AND appointment_id = ? LIMIT 1 FOR UPDATE");
                    $dupVisit->execute([$org_id, $center_id, $appointment_id]);
                    $alreadyBilled = $dupVisit->fetch(PDO::FETCH_ASSOC);
                    if ($alreadyBilled) {
                        throw new Exception("IS APPOINTMENT KA OPD BILL PEHLE HI BAN CHUKA HAI (Receipt: " . (string)($alreadyBilled['receipt_no'] ?? $alreadyBilled['visit_id']) . "). Duplicate bill nahi banaya gaya.");
                    }

                    if (!empty($appt_row['uhid'])) {
                        $appt_uhid = trim((string)$appt_row['uhid']);
                        $p_stmt = $tenant_pdo->prepare("
                            SELECT patient_id, uhid
                            FROM patient_master
                            WHERE uhid = ? AND org_id = ? AND center_id = ?
                            LIMIT 1 FOR UPDATE
                        ");
                        $p_stmt->execute([$appt_uhid, $org_id, $center_id]);
                        $existing_for_appt = $p_stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$existing_for_appt) {
                            throw new Exception("Linked appointment patient was not found.");
                        }

                        $patient_id = (int)$existing_for_appt['patient_id'];
                        $uhid = $existing_for_appt['uhid'];
                    } else {
                        $uhid = generateSecureUHID($tenant_pdo, $center_id, $current_fy_code);
                        $stmt = $tenant_pdo->prepare("
                            INSERT INTO patient_master (org_id, center_id, uhid, fullname, dob, gender, age, mobile, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                        ");
                        $stmt->execute([$org_id, $center_id, $uhid, $fullname, $dob, $gender, $age, $mobile]);
                        $patient_id = (int)$tenant_pdo->lastInsertId();
                    }
                } else {
                    $existing_patient_id = (int)($_POST['existing_patient_id'] ?? 0);

                    if ($existing_patient_id > 0) {
                        $existing_patient_stmt = $tenant_pdo->prepare("
                            SELECT patient_id, uhid
                            FROM patient_master
                            WHERE patient_id = ? AND org_id = ? AND center_id = ? AND status = 1
                            LIMIT 1 FOR UPDATE
                        ");
                        $existing_patient_stmt->execute([$existing_patient_id, $org_id, $center_id]);
                        $existing_patient = $existing_patient_stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$existing_patient) {
                            throw new Exception("Selected registered patient was not found.");
                        }

                        $patient_id = (int)$existing_patient['patient_id'];
                        $uhid = (string)$existing_patient['uhid'];

                        $tenant_pdo->prepare("
                            UPDATE patient_master
                            SET fullname = ?, dob = ?, gender = ?, age = ?, mobile = ?
                            WHERE patient_id = ? AND org_id = ? AND center_id = ?
                        ")->execute([$fullname, $dob, $gender, $age, $mobile, $patient_id, $org_id, $center_id]);
                    } else {
                        $uhid = generateSecureUHID($tenant_pdo, $center_id, $current_fy_code);
                        $stmt = $tenant_pdo->prepare("
                            INSERT INTO patient_master (org_id, center_id, uhid, fullname, dob, gender, age, mobile, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                        ");
                        $stmt->execute([$org_id, $center_id, $uhid, $fullname, $dob, $gender, $age, $mobile]);
                        $patient_id = (int)$tenant_pdo->lastInsertId();
                    }
                }

                // Backward-compatible duplicate guard for bills created before appointment_id was added.
                if ($appointment_id > 0 && isset($appt_row) && ($appt_row['status'] ?? '') === 'Checked In') {
                    $legacyDup = $tenant_pdo->prepare("SELECT visit_id, receipt_no FROM opd_visits WHERE org_id = ? AND center_id = ? AND patient_id = ? AND doctor_id = ? AND visit_date = ? LIMIT 1 FOR UPDATE");
                    $legacyDup->execute([$org_id, $center_id, $patient_id, $doctor_id, $visit_date]);
                    $legacyExisting = $legacyDup->fetch(PDO::FETCH_ASSOC);
                    if ($legacyExisting) {
                        throw new Exception("IS APPOINTMENT KA BILL/OPD VISIT PEHLE HI SAVE HAI (Receipt: " . (string)($legacyExisting['receipt_no'] ?? $legacyExisting['visit_id']) . "). Duplicate entry rok di gayi.");
                    }
                }

                // Billing mode is enforced server-side. Follow-up is free only when the same patient
                // has a visit with the same doctor within the configured validity window.
                $billing_mode = (string)($_POST['billing_mode'] ?? 'regular');
                if ($billing_mode === 'followup') {
                    $fuCheck = $tenant_pdo->prepare("SELECT v.visit_date FROM opd_visits v WHERE v.patient_id = ? AND v.org_id = ? AND v.center_id = ? AND v.doctor_id = ? AND v.visit_date <= ? AND v.visit_date >= DATE_SUB(?, INTERVAL {$followup_validity_days} DAY) ORDER BY v.visit_date DESC, v.visit_id DESC LIMIT 1");
                    $fuCheck->execute([$patient_id, $org_id, $center_id, $doctor_id, $visit_date, $visit_date]);
                    $validFollowupDate = (string)($fuCheck->fetchColumn() ?: '');
                    if ($validFollowupDate === '') {
                        throw new Exception("Follow-up without consultation fee is allowed only within {$followup_validity_days} days of a previous visit with the same doctor.");
                    }
                    $fee = 0;
                    $discount = 0;
                    $discount_percent = 0;
                    $payable = 0;
                    $total_paid = 0;
                    $change_ret = 0;
                    $pay_mode = 'FOLLOW-UP - NO CHARGE';
                    $pay_breakdown = json_encode(['Follow-up' => ['amount' => 0]], JSON_UNESCAPED_UNICODE);
                    $pay_status = 1;
                }

                $next_token = 0;
                if ($appointment_id > 0) {
                    $apptTokenStmt = $tenant_pdo->prepare("SELECT token_no FROM opd_appointments WHERE appointment_id = ? AND org_id = ? AND center_id = ? LIMIT 1");
                    $apptTokenStmt->execute([$appointment_id, $org_id, $center_id]);
                    $next_token = (int)$apptTokenStmt->fetchColumn();
                }
                if ($next_token <= 0) {
                    $token_stmt = $tenant_pdo->prepare("SELECT COALESCE(MAX(token_no), 0) FROM opd_visits WHERE visit_date = ? AND org_id = ? AND center_id = ? AND doctor_id = ?");
                    $token_stmt->execute([$visit_date, $org_id, $center_id, $doctor_id]);
                    $lastVisitToken = (int)$token_stmt->fetchColumn();
                    $appt_token_stmt = $tenant_pdo->prepare("SELECT COALESCE(MAX(token_no), 0) FROM opd_appointments WHERE appointment_date = ? AND org_id = ? AND center_id = ? AND doctor_id = ?");
                    $appt_token_stmt->execute([$visit_date, $org_id, $center_id, $doctor_id]);
                    $lastAppointmentToken = (int)$appt_token_stmt->fetchColumn();
                    $next_token = max($lastVisitToken, $lastAppointmentToken) + 1;
                }

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

                $receipt_stmt = $tenant_pdo->prepare("SELECT COALESCE(MAX(receipt_no), 0) FROM opd_visits WHERE visit_date BETWEEN ? AND ? AND org_id = ? AND center_id = ?");
                $receipt_stmt->execute([$fyStartDate, $fyEndDate, $org_id, $center_id]);
                $last_receipt_no = (int)$receipt_stmt->fetchColumn();
                $next_receipt_no = $last_receipt_no + 1;

                $v_stmt = $tenant_pdo->prepare("
                    INSERT INTO opd_visits (
                        org_id, center_id, patient_id, appointment_id, doctor_id, department_id, service_id,
                        category_id, insurance_id, token_no, receipt_no, visit_date, payment_date,
                        consultation_fee, discount_amount, discount_percent, remark,
                        payment_mode, amount_paid, change_return, payment_breakdown, payment_status, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Waiting')
                ");

                $v_stmt->execute([$org_id, $center_id, $patient_id, ($appointment_id > 0 ? $appointment_id : null), $doctor_id, $department_id, $service_id, $category_id, $insurance_id, $next_token, $next_receipt_no, $visit_date, $payment_date, $fee, $discount, $discount_percent, $remark, $pay_mode, $total_paid, $change_ret, $pay_breakdown, $pay_status]);

                if ($appointment_id > 0) {
                    $tenant_pdo->prepare("
                        UPDATE opd_appointments
                        SET status = 'Checked In', uhid = ?
                        WHERE appointment_id = ? AND org_id = ? AND center_id = ?
                    ")->execute([$uhid, $appointment_id, $org_id, $center_id]);
                }

                $tenant_pdo->commit();
                $display_token = (string)$next_token;
                $display_receipt = $display_fy . '/' . str_pad($next_receipt_no, 2, '0', STR_PAD_LEFT);
                $doc_name = "General OPD";
                if ($doctor_id > 0) {
                    $d_stmt = $tenant_pdo->prepare("SELECT full_name FROM master_doctors WHERE id = ? AND org_id = ?");
                    $d_stmt->execute([$doctor_id, $org_id]);
                    $doc_name = $d_stmt->fetchColumn() ?: $doc_name;
                }

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
                    'date_time'    => date('d-m-Y', strtotime($payment_date)) . ' ' . date('h:i A'),
                    'payment_date' => $payment_date,
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
    try {
        $tenant_pdo->exec("ALTER TABLE patient_master ADD COLUMN IF NOT EXISTS dob DATE NULL AFTER fullname");
    } catch (Exception $schemaEx) { }

    try {
        $tv_stmt = $tenant_pdo->prepare("
            SELECT v.*, p.uhid, p.fullname, p.mobile, p.dob,
                   CASE WHEN p.dob IS NOT NULL AND p.dob <> '' THEN TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) ELSE p.age END AS age,
                   p.gender, d.full_name AS doctor_name
            FROM opd_visits v
            JOIN patient_master p ON v.patient_id = p.patient_id
            LEFT JOIN master_doctors d ON v.doctor_id = d.id
            WHERE v.org_id = ? AND v.center_id = ?
            ORDER BY v.visit_id DESC
        ");
        $tv_stmt->execute([$org_id, $center_id]);
        $today_visits = $tv_stmt->fetchAll() ?: [];
    } catch (Exception $billingEx) {
        $tv_stmt = $tenant_pdo->prepare("
            SELECT v.*, p.uhid, p.fullname, p.mobile, p.age, p.gender, d.full_name AS doctor_name
            FROM opd_visits v
            JOIN patient_master p ON v.patient_id = p.patient_id
            LEFT JOIN master_doctors d ON v.doctor_id = d.id
            WHERE v.org_id = ? AND v.center_id = ?
            ORDER BY v.visit_id DESC
        ");
        $tv_stmt->execute([$org_id, $center_id]);
        $today_visits = $tv_stmt->fetchAll() ?: [];
    }
} catch (Exception $e) {
    $today_visits = [];
}

if ($embedded_mode) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>OPD Payment</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css"><script src="https://cdn.jsdelivr.net/npm/flatpickr"></script><style>body{margin:0!important;padding:12px!important;background:#fff!important;overflow-x:hidden!important}.main-content,main,.content-wrapper,.page-content{margin:0!important;padding:0!important;width:100%!important;max-width:100%!important}.sidebar,.sidebar-wrapper,.topbar,.navbar,.app-sidebar,.main-sidebar,.clinic-sidebar,.layout-sidebar,header.app-header{display:none!important}body>.container,body>.container-fluid{width:100%!important;max-width:100%!important;padding:0!important}</style></head><body class="opd-embedded-frame">';
} else {
    require_once __DIR__ . '/layout_header.php';
}
?>

<style>
body, .card, .card-header, .card-body, .form-label, .form-select, .form-control, .form-check-label,
.btn, .badge, .table, .modal, .modal-title, .modal-body, .modal-footer, .alert, .small, .text-muted,
.text-secondary, .text-primary, .text-danger, .text-success, label, th, td { text-transform: uppercase; }
input[type="number"], input[type="date"], input[inputmode="numeric"], .mob-input, #visit_date_display { text-transform: none; }
.visit-date-picker-wrap { position: relative; }
.native-date-picker { position: absolute; right: 0; top: 0; width: 38px; height: 31px; opacity: 0; cursor: pointer; z-index: 10; pointer-events: auto; }
.receipt-preview-wrap, .receipt-preview-wrap * { text-transform: uppercase; }
@media print {
    @page { size: A5 landscape; margin: 0; }
    body * { visibility: hidden; }
    #hospitalReceipt, #hospitalReceipt * { visibility: visible; }
    #hospitalReceipt { position: absolute; left: 0; top: 0; width: 210mm; height: 148mm; margin: 0; padding: 5mm; box-sizing: border-box; background: #fff; border: none !important; box-shadow: none !important; overflow: hidden; }
    .no-print { display: none !important; }
}
.tariff-box { background-color: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 15px; text-align: center; }
.patient-lookup-box { font-size: 10px; line-height: 1.25; }
#patientSelectionModal .modal-content { border: 0; border-radius: 14px; box-shadow: 0 15px 45px rgba(15, 23, 42, .22); }
#patientSelectionModal .modal-header { background: #eff6ff; border-bottom: 1px solid #dbeafe; }
.patient-popup-item { width: 100%; text-align: left; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 10px 12px; margin-bottom: 8px; transition: .15s ease; cursor: pointer; }
.patient-popup-item:hover { border-color: #60a5fa; background: #f8fbff; transform: translateY(-1px); }
.patient-popup-item .name { font-weight: 900; font-size: 12px; color: #0f172a; }
.patient-popup-item .meta, .patient-popup-item .uhid { font-size: 10px; color: #64748b; margin-top: 3px; }
.patient-popup-item .uhid { color: #2563eb; font-weight: 800; margin-top: 4px; }
.patient-lookup-box .patient-option { cursor: pointer; }
.patient-lookup-box .patient-option:hover { background: #eef6ff; }
#patient_lookup_result { position: relative; z-index: 20; }
.modal-a5 { width: 220mm; max-width: calc(100vw - 24px); margin: 20px auto; }
.modal-a5 .modal-content { max-height: calc(100vh - 40px); overflow: hidden; border-radius: 10px; }
.modal-a5 .modal-body { overflow: auto; padding: 8px; background: #e9ecef; }
.receipt-preview-wrap { width: 210mm; height: 148mm; max-width: none; min-width: 210mm; margin: 0 auto; padding: 5mm; background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.18); overflow: hidden; box-sizing: border-box; }
.receipt-preview-wrap .receipt-sheet { width:100%; max-width:200mm; margin:0 auto; font-family:Arial,sans-serif; color:#111; font-size:11px; }
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
@media (max-width: 800px) { .modal-a5 { width: calc(100vw - 16px); max-width: calc(100vw - 16px); margin: 8px auto; } .modal-a5 .modal-content { max-height: calc(100vh - 16px); } .modal-a5 .modal-body { padding: 4px; } .receipt-preview-wrap { width: 148mm; height: 148mm; transform-origin: top left; } }
.no-print #parcheTable td.text-end.pe-3 { vertical-align: middle !important; white-space: nowrap; }
.no-print #parcheTable td.text-end.pe-3 > .btn, .no-print #parcheTable td.text-end.pe-3 > a.btn { display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important; position: relative !important; top: 0 !important; margin-top: 0 !important; margin-bottom: 0 !important; line-height: 1 !important; }
.no-print #parcheTable td.text-end.pe-3 > a.btn { margin-left: 4px !important; }
.no-print .card { border-radius: 8px !important; }
.no-print .card-header, .no-print > .col-12 > .card > .py-2 { min-height: 36px; padding: 6px 10px !important; }
.no-print .card-body { padding: 10px !important; }
.no-print .row.g-3 { --bs-gutter-x: .65rem; --bs-gutter-y: .55rem; }
.no-print .row.g-2 { --bs-gutter-x: .5rem; --bs-gutter-y: .4rem; }
.no-print .form-label { font-size: 10px !important; line-height: 1.15; margin-bottom: 3px !important; }
.no-print .form-control, .no-print .form-select, .no-print .input-group-text, .no-print .btn { min-height: 30px; height: 30px; font-size: 11px !important; padding-top: 3px; padding-bottom: 3px; }
.no-print .input-group-sm > .form-control, .no-print .input-group-sm > .form-select, .no-print .input-group-sm > .input-group-text, .no-print .input-group-sm > .btn { min-height: 30px; height: 30px; }
.no-print .form-control::placeholder { font-size: 10px; }
.no-print .form-text, .no-print small { line-height: 1.15; }
.no-print #mobile_wrapper .mob-row { margin-bottom: 3px !important; }
.no-print #mobile_wrapper .input-group-text { min-width: 40px !important; font-size: 9px !important; }
.no-print #patient_lookup_result { font-size: 10px; }
.no-print .border-bottom.pb-3 { padding-bottom: 8px !important; margin-bottom: 8px !important; }
.no-print .tariff-box { padding: 8px 10px !important; border-radius: 7px !important; }
.no-print .tariff-box .text-secondary { font-size: 9px !important; line-height: 1.1; }
.no-print #display_tariff_fee { font-size: 1.45rem !important; line-height: 1; margin: 4px 0 8px !important; }
.no-print .tariff-box .btn { margin-bottom: 5px !important; }
.no-print .tariff-box .btn-success { padding-top: 5px !important; padding-bottom: 5px !important; }
.no-print #parcheTable { font-size: 10px !important; }
.no-print #parcheTable th, .no-print #parcheTable td { padding: 5px 6px !important; line-height: 1.15; white-space: nowrap; }
.no-print #parcheTable th { font-size: 9px !important; font-weight: 700; }
.no-print #parcheTable td .fs-6 { font-size: 12px !important; }
.no-print #parcheTable .btn { min-width: 28px; padding-left: 6px !important; padding-right: 6px !important; }
.no-print #searchParcheInput { max-width: 240px; }
.no-print #opdForm > .row { margin-bottom: 6px !important; }
.no-print #opdForm > .row:first-of-type { align-items: flex-start; }
.no-print #opdForm > .row:first-of-type > [class*="col-md-"] { align-self: flex-start; }
.no-print #patient_uhid, .no-print #gender, .no-print #primary_mobile { margin-top: 0 !important; }
.no-print #mobile_wrapper .mob-row { margin-top: 0 !important; }
.no-print #mobile_wrapper .mob-input { min-width: 0; }
.no-print #mobile_wrapper .input-group { width: 100%; }
.no-print #opdForm .mb-3 { margin-bottom: 7px !important; }
.no-print #opdForm .mb-2 { margin-bottom: 5px !important; }
#patientSelectionModal .modal-header { padding: 8px 10px !important; }
#patientSelectionModal .modal-body { padding: 10px !important; }
#patientSelectionModal .patient-popup-item { padding: 7px 9px; margin-bottom: 5px; border-radius: 7px; }
#patientSelectionModal .patient-popup-item .name { font-size: 11px; }
#patientSelectionModal .patient-popup-item .meta, #patientSelectionModal .patient-popup-item .uhid { font-size: 9px; }
#paymentModal .modal-content { border-radius: 8px !important; }
#paymentModal .modal-header { padding: 6px 10px !important; }
#paymentModal .modal-body { padding: 10px !important; }
#paymentModal .row.g-2 { --bs-gutter-x: .5rem; --bs-gutter-y: .4rem; }
#paymentModal .mb-3 { margin-bottom: 7px !important; }
#paymentModal .mb-2 { margin-bottom: 5px !important; }
#paymentModal .p-2 { padding: 6px !important; }
#successPrintModal .modal-body { padding: 14px !important; }
#successPrintModal .mb-3 { margin-bottom: 7px !important; }
#successPrintModal .row.g-2 { --bs-gutter-x: .4rem; --bs-gutter-y: .4rem; }
#successPrintModal .bg-light.rounded-3 { padding: 6px !important; border-radius: 6px !important; }
#successPrintModal h4 { font-size: 1.2rem !important; }
#successPrintModal .btn { min-height: 30px; height: 30px; }
.no-print #visit_date_display, .no-print #dob_display { height: 25px !important; min-height: 25px !important; font-size: 9px !important; padding: 1px 5px !important; }
.no-print #visit_date_calendar_btn, .no-print #dob_calendar_btn { height: 25px !important; min-height: 25px !important; width: 27px !important; padding: 1px 4px !important; }
.no-print #dob_display { text-transform: none !important; }
.no-print .dob-age-row { display: flex; align-items: flex-start; gap: 6px; width: 100%; }
.no-print .dob-age-row .dob-control { width: 185px !important; max-width: 185px !important; flex: 0 0 185px !important; min-width: 185px !important; }
.no-print .dob-age-row .dob-control .input-group { width: 185px !important; max-width: 185px !important; }
.no-print .dob-age-row .dob-control #dob_display { width: 158px !important; max-width: 158px !important; flex: 0 0 158px !important; }
.no-print .dob-age-row .age-control { width: 92px; flex: 0 0 92px; }
.no-print .dob-age-row .age-entry-label { display: none !important; }
.no-print .dob-age-row .age-control { width: 92px !important; flex: 0 0 92px !important; padding-top: 0 !important; margin-top: 0 !important; }
.no-print .dob-age-row .age-control .input-group { margin-top: 0 !important; height: 31px !important; }
.no-print .dob-age-row .age-control .form-control { height: 31px !important; min-height: 31px !important; }
.no-print .dob-age-row .age-control .input-group-text { font-size: 8px !important; padding-left: 4px !important; padding-right: 4px !important; height: 31px !important; }

/* Payment methods are part of the billing screen, not a second popup. */
#paymentModal{display:block!important;visibility:visible!important;position:static!important;inset:auto!important;overflow:visible!important;background:transparent!important;opacity:1!important;z-index:auto!important}
#paymentModal .modal-dialog{width:100%!important;max-width:none!important;margin:10px 0!important;transform:none!important}
#paymentModal .modal-content{border:1px solid #cbd5e1!important;border-radius:10px!important;box-shadow:none!important}
#paymentModal .modal-body{max-height:none!important;overflow:visible!important}
#paymentModal .btn-close{display:none!important}
/* Compact payment-date calendar: smaller footprint while keeping month/year navigation visible. */
.flatpickr-calendar.payment-date-calendar-compact,
.flatpickr-calendar.payment-date-calendar-compact.open {
    width: 248px !important;
    padding: 4px !important;
    z-index: 999999 !important;
    box-sizing: border-box !important;
    font-size: 11px !important;
}
.flatpickr-calendar.payment-date-calendar-compact .flatpickr-months {
    height: 30px !important;
    display: flex !important;
    align-items: center !important;
}
.flatpickr-calendar.payment-date-calendar-compact .flatpickr-months .flatpickr-month {
    height: 30px !important;
}
.flatpickr-calendar.payment-date-calendar-compact .flatpickr-current-month {
    padding-top: 5px !important;
    font-size: 12px !important;
    height: 30px !important;
}
.flatpickr-calendar.payment-date-calendar-compact .flatpickr-current-month input.cur-year,
.flatpickr-calendar.payment-date-calendar-compact .flatpickr-current-month .flatpickr-monthDropdown-months {
    font-size: 12px !important;
}
.flatpickr-calendar.payment-date-calendar-compact .flatpickr-weekdays,
.flatpickr-calendar.payment-date-calendar-compact span.flatpickr-weekday {
    height: 24px !important;
    line-height: 24px !important;
    font-size: 10px !important;
}
.flatpickr-calendar.payment-date-calendar-compact .flatpickr-days,
.flatpickr-calendar.payment-date-calendar-compact .dayContainer {
    width: 238px !important;
    min-width: 238px !important;
    max-width: 238px !important;
}
.flatpickr-calendar.payment-date-calendar-compact .flatpickr-day {
    height: 30px !important;
    line-height: 30px !important;
    max-width: 34px !important;
    flex-basis: 14.2857% !important;
    font-size: 11px !important;
}
body.opd-embedded-registration .flatpickr-calendar.payment-date-calendar-compact {
    position: absolute !important;
}
html:has(body.opd-embedded-frame), body.opd-embedded-frame{height:100% !important; overflow:hidden !important}
body.opd-embedded-frame{margin:0 !important; padding:5px !important; overflow-x:hidden !important; overflow-y:hidden !important}
body.opd-embedded-frame #paymentModal{margin-top:4px}
</style>

<?php if ($embedded_mode && $appointment_id > 0): ?>
<style>
/* Appointment-linked mode: hide fields already entered on Appointment Desk while keeping values in the form submission. */
body.opd-embedded-registration .patient-info-balanced { display:none !important; }
body.opd-embedded-registration .row.g-3.mb-3 > .col-md-8 .row.g-2 > div:has(#department_id),
body.opd-embedded-registration .row.g-3.mb-3 > .col-md-8 .row.g-2 > div:has(#doctor_id) { display:none !important; }
body.opd-embedded-registration .opd-payment-hero { display:none !important; }
body.opd-embedded-registration { padding:5px !important; font-size:11px !important; }
body.opd-embedded-registration .card { margin-bottom:6px !important; border-radius:7px !important; }
body.opd-embedded-registration .card-header { padding:5px 9px !important; min-height:0 !important; }
body.opd-embedded-registration .card-body { padding:7px !important; }
body.opd-embedded-registration .embedded-patient-summary { padding:5px 8px !important; margin-bottom:6px !important; gap:12px !important; font-size:11px !important; }
body.opd-embedded-registration .embedded-patient-summary .small { font-size:9px !important; }
body.opd-embedded-registration .row { --bs-gutter-x:.45rem; --bs-gutter-y:.35rem; }
body.opd-embedded-registration .form-label { margin-bottom:2px !important; font-size:9px !important; line-height:1.1 !important; }
body.opd-embedded-registration .form-control,
body.opd-embedded-registration .form-select,
body.opd-embedded-registration .input-group-text,
body.opd-embedded-registration .btn { min-height:27px !important; height:27px !important; padding:3px 6px !important; font-size:10px !important; }
body.opd-embedded-registration .tariff-box { padding:6px !important; gap:2px !important; }
body.opd-embedded-registration .tariff-box h1 { font-size:20px !important; margin:1px 0 4px !important; }
body.opd-embedded-registration .tariff-box select { max-width:100% !important; margin-bottom:4px !important; }
body.opd-embedded-registration .mb-3 { margin-bottom:5px !important; }
body.opd-embedded-registration .mb-2 { margin-bottom:4px !important; }
body.opd-embedded-registration .pb-2 { padding-bottom:4px !important; }
body.opd-embedded-registration .pt-2 { padding-top:4px !important; }
body.opd-embedded-registration #paymentModal { margin-top:4px !important; }
body.opd-embedded-registration #paymentModal .modal-content { border-radius:7px !important; }
body.opd-embedded-registration #paymentModal .modal-header { padding:5px 8px !important; }
body.opd-embedded-registration #paymentModal .modal-body { padding:7px !important; }
body.opd-embedded-registration #paymentModal .mb-3 { margin-bottom:5px !important; }
body.opd-embedded-registration #paymentModal .mb-2 { margin-bottom:3px !important; }
body.opd-embedded-registration #paymentModal .row.g-2 { --bs-gutter-x:.4rem; --bs-gutter-y:.3rem; }
body.opd-embedded-registration #paymentModal .form-check { min-height:0 !important; margin-bottom:2px !important; }
body.opd-embedded-registration #paymentModal .form-check-label,
body.opd-embedded-registration #paymentModal .small { font-size:10px !important; }
body.opd-embedded-registration #paymentModal .form-control,
body.opd-embedded-registration #paymentModal .input-group-text { min-height:25px !important; height:25px !important; padding:2px 5px !important; font-size:10px !important; }
body.opd-embedded-registration #paymentModal .bg-primary.bg-opacity-10 { padding:5px 7px !important; margin-bottom:5px !important; }
body.opd-embedded-registration #paymentModal h5 { font-size:15px !important; }
body.opd-embedded-registration #paymentModal .btn { min-height:29px !important; height:auto !important; padding:5px 7px !important; font-size:10px !important; }
body.opd-embedded-registration .embedded-prefill-note { display:block; }

/* Compact, polished inline payment panel for appointment-linked billing. */
body.opd-embedded-registration #paymentModal { margin-top:3px !important; }
body.opd-embedded-registration #paymentModal .modal-dialog { margin:3px 0 !important; }
body.opd-embedded-registration #paymentModal .modal-content { border:1px solid #dbe4f0 !important; border-radius:9px !important; background:#fff !important; overflow:hidden; }
body.opd-embedded-registration #paymentModal .modal-header { min-height:0 !important; background:#f8fafc !important; border-bottom:1px solid #e2e8f0 !important; padding:5px 9px !important; }
body.opd-embedded-registration #paymentModal .modal-title { font-size:11px !important; letter-spacing:.02em; }
body.opd-embedded-registration #paymentModal .modal-body { padding:7px 9px !important; }
body.opd-embedded-registration #paymentModal .modal-body > .d-flex:first-child { background:#f8fafc; border:1px solid #e7edf5; border-radius:6px; padding:5px 8px; margin-bottom:6px !important; }
body.opd-embedded-registration #paymentModal .row.g-2.mb-3 { margin-bottom:6px !important; padding-bottom:6px !important; }
body.opd-embedded-registration #paymentModal .bg-primary.bg-opacity-10 { border:1px solid #dbeafe; border-radius:7px !important; background:#eff6ff !important; padding:6px 9px !important; margin-bottom:7px !important; }
body.opd-embedded-registration #paymentModal .bg-primary.bg-opacity-10 > span { font-size:11px !important; }
body.opd-embedded-registration #paymentModal h5 { font-size:16px !important; }
body.opd-embedded-registration #paymentModal .payment-methods-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:6px; margin:0 0 7px; }
body.opd-embedded-registration #paymentModal .payment-method-card { min-width:0; border:1px solid #e2e8f0; border-radius:7px; background:#fbfdff; padding:6px; margin:0 !important; }
body.opd-embedded-registration #paymentModal .payment-method-card .form-check { display:flex; align-items:center; gap:4px; padding-left:0; margin:0 0 5px !important; }
body.opd-embedded-registration #paymentModal .payment-method-card .form-check-input { float:none; margin:0; flex:0 0 auto; }
body.opd-embedded-registration #paymentModal .payment-method-card .form-check-label { font-size:9px !important; font-weight:800 !important; line-height:1.15; }
body.opd-embedded-registration #paymentModal .payment-method-card .ps-4 { padding-left:0 !important; }
body.opd-embedded-registration #paymentModal .payment-method-card .input-group { flex-wrap:nowrap; }
body.opd-embedded-registration #paymentModal .payment-method-card .input-group-text { padding:2px 5px !important; }
body.opd-embedded-registration #paymentModal .payment-method-card .form-control { min-width:0; width:100%; }
body.opd-embedded-registration #paymentModal .payment-method-card input[type=text] { margin-top:4px; }
body.opd-embedded-registration #paymentModal .payment-totals-strip { display:grid; grid-template-columns:1fr 1fr; gap:10px; border-color:#e2e8f0 !important; background:#f8fafc; border-radius:6px; padding:5px 8px; margin:0 0 7px !important; }
body.opd-embedded-registration #paymentModal .payment-totals-strip > div { margin:0 !important; }
body.opd-embedded-registration #paymentModal .btn-success { min-height:30px !important; height:30px !important; padding:4px 9px !important; border-radius:6px !important; box-shadow:none !important; }
body.opd-embedded-registration #paymentModal .modal-dialog { max-width:100% !important; margin:2px 0 !important; }
body.opd-embedded-registration #paymentModal .payment-totals-strip { padding:4px 7px !important; font-size:9px !important; }
body.opd-embedded-registration #paymentModal .payment-method-card { padding:4px 5px !important; }
body.opd-embedded-registration #paymentModal .payment-method-card .form-check { margin-bottom:3px !important; }
body.opd-embedded-registration #paymentModal .btn-success { display:block !important; width:auto !important; min-width:210px !important; max-width:250px !important; margin:5px auto 0 !important; min-height:28px !important; height:28px !important; padding:3px 12px !important; font-size:10px !important; }
body.opd-embedded-registration .card-body { padding:5px 7px !important; }
body.opd-embedded-registration #paymentModal .payment-methods-grid { gap:4px !important; margin-bottom:5px !important; }
body.opd-embedded-registration #paymentModal .payment-method-card { padding:3px 4px !important; border-radius:5px !important; }
body.opd-embedded-registration #paymentModal .payment-method-card .form-check-label { font-size:8px !important; }
body.opd-embedded-registration #paymentModal .payment-method-card .form-check { margin-bottom:2px !important; }
body.opd-embedded-registration #paymentModal .payment-method-card .form-control,
body.opd-embedded-registration #paymentModal .payment-method-card .input-group-text { height:23px !important; min-height:23px !important; font-size:10px !important; }
body.opd-embedded-registration #paymentModal .payment-method-card input[type=text] { height:22px !important; min-height:22px !important; margin-top:3px !important; }
body.opd-embedded-registration #paymentModal .bg-primary.bg-opacity-10 { margin-bottom:5px !important; padding:5px 8px !important; }
body.opd-embedded-registration #paymentModal .payment-totals-strip { margin-top:4px !important; margin-bottom:5px !important; }
body.opd-embedded-registration .embedded-patient-summary { margin-bottom:4px !important; }
body.opd-embedded-registration #paymentModal { width:100% !important; }
body.opd-embedded-registration #paymentModal .modal-body { min-height:0 !important; }
body.opd-embedded-registration #opdForm .form-control[aria-readonly="true"] { display:flex; align-items:center; min-height:27px !important; height:27px !important; }
body.opd-embedded-frame { min-height:100% !important; }
@media (max-width: 760px) { body.opd-embedded-registration #paymentModal .payment-methods-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('opd-embedded-registration');
    document.querySelectorAll('.patient-info-balanced [required]').forEach(function (el) { el.removeAttribute('required'); });
});
</script>
<?php endif; ?>

<?php if (!empty($err)): ?>
    <div id="opdRegistrationErrorMessage" class="d-none"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- REGISTERED FAMILY MEMBER SELECTION POPUP -->
<div class="modal fade" id="patientSelectionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content overflow-hidden">
            <div class="modal-header py-3">
                <div>
                    <div class="fw-bold text-primary">REGISTERED PATIENTS FOUND</div>
                    <div class="small text-muted">THIS MOBILE NUMBER IS USED BY MULTIPLE FAMILY MEMBERS.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="small text-secondary fw-semibold mb-2">
                    SELECT THE CORRECT FAMILY MEMBER TO CONTINUE WITH THEIR EXISTING UHID.
                </div>
                <div id="patientSelectionList"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">CANCEL</button>
            </div>
        </div>
    </div>
</div>

<?php if ($token_generated): ?>
    <!-- SUCCESS POPUP MODAL FOR NEW REGISTRATION -->
    <div class="modal fade" id="successPrintModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-body p-4 text-center">
                    <div class="mb-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem;"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">OPD Payment Saved!</h5>
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
                        <button type="button" class="btn btn-light border fw-semibold" data-bs-dismiss="modal" onclick="if(window.parent && window.parent !== window){window.parent.postMessage({type:'opd-payment-saved'}, '*');}">Done / Return to Appointment</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>


<style>
/* OPD Payment refresh: modern compact billing workspace; business logic remains unchanged. */
.opd-payment-hero{background:linear-gradient(120deg,#fff 0%,#eff6ff 100%);border:1px solid #dbeafe;border-radius:14px;padding:16px 18px;box-shadow:0 4px 16px rgba(15,23,42,.045)}
.opd-payment-icon{width:48px;height:48px;border-radius:13px;background:#2563eb;color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 6px 14px rgba(37,99,235,.2)}
.opd-payment-eyebrow{font-size:10px;letter-spacing:1.2px;font-weight:800;color:#2563eb;margin-bottom:3px}
.opd-payment-hero h4{font-size:21px;color:#0f172a}
#legacyBillList{display:none!important}
body:has(.opd-payment-hero) .card{border-radius:12px!important;overflow:hidden}
body:has(.opd-payment-hero) .card-header{background:#fff!important}
body:has(.opd-payment-hero) .form-label{font-size:10px;letter-spacing:.25px;color:#475569!important}
body:has(.opd-payment-hero) .form-control,body:has(.opd-payment-hero) .form-select{border-color:#cbd5e1;border-radius:7px;min-height:34px}
body:has(.opd-payment-hero) .form-control:focus,body:has(.opd-payment-hero) .form-select:focus{border-color:#60a5fa;box-shadow:0 0 0 .16rem rgba(37,99,235,.12)}
@media(max-width:767px){.opd-payment-hero>div{align-items:flex-start!important;flex-wrap:wrap}.opd-payment-hero .flex-grow-1{min-width:calc(100% - 70px)}.opd-payment-hero .btn{flex:1}}
</style>

<!-- OPD PAYMENT WORKSPACE -->
<div class="opd-payment-hero no-print mb-3">
  <div class="d-flex align-items-center gap-3">
    <div class="opd-payment-icon"><i class="bi bi-wallet2"></i></div>
    <div class="flex-grow-1">
      <div class="opd-payment-eyebrow">OPD SERVICES / BILLING DESK</div>
      <h4 class="mb-1 fw-bold">OPD Payment</h4>
      <div class="text-secondary small">Register a patient, generate UHID when required, collect consultation payment and save the receipt.</div>
    </div>
    <a href="opd_bills.php" class="btn btn-outline-primary btn-sm fw-semibold"><i class="bi bi-receipt me-1"></i> OPD Bills</a>
    <a href="opd_appointments.php" class="btn btn-primary btn-sm fw-semibold"><i class="bi bi-calendar-check me-1"></i> Appointment Desk</a>
  </div>
</div>

<!-- REGISTRATION FORM -->
<div class="row g-3 no-print">
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-person-plus text-primary me-2"></i><?= $edit_data ? 'Edit OPD Visit #' . $edit_data['visit_id'] : ($appointment_prefill ? 'Appointment Check-In / OPD Payment' : 'OPD Payment & Patient Registration') ?></h6>
                <?php if ($embedded_mode): ?><span class="badge bg-primary-subtle text-primary">APPOINTMENT DETAILS LINKED</span><?php endif; ?>
                <?php if ($edit_data): ?>
                    <a href="opd_registration.php" class="btn btn-sm btn-outline-secondary py-0">Cancel Edit</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-3">
                <?php if ($embedded_mode && $appointment_prefill): ?>
                <div class="embedded-patient-summary d-flex flex-wrap align-items-center gap-3 mb-3 px-3 py-2 rounded-3 border bg-primary bg-opacity-10">
                  <div><span class="small text-secondary d-block">PATIENT NAME</span><strong><?= htmlspecialchars($appointment_prefill['master_fullname'] ?? $appointment_prefill['patient_name'] ?? '') ?></strong></div>
                  <div><span class="small text-secondary d-block">UHID</span><strong class="text-primary font-monospace"><?= htmlspecialchars($appointment_prefill['master_uhid'] ?? $appointment_prefill['uhid'] ?? 'UHID will be generated') ?></strong></div>
                  <div class="ms-auto small text-secondary">Appointment #<?= (int)$appointment_prefill['appointment_id'] ?></div>
                </div>
                <?php endif; ?>
                <form method="POST" id="opdForm">
                    <input type="hidden" name="register_patient" value="1">
                    <input type="hidden" name="post_edit_id" value="<?= $edit_data['visit_id'] ?? 0 ?>">
                    <input type="hidden" name="appointment_id" value="<?= (int)($appointment_prefill['appointment_id'] ?? 0) ?>">
                    <input type="hidden" name="existing_patient_id" id="existing_patient_id" value="<?= (int)($edit_data['patient_id'] ?? 0) ?>">
                    
                    <input type="hidden" name="consultation_fee" id="consultation_fee" value="<?= $edit_data['consultation_fee'] ?? 0 ?>">
                    <input type="hidden" name="modal_discount" id="modal_discount" value="<?= $edit_data['discount_amount'] ?? 0 ?>">
                    <input type="hidden" name="discount_percent" id="hidden_discount_percent" value="<?= $edit_data['discount_percent'] ?? 0 ?>">
                    <input type="hidden" name="modal_total_paid" id="modal_total_paid" value="<?= $edit_data['amount_paid'] ?? 0 ?>">
                    <input type="hidden" name="modal_change_return" id="modal_change_return" value="<?= $edit_data['change_return'] ?? 0 ?>">
                    <input type="hidden" name="payment_breakdown_json" id="payment_breakdown_json" value="<?= htmlspecialchars($edit_data['payment_breakdown'] ?? '') ?>">
                    <input type="hidden" name="summary_payment_mode" id="summary_payment_mode" value="<?= htmlspecialchars($edit_data['payment_mode'] ?? 'CASH: ₹0') ?>">
                    <input type="hidden" name="payment_date" id="payment_date" value="<?= htmlspecialchars($edit_data['payment_date'] ?? date('Y-m-d')) ?>">

                    <!-- PATIENT INFO -->
                    <!-- TOP ROW: ONLY VISIT DATE + MOBILE -->
                    <div class="row g-2 mb-2 border-bottom pb-2 patient-info-balanced">
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold text-secondary">Visit Date *</label>
                            <div class="visit-date-picker-wrap" style="width:100%; max-width:145px;">
                                <div class="input-group input-group-sm" style="width:145px; max-width:145px; flex-wrap:nowrap;">
                                    <input type="hidden" name="visit_date" id="visit_date" value="<?= htmlspecialchars($edit_data['visit_date'] ?? ($appointment_prefill['appointment_date'] ?? date('Y-m-d'))) ?>">
                                    <input type="text" id="visit_date_display" class="form-control text-dark fw-bold" style="width:118px; max-width:118px; flex:0 0 118px; height:25px; min-height:25px; font-size:10px; padding:2px 5px;" value="<?= htmlspecialchars(!empty($edit_data['visit_date']) ? date('d-m-Y', strtotime($edit_data['visit_date'])) : (!empty($appointment_prefill['appointment_date']) ? date('d-m-Y', strtotime($appointment_prefill['appointment_date'])) : date('d-m-Y'))) ?>" placeholder="DD-MM-YYYY" maxlength="10" autocomplete="off" inputmode="text" required>
                                    <button type="button" class="btn btn-outline-primary" id="visit_date_calendar_btn" style="width:27px; min-width:27px; max-width:27px; height:25px; min-height:25px; padding:1px 3px;" title="Select Visit Date" onclick="openVisitDatePicker()">
                                        <i class="bi bi-calendar3"></i>
                                    </button>
                                </div>
                                <input type="date" id="visit_date_picker" class="native-date-picker" value="<?= htmlspecialchars($edit_data['visit_date'] ?? ($appointment_prefill['appointment_date'] ?? date('Y-m-d'))) ?>" aria-label="Select Visit Date">
                            </div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Mobile Number(s) *</label>
                            <div id="mobile_wrapper" style="width:100%;">
                                <?php
                                $saved_mobs = array_filter(
                                    array_map('trim', explode(',', $edit_data['mobile']
                                        ?? ($appointment_prefill['master_mobile']
                                        ?? ($appointment_prefill['mobile'] ?? ''))))
                                );
                                if (empty($saved_mobs)) $saved_mobs = [''];
                                $i = 0;
                                foreach ($saved_mobs as $mob):
                                    $len = strlen($mob);
                                ?>
                                    <div class="input-group input-group-sm mb-1 mob-row">
                                        <input type="text" name="mobile[]" id="<?= $i === 0 ? 'primary_mobile' : '' ?>" class="form-control form-control-sm mob-input" value="<?= htmlspecialchars($mob) ?>" placeholder="Mobile No" maxlength="10" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, ''); updateMobCount(this, <?= $i ?>); <?= $i === 0 ? 'lookupPatientByMobile(this.value)' : '' ?>" <?= $i === 0 ? 'required' : '' ?>>
                                        <span class="input-group-text <?= $len == 10 ? 'text-success fw-bold' : 'text-muted' ?>" id="mob_count_<?= $i ?>" style="font-size:0.7rem; min-width:45px; text-align:center;">
                                            <?= $len ?>/10
                                        </span>
                                        <?php if ($i === 0): ?>
                                            <button type="button" class="btn btn-outline-primary" onclick="addMobileField()"><i class="bi bi-plus-lg"></i></button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.mob-row').remove()"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                <?php $i++; endforeach; ?>
                            </div>
                            <div id="patient_lookup_result" class="mt-1 d-none" aria-hidden="true"></div>
                        </div>
                    </div>

                    <!-- SECOND ROW: PATIENT NAME + UHID + GENDER + DOB/AGE -->
                    <div class="row g-2 mb-3 border-bottom pb-2 patient-info-balanced">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mt-1 mb-1">Patient Full Name *</label>
                            <input type="text" name="fullname" class="form-control form-control-sm text-capitalize" value="<?= htmlspecialchars($edit_data['fullname'] ?? ($appointment_prefill['master_fullname'] ?? ($appointment_prefill['patient_name'] ?? ''))) ?>" placeholder="Patient Name" required autofocus>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-semibold text-secondary mt-1">UHID</label>
                            <input type="text" id="patient_uhid" class="form-control form-control-sm bg-light font-monospace text-muted" value="<?= htmlspecialchars($edit_data['uhid'] ?? ($appointment_prefill['master_uhid'] ?? '')) ?>" placeholder="Auto Generated" readonly>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-semibold mt-1">Gender *</label>
                            <?php $current_gender = $edit_data['gender'] ?? ($appointment_prefill['master_gender'] ?? ($appointment_prefill['gender'] ?? '')); ?>
                            <select name="gender" id="gender" class="form-select form-select-sm" required>
                                <option value="" disabled <?= $current_gender === '' ? 'selected' : '' ?>>-- Select Gender --</option>
                                <option value="Male" <?= $current_gender === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $current_gender === 'Female' ? 'selected' : '' ?>>Female</option>
                                <option value="Other" <?= $current_gender === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>

                        <div class="col-md-5">
                            <?php
                            $current_dob = $edit_data['dob'] ?? ($appointment_prefill['master_dob'] ?? ($appointment_prefill['dob'] ?? ''));
                            $current_age = $edit_data['age'] ?? ($appointment_prefill['master_age'] ?? ($appointment_prefill['age'] ?? ''));

                            // Fix: Agar DOB blank hai lekin Age majood hai (Appointment se), toh DOB calculate karke set karein
                            if ($current_dob === '' && $current_age !== '') {
                                $base_date = $edit_data['visit_date'] ?? ($appointment_prefill['appointment_date'] ?? date('Y-m-d'));
                                $current_dob = date('Y-m-d', strtotime($base_date . " -{$current_age} years"));
                            } else if ($current_dob !== '') {
                                $current_age = calculateAgeFromDob($current_dob);
                            }
                            ?>
                            <label class="form-label small fw-semibold mt-1">Date of Birth / Age *</label>
                            <div class="dob-age-row">
                                <div class="visit-date-picker-wrap dob-control">
                                    <div class="input-group input-group-sm">
                                        <input type="hidden" name="dob" id="dob" value="<?= htmlspecialchars($current_dob) ?>">
                                        <input type="text" id="dob_display" class="form-control text-dark fw-bold" value="<?= htmlspecialchars($current_dob ? date('d-m-Y', strtotime($current_dob)) : '') ?>" placeholder="DD-MM-YYYY" maxlength="10" autocomplete="off" inputmode="text">
                                        <button type="button" class="btn btn-outline-primary" id="dob_calendar_btn" title="Select Date of Birth" onclick="openDobPicker()">
                                            <i class="bi bi-calendar3"></i>
                                        </button>
                                    </div>
                                    <input type="date" id="dob_picker" class="native-date-picker" value="<?= htmlspecialchars($current_dob) ?>" max="<?= date('Y-m-d') ?>" aria-label="Select Date of Birth">
                                </div>
                                <div class="age-control">
                                    <label class="form-label age-entry-label mb-1">Age</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" id="age" name="age" class="form-control form-control-sm" value="<?= $current_age !== '' && $current_age !== null ? (int)$current_age : '' ?>" min="0" max="150" step="1" placeholder="Age" inputmode="numeric" autocomplete="off">
                                        <span class="input-group-text">YRS</span>
                                    </div>
                                </div>
                            </div>
                        </div>
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
                                            <option value="<?= $dept['id'] ?>" <?= ((string)($edit_data['department_id'] ?? ($appointment_prefill['department_id'] ?? '')) === (string)$dept['id']) ? 'selected' : '' ?>><?= htmlspecialchars($dept['dept_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold text-primary">Doctor *</label>
                                    <select name="doctor_id" id="doctor_id" class="form-select form-select-sm" required onchange="calculateTariff()">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($doctors as $doc): ?>
                                            <option value="<?= $doc['id'] ?>" data-dept="<?= $doc['department_id'] ?>" <?= ((string)($edit_data['doctor_id'] ?? ($appointment_prefill['doctor_id'] ?? '')) === (string)$doc['id']) ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>
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
                                    <!-- FIX: Added AJAX Refresh Button for Categories & Insurances -->
                                    <label class="form-label small fw-semibold text-primary">
                                        Category *
                                        <a href="javascript:void(0)" onclick="refreshMasters()" class="ms-1 text-primary" title="Refresh Dropdowns">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </a>
                                    </label>
                                    <?php if ($embedded_mode && $appointment_id > 0):
                                        $selectedCategoryId = (int)($edit_data['category_id'] ?? $appointment_prefill['category_id'] ?? 0);
                                        $selectedCategoryName = '—';
                                        foreach ($categories as $categoryRow) {
                                            if ((int)$categoryRow['id'] === $selectedCategoryId) { $selectedCategoryName = $categoryRow['category_name']; break; }
                                        }
                                    ?>
                                        <div class="form-control form-control-sm bg-light fw-semibold" aria-readonly="true"><?= htmlspecialchars($selectedCategoryName) ?></div>
                                        <input type="hidden" name="category_id" id="category_id" value="<?= $selectedCategoryId ?>">
                                    <?php else: ?>
                                        <select name="category_id" id="category_id" class="form-select form-select-sm" required onchange="filterInsurances()">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= $cat['id'] ?>" <?= (((isset($edit_data['category_id']) && $edit_data['category_id'] == $cat['id']) || (!isset($edit_data['category_id']) && isset($appointment_prefill['category_id']) && $appointment_prefill['category_id'] == $cat['id']))) ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold text-primary">Insurance / Provider</label>
                                    <?php if ($embedded_mode && $appointment_id > 0):
                                        $selectedInsuranceId = (int)($edit_data['insurance_id'] ?? $appointment_prefill['insurance_id'] ?? 0);
                                        $selectedInsuranceName = 'GENERAL';
                                        foreach ($insurances as $insuranceRow) {
                                            if ((int)$insuranceRow['id'] === $selectedInsuranceId) { $selectedInsuranceName = $insuranceRow['insurance_name']; break; }
                                        }
                                    ?>
                                        <div class="form-control form-control-sm bg-light fw-semibold" aria-readonly="true"><?= htmlspecialchars($selectedInsuranceName) ?></div>
                                        <input type="hidden" name="insurance_id" id="insurance_id" value="<?= $selectedInsuranceId > 0 ? $selectedInsuranceId : '' ?>">
                                    <?php else: ?>
                                        <select name="insurance_id" id="insurance_id" class="form-select form-select-sm" onchange="calculateTariff()">
                                            <option value="">-- Base Category (No specific insurance) --</option>
                                            <?php foreach ($insurances as $ins): ?>
                                                <option value="<?= $ins['id'] ?>" data-cat="<?= $ins['category_id'] ?>" <?= (((isset($edit_data['insurance_id']) && $edit_data['insurance_id'] == $ins['id']) || (!isset($edit_data['insurance_id']) && isset($appointment_prefill['insurance_id']) && $appointment_prefill['insurance_id'] == $ins['id']))) ? 'selected' : '' ?>><?= htmlspecialchars($ins['insurance_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
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
                                <span class="d-block text-secondary small fw-bold mb-1">Billing Type</span>
                                <select name="billing_mode" id="billing_mode" class="form-select form-select-sm mb-2" style="max-width:260px;" onchange="applyBillingMode()">
                                    <option value="regular" selected>Consultation</option>
                                    <option value="followup">Follow-up</option>
                                </select>
                                <span class="d-block text-secondary small fw-bold mb-1">Applicable Tariff Fee</span>
                                <?php 
                                    $disp_fee = ($edit_data['consultation_fee'] ?? 0) - ($edit_data['discount_amount'] ?? 0);
                                    if($disp_fee < 0) $disp_fee = 0;
                                ?>
                                <h1 class="fw-bold text-dark mb-3" id="display_tariff_fee">₹<?= number_format($disp_fee, 2) ?></h1>
                                <!-- FIX: Validation Message Placeholder -->
                                <div id="tariff_warning_msg" class="text-danger small fw-bold lh-sm mb-2 text-center" style="display:none; font-size:10px;"></div>
                                
                                <?php if (!$embedded_mode): ?>
                                <button type="submit" class="btn btn-success btn-sm w-100 fw-bold shadow-sm py-2">
                                    <i class="bi <?= $edit_data ? 'bi-check-lg' : 'bi-ticket-detailed' ?> me-1"></i> <?= $edit_data ? 'Update Registration' : 'Register & Save' ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Legacy embedded bill list moved to admin/opd_bills.php -->
    <div class="col-12 mt-2" id="legacyBillList">
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
                                    $list_age = !empty($tv['dob']) ? calculateAgeFromDob($tv['dob']) : ($tv['age'] ?? '');
                                    $list_fy = getCurrentFY($tenant_pdo, $org_id, $center_id, $tv['visit_date']);
                                    $list_fy_formatted = formatFYWithHyphen($list_fy);
                                    $list_token = $list_fy_formatted . '/' . str_pad($tv['token_no'], 2, '0', STR_PAD_LEFT);
                                ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-bold fs-6 text-primary">T-<?= htmlspecialchars($tv['token_no']) ?></div>
                                        <div class="text-success" style="font-size:0.68rem;">R-<?= htmlspecialchars($list_fy_formatted . '/' . str_pad((int)($tv['receipt_no'] ?? 0), 2, '0', STR_PAD_LEFT)) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= !empty($tv['visit_date']) ? date('d-m-Y', strtotime($tv['visit_date'])) : '' ?></div>
                                        <?php if (!empty($tv['created_at'])): ?>
                                            <small class="text-muted"><?= date('h:i A', strtotime($tv['created_at'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark text-capitalize"><?= htmlspecialchars($tv['fullname'] ?? '') ?></div>
                                        <small class="text-muted"><?= htmlspecialchars((string)$list_age) ?> Yrs / <?= htmlspecialchars($tv['gender'] ?? '') ?></small>
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
                                            <?= htmlspecialchars(json_encode((!empty($tv['payment_date']) ? date('d-m-Y', strtotime($tv['payment_date'])) : date('d-m-Y', strtotime($tv['visit_date'] ?? 'now'))) . ' ' . (!empty($tv['created_at']) ? date('h:i A', strtotime($tv['created_at'])) : date('h:i A'))), ENT_QUOTES, 'UTF-8') ?>,
                                            <?= htmlspecialchars(json_encode($tv['payment_breakdown'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                            <?= htmlspecialchars(json_encode($tv['amount_paid'] ?? 0), ENT_QUOTES, 'UTF-8') ?>,
                                            <?= htmlspecialchars(json_encode($tv['change_return'] ?? 0), ENT_QUOTES, 'UTF-8') ?>,
                                            <?= htmlspecialchars(json_encode($tv['payment_status'] ?? 3), ENT_QUOTES, 'UTF-8') ?>
                                        )">
                                            <i class="bi bi-printer"></i>
                                        </button>
                                        <a href="opd_registration.php?edit_visit=<?= $tv['visit_id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2 ms-1 d-inline-flex align-items-center justify-content-center" title="Edit Registration">
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
    <div class="modal-dialog modal-dialog-centered" style="max-width: 360px;">
        <div class="modal-content border-0 shadow rounded-3">
            <div class="modal-header bg-light py-2 px-3 border-bottom">
                <h6 class="modal-title fw-bold text-dark m-0"><i class="bi bi-wallet2 text-primary me-2"></i>Payment Details</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div class="row g-2 align-items-end mb-2">
                    <div class="col-6">
                        <label for="payment_date_picker" class="form-label small fw-semibold text-secondary mb-1">Payment Date</label>
                        <input type="text" class="form-control form-control-sm" id="payment_date_picker" value="<?= htmlspecialchars(date('d-m-Y', strtotime($edit_data['payment_date'] ?? date('Y-m-d')))) ?>" placeholder="DD-MM-YYYY" autocomplete="off" required>
                    </div>
                    <div class="col-6 d-flex align-items-center justify-content-end pb-1">
                        <div class="text-end"><span class="fw-semibold text-secondary small d-block">Tariff Amount:</span><strong class="text-dark" id="modalTariffAmount">₹0.00</strong></div>
                    </div>
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
                
                <div class="payment-methods-grid">
                <!-- CASH PAYMENT BLOCK -->
                <div class="payment-method-card">
                    <div class="form-check mb-1">
                        <input class="form-check-input pay-check" type="checkbox" id="check_cash" <?= !$edit_data ? 'checked' : '' ?> onchange="togglePaymentInputs('cash')">
                        <label class="form-check-label fw-bold text-secondary small" for="check_cash">Cash Payment</label>
                    </div>
                    <div id="div_input_cash" style="<?= !$edit_data ? 'block' : 'none' ?>;" class="ps-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white fw-bold">₹</span>
                            <input type="number" id="amt_cash" class="form-control pay-amt fw-bold text-dark" value="<?= $edit_data['amount_paid'] ?? 0 ?>" placeholder="0" oninput="handlePaymentAmountChange(this)">
                        </div>
                    </div>
                </div>

                <!-- UPI PAYMENT BLOCK -->
                <div class="payment-method-card">
                    <div class="form-check mb-1">
                        <input class="form-check-input pay-check" type="checkbox" id="check_upi" onchange="togglePaymentInputs('upi')">
                        <label class="form-check-label fw-bold text-secondary small" for="check_upi">UPI Payment</label>
                    </div>
                    <div id="div_input_upi" style="display:none;" class="ps-4">
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text bg-white fw-bold">₹</span>
                            <input type="number" id="amt_upi" class="form-control pay-amt fw-bold text-dark" value="0" placeholder="0" oninput="handlePaymentAmountChange(this)">
                        </div>
                        <input type="text" id="utr_upi" class="form-control form-control-sm font-monospace" style="font-size: 0.75rem;" placeholder="Ref / UTR No.">
                    </div>
                </div>

                <!-- CARD PAYMENT BLOCK -->
                <div class="payment-method-card">
                    <div class="form-check mb-1">
                        <input class="form-check-input pay-check" type="checkbox" id="check_card" onchange="togglePaymentInputs('card')">
                        <label class="form-check-label fw-bold text-secondary small" for="check_card">Card Payment</label>
                    </div>
                    <div id="div_input_card" style="display:none;" class="ps-4">
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text bg-white fw-bold">₹</span>
                            <input type="number" id="amt_card" class="form-control pay-amt fw-bold text-dark" value="0" placeholder="0" oninput="handlePaymentAmountChange(this)">
                        </div>
                        <input type="text" id="utr_card" class="form-control form-control-sm font-monospace" style="font-size: 0.75rem;" placeholder="Txn Ref No.">
                    </div>
                </div>

                <!-- NET BANKING BLOCK -->
                <div class="payment-method-card">
                    <div class="form-check mb-1">
                        <input class="form-check-input pay-check" type="checkbox" id="check_net" onchange="togglePaymentInputs('net')">
                        <label class="form-check-label fw-bold text-secondary small" for="check_net">Net Banking</label>
                    </div>
                    <div id="div_input_net" style="display:none;" class="ps-4">
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text bg-white fw-bold">₹</span>
                            <input type="number" id="amt_net" class="form-control pay-amt fw-bold text-dark" value="0" placeholder="0" oninput="handlePaymentAmountChange(this)">
                        </div>
                        <input type="text" id="utr_net" class="form-control form-control-sm font-monospace" style="font-size: 0.75rem;" placeholder="UTR No.">
                    </div>
                </div>
                </div><!-- /.payment-methods-grid -->

                <div class="payment-totals-strip small border mb-2" style="display:flex;align-items:center;justify-content:space-between;gap:10px;background:#eff6ff;border:1px solid #bfdbfe!important;border-radius:7px;padding:6px 9px;margin-top:5px!important;">
                    <span class="text-primary fw-bold">TOTAL PAYMENT RECEIVED</span>
                    <strong class="text-primary fs-6" id="modalTotalPaid">₹<?= number_format($edit_data['amount_paid'] ?? 0, 2) ?></strong>
                    <span id="modalReturnChange" style="display:none" aria-hidden="true">₹0.00</span>
                </div>
                <?php if ($embedded_mode): ?>
                <button type="button" class="btn btn-success btn-sm w-100 fw-bold" onclick="saveEmbeddedOpdPayment()"><i class="bi bi-check-circle me-1"></i> Register &amp; Save OPD Payment</button>
                <?php else: ?>
                <button type="button" class="btn btn-primary btn-sm w-100 fw-semibold py-1" onclick="applyModalPayment()">Save Payment Details</button>
                <?php endif; ?>
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
                <button type="button" class="btn btn-outline-secondary fw-semibold" onclick="backToAppointmentDesk()"><i class="bi bi-arrow-left me-1"></i> Back to Appointment</button>
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary fw-bold" onclick="printReceiptFromPreview()">
                    <i class="bi bi-printer me-1"></i> Print A5 Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const errorNode = document.getElementById('opdRegistrationErrorMessage');
    if (errorNode && errorNode.textContent.trim()) {
        const message = errorNode.textContent.trim();
        if (window.Swal) {
            Swal.fire({
                icon: 'error',
                title: 'Unable to Complete',
                text: message,
                confirmButtonText: 'OK',
                confirmButtonColor: '#2563eb'
            });
        } else {
            window.alert(message);
        }
    }
});
</script>

<script>
function formatDisplayDateDMY(isoDate) {
    if (!isoDate) return '';
    const parts = String(isoDate).split('-');
    if (parts.length !== 3) return '';

    const yyyy = parts[0];
    const mm = parseInt(parts[1], 10);
    const dd = parts[2].padStart(2, '0');

    if (!mm || mm < 1 || mm > 12) return '';

    return `${dd}-${String(mm).padStart(2, '0')}-${yyyy}`;
}

function formatIsoDate(dateText) {
    const value = String(dateText || '').trim();

    let m = value.match(/^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/);
    let dd, mm, yyyy;

    if (m) {
        const months = { jan: '01', feb: '02', mar: '03', apr: '04', may: '05', jun: '06', jul: '07', aug: '08', sep: '09', oct: '10', nov: '11', dec: '12' };
        dd = m[1].padStart(2, '0');
        mm = months[m[2].toLowerCase()] || '';
        yyyy = m[3];
        if (!mm) return '';
    } else {
        m = value.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
        if (!m) return '';
        dd = m[1].padStart(2, '0');
        mm = m[2].padStart(2, '0');
        yyyy = m[3];
    }

    const d = new Date(`${yyyy}-${mm}-${dd}T00:00:00`);
    if ( Number.isNaN(d.getTime()) || d.getFullYear() !== Number(yyyy) || d.getMonth() + 1 !== Number(mm) || d.getDate() !== Number(dd) ) return '';
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

    syncFromIso(hidden.value || picker.value);

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
        if (!iso) { this.classList.add('is-invalid'); return; }
        syncFromIso(iso);
    });

    picker.addEventListener('change', function () {
        if (this.value) { syncFromIso(this.value); }
    });
}

function setupDobField() {
    const display = document.getElementById('dob_display');
    const hidden = document.getElementById('dob');
    const picker = document.getElementById('dob_picker');
    if (!display || !hidden || !picker) return;

    function syncFromIso(iso) {
        hidden.value = iso || '';
        picker.value = iso || '';
        display.value = iso ? formatDisplayDateDMY(iso) : '';
        display.classList.remove('is-invalid');
        calculateAgeFromDobUI();
    }

    if (hidden.value) syncFromIso(hidden.value);

    display.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9-]/g, '').slice(0, 10);
        const iso = formatIsoDate(this.value);
        if (iso) {
            hidden.value = iso;
            picker.value = iso;
            this.classList.remove('is-invalid');
            calculateAgeFromDobUI();
        } else {
            hidden.value = '';
            this.classList.add('is-invalid');
            calculateAgeFromDobUI();
        }
    });

    display.addEventListener('blur', function () {
        const iso = formatIsoDate(this.value);
        if (!iso) { this.classList.add('is-invalid'); return; }
        syncFromIso(iso);
    });

    picker.addEventListener('change', function () {
        if (this.value) syncFromIso(this.value);
    });
}

function openDobPicker() {
    const picker = document.getElementById('dob_picker');
    if (!picker) return;
    try { picker.focus({ preventScroll: true }); } catch (e) { picker.focus(); }
    try { if (typeof picker.showPicker === 'function') picker.showPicker(); else picker.click(); } catch (e) { picker.click(); }
}

function openVisitDatePicker() {
    const picker = document.getElementById('visit_date_picker');
    if (!picker) return;
    try { picker.focus({ preventScroll: true }); } catch (e) { picker.focus(); }
    try { if (typeof picker.showPicker === 'function') { picker.showPicker(); } else { picker.click(); } } catch (e) { picker.click(); }
}

document.addEventListener('DOMContentLoaded', function () {
    setupVisitDateField();
    setupDobField();
    setupAgeField();
    calculateAgeFromDobUI();

    const dobInput = document.getElementById('dob');
    if (dobInput) {
        dobInput.addEventListener('change', calculateAgeFromDobUI);
        dobInput.addEventListener('input', calculateAgeFromDobUI);
    }
    setInterval(calculateAgeFromDobUI, 60 * 60 * 1000);
});

function normalizeTypedTextToUppercase(root = document) {
    const fields = root.querySelectorAll('input[type="text"]:not(#visit_date_display):not(#dob_display), textarea');
    fields.forEach(field => {
        if ( field.id === 'searchParcheInput' || field.id === 'visit_date_display' || field.id === 'dob_display' || field.classList.contains('mob-input') || field.inputMode === 'numeric' ) return;
        field.addEventListener('input', function () {
            const start = this.selectionStart;
            const end = this.selectionEnd;
            this.value = this.value.toUpperCase();
            if (document.activeElement === this && start !== null && end !== null) {
                try { this.setSelectionRange(start, end); } catch (e) {}
            }
        });
        if (field.value) { field.value = field.value.toUpperCase(); }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    normalizeTypedTextToUppercase();
    const gender = document.getElementById('gender');
    if (gender && gender.value) {
        const normalized = String(gender.value).trim().toLowerCase();
        const map = { male: 'Male', female: 'Female', other: 'Other' };
        if (map[normalized]) gender.value = map[normalized];
    }
    if (gender) {
        gender.addEventListener('change', function () {
            const normalized = String(this.value || '').trim().toLowerCase();
            const map = { male: 'Male', female: 'Female', other: 'Other' };
            if (map[normalized]) this.value = map[normalized];
        });
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const primaryMobile = document.getElementById('primary_mobile');
    if ( primaryMobile && String(primaryMobile.value || '').replace(/\D/g, '').length === 10 && !document.getElementById('existing_patient_id')?.value ) {
        lookupPatientByMobile(primaryMobile.value);
    }
});

let dbTariffs = <?= json_encode($tariffs) ?>;
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

// ============================================================
// REGISTERED PATIENT LOOKUP BY PRIMARY MOBILE
// ============================================================
let patientLookupTimer = null;
let patientLookupCache = {};

function escapePatientHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
    });
}

function clearPatientSelection(clearPatientInfo = true) {
    const patientId = document.getElementById('existing_patient_id');
    const uhid = document.getElementById('patient_uhid');
    const resultBox = document.getElementById('patient_lookup_result');

    if (patientId) patientId.value = '';
    if (uhid) {
        uhid.value = '';
        uhid.classList.remove('text-success', 'fw-bold');
        uhid.classList.add('text-muted');
    }
    if (resultBox && clearPatientInfo) { resultBox.innerHTML = ''; }

    if (clearPatientInfo) {
        const name = document.querySelector('input[name="fullname"]');
        const dob = document.getElementById('dob');
        const dobDisplay = document.getElementById('dob_display');
        const dobPicker = document.getElementById('dob_picker');
        const age = document.getElementById('age');
        const ageHint = document.getElementById('age_live_hint');
        const gender = document.getElementById('gender');

        if (name) name.value = '';
        if (dob) dob.value = '';
        if (dobDisplay) dobDisplay.value = '';
        if (dobPicker) dobPicker.value = '';
        if (age) { age.value = ''; delete age.dataset.directEntry; }
        if (ageHint) ageHint.textContent = '--';
        if (gender) gender.value = '';
    }
}

function fillExistingPatient(patient) {
    const patientId = document.getElementById('existing_patient_id');
    const uhid = document.getElementById('patient_uhid');
    const name = document.querySelector('input[name="fullname"]');
    const dob = document.getElementById('dob');
    const dobDisplay = document.getElementById('dob_display');
    const dobPicker = document.getElementById('dob_picker');
    const age = document.getElementById('age');
    const ageHint = document.getElementById('age_live_hint');
    const gender = document.getElementById('gender');

    if (patientId) patientId.value = patient.patient_id || '';
    if (uhid) {
        uhid.value = patient.uhid || '';
        uhid.classList.remove('text-muted');
        uhid.classList.add('text-success', 'fw-bold');
    }

    if (name) {
        name.value = patient.fullname || '';
        name.dispatchEvent(new Event('input', { bubbles: true }));
    }

    if (dob) dob.value = patient.dob || '';
    if (dobDisplay) dobDisplay.value = patient.dob ? formatDisplayDateDMY(patient.dob) : '';
    if (dobPicker) dobPicker.value = patient.dob || '';

    if (age) {
        const calculatedAge = calculateAgeFromDobUI();
        if (calculatedAge === null && patient.age !== undefined) {
            age.value = patient.age || '';
            if (ageHint) ageHint.textContent = patient.age ? patient.age + ' YRS' : '--';
        }
    }

    if (gender) {
        const g = String(patient.gender || '').trim().toLowerCase();
        gender.value = g === 'male' ? 'Male' : (g === 'female' ? 'Female' : (g ? 'Other' : ''));
    }

    if (patient.mobile) {
        const mobiles = String(patient.mobile).split(',').map(v => v.trim()).filter(Boolean);
        const rows = document.querySelectorAll('#mobile_wrapper .mob-row');

        if (rows.length && mobiles.length) {
            rows[0].querySelector('.mob-input').value = mobiles[0];
            updateMobCount(rows[0].querySelector('.mob-input'), 0);
            for (let i = 1; i < rows.length && i < mobiles.length; i++) {
                const input = rows[i].querySelector('.mob-input');
                if (input) input.value = mobiles[i];
            }
        }
    }
}

function renderPatientLookupResults(patients) {
    const resultBox = document.getElementById('patient_lookup_result');
    if (!resultBox) return;

    patientLookupCache = {};
    patients.forEach(patient => { patientLookupCache[String(patient.patient_id)] = patient; });

    if (!patients.length) {
        clearPatientSelection(false);
        resultBox.innerHTML = '';
        return;
    }

    if (patients.length === 1) {
        fillExistingPatient(patients[0]);
        resultBox.innerHTML = '';
        return;
    }

    const list = document.getElementById('patientSelectionList');
    if (list) {
        list.innerHTML = patients.map(patient => `
            <button type="button" class="patient-popup-item" data-patient-id="${escapePatientHtml(patient.patient_id)}">
                <div class="name">${escapePatientHtml(patient.fullname)}</div>
                <div class="meta">${escapePatientHtml(patient.gender || '')}${patient.age ? ' • ' + escapePatientHtml(patient.age) + ' YRS' : ''}</div>
                <div class="uhid">UHID: ${escapePatientHtml(patient.uhid || '')}</div>
            </button>
        `).join('');

        list.querySelectorAll('.patient-popup-item').forEach(function(button) {
            button.addEventListener('click', function() { selectExistingPatientFromLookup(this.dataset.patientId); });
        });
    }

    const modal = document.getElementById('patientSelectionModal');
    if (modal) { bootstrap.Modal.getOrCreateInstance(modal).show(); }
}

function selectExistingPatientFromLookup(patientId) {
    const patient = patientLookupCache[String(patientId)];
    const resultBox = document.getElementById('patient_lookup_result');

    if (!patient) { clearPatientSelection(false); return; }
    fillExistingPatient(patient);
    if (resultBox) { resultBox.innerHTML = ''; }
    const modal = document.getElementById('patientSelectionModal');
    if (modal) {
        const instance = bootstrap.Modal.getInstance(modal);
        if (instance) instance.hide();
    }
}

function lookupPatientByMobile(mobile) {
    clearTimeout(patientLookupTimer);
    mobile = String(mobile || '').replace(/\D/g, '');

    const existingPatientId = document.getElementById('existing_patient_id');
    if (existingPatientId) existingPatientId.value = '';
    const uhid = document.getElementById('patient_uhid');
    if (uhid) {
        uhid.value = '';
        uhid.classList.remove('text-success', 'fw-bold');
        uhid.classList.add('text-muted');
    }

    const resultBox = document.getElementById('patient_lookup_result');
    if (!resultBox) return;

    if (mobile.length < 10) { resultBox.innerHTML = ''; return; }
    if (mobile.length !== 10) return;

    resultBox.innerHTML = '';

    patientLookupTimer = setTimeout(function() {
        fetch('opd_registration.php?lookup_patient_mobile=' + encodeURIComponent(mobile), { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(response => {
            if (!response.ok) throw new Error('Patient lookup request failed.');
            return response.json();
        })
        .then(data => {
            if (!data || !data.success) throw new Error(data && data.error ? data.error : 'Lookup failed.');
            renderPatientLookupResults(data.patients || []);
        })
        .catch(error => {
            console.error('Patient mobile lookup error:', error);
            resultBox.innerHTML = '';
        });
    }, 300);
}

function calculateAgeFromDobUI() {
    const dobInput = document.getElementById('dob');
    const ageInput = document.getElementById('age');
    if (!dobInput || !ageInput) return null;

    const dobValue = String(dobInput.value || '').trim();

    if (!dobValue) {
        if (!ageInput.dataset.directEntry) ageInput.value = '';
        return null;
    }

    const dob = new Date(dobValue + 'T00:00:00');
    if (Number.isNaN(dob.getTime())) return null;

    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const monthDiff = today.getMonth() - dob.getMonth();

    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
        age--;
    }

    if (age < 0 || age > 150) {
        ageInput.value = '';
        ageInput.classList.add('is-invalid');
        return null;
    }

    ageInput.classList.remove('is-invalid');
    ageInput.value = String(age);
    delete ageInput.dataset.directEntry;
    return age;
}

function setupAgeField() {
    const ageInput = document.getElementById('age');
    const dobInput = document.getElementById('dob');
    const dobDisplay = document.getElementById('dob_display');
    const dobPicker = document.getElementById('dob_picker');

    if (!ageInput || !dobInput) return;

    ageInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 3);
        if (this.value === '') {
            this.dataset.directEntry = '1';
            dobInput.value = '';
            if (dobDisplay) dobDisplay.value = '';
            if (dobPicker) dobPicker.value = '';
            return;
        }

        const age = parseInt(this.value, 10);
        if (Number.isNaN(age) || age < 0 || age > 150) {
            this.classList.add('is-invalid');
            return;
        }

        this.classList.remove('is-invalid');
        this.dataset.directEntry = '1';

        const visitIso = document.getElementById('visit_date')?.value || '';
        const base = visitIso ? new Date(visitIso + 'T00:00:00') : new Date();

        if (Number.isNaN(base.getTime())) return;
        const dob = new Date( base.getFullYear() - age, base.getMonth(), base.getDate() );
        const iso = [ dob.getFullYear(), String(dob.getMonth() + 1).padStart(2, '0'), String(dob.getDate()).padStart(2, '0') ].join('-');

        dobInput.value = iso;
        if (dobPicker) dobPicker.value = iso;
        if (dobDisplay) dobDisplay.value = formatDisplayDateDMY(iso);
    });

    ageInput.addEventListener('blur', function () {
        if (this.value === '') { delete this.dataset.directEntry; return; }
        const age = parseInt(this.value, 10);
        if (Number.isNaN(age) || age < 0 || age > 150) { this.classList.add('is-invalid'); return; }
        this.classList.remove('is-invalid');
        this.dataset.directEntry = '1';
    });
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

// FIX: AJAX Refresh Masters function
function refreshMasters() {
    fetch('opd_registration.php?ajax=refresh_masters')
    .then(res => res.json())
    .then(data => {
        dbTariffs = data.tariffs; 
        
        let catSelect = document.getElementById('category_id');
        let currentCat = catSelect.value;
        catSelect.innerHTML = '<option value="">-- Select --</option>';
        data.categories.forEach(c => {
            catSelect.innerHTML += `<option value="${c.id}">${c.category_name.toUpperCase()}</option>`;
        });
        catSelect.value = currentCat;

        let insSelect = document.getElementById('insurance_id');
        let allInsOptions = data.insurances.map(i => ({ value: i.id, text: i.insurance_name.toUpperCase(), cat: i.category_id }));
        insSelect.dataset.allOptions = JSON.stringify(allInsOptions);
        
        filterInsurances();
        
        const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 });
        Toast.fire({ icon: 'success', title: 'Dropdowns Refreshed' });
    });
}

function filterInsurances() {
    const catSelect = document.getElementById('category_id');
    const insSelect = document.getElementById('insurance_id');
    const catId = catSelect.value;
    const currentValue = insSelect.value;

    if (!insSelect.dataset.allOptions) {
        insSelect.dataset.allOptions = JSON.stringify(
            Array.from(insSelect.options).map(o => ({
                value: o.value, text: o.textContent, cat: o.getAttribute('data-cat') || ''
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

    const matching = allOptions.filter(o => o.value !== '' && String(o.cat) === String(catId));

    if (matching.length === 0) {
        const base = document.createElement('option');
        base.value = '';
        base.textContent = '-- Base Category (No specific insurance) --';
        insSelect.appendChild(base);
    } else if (matching.length === 1) {
        const o = document.createElement('option');
        o.value = matching[0].value;
        o.textContent = matching[0].text;
        o.setAttribute('data-cat', matching[0].cat);
        o.selected = true;
        insSelect.appendChild(o);
    } else {
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

function applyBillingMode() {
    const mode = document.getElementById('billing_mode')?.value || 'regular';
    const feeInput = document.getElementById('consultation_fee');
    const feeDisplay = document.getElementById('display_tariff_fee');
    const collectBtn = document.querySelector('button[data-bs-target="#paymentModal"]');
    const payMode = document.getElementById('summary_payment_mode');
    if (mode === 'followup') {
        if (feeInput) feeInput.value = '0';
        if (feeDisplay) feeDisplay.textContent = '₹0.00';
        document.getElementById('modal_discount').value = '0';
        document.getElementById('hidden_discount_percent').value = '0';
        document.getElementById('modal_total_paid').value = '0';
        document.getElementById('modal_change_return').value = '0';
        document.getElementById('payment_breakdown_json').value = JSON.stringify({'Follow-up': {amount: 0}});
        if (payMode) payMode.value = 'FOLLOW-UP - NO CHARGE';
        const summary = document.getElementById('paymentSummaryText'); if (summary) summary.textContent = 'FOLLOW-UP - NO CHARGE';
        if (collectBtn) collectBtn.disabled = true;
        const warning = document.getElementById('tariff_warning_msg'); if (warning) { warning.textContent = 'FOLLOW-UP: NO CONSULTATION FEE'; warning.style.display = 'block'; warning.classList.remove('text-danger'); warning.classList.add('text-success'); }
    } else {
        const warning = document.getElementById('tariff_warning_msg'); if (warning) { warning.classList.add('text-danger'); warning.classList.remove('text-success'); }
        if (collectBtn) collectBtn.disabled = false;
        calculateTariff();
    }
}

function calculateTariff() {
    if (document.getElementById('billing_mode')?.value === 'followup') { applyBillingMode(); return; }
    let docId = document.getElementById('doctor_id').value;
    let srvId = document.getElementById('service_id').value;
    let catId = document.getElementById('category_id').value;
    let insId = document.getElementById('insurance_id').value;

    let finalRate = 0;
    let isTariffFound = false;

    if (docId && srvId && catId) {
        let exactMatch = dbTariffs.find(t => t.doctor_id == docId && t.service_id == srvId && t.category_id == catId && t.insurance_id == insId);
        if (!exactMatch && insId) exactMatch = dbTariffs.find(t => t.doctor_id == docId && t.service_id == srvId && t.category_id == catId && (t.insurance_id == null || t.insurance_id == ''));
        if (exactMatch) {
            finalRate = parseFloat(exactMatch.rate);
            isTariffFound = true;
        }
    }

    document.getElementById('consultation_fee').value = finalRate;
    document.getElementById('modalTariffAmount').innerText = '₹' + finalRate.toFixed(2);

    // FIX: Disable Buttons if rate is not set
    const collectBtn = document.querySelector('button[data-bs-target="#paymentModal"]');
    const registerBtn = document.querySelector('#opdForm button[type="submit"]');
    const warnMsg = document.getElementById('tariff_warning_msg');

    if (docId && srvId && catId && !isTariffFound) {
        if(collectBtn) collectBtn.disabled = true;
        if(registerBtn) registerBtn.disabled = true;
        if(warnMsg) {
            warnMsg.innerText = "RATE NOT SET FOR THIS COMBINATION!";
            warnMsg.style.display = 'block';
        }
    } else {
        if(collectBtn) collectBtn.disabled = false;
        if(registerBtn) registerBtn.disabled = false;
        if(warnMsg) warnMsg.style.display = 'none';
    }
    
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
    const isAppointmentEmbedded = <?= ($embedded_mode && $appointment_id > 0) ? 'true' : 'false' ?>;
    if (isAppointmentEmbedded) {
        const form = document.getElementById('opdForm');
        const paymentPanel = document.getElementById('paymentModal');
        // Keep the original appointment-linked layout:
        // patient summary -> Service/Category/Insurance/Billing Type -> Payment Details.
        // Do not move the payment panel above the billing fields.
        const billingFieldsRow = form ? form.querySelector('.row.g-3.mb-3') : null;
        if (form && paymentPanel) {
            if (billingFieldsRow) {
                billingFieldsRow.insertAdjacentElement('afterend', paymentPanel);
            } else {
                form.appendChild(paymentPanel);
            }
            paymentPanel.classList.add('payment-panel-after-billing-fields');
        }
    }

    // Use the same Flatpickr calendar configuration as layout_footer.php.
    const paymentDateInput = document.getElementById('payment_date_picker');
    const paymentDateHidden = document.getElementById('payment_date');
    if (paymentDateInput && paymentDateHidden && typeof flatpickr !== 'undefined' && !paymentDateInput._flatpickr) {
        const isNewAppointmentPayment = isAppointmentEmbedded && Number(document.querySelector('input[name="post_edit_id"]')?.value || 0) === 0;
        const browserToday = new Date();
        const browserTodayIso = [
            browserToday.getFullYear(),
            String(browserToday.getMonth() + 1).padStart(2, '0'),
            String(browserToday.getDate()).padStart(2, '0')
        ].join('-');
        if (isNewAppointmentPayment) paymentDateHidden.value = browserTodayIso;

        flatpickr(paymentDateInput, {
            altInput: false,
            dateFormat: 'd-m-Y',
            defaultDate: isNewAppointmentPayment ? browserToday : (paymentDateHidden.value || new Date()),
            disableMobile: true,
            allowInput: false,
            // The appointment payment form is displayed in a short iframe.
            // Open the calendar upward so its full month grid stays inside the frame.
            position: 'above',
            onReady: function(selectedDates, dateStr, instance) {
                instance.calendarContainer.classList.add('payment-date-calendar-compact');
                if (selectedDates[0]) paymentDateHidden.value = instance.formatDate(selectedDates[0], 'Y-m-d');
            },
            onChange: function(selectedDates, dateStr, instance) {
                paymentDateHidden.value = selectedDates[0] ? instance.formatDate(selectedDates[0], 'Y-m-d') : '';
            }
        });
    }

    const opdForm = document.getElementById('opdForm');
    if (opdForm) {
        opdForm.addEventListener('submit', function(e) {
            // FIX: Explicit Mandatory Fields Alert
            let missingFields = [];
            if (!document.getElementById('visit_date_display').value) missingFields.push('Visit Date');
            if (!document.querySelector('input[name="fullname"]').value.trim()) missingFields.push('Patient Full Name');
            if (!document.getElementById('primary_mobile').value.trim()) missingFields.push('Mobile Number');
            if (!document.getElementById('gender').value) missingFields.push('Gender');
            if (!document.getElementById('dob_display').value && !document.getElementById('age').value) missingFields.push('Date of Birth / Age');
            if (!document.getElementById('doctor_id').value) missingFields.push('Doctor');
            if (!document.getElementById('service_id').value) missingFields.push('Service');
            if (!document.getElementById('category_id').value) missingFields.push('Category');

            if (missingFields.length > 0) {
                e.preventDefault(); 
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Mandatory Fields',
                    html: 'Please fill out the following required fields to continue:<br><br><b>' + missingFields.join('<br>') + '</b>',
                    confirmButtonColor: '#2563eb'
                });
                return false;
            }

            if (typeof syncDiscountHiddenFields === 'function') {
                syncDiscountHiddenFields();
            }

            const selectedModes = document.querySelectorAll('.pay-check:checked');
            if (selectedModes.length === 0) {
                const fee = parseFloat(document.getElementById('consultation_fee')?.value) || 0;
                const discount = parseFloat(document.getElementById('modal_discount_input')?.value) || 0;
                const payable = Math.max(0, fee - discount);

                const cashCheck = document.getElementById('check_cash');
                const cashInput = document.getElementById('amt_cash');
                const cashWrap = document.getElementById('div_input_cash');

                if (cashCheck && cashInput && payable > 0) {
                    cashCheck.checked = true;
                    if (cashWrap) cashWrap.style.display = 'block';
                    cashInput.value = payable.toFixed(2);

                    document.getElementById('modal_total_paid').value = payable.toFixed(2);
                    document.getElementById('modal_change_return').value = '0.00';
                    document.getElementById('payment_breakdown_json').value = JSON.stringify({
                        Cash: { amount: payable }
                    });
                    document.getElementById('summary_payment_mode').value = 'CASH: ₹' + payable.toFixed(2);
                }
            }
        });
    }

    const cat = document.getElementById('category_id');
    if (cat && cat.value && !<?= ($embedded_mode && $appointment_id > 0) ? 'true' : 'false' ?>) filterInsurances();
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
    const check = document.getElementById('check_' + changedMode);
    const amountWrap = document.getElementById('div_input_' + changedMode);
    const amountInput = document.getElementById('amt_' + changedMode);
    const isChecked = !!check.checked;

    amountWrap.style.display = isChecked ? 'block' : 'none';
    if (isChecked) {
        // Newly selected mode starts blank; keep existing mode amount ready to rebalance.
        amountInput.value = '';
        lastPaymentMode = changedMode;
        amountInput.focus();
    } else {
        amountInput.value = '';
        const utr = document.getElementById('utr_' + changedMode);
        if (utr) utr.value = '';
    }
    calculateModalTotals(false);
}

let lastPaymentMode = 'cash';

function handlePaymentAmountChange(changedInput) {
    const changedMode = changedInput.id.replace('amt_', '');
    const fee = parseFloat(document.getElementById('consultation_fee').value) || 0;
    const discount = parseFloat(document.getElementById('modal_discount_input').value) || 0;
    const payable = Math.max(0, fee - discount);

    let entered = parseFloat(changedInput.value) || 0;
    if (entered < 0) entered = 0;
    if (entered > payable) entered = payable;
    changedInput.value = entered === 0 && changedInput.value === '' ? '' : entered;

    const modes = ['cash', 'upi', 'card', 'net'];
    const otherChecked = modes.filter(mode =>
        mode !== changedMode && document.getElementById('check_' + mode).checked
    );

    // Keep the bill's total aligned with the payable amount: the amount just
    // entered is respected, and the previously used checked mode gets the balance.
    if (otherChecked.length > 0) {
        const otherSum = otherChecked.reduce((sum, mode) =>
            sum + (parseFloat(document.getElementById('amt_' + mode).value) || 0), 0);
        const targetOtherTotal = Math.max(0, payable - entered);

        if (otherSum > targetOtherTotal) {
            let excess = otherSum - targetOtherTotal;
            // Reduce other modes from the most recently used backwards.
            const reduceOrder = [...otherChecked].reverse();
            for (const mode of reduceOrder) {
                const input = document.getElementById('amt_' + mode);
                const value = parseFloat(input.value) || 0;
                const reduction = Math.min(value, excess);
                input.value = Math.max(0, value - reduction);
                excess -= reduction;
                if (excess <= 0) break;
            }
        } else if (otherSum < targetOtherTotal) {
            const balanceMode = otherChecked.includes(lastPaymentMode)
                ? lastPaymentMode
                : otherChecked[0];
            const input = document.getElementById('amt_' + balanceMode);
            input.value = ((parseFloat(input.value) || 0) + (targetOtherTotal - otherSum)).toFixed(2);
        }
    }
    lastPaymentMode = changedMode;
    calculateModalTotals(false);
}

function calculateModalTotals(fromDiscountChange) {
    let fee = parseFloat(document.getElementById('consultation_fee').value) || 0;
    let discountInput = document.getElementById('modal_discount_input');
    
    let discount = parseFloat(discountInput.value) || 0;
    if(discount < 0) { discount = 0; discountInput.value = 0; }
    
    let payable = fee - discount;
    if(payable < 0) payable = 0;
    
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

function saveEmbeddedOpdPayment() {
    const paymentDatePicker = document.getElementById('payment_date_picker');
    const paymentDateHidden = document.getElementById('payment_date');
    if (paymentDatePicker && paymentDateHidden) {
        const dateParts = paymentDatePicker.value.trim().split('-');
        if (dateParts.length === 3 && dateParts[0].length === 2 && dateParts[1].length === 2 && dateParts[2].length === 4) {
            paymentDateHidden.value = `${dateParts[2]}-${dateParts[1]}-${dateParts[0]}`;
        }
    }
    // Apply the selected payment modes to hidden fields, then submit the one OPD form.
    applyModalPayment();
    const form = document.getElementById('opdForm');
    if (!form) {
        alert('OPD form was not found. Please refresh and try again.');
        return;
    }
    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
    } else {
        form.submit();
    }
}

function applyModalPayment() {
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

    if (modes.length === 0 && payable > 0) {
        const cashCheck = document.getElementById('check_cash');
        const cashWrap = document.getElementById('div_input_cash');
        const cashInput = document.getElementById('amt_cash');

        cashCheck.checked = true;
        cashWrap.style.display = 'block';
        cashInput.value = payable.toFixed(2);

        cash = payable;
        totalPaid = payable;
        returnChange = 0;
        modes.push('CASH: ₹' + cash.toFixed(2));
        breakdown.Cash = { amount: cash };
    }

    let summaryStr = modes.length > 0 ? modes.join(' | ') : 'Unpaid (₹0)';

    const paymentSummary = document.getElementById('paymentSummaryText');
    if (paymentSummary) paymentSummary.innerText = summaryStr;
    document.getElementById('modal_total_paid').value = totalPaid;
    document.getElementById('modal_change_return').value = returnChange > 0 ? returnChange : 0;
    document.getElementById('payment_breakdown_json').value = JSON.stringify(breakdown);
    document.getElementById('summary_payment_mode').value = summaryStr;
}

<?php if ($token_generated): ?>
document.addEventListener("DOMContentLoaded", function() {
    // Show the receipt directly after a successful save: no intermediate success popup.
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.pathname + (window.location.search.includes('embedded=1') ? '?embedded=1' : ''));
    }
    const form = document.getElementById('opdForm');
    if(form) form.reset();
    clearPatientSelection(true);
    const tariff = document.getElementById('display_tariff_fee');
    if (tariff) tariff.innerText = '₹0.00';
    const paymentSummary = document.getElementById('paymentSummaryText');
    if (paymentSummary) paymentSummary.innerText = 'Collect Payment';
    showGeneratedReceipt();
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
    const safe = (v) => upper(v).replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[c]));

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

    const remarkRow = data.remark ? `<tr><th>Remarks</th><td colspan="3">${safe(data.remark)}</td></tr>` : '';

    return `
    <div class="receipt-sheet">
        <div class="receipt-header">
            <div class="hospital">${safe(orgName)}</div>
            <div class="center">${safe(centerName)}</div>
            <div class="address">${safe(centerAddress)}</div>
            <div class="receipt-title">OPD CONSULTATION RECEIPT</div>
        </div>
        <div class="token-box">
            <div>
                <div class="small-label">TOKEN NO.</div>
                <div class="token">${safe(data.token)}</div>
                <div style="font-size:10px;font-weight:700;margin-top:4px;">RECEIPT NO. ${safe(data.receiptNo)}</div>
            </div>
            <div class="date-block"><b>Receipt Date</b><br>${safe(data.datetime)}</div>
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
            <tr class="grand"><td>Payment Status</td><td class="amount">${statusLabel}</td></tr>
        </table>
        <div class="instructions"><b>Instructions</b><br>${receiptInstructions}</div>
    </div>`;
}

function backToAppointmentDesk() {
    const receiptModal = document.getElementById('receiptPreviewModal');
    if (receiptModal) bootstrap.Modal.getOrCreateInstance(receiptModal).hide();
    const successModal = document.getElementById('successPrintModal');
    if (successModal) bootstrap.Modal.getOrCreateInstance(successModal).hide();

    if (window.parent && window.parent !== window) {
        window.parent.postMessage({type: 'opd-payment-saved', action: 'back-to-appointment'}, '*');
        return;
    }
    window.location.href = 'opd_appointments.php';
}

let opdReceiptPrintStarted = false;
function printReceiptFromPreview() {
    if (!currentReceiptHtml || opdReceiptPrintStarted) return;
    opdReceiptPrintStarted = true;

    let frame = document.getElementById('opdReceiptPrintFrame');
    if (!frame) {
        frame = document.createElement('iframe');
        frame.id = 'opdReceiptPrintFrame';
        frame.title = 'OPD Receipt Print';
        frame.style.position = 'fixed';
        frame.style.right = '0';
        frame.style.bottom = '0';
        frame.style.width = '0';
        frame.style.height = '0';
        frame.style.border = '0';
        frame.style.visibility = 'hidden';
        document.body.appendChild(frame);
    }

    const doc = frame.contentWindow.document;
    doc.open();
    doc.write(`<!doctype html><html><head><title>OPD Receipt</title>
<style>
@page{size:A5 landscape;margin:5mm}
*{box-sizing:border-box}
html,body{width:200mm;margin:0;padding:0}
body{font-family:Arial,sans-serif;color:#111;background:#fff;font-size:10px}
.receipt-sheet{width:200mm;max-width:200mm;margin:0 auto}
.receipt-header{text-align:center;border-bottom:2px solid #111;padding-bottom:5px;margin-bottom:6px}
.hospital{font-size:16px;font-weight:800;text-transform:uppercase}
.center{font-size:11px;font-weight:700;margin-top:2px}
.address{font-size:8px;color:#555;margin-top:2px}
.receipt-title{font-size:10px;font-weight:800;margin-top:5px}
.token-box{display:flex;justify-content:space-between;align-items:center;border:1px solid #aaa;background:#f7f7f7;padding:5px;margin-bottom:6px}
.small-label{font-size:7px;font-weight:700;color:#666}
.token{font-size:18px;font-weight:800}
.date-block{text-align:right;font-size:8px;line-height:1.4}
.receipt-table{width:100%;border-collapse:collapse}
.receipt-table th,.receipt-table td{border:1px solid #bbb;padding:3px;vertical-align:top}
.info-table th{width:20%;text-align:left;background:#f1f3f5;font-weight:700}
.billing-table td:first-child{width:65%}
.section-title{font-size:9px;font-weight:800;margin:5px 0 3px;border-bottom:1px solid #222;padding-bottom:2px}
.amount{text-align:right;font-weight:700;white-space:nowrap}
.discount{color:#c62828}
.grand td{font-weight:800;background:#f3f4f6}
.billing-table small{color:#666;font-weight:400}
.instructions{margin-top:6px;padding-top:4px;border-top:1px dashed #999;font-size:8px;line-height:1.3}
</style></head><body>${currentReceiptHtml}</body></html>`);
    doc.close();

    const returnAfterPrint = () => {
        if (!opdReceiptPrintStarted) return;
        opdReceiptPrintStarted = false;
        backToAppointmentDesk();
    };
    // afterprint fires when the user closes the system print dialog (Print or Cancel).
    frame.contentWindow.onafterprint = returnAfterPrint;
    frame.onload = function() {
        frame.onload = null;
        setTimeout(() => {
            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (e) {
                opdReceiptPrintStarted = false;
                alert('Print dialog could not be opened. Please try again.');
            }
        }, 150);
    };
}
function printExistingParcha(name, uhid, ageGender, mobile, doctor, fee, discount, discountPercent, mode, token, receiptNo, remark, datetime, paymentBreakdownRaw, amountPaid, changeReturn, statusCode) {
    openReceiptPreview(name, uhid, ageGender, mobile, doctor, fee, discount, discountPercent, mode, token, receiptNo, remark, datetime, paymentBreakdownRaw, amountPaid, changeReturn, statusCode);
}
</script>

<?php if ($embedded_mode): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
<?php else: ?>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
<?php endif; ?>
