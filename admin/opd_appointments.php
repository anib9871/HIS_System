<?php

// admin/opd_appointments.php

$page_title = "OPD Appointment Desk";

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php'; 



$msg = "";

$err = "";

$org_id = (int)($_SESSION['org_id'] ?? 1);

$center_id = (int)($_SESSION['center_id'] ?? 1);

$created_by = (int)($_SESSION['user_id'] ?? 0);

$today_date = date('Y-m-d');



try {
    // Schema-safe migrations for MySQL/MariaDB versions that do not support
    // ALTER TABLE ... ADD COLUMN IF NOT EXISTS.
    $ensureColumn = function (string $table, string $column, string $definition) use ($tenant_pdo): void {
        $check = $tenant_pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $check->execute([$table, $column]);
        if ((int)$check->fetchColumn() === 0) {
            $tenant_pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    };

    $ensureColumn('opd_appointments', 'uhid', 'VARCHAR(50) NULL');
    $ensureColumn('opd_appointments', 'age_unit', "VARCHAR(10) NOT NULL DEFAULT 'years'");
    $ensureColumn('opd_appointments', 'created_by', 'INT NULL');
    $ensureColumn('opd_appointments', 'category_id', 'INT NULL');
    $ensureColumn('opd_appointments', 'insurance_id', 'INT NULL');
    // Token is allocated at booking time, so it must exist on appointments.
    $ensureColumn('opd_appointments', 'token_no', 'INT NULL');
    $ensureColumn('opd_queue_tracking', 'waiting_since', 'DATETIME NULL');
} catch (Throwable $e) {
    // Do not hide the page behind a migration warning; the query below will
    // show any genuinely missing required schema. The migration is retried on next load.
}


// ==============================================================

 // PATIENT LOOKUP

 // ==============================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_lookup') {

    header('Content-Type: application/json; charset=utf-8');

    $mobile_lookup = preg_replace('/\D+/', '', $_GET['mobile'] ?? '');



    if (strlen($mobile_lookup) < 10) {

        echo json_encode(['found' => false]);

        exit;

    }



    try {

        $s = $tenant_pdo->prepare("

            SELECT patient_id, uhid, fullname, gender, age, mobile

            FROM patient_master

            WHERE org_id = ?

              AND center_id = ?

              AND (

                    mobile = ?

                    OR FIND_IN_SET(?, REPLACE(mobile, ' ', '')) > 0

                  )

            ORDER BY fullname ASC, patient_id ASC

        ");

        $s->execute([$org_id, $center_id, $mobile_lookup, $mobile_lookup]);

        $patients = $s->fetchAll(PDO::FETCH_ASSOC);



        echo json_encode(

            !empty($patients)

                ? ['found' => true, 'count' => count($patients), 'patients' => $patients]

                : ['found' => false, 'count' => 0, 'patients' => []]

        );

    } catch (Exception $e) {

        echo json_encode(['found' => false]);

    }

    exit;

}



// ==============================================================

// AUTO-SYNC: OPD VISITS TO QUEUE TRACKING

// (Naya parcha banne par apne aap queue list me add ho jayega)

// ==============================================================

try {

    $tenant_pdo->exec("

        INSERT IGNORE INTO opd_queue_tracking (org_id, center_id, visit_id, status, waiting_since, in_time, out_time)

        SELECT org_id, center_id, visit_id, 'Waiting', NULL, NULL, NULL

        FROM opd_visits 

        WHERE visit_date = CURDATE() 

        AND visit_id NOT IN (SELECT visit_id FROM opd_queue_tracking)

    ");

} catch (Exception $e) {}



// ==============================================================

// 1. QUEUE TRACKING ACTIONS (Patient Present -> Call In -> Consultation Complete)
if (isset($_GET['q_action']) && isset($_GET['track_id'])) {
    $action = (string)$_GET['q_action'];
    $track_id = (int)$_GET['track_id'];

    try {
        if ($action === 'waiting') {
            $q = $tenant_pdo->prepare("UPDATE opd_queue_tracking
                SET waiting_since = COALESCE(waiting_since, NOW()), status = 'Waiting'
                WHERE track_id = ? AND org_id = ? AND center_id = ?
                  AND out_time IS NULL AND status <> 'Completed'");
            $q->execute([$track_id, $org_id, $center_id]);
            if (function_exists('set_flash_msg')) set_flash_msg($q->rowCount() ? "PATIENT PRESENT TIME CAPTURED!" : "PATIENT IS ALREADY COMPLETED OR PRESENT.");

        } elseif ($action === 'in') {
            $q = $tenant_pdo->prepare("UPDATE opd_queue_tracking
                SET waiting_since = COALESCE(waiting_since, NOW()),
                    status = 'Inside Cabin', in_time = COALESCE(in_time, NOW())
                WHERE track_id = ? AND org_id = ? AND center_id = ?
                  AND out_time IS NULL AND status <> 'Completed'");
            $q->execute([$track_id, $org_id, $center_id]);
            if (function_exists('set_flash_msg')) set_flash_msg($q->rowCount() ? "PATIENT CALLED IN — TIME CAPTURED!" : "PATIENT IS ALREADY COMPLETED.");

        } elseif ($action === 'out') {
            $q = $tenant_pdo->prepare("UPDATE opd_queue_tracking
                SET status = 'Completed', out_time = COALESCE(out_time, NOW())
                WHERE track_id = ? AND org_id = ? AND center_id = ?
                  AND status = 'Inside Cabin' AND out_time IS NULL");
            $q->execute([$track_id, $org_id, $center_id]);
            if ($q->rowCount()) {
                $sync = $tenant_pdo->prepare("UPDATE opd_appointments a
                    JOIN opd_visits v ON v.appointment_id = a.appointment_id
                    JOIN opd_queue_tracking qt ON qt.visit_id = v.visit_id
                    SET a.status = 'Completed'
                    WHERE qt.track_id = ? AND a.org_id = ? AND a.center_id = ?
                      AND v.org_id = ? AND v.center_id = ?");
                $sync->execute([$track_id, $org_id, $center_id, $org_id, $center_id]);
                if (function_exists('set_flash_msg')) set_flash_msg("CONSULTATION COMPLETED — CHECK-OUT TIME CAPTURED!");
            } else {
                if (function_exists('set_flash_msg')) set_flash_msg("CALL THE PATIENT IN BEFORE CHECK-OUT.");
            }
        }

        $returnDate = (string)($_GET['date_filter'] ?? $today_date);
        $returnDoctor = (int)($_GET['doctor_filter'] ?? 0);
        header("Location: opd_appointments.php?date_filter=" . urlencode($returnDate) . ($returnDoctor > 0 ? "&doctor_filter=" . $returnDoctor : "") . "#nav-appt");
        exit;
    } catch (Exception $e) { $err = $e->getMessage(); }
}

// EDIT APPOINTMENT LOAD

 // ==============================================================

$edit_appointment_id = (int)($_GET['edit_appointment'] ?? 0);

$edit_appointment = null;



if ($edit_appointment_id > 0) {

    try {

        $ea = $tenant_pdo->prepare("

            SELECT a.*,

                   COALESCE(p.fullname, a.patient_name) AS patient_display_name,

                   COALESCE(p.mobile, a.mobile) AS patient_display_mobile,

                   COALESCE(p.age, a.age) AS patient_display_age,

                   COALESCE(p.gender, a.gender) AS patient_display_gender,

                   p.uhid AS patient_uhid

            FROM opd_appointments a

            LEFT JOIN patient_master p ON a.uhid = p.uhid

            WHERE a.appointment_id = ?

              AND a.org_id = ?

              AND a.center_id = ?

            LIMIT 1

        ");

        $ea->execute([$edit_appointment_id, $org_id, $center_id]);

        $edit_appointment = $ea->fetch(PDO::FETCH_ASSOC) ?: null;



        if (!$edit_appointment) {

            $err = "Appointment not found.";

        } elseif (in_array($edit_appointment['status'], ['Cancelled'], true)) {

            $err = "Cancelled appointments cannot be edited.";

            $edit_appointment = null;

        }

    } catch (Exception $e) {

        $err = $e->getMessage();

    }

}



// ==============================================================

// 2. APPOINTMENT ACTIONS (Save / Update Status)

// ==============================================================

if (isset($_GET['update_status']) && isset($_GET['id'])) {

    $appt_id = (int)$_GET['id'];

    $new_status = $_GET['update_status'];

    if (in_array($new_status, ['Confirmed', 'Cancelled', 'Pending', 'Checked In'])) {

        $tenant_pdo->prepare("UPDATE opd_appointments SET status = ? WHERE appointment_id = ? AND org_id = ?")->execute([$new_status, $appt_id, $org_id]);

        header("Location: opd_appointments.php");

        exit;

    }

}



// ==============================================================

 // UPDATE APPOINTMENT

 // ==============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_appointment'])) {

    $edit_id        = (int)($_POST['appointment_id'] ?? 0);

    $patient_name   = strtoupper(trim($_POST['patient_name'] ?? ''));

    $mobile         = preg_replace('/\D+/', '', $_POST['mobile'] ?? '');

    $gender         = $_POST['gender'] ?? 'Male';

    $age            = (int)($_POST['age'] ?? 0);
    $age_unit       = strtolower((string)($_POST['age_unit'] ?? 'years'));
    if (!in_array($age_unit, ['years', 'months', 'days'], true)) $age_unit = 'years';

    $appt_date      = !empty($_POST['appointment_date']) ? $_POST['appointment_date'] : $today_date;

    $appt_time      = !empty($_POST['appointment_time']) ? $_POST['appointment_time'] : null;

    $department_id  = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;

    $doctor_id      = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;

    $remark         = trim($_POST['remark'] ?? '');

    $selected_uhid = trim((string)($_POST['uhid'] ?? ''));
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $insurance_id = !empty($_POST['insurance_id']) ? (int)$_POST['insurance_id'] : null;

    $new_family_member = (int)($_POST['new_family_member'] ?? 0);



    if ($edit_id > 0 && $patient_name !== '' && strlen($mobile) >= 10 && $doctor_id > 0) {

        try {

            $tenant_pdo->beginTransaction();



            $get_appt = $tenant_pdo->prepare("

                SELECT appointment_id, uhid, status

                FROM opd_appointments

                WHERE appointment_id = ? AND org_id = ? AND center_id = ?

                LIMIT 1

                FOR UPDATE

            ");

            $get_appt->execute([$edit_id, $org_id, $center_id]);

            $old_appt = $get_appt->fetch(PDO::FETCH_ASSOC);



            if (!$old_appt) {

                throw new Exception("Appointment not found.");

            }



            if (in_array($old_appt['status'], ['Cancelled'], true)) {

                throw new Exception("Cancelled appointments cannot be edited.");

            }



            // IMPORTANT: APPOINTMENT ONLY. NEVER UPDATE/CREATE PATIENT MASTER HERE.

            // Existing registered patient is referenced by UHID. New family member keeps UHID NULL.



            if ($selected_uhid !== '') {

                $find = $tenant_pdo->prepare("

                    SELECT patient_id, uhid, mobile, fullname, age, gender

                    FROM patient_master

                    WHERE uhid = ? AND org_id = ? AND center_id = ?

                    LIMIT 1

                ");

                $find->execute([$selected_uhid, $org_id, $center_id]);

                $patient = $find->fetch(PDO::FETCH_ASSOC);



                if (!$patient) {

                    throw new Exception("Selected patient was not found.");

                }



                $patientMobile = preg_replace('/\D+/', '', (string)$patient['mobile']);

                if ($patientMobile !== $mobile) {

                    throw new Exception("Selected family member does not match the entered mobile number.");

                }

                // Use authoritative registered details; never trust editable POST values.
                $patient_name = strtoupper(trim((string)$patient['fullname']));
                $age = (int)($patient['age'] ?? 0);
                $age_unit = 'years';
                $gender = (string)($patient['gender'] ?: 'Male');
                $selected_uhid = (string)$patient['uhid'];

            } elseif (!$new_family_member) {

                throw new Exception("PLEASE SELECT THE CORRECT FAMILY MEMBER OR CHOOSE NEW FAMILY MEMBER.");

            }



            $up = $tenant_pdo->prepare("

                UPDATE opd_appointments

                SET uhid = ?,

                    patient_name = ?,

                    mobile = ?,

                    age = ?,

                    gender = ?,

                    department_id = ?,

                    doctor_id = ?,

                    category_id = ?,

                    insurance_id = ?,

                    appointment_date = ?,

                    appointment_time = ?,

                    remark = ?

                WHERE appointment_id = ? AND org_id = ? AND center_id = ?

            ");

            $up->execute([

                ($selected_uhid !== '' ? $selected_uhid : null), $patient_name, $mobile, $age, $age_unit, $gender,

                $department_id, $doctor_id, $category_id, $insurance_id, $appt_date, $appt_time, $remark,

                $edit_id, $org_id, $center_id

            ]);



            $tenant_pdo->commit();

            if (function_exists('set_flash_msg')) set_flash_msg("Appointment Updated!");

            header("Location: opd_appointments.php?date_filter=" . urlencode($appt_date));

            exit;

        } catch (Exception $e) {

            if ($tenant_pdo->inTransaction()) $tenant_pdo->rollBack();

            $err = $e->getMessage();

            $edit_appointment_id = $edit_id;

        }

    } else {

        $err = "Patient Name, valid Mobile, and Doctor are mandatory.";

        $edit_appointment_id = $edit_id;

    }

}



// ==============================================================

// BOOK APPOINTMENT ONLY - NEVER CREATE/UPDATE PATIENT MASTER

// ==============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_appointment'])) {

    $patient_name  = strtoupper(trim($_POST['patient_name'] ?? ''));

    $mobile        = preg_replace('/\D+/', '', $_POST['mobile'] ?? '');

    $gender        = $_POST['gender'] ?? 'Male';

    $age           = (int)($_POST['age'] ?? 0);
    $age_unit      = strtolower((string)($_POST['age_unit'] ?? 'years'));
    if (!in_array($age_unit, ['years', 'months', 'days'], true)) $age_unit = 'years';

    $appt_date     = !empty($_POST['appointment_date']) ? $_POST['appointment_date'] : $today_date;

    $appt_time     = !empty($_POST['appointment_time']) ? $_POST['appointment_time'] : null;

    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;

    $doctor_id     = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;

    $remark        = trim($_POST['remark'] ?? '');

    $selected_uhid = trim((string)($_POST['uhid'] ?? ''));
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $insurance_id = !empty($_POST['insurance_id']) ? (int)$_POST['insurance_id'] : null;

    $new_family_member = (int)($_POST['new_family_member'] ?? 0);



    if ($patient_name !== '' && strlen($mobile) >= 10 && $doctor_id > 0) {

        try {

            $tenant_pdo->beginTransaction();



            if ($selected_uhid !== '') {

                // Existing patient: validate only. NEVER overwrite master fields.

                $find = $tenant_pdo->prepare("

                    SELECT patient_id, uhid, mobile, fullname, age, gender

                    FROM patient_master

                    WHERE uhid = ? AND org_id = ? AND center_id = ?

                    LIMIT 1

                ");

                $find->execute([$selected_uhid, $org_id, $center_id]);

                $patient = $find->fetch(PDO::FETCH_ASSOC);



                if (!$patient) {

                    throw new Exception("Selected patient was not found.");

                }



                $patientMobile = preg_replace('/\D+/', '', (string)$patient['mobile']);

                if ($patientMobile !== $mobile) {

                    throw new Exception("Selected family member does not match the entered mobile number.");

                }

                // Use authoritative registered details; never trust editable POST values.
                $patient_name = strtoupper(trim((string)$patient['fullname']));
                $age = (int)($patient['age'] ?? 0);
                $age_unit = 'years';
                $gender = (string)($patient['gender'] ?: 'Male');
                $selected_uhid = (string)$patient['uhid'];

            } else {

                // New/unregistered family member: check existing family/mobile first.

                if (!$new_family_member) {

                    $findExisting = $tenant_pdo->prepare("

                        SELECT uhid

                        FROM patient_master

                        WHERE org_id = ? AND center_id = ?

                          AND (mobile = ? OR FIND_IN_SET(?, REPLACE(mobile, ' ', '')) > 0)

                        LIMIT 1

                    ");

                    $findExisting->execute([$org_id, $center_id, $mobile, $mobile]);

                    $existing = $findExisting->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {

                        throw new Exception("THIS MOBILE IS ALREADY REGISTERED. PLEASE SELECT THE FAMILY MEMBER OR CHOOSE NEW FAMILY MEMBER.");

                    }

                }

            }



            // Generate UHID and create the patient master record at appointment booking for new patients.
            if ($selected_uhid === '') {
                $apptYear = (int)date('Y', strtotime($appt_date));
                $apptMonth = (int)date('m', strtotime($appt_date));
                $fyStart = $apptMonth >= 4 ? $apptYear : $apptYear - 1;
                $fyEnd = $fyStart + 1;
                $fyCode = substr((string)$fyStart, -2) . substr((string)$fyEnd, -2);
                $orgPrefix = 'MAX';
                $centerCode = 'LKO';
                try { $x = $tenant_pdo->query("SELECT mnemonic FROM org_profile LIMIT 1")->fetchColumn(); if ($x) $orgPrefix = strtoupper(trim($x)); } catch (Exception $ignore) {}
                try { $x = $tenant_pdo->prepare("SELECT center_code FROM master_centers WHERE center_id = ?"); $x->execute([$center_id]); $v = $x->fetchColumn(); if ($v) $centerCode = strtoupper(trim($v)); } catch (Exception $ignore) {}
                $uhidBase = $orgPrefix . '-' . $centerCode . '-' . $fyCode . '-';
                $lastStmt = $tenant_pdo->prepare("SELECT uhid FROM patient_master WHERE org_id = ? AND center_id = ? AND uhid LIKE ? ORDER BY patient_id DESC LIMIT 1 FOR UPDATE");
                $lastStmt->execute([$org_id, $center_id, $uhidBase . '%']);
                $lastUhid = (string)($lastStmt->fetchColumn() ?: '');
                $nextSerial = $lastUhid !== '' ? ((int)substr($lastUhid, strlen($uhidBase)) + 1) : 1;
                $selected_uhid = $uhidBase . $nextSerial;
                $newPatient = $tenant_pdo->prepare("INSERT INTO patient_master (org_id, center_id, uhid, fullname, dob, gender, age, mobile, status) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 1)");
                $newPatient->execute([$org_id, $center_id, $selected_uhid, $patient_name, $gender, $age, $mobile]);
            }

            // Allocate a doctor-wise token when the appointment is booked, not after payment.
            $lastApptToken = $tenant_pdo->prepare("SELECT COALESCE(MAX(token_no), 0) FROM opd_appointments WHERE org_id = ? AND center_id = ? AND doctor_id = ? AND appointment_date = ?");
            $lastApptToken->execute([$org_id, $center_id, $doctor_id, $appt_date]);
            $lastVisitToken = $tenant_pdo->prepare("SELECT COALESCE(MAX(token_no), 0) FROM opd_visits WHERE org_id = ? AND center_id = ? AND doctor_id = ? AND visit_date = ?");
            $lastVisitToken->execute([$org_id, $center_id, $doctor_id, $appt_date]);
            $appointment_token = max((int)$lastApptToken->fetchColumn(), (int)$lastVisitToken->fetchColumn()) + 1;

            $stmt = $tenant_pdo->prepare("
                INSERT INTO opd_appointments
                    (org_id, center_id, uhid, patient_name, mobile, age, age_unit, gender, department_id, doctor_id, category_id, insurance_id, token_no, appointment_date, appointment_time, status, remark, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)
            ");
            $stmt->execute([$org_id, $center_id, $selected_uhid, $patient_name, $mobile, $age, $age_unit, $gender, $department_id, $doctor_id, $category_id, $insurance_id, $appointment_token, $appt_date, $appt_time, $remark, $created_by]);



            $tenant_pdo->commit();

            if (function_exists('set_flash_msg')) {

                set_flash_msg($selected_uhid !== ''

                    ? "Appointment Booked For Registered Patient!"

                    : "Appointment Booked For New Family Member. Registration Will Create UHID.");

            }

            header("Location: opd_appointments.php?date_filter=" . urlencode($appt_date) . "&doctor_filter=" . (int)$doctor_id . "#nav-appt");

            exit;

        } catch (Exception $e) {

            if ($tenant_pdo->inTransaction()) $tenant_pdo->rollBack();

            $err = $e->getMessage();

        }

    } else {

        $err = "Patient Name, valid Mobile, and Doctor are mandatory.";

    }

}

// --- FETCH DROPDOWNS ---

$departments = $tenant_pdo->query("SELECT id, dept_name FROM master_departments WHERE org_id = $org_id AND center_id = $center_id AND status = 1")->fetchAll(PDO::FETCH_ASSOC);
$categories = $tenant_pdo->query("SELECT id, category_name FROM master_categories WHERE org_id = $org_id AND center_id = $center_id AND status = 1")->fetchAll(PDO::FETCH_ASSOC);
$insurances = $tenant_pdo->query("SELECT id, category_id, insurance_name FROM insurance WHERE org_id = $org_id AND center_id = $center_id AND status = 1 AND insurance_name IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

$doctors = $tenant_pdo->query("SELECT id, full_name, department_id FROM master_doctors WHERE org_id = $org_id AND center_id = $center_id AND status = 1")->fetchAll(PDO::FETCH_ASSOC);



// --- FETCH APPOINTMENTS ---

$date_filter = $_GET['date_filter'] ?? $today_date;

$appointments = $tenant_pdo->prepare("

    SELECT a.*,

           COALESCE(p.fullname, a.patient_name) AS patient_display_name,

           COALESCE(p.mobile, a.mobile) AS patient_display_mobile,

           COALESCE(p.uhid, '') AS patient_uhid,
           (SELECT qt.track_id
              FROM opd_visits vq
              JOIN opd_queue_tracking qt ON qt.visit_id = vq.visit_id
             WHERE vq.org_id = a.org_id
               AND vq.center_id = a.center_id
               AND (
                    vq.appointment_id = a.appointment_id
                    OR (vq.appointment_id IS NULL AND vq.patient_id = p.patient_id)
               )
               AND vq.doctor_id = a.doctor_id
               AND vq.visit_date = a.appointment_date
             ORDER BY (vq.appointment_id = a.appointment_id) DESC, vq.visit_id DESC LIMIT 1) AS queue_track_id,
           (SELECT qt.status
              FROM opd_visits vq
              JOIN opd_queue_tracking qt ON qt.visit_id = vq.visit_id
             WHERE vq.org_id = a.org_id
               AND vq.center_id = a.center_id
               AND (
                    vq.appointment_id = a.appointment_id
                    OR (vq.appointment_id IS NULL AND vq.patient_id = p.patient_id)
               )
               AND vq.doctor_id = a.doctor_id
               AND vq.visit_date = a.appointment_date
             ORDER BY (vq.appointment_id = a.appointment_id) DESC, vq.visit_id DESC LIMIT 1) AS queue_tracking_status,
           (SELECT qt.waiting_since FROM opd_visits vq JOIN opd_queue_tracking qt ON qt.visit_id = vq.visit_id
              WHERE vq.org_id = a.org_id AND vq.center_id = a.center_id
                AND (
                    vq.appointment_id = a.appointment_id
                    OR (vq.appointment_id IS NULL AND vq.patient_id = p.patient_id)
                )
                AND vq.doctor_id = a.doctor_id AND vq.visit_date = a.appointment_date
              ORDER BY (vq.appointment_id = a.appointment_id) DESC, vq.visit_id DESC LIMIT 1) AS queue_present_time,
           (SELECT qt.in_time FROM opd_visits vq JOIN opd_queue_tracking qt ON qt.visit_id = vq.visit_id
              WHERE vq.org_id = a.org_id AND vq.center_id = a.center_id
                AND (
                    vq.appointment_id = a.appointment_id
                    OR (vq.appointment_id IS NULL AND vq.patient_id = p.patient_id)
                )
                AND vq.doctor_id = a.doctor_id AND vq.visit_date = a.appointment_date
              ORDER BY (vq.appointment_id = a.appointment_id) DESC, vq.visit_id DESC LIMIT 1) AS queue_in_time,
           (SELECT qt.out_time FROM opd_visits vq JOIN opd_queue_tracking qt ON qt.visit_id = vq.visit_id
              WHERE vq.org_id = a.org_id AND vq.center_id = a.center_id
                AND (
                    vq.appointment_id = a.appointment_id
                    OR (vq.appointment_id IS NULL AND vq.patient_id = p.patient_id)
                )
                AND vq.doctor_id = a.doctor_id AND vq.visit_date = a.appointment_date
              ORDER BY (vq.appointment_id = a.appointment_id) DESC, vq.visit_id DESC LIMIT 1) AS queue_out_time,
           COALESCE((SELECT vq.token_no
               FROM opd_visits vq
              WHERE vq.org_id = a.org_id
                AND vq.center_id = a.center_id
                AND (
                    vq.appointment_id = a.appointment_id
                    OR (vq.appointment_id IS NULL AND vq.patient_id = p.patient_id)
                )
                AND vq.doctor_id = a.doctor_id
                AND vq.visit_date = a.appointment_date
              ORDER BY (vq.appointment_id = a.appointment_id) DESC, vq.visit_id DESC LIMIT 1), a.token_no) AS real_token_no,
            (SELECT vpay.payment_status
               FROM opd_visits vpay
              WHERE vpay.org_id = a.org_id AND vpay.center_id = a.center_id
                AND vpay.appointment_id = a.appointment_id
              ORDER BY vpay.visit_id DESC LIMIT 1) AS opd_payment_status,
            d.full_name AS doctor_name,
           dept.dept_name

    FROM opd_appointments a

    LEFT JOIN patient_master p ON a.uhid = p.uhid

    LEFT JOIN master_doctors d ON a.doctor_id = d.id

    LEFT JOIN master_departments dept ON a.department_id = dept.id

    WHERE a.org_id = ? AND a.center_id = ? AND a.appointment_date = ?

    ORDER BY a.appointment_time ASC, a.appointment_id DESC

");

$appointments->execute([$org_id, $center_id, $date_filter]);

$appointments = $appointments->fetchAll(PDO::FETCH_ASSOC);

// Today's Status: calculate one effective state per appointment, using queue timestamps
// first. Queue timestamps are the most reliable source because a patient may be present,
// inside the cabin, or completed while the appointment's own status remains Pending.
$apptStatusCounts = [
    'Pending' => 0,
    'Patient Present' => 0,
    'Patient Checked In' => 0,
    'Cancelled' => 0,
    'Completed' => 0
];
$apptDepartmentCounts = [];

foreach ($appointments as $summaryAppt) {
    $queueStatusKey = strtolower(trim((string)($summaryAppt['queue_tracking_status'] ?? '')));
    $statusKey = strtolower(trim((string)($summaryAppt['status'] ?? 'pending')));
    $presentAt = trim((string)($summaryAppt['queue_present_time'] ?? ''));
    $checkedInAt = trim((string)($summaryAppt['queue_in_time'] ?? ''));
    $completedAt = trim((string)($summaryAppt['queue_out_time'] ?? ''));

    // Keep categories mutually exclusive and prioritize the latest workflow state.
    if (strpos($statusKey, 'cancel') !== false) {
        $effectiveStatus = 'Cancelled';
    } elseif ($completedAt !== '' || strpos($queueStatusKey, 'complete') !== false ||
              strpos($statusKey, 'complete') !== false || strpos($statusKey, 'success') !== false) {
        $effectiveStatus = 'Completed';
    } elseif ($checkedInAt !== '' || strpos($queueStatusKey, 'inside cabin') !== false ||
              strpos($queueStatusKey, 'checked') !== false || strpos($statusKey, 'check') !== false) {
        $effectiveStatus = 'Patient Checked In';
    } elseif ($presentAt !== '' || strpos($queueStatusKey, 'present') !== false ||
              strpos($queueStatusKey, 'waiting') !== false || strpos($statusKey, 'present') !== false ||
              strpos($statusKey, 'confirm') !== false) {
        $effectiveStatus = 'Patient Present';
    } else {
        $effectiveStatus = 'Pending';
    }

    $apptStatusCounts[$effectiveStatus]++;
    $deptLabel = trim((string)($summaryAppt['dept_name'] ?? 'Unassigned')) ?: 'Unassigned';
    $apptDepartmentCounts[$deptLabel] = ($apptDepartmentCounts[$deptLabel] ?? 0) + 1;
}

// --- FETCH LIVE QUEUE TRACKING ---

$queue_list = $tenant_pdo->prepare("

    SELECT qt.*, v.token_no, v.doctor_id, v.department_id, p.fullname, p.age, p.gender, p.mobile, d.full_name as doctor_name, dept.dept_name

    FROM opd_queue_tracking qt

    JOIN opd_visits v ON qt.visit_id = v.visit_id

    JOIN patient_master p ON v.patient_id = p.patient_id

    LEFT JOIN master_doctors d ON v.doctor_id = d.id

    LEFT JOIN master_departments dept ON v.department_id = dept.id

    WHERE qt.org_id = ? AND qt.center_id = ? AND v.visit_date = CURDATE()

    ORDER BY v.token_no DESC

");

$queue_list->execute([$org_id, $center_id]);

$queue_list = $queue_list->fetchAll(PDO::FETCH_ASSOC);



// Helper for Financial Year token formatting

function getFYToken($token_int, $date_str) {

    return (string)((int)$token_int);

}



require_once __DIR__ . '/layout_header.php';

?>



<style>

body, .card, .form-label, .form-select, .form-control, .btn, .badge, .table, th, td { text-transform: uppercase; }

input[type="number"], input[type="date"], input[type="time"], input[inputmode="numeric"] { text-transform: none; }

.nav-pills .nav-link { font-weight: 600; color: #475569; border-radius: 8px; }

.nav-pills .nav-link.active { background-color: #0284c7; color: #fff; }

.appointment-booking-card .form-label { margin-bottom: 4px; }

.appointment-booking-card .form-control,

.appointment-booking-card .form-select { min-height: 34px; }

.appointment-booking-card .btn { min-height: 34px; }



/* APPOINTMENT SCREEN: ALL TEXT IN CAPITALS */

.appointment-booking-card input[type="text"],

.appointment-booking-card textarea,

.appointment-schedule-card,

#familyMemberModal,

#familyMemberModal * {

    text-transform: uppercase !important;

}



/* Keep mobile/search/date/time/numeric values unaffected */

#appointment_mobile,

#appointment_date_display,

#date_filter_display,

.appointment-booking-card input[type="time"],

.appointment-booking-card input[type="number"] {

    text-transform: none !important;

}





.appt-date-wrap,

.appt-filter-date-wrap {

    position: relative;

    display: flex;

    width: 100%;

}

.appt-date-wrap .form-control,

.appt-filter-date-wrap .form-control {

    padding-right: 42px;

}

.appt-date-btn,

.appt-filter-date-btn {

    position: absolute;

    right: 0;

    top: 0;

    height: 100%;

    width: 38px;

    padding: 0;

    z-index: 2;

    border-radius: 0 .375rem .375rem 0 !important;

}

.appt-native-date {

    position: absolute;

    right: 0;

    top: 0;

    width: 38px;

    height: 100%;

    opacity: 0;

    cursor: pointer;

    z-index: 3;

}

/* BLINKING STATUS FOR PATIENT INSIDE CABIN */

.status-blink {

    animation: queueStatusBlink 1s infinite;

    font-weight: 800 !important;

}



@keyframes queueStatusBlink {

    0%, 100% {

        opacity: 1;

        transform: scale(1);

    }

    50% {

        opacity: 0.35;

        transform: scale(1.04);

    }

}



.action-buttons { white-space: nowrap; }

.action-btn {

    min-width: 36px;

    font-weight: 700;

    line-height: 1.2;

    padding: .28rem .45rem;

}

.action-btn .action-label {

    display: inline;

    margin-left: 3px;

    font-size: 10px;

}

.family-panel { display:none !important; }

.family-modal-list { max-height: 340px; overflow-y:auto; }

.family-modal-item {

    width:100%; border:1px solid #dbe3ef; background:#fff; border-radius:8px;

    padding:10px 12px; text-align:left; cursor:pointer; margin-bottom:7px;

}

.family-modal-item:hover { background:#eff6ff; border-color:#60a5fa; }

.family-modal-item .fm-name { font-size:13px; font-weight:800; color:#0f172a; }

.family-modal-item .fm-meta { font-size:10px; color:#64748b; margin-top:2px; }

.family-mobile-badge { font-size:10px; }
.appointment-booking-card .patient-locked-field{background:#f1f5f9!important;color:#334155!important;cursor:not-allowed!important;border-color:#cbd5e1!important;}

.family-member-row {

    width: 100%;

    border: 1px solid #dbe3ef;

    background: #fff;

    border-radius: 6px;

    padding: 6px 8px;

    text-align: left;

    cursor: pointer;

    transition: .15s ease;

}

.family-member-row:hover,

.family-member-row.active {

    background: #eff6ff;

    border-color: #60a5fa;

}

.family-member-row .fm-name {

    font-size: 11px;

    font-weight: 800;

    color: #0f172a;

}

.family-member-row .fm-meta {

    font-size: 9px;

    color: #64748b;

    margin-top: 1px;

}

.family-add-btn {

    margin-top: 5px;

    width: 100%;

    border: 1px dashed #0284c7;

    background: #fff;

    color: #0284c7;

    border-radius: 6px;

    padding: 5px 7px;

    font-size: 10px;

    font-weight: 800;

}

.family-add-btn:hover { background: #eff6ff; }

.family-empty {

    font-size: 10px;

    color: #64748b;

    padding: 4px 2px;

}





@media (max-width: 1100px) {

    .action-btn .action-label { display: none; }

    .action-btn { width: 34px; padding-left: 0; padding-right: 0; }

}



/* Keep appointment Action dropdown visible above the table/card. */

.appointment-schedule-card {

    position: relative;

    z-index: 20;

    overflow: visible !important;

}

.appointment-table-wrap {

    overflow: visible !important;

    max-height: none !important;

}

.appointment-table-wrap .dropdown {

    position: relative;

}

.appointment-table-wrap .dropdown-menu {

    z-index: 2000 !important;

    min-width: 190px;

    white-space: nowrap;

}





/* Appointment Desk: readable compact desktop layout; booking form scrolls inside its own panel. */
.appointment-desk-layout{display:grid;grid-template-columns:258px minmax(0,1fr) 215px;gap:9px;align-items:stretch;height:calc(100vh - 250px);min-height:430px;max-height:720px;overflow:hidden;}
.appointment-desk-layout>.appointment-booking-card{grid-column:1;grid-row:1;margin:0!important;min-width:0;min-height:0;display:flex;flex-direction:column;border:1px solid #cbd5e1!important;border-radius:5px!important;box-shadow:none!important;overflow:hidden;}
.appointment-desk-layout>.appointment-schedule-card{grid-column:2;grid-row:1;margin:0!important;min-width:0;min-height:0;display:flex;flex-direction:column;border:1px solid #cbd5e1!important;border-radius:5px!important;box-shadow:none!important;overflow:hidden;}
.appointment-booking-card .card-header,.appointment-schedule-card .card-header{background:#f8fafc!important;padding:9px 10px!important;flex:0 0 auto;}
.appointment-booking-card .card-body{padding:10px!important;overflow-y:auto;overflow-x:hidden;min-height:0;flex:1 1 auto;scrollbar-gutter:stable;}
.appointment-booking-card form>.row{display:flex;flex-direction:column;gap:8px!important;}
.appointment-booking-card form>.row>[class*="col-"]{width:100%;max-width:100%;flex:0 0 100%;}
.appointment-booking-card .form-label{font-size:11px!important;font-weight:700!important;text-transform:uppercase;letter-spacing:.01em;margin-bottom:3px!important;}
.appointment-booking-card .form-control,.appointment-booking-card .form-select{min-height:34px;font-size:12px;padding:5px 8px;}
.appointment-booking-card .btn{min-height:35px;font-size:12px;}
.appointment-schedule-card .table-responsive{overflow:auto!important;max-height:none!important;min-height:0;flex:1 1 auto;}
.appointment-schedule-card table{min-width:600px;font-size:12px!important;}
.appointment-schedule-card table th{white-space:nowrap;font-size:11px;text-transform:uppercase;background:#f1f5f9!important;padding:10px 8px!important;}
.appointment-schedule-card table td{padding:10px 8px!important;border-color:#e2e8f0;}
.appointment-schedule-card .appt-filter-date-wrap{max-width:145px;}
.appointment-schedule-card .appointment-doctor-filter{min-width:155px;max-width:210px;font-size:12px;}
.appointment-context-row{transition:background-color .12s ease;}
.queue-time-stamps{display:flex;flex-wrap:wrap;gap:5px;margin-top:3px;font-size:9px;line-height:1.2;color:#475569;text-transform:uppercase}
.queue-time-stamps span{white-space:nowrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:3px;padding:2px 4px}
.queue-time-stamps b{color:#0284c7;font-weight:800}
.appointment-context-row.appt-row-pending,.appointment-context-row.appt-row-pending>*{background-color:#fff7cc!important;}
.appointment-context-row.appt-row-confirmed,.appointment-context-row.appt-row-confirmed>*{background-color:#dcfce7!important;}
.appointment-context-row.appt-row-present,.appointment-context-row.appt-row-present>*{background-color:#f0eaff!important;}
.appointment-context-row.appt-row-checked-in,.appointment-context-row.appt-row-checked-in>*{background-color:#dbeafe!important;}
.appointment-context-row.appt-row-cancelled,.appointment-context-row.appt-row-cancelled>*{background-color:#fee2e2!important;}
.appointment-context-row:hover{filter:brightness(.97);}
.appointment-context-row:focus{outline:2px solid #0284c7;outline-offset:-2px;}
.appointment-side-summary{grid-column:3;grid-row:1;min-width:0;min-height:0;overflow-y:auto;overflow-x:hidden;background:#fff;border:1px solid #cbd5e1;border-radius:5px;padding:9px 10px;font-size:11px;color:#334155;}
.appointment-side-summary h6{font-size:11px;font-weight:800;color:#7e22ce;text-transform:uppercase;margin:0 0 7px;padding-bottom:5px;border-bottom:1px solid #e2e8f0;}
.appointment-legend-item{display:flex;align-items:center;gap:7px;margin:0 0 7px;font-size:10px;text-transform:uppercase;}
.appointment-legend-dot{width:8px;height:8px;border-radius:2px;display:inline-block;flex:0 0 8px;}
.age-entry-group .form-select{min-width:0!important;padding-right:20px!important;}
.appointment-summary-line strong{display:inline-flex;align-items:center;justify-content:center;min-width:22px;padding:2px 7px;border-radius:999px;background:#f1f5f9;color:#334155;}
#sidePendingCount{background:#fef3c7;color:#92400e}#sidePresentCount{background:#ede9fe;color:#5b21b6}#sideCheckedCount{background:#dbeafe;color:#1d4ed8}#sideCancelledCount{background:#fee2e2;color:#991b1b}#sideCompletedCount{background:#dcfce7;color:#166534}
.appointment-summary-line{display:flex;justify-content:space-between;gap:8px;padding:5px 0;border-bottom:1px dotted #cbd5e1;font-size:10px;}
.appointment-summary-line strong{color:#0f172a;}
.appointment-summary-line:last-child{border-bottom:0;}
.appointment-summary-section{margin-top:12px;}
@media(max-width:1100px){.appointment-desk-layout{grid-template-columns:245px minmax(0,1fr);height:calc(100vh - 225px);min-height:420px;}.appointment-side-summary{grid-column:1 / -1;grid-row:2;max-height:180px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;}}
@media(max-width:760px){.appointment-desk-layout{grid-template-columns:minmax(0,1fr);height:auto;max-height:none;min-height:0;overflow:visible;}.appointment-desk-layout>.appointment-booking-card{height:390px;}.appointment-desk-layout>.appointment-schedule-card{height:55vh;min-height:320px;}.appointment-side-summary{grid-column:1;grid-row:auto;max-height:none;display:block;}.appointment-booking-card .card-body{overflow-y:auto;}.appointment-schedule-card .table-responsive{max-height:none!important;}}

/* VB.NET-style right-click context menu */
.opd-context-menu {
    position: fixed; z-index: 100000; display: none; min-width: 220px;
    padding: 5px 0; background: #fff; border: 1px solid #cbd5e1;
    border-radius: 5px; box-shadow: 0 8px 24px rgba(15,23,42,.20);
    color: #1f2937; font-size: 12px; text-transform: uppercase;
}
.opd-context-menu.show { display: block; }
.opd-context-menu button {
    display: flex; align-items: center; gap: 9px; width: 100%; padding: 8px 13px;
    border: 0; background: transparent; color: inherit; text-align: left;
    font: inherit; white-space: nowrap; cursor: pointer;
}
.opd-context-menu button i { width: 16px; font-size: 14px; color: #475569; }
.opd-context-menu button:hover { background: #eaf2ff; color: #0759b8; }
.opd-context-menu button:hover i { color: #0759b8; }
.opd-context-menu .context-divider { height: 1px; margin: 4px 0; background: #e2e8f0; }
.appointment-context-row, .queue-context-row { cursor: context-menu; }

.opd-registration-workspace { background:#f1f5f9; border:1px solid #dbe3ed; border-radius:12px; overflow:hidden; box-shadow:0 4px 16px rgba(15,23,42,.06); }
.opd-registration-workspace[hidden] { display:none !important; }
.opd-workspace-toolbar { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:14px 18px; background:#fff; border-bottom:1px solid #e2e8f0; }
.opd-workspace-eyebrow { color:#64748b; font-size:10px; font-weight:800; letter-spacing:.08em; margin-bottom:4px; }
.opd-workspace-frame-wrap { height:calc(100vh - 245px); min-height:540px; background:#f8fafc; }
#opdRegistrationFrame { display:block; width:100%; height:100%; border:0; background:#f8fafc; }
body.opd-registration-open #pills-tabContent { display:none !important; }
body.opd-registration-open #pills-tab { display:none !important; }
@media(max-width:768px) { .opd-workspace-toolbar { align-items:flex-start; flex-direction:column; } .opd-workspace-frame-wrap { height:calc(100vh - 260px); min-height:620px; } }

.appointment-context-row:focus-visible, .queue-context-row:focus-visible { outline: 2px solid #0284c7; outline-offset: -2px; }
#queueDoctorFilter { font-size:11px; }
#queueStatusSummary .badge { font-size:10px; padding:6px 9px; }
#opdRegistrationModal .modal-dialog { margin:0; max-width:none; }


/* Compact Appointment Desk header: legend sits inside the same block as the tab */
#pills-tab{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:7px 9px!important;min-height:54px}
#pills-tab .nav-item{flex:0 0 auto}
.appointment-legend-bar{display:flex;align-items:center;justify-content:flex-start;flex-wrap:wrap;gap:12px;background:transparent;border:0;border-radius:0;padding:0;margin:0 0 0 auto;font-size:10px;color:#334155;min-width:0}
.appointment-legend-bar .appointment-legend-item{margin:0;white-space:nowrap;font-size:10px}
.appointment-desk-layout{grid-template-columns:300px minmax(0,1fr) 215px;gap:8px;height:calc(100vh - 250px);min-height:420px}
.appointment-booking-card .card-body{padding:7px!important;overflow-y:auto}
.appointment-booking-card form>.row{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px 7px!important;margin:0!important}
.appointment-booking-card form>.row>[class*="col-"]{width:auto!important;max-width:none!important;flex:initial!important;padding:0!important;min-width:0}
.appointment-booking-card .form-label{font-size:10px!important;margin-bottom:2px!important;line-height:1.15}
.appointment-booking-card .form-control,.appointment-booking-card .form-select{min-height:29px!important;height:29px;font-size:11px!important;padding:3px 6px!important}
.appointment-booking-card .appt-date-wrap .form-control{padding-right:35px!important}
.appointment-booking-card .appt-date-btn{width:30px}
.appointment-booking-card .btn{min-height:29px!important;font-size:10px!important;padding:4px 6px!important}
 .appointment-schedule-card .card-header{padding:6px 8px!important;gap:6px!important}
.appointment-schedule-card .card-header h6{font-size:11px!important;line-height:1.15;margin:0!important}
.appointment-schedule-card .table-responsive{min-height:0}
.appointment-schedule-card table{font-size:11px!important;line-height:1.15}
.appointment-schedule-card table th{padding:6px 6px!important;font-size:10px!important}
.appointment-schedule-card table td{padding:5px 6px!important;vertical-align:middle!important}
.appointment-schedule-card table td .fw-bold{line-height:1.15}
.appointment-schedule-card table td small{font-size:9px!important;line-height:1.1;display:inline-block;margin-top:2px}
.appointment-context-row.appt-row-pending,.appointment-context-row.appt-row-pending>*{background-color:#fff7cc!important}
.appointment-context-row.appt-row-confirmed,.appointment-context-row.appt-row-confirmed>*{background-color:#dcfce7!important}
.appointment-context-row.appt-row-present,.appointment-context-row.appt-row-present>*{background-color:#f0eaff!important}
.appointment-context-row.appt-row-checked-in,.appointment-context-row.appt-row-checked-in>*{background-color:#dbeafe!important}
.appointment-context-row.appt-row-cancelled,.appointment-context-row.appt-row-cancelled>*{background-color:#fee2e2!important}
@media(max-width:1100px){.appointment-desk-layout{grid-template-columns:280px minmax(0,1fr);height:calc(100vh - 245px)}}
@media(max-width:760px){#pills-tab{align-items:flex-start}.appointment-legend-bar{gap:7px;margin:4px 0 0;width:100%}.appointment-desk-layout{grid-template-columns:minmax(0,1fr);height:auto}.appointment-booking-card form>.row{grid-template-columns:repeat(2,minmax(0,1fr))}}



/* FINAL BOOK APPOINTMENT FORM LAYOUT: balanced fields, compact columns, no dead space. */
@media (min-width: 1101px) {
  .appointment-desk-layout { grid-template-columns: 370px minmax(0,1fr) 205px !important; gap: 9px !important; }
  .appointment-booking-card .card-header { padding: 10px 12px !important; }
  .appointment-booking-card .card-body { padding: 12px !important; }
  .appointment-booking-card form > .row {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 9px 8px !important;
    align-items: end !important;
    margin: 0 !important;
  }
  .appointment-booking-card form > .row > [class*="col-"] {
    width: auto !important; max-width: none !important; min-width: 0 !important;
    flex: initial !important; padding: 0 !important; grid-column: auto; margin: 0 !important;
  }
  .appointment-booking-card form > .row > :nth-child(1) { grid-column: 1 / 3; }
  .appointment-booking-card form > .row > :nth-child(2) { grid-column: 3 / 5; }
  .appointment-booking-card form > .row > :nth-child(3) { grid-column: 1 / 4; }
  .appointment-booking-card form > .row > :nth-child(4) { grid-column: 4 / 5; }
  .appointment-booking-card form > .row > :nth-child(5) { grid-column: 1 / 3; }
  .appointment-booking-card form > .row > :nth-child(6) { grid-column: 3 / 5; }
  .appointment-booking-card form > .row > :nth-child(7) { grid-column: 1 / 2; }
  .appointment-booking-card form > .row > :nth-child(8) { grid-column: 2 / 3; }
  .appointment-booking-card form > .row > :nth-child(9) { grid-column: 3 / 5; }
  .appointment-booking-card form > .row > :nth-child(10) { grid-column: 1 / 3; }
  .appointment-booking-card form > .row > :nth-child(11) { grid-column: 3 / 5; }
  .appointment-booking-card form > .row > :nth-child(12) { grid-column: 1 / 5; }
  .appointment-booking-card .form-label { display:block; min-height: 13px; font-size: 10px !important; margin-bottom: 4px !important; color:#475569 !important; }
  .appointment-booking-card .form-control,
  .appointment-booking-card .form-select { width:100%; min-width:0; height:33px !important; min-height:33px !important; border-radius:6px !important; border-color:#cbd5e1; background-color:#fff; font-size:11px !important; padding:5px 7px !important; }
  .appointment-booking-card .btn { min-height:34px !important; border-radius:6px !important; }
  .appointment-booking-card #appointment_mobile_count { font-size:9px !important; line-height:1.2; }
  .appointment-booking-card #appointment_patient_status { font-size:9px !important; line-height:1.25; }
}
@media (min-width: 761px) and (max-width: 1100px) {
  .appointment-desk-layout { grid-template-columns: 320px minmax(0,1fr) !important; }
  .appointment-booking-card form > .row { grid-template-columns: repeat(2,minmax(0,1fr)) !important; gap:8px !important; }
  .appointment-booking-card form > .row > [class*="col-"] { min-width:0 !important; }
  .appointment-booking-card .form-control,.appointment-booking-card .form-select { width:100%; min-width:0; }
}
@media (max-width: 760px) {
  .appointment-booking-card form > .row { grid-template-columns: repeat(2,minmax(0,1fr)) !important; gap:9px 8px !important; }
  .appointment-booking-card form > .row > [class*="col-"] { min-width:0 !important; }
  .appointment-booking-card .form-label { min-height:0; }
  .appointment-booking-card .form-control,.appointment-booking-card .form-select { width:100%; min-width:0; }
}

</style>



<?php if (!empty($err)): ?>
    <div class="alert alert-danger py-2 px-3 mb-3 fw-bold d-none" id="appointmentErrorFallback"><?= htmlspecialchars($err) ?></div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.Swal) {
            Swal.fire({icon:'error', title:'Unable to Complete', text: <?= json_encode($err, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, confirmButtonColor:'#2563eb'});
        } else {
            document.getElementById('appointmentErrorFallback')?.classList.remove('d-none');
        }
    });
    </script>
<?php endif; ?>



<!-- TOP TABS NAVIGATION -->

<ul class="nav nav-pills mb-3 bg-white p-2 rounded-3 shadow-sm border" id="pills-tab" role="tablist">

    <li class="nav-item" role="presentation">

        <button class="nav-link active px-4" id="nav-appt-tab" data-bs-toggle="pill" data-bs-target="#nav-appt" type="button" role="tab"><i class="bi bi-calendar-plus me-2"></i>Appointment Desk</button>

    </li>

    <div class="appointment-legend-bar" aria-label="Appointment status legend">
        <span class="appointment-legend-item"><i class="appointment-legend-dot" style="background:#f59e0b"></i> Scheduled / Pending</span>
        <span class="appointment-legend-item"><i class="appointment-legend-dot" style="background:#22c55e"></i> Completed</span>
        <span class="appointment-legend-item"><i class="appointment-legend-dot" style="background:#c4b5fd"></i> Patient Present</span>
        <span class="appointment-legend-item"><i class="appointment-legend-dot" style="background:#60a5fa"></i> Patient Checked In</span>
        <span class="appointment-legend-item"><i class="appointment-legend-dot" style="background:#ef4444"></i> Cancelled</span>
    </div>
</ul>



<div class="tab-content" id="pills-tabContent">



    <!-- ========================================== -->

    <!-- TAB 1: APPOINTMENT DESK -->

    <!-- ========================================== -->

    <div class="tab-pane fade show active" id="nav-appt" role="tabpanel">

      <div class="appointment-desk-layout">

        <!-- BOOK NEW APPOINTMENT: compact left-side panel -->

        <div class="card border-0 shadow-sm rounded-3 mb-3 appointment-booking-card">

            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">

                <h6 class="mb-0 fw-bold text-dark small">

                    <i class="bi <?= $edit_appointment ? 'bi-pencil-square' : 'bi-calendar-plus' ?> text-primary me-2"></i>

                    <?= $edit_appointment ? 'Edit Appointment #' . (int)$edit_appointment['appointment_id'] : 'Book New Appointment' ?>

                </h6>

                <?php if ($edit_appointment): ?>

                    <a href="opd_appointments.php?date_filter=<?= urlencode($date_filter ?? $today_date) ?>" class="btn btn-sm btn-outline-secondary py-0">Cancel Edit</a>

                <?php endif; ?>

            </div>

            <div class="card-body p-3">

                <form method="POST">

                    <?php if ($edit_appointment): ?>

                        <input type="hidden" name="update_appointment" value="1">

                        <input type="hidden" name="appointment_id" value="<?= (int)$edit_appointment['appointment_id'] ?>">

                    <?php else: ?>

                        <input type="hidden" name="book_appointment" value="1">

                    <?php endif; ?>



                    <div class="row g-2 align-items-end">

                        <div class="col-md-2">

                            <label class="form-label small fw-semibold text-secondary mb-1">Date *</label>

                            <div class="appt-date-wrap">

                                <input type="hidden" name="appointment_date" id="appointment_date"

                                       value="<?= htmlspecialchars($edit_appointment['appointment_date'] ?? date('Y-m-d')) ?>">

                                <input type="text" id="appointment_date_display"

                                       class="form-control form-control-sm text-dark fw-bold"

                                       value="<?= htmlspecialchars(!empty($edit_appointment['appointment_date']) ? date('d-m-Y', strtotime($edit_appointment['appointment_date'])) : date('d-m-Y')) ?>"

                                       placeholder="DD-MM-YYYY" maxlength="10" autocomplete="off" required>

                                <button type="button" class="btn btn-outline-primary appt-date-btn"

                                        id="appointment_date_btn" title="SELECT DATE">

                                    <i class="bi bi-calendar3"></i>

                                </button>

                                <input type="date" id="appointment_date_picker"

                                       class="appt-native-date"

                                       value="<?= htmlspecialchars($edit_appointment['appointment_date'] ?? date('Y-m-d')) ?>"

                                       aria-label="Select appointment date">

                            </div>

                        </div>

                        <div class="col-md-3">

                            <label class="form-label small fw-semibold text-secondary mb-1">Department</label>

                            <select name="department_id" id="department_id" class="form-select form-select-sm" onchange="filterDoctors()">

                                <option value="">-- All --</option>

                                <?php foreach ($departments as $dept): ?>

                                    <option value="<?= $dept['id'] ?>" <?= ((string)($edit_appointment['department_id'] ?? '') === (string)$dept['id']) ? 'selected' : '' ?>><?= htmlspecialchars($dept['dept_name']) ?></option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-md-3">

                            <label class="form-label small fw-semibold text-secondary mb-1">Doctor *</label>

                            <select name="doctor_id" id="doctor_id" class="form-select form-select-sm" required onchange="syncDepartmentFromDoctor()">

                                <option value="">-- Select --</option>

                                <?php foreach ($doctors as $doc): ?>

                                    <option value="<?= $doc['id'] ?>" data-dept="<?= $doc['department_id'] ?>" <?= ((string)($edit_appointment['doctor_id'] ?? '') === (string)$doc['id']) ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-md-2">

                            <label class="form-label small fw-semibold text-secondary mb-1">Time</label>

                            <input type="time" name="appointment_time" class="form-control form-control-sm text-dark fw-bold" value="<?= htmlspecialchars($edit_appointment['appointment_time'] ?? '') ?>">

                        </div>

                        <div class="col-md-2">

                            <label class="form-label small fw-semibold text-secondary mb-1">Mobile No. / Search *</label>

                            <input type="text" name="mobile" id="appointment_mobile" class="form-control form-control-sm" maxlength="10" inputmode="numeric" placeholder="10-DIGIT MOBILE NO." value="<?= htmlspecialchars($edit_appointment['patient_display_mobile'] ?? $edit_appointment['mobile'] ?? '') ?>" required autocomplete="off">
                            <div id="appointment_mobile_count" class="small text-muted mt-1">0 / 10 digits</div>

                            <input type="hidden" name="uhid" id="appointment_uhid" value="<?= htmlspecialchars($edit_appointment['uhid'] ?? '') ?>">

                            <input type="hidden" name="new_family_member" id="appointment_new_family_member" value="<?= empty($edit_appointment['uhid']) ? '1' : '0' ?>">

                            <div id="appointment_patient_status" class="small mt-1"></div>
                            <button type="button" id="appointment_clear_patient_btn" class="btn btn-outline-secondary btn-sm w-100 mt-1 d-none" style="font-size:10px;font-weight:800;">CLEAR / CHANGE MOBILE</button>

                            <div id="appointment_family_panel" class="family-panel d-none"></div>

                            <button type="button" id="appointment_family_open_btn" class="btn btn-outline-primary btn-sm w-100 mt-1 d-none" style="font-size:10px;font-weight:800;">VIEW FAMILY MEMBERS</button>

                        </div>

                        <div class="col-md-3">

                            <label class="form-label small fw-semibold text-secondary mb-1">Patient Name *</label>

                            <input type="text" name="patient_name" id="appointment_patient_name" class="form-control form-control-sm" placeholder="PATIENT NAME" value="<?= htmlspecialchars($edit_appointment['patient_display_name'] ?? $edit_appointment['patient_name'] ?? '') ?>" required>

                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-semibold text-secondary mb-1">Age</label>
                            <div class="input-group input-group-sm age-entry-group">
                                <input type="number" name="age" id="appointment_age" class="form-control form-control-sm" min="0" step="1" placeholder="AGE" value="<?= htmlspecialchars($edit_appointment['patient_display_age'] ?? $edit_appointment['age'] ?? '') ?>">
                                <select name="age_unit" id="appointment_age_unit" class="form-select form-select-sm" aria-label="Age unit" style="max-width:82px;">
                                    <option value="years" <?= strtolower((string)($edit_appointment['age_unit'] ?? 'years')) === 'years' ? 'selected' : '' ?>>Years</option><option value="months" <?= strtolower((string)($edit_appointment['age_unit'] ?? 'years')) === 'months' ? 'selected' : '' ?>>Months</option><option value="days" <?= strtolower((string)($edit_appointment['age_unit'] ?? 'years')) === 'days' ? 'selected' : '' ?>>Days</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-2">

                            <label class="form-label small fw-semibold text-secondary mb-1">Gender</label>

                            <select name="gender" id="appointment_gender" class="form-select form-select-sm">

                                <option value="Male" <?= (($edit_appointment['patient_display_gender'] ?? $edit_appointment['gender'] ?? 'Male') === 'Male') ? 'selected' : '' ?>>Male</option>

                                <option value="Female" <?= (($edit_appointment['patient_display_gender'] ?? $edit_appointment['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>

                                <option value="Other" <?= (($edit_appointment['patient_display_gender'] ?? $edit_appointment['gender'] ?? '') === 'Other') ? 'selected' : '' ?>>Other</option>

                            </select>

                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Patient Category / Billing Type</label>
                            <select name="category_id" id="appointment_category_id" class="form-select form-select-sm" onchange="filterAppointmentInsurances()">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>" <?= ((string)($edit_appointment['category_id'] ?? '') === (string)$cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Insurance / Provider</label>
                            <select name="insurance_id" id="appointment_insurance_id" class="form-select form-select-sm">
                                <option value="">-- Base Category / No Insurance --</option>
                                <?php foreach ($insurances as $ins): ?>
                                    <option value="<?= (int)$ins['id'] ?>" data-cat="<?= (int)$ins['category_id'] ?>" <?= ((string)($edit_appointment['insurance_id'] ?? '') === (string)$ins['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ins['insurance_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">

                            <label class="form-label small fw-semibold text-secondary mb-1">Remarks</label>

                            <input type="text" name="remark" class="form-control form-control-sm" placeholder="Any remarks..." value="<?= htmlspecialchars($edit_appointment['remark'] ?? '') ?>">

                        </div>

                        <div class="col-md-2">

                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold shadow-sm">

                                <?= $edit_appointment

    ? '<i class="bi bi-check-lg me-1"></i> Update Appointment'

    : '<i class="bi bi-calendar-check me-1"></i> Book Appointment' ?>

                            </button>

                        </div>

                    </div>

                </form>

            </div>

        </div>



        <!-- APPOINTMENTS LIST -->

        <div class="card border-0 shadow-sm rounded-3 appointment-schedule-card">

            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">

                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-calendar2-week text-primary me-2"></i>Consultation Appointments <small class="text-muted fw-normal d-block mt-1">Right-click for appointment, billing and queue actions</small></h6>

                <form method="GET" class="d-flex gap-2">

                    <label class="small fw-semibold text-secondary align-self-center mb-0">DATE</label>

                    <div class="appt-filter-date-wrap">

                        <input type="hidden" name="date_filter" id="date_filter" value="<?= htmlspecialchars($date_filter) ?>">

                        <input type="text" id="date_filter_display"

                               class="form-control form-control-sm fw-bold text-primary"

                               value="<?= htmlspecialchars(date('d-m-Y', strtotime($date_filter))) ?>"

                               placeholder="DD-MM-YYYY" maxlength="10" autocomplete="off">

                        <button type="button" class="btn btn-outline-secondary appt-filter-date-btn"

                                id="date_filter_btn" title="SELECT DATE">

                            <i class="bi bi-calendar3"></i>

                        </button>

                        <input type="date" id="date_filter_picker"

                               class="appt-native-date"

                               value="<?= htmlspecialchars($date_filter) ?>"

                               aria-label="Select schedule date">

                    </div>

                </form>
                <select id="appointmentDoctorFilter" class="form-select form-select-sm appointment-doctor-filter" aria-label="Filter appointments by doctor">
                    <?php $initialDoctorFilter = isset($_GET['doctor_filter']) ? (string)(int)$_GET['doctor_filter'] : '__ALL__'; ?>
                    <option value="__ALL__" <?= $initialDoctorFilter === '__ALL__' ? 'selected' : '' ?>>ALL DOCTORS</option>
                    <?php foreach ($doctors as $doc): ?>
                        <option value="<?= (int)$doc['id'] ?>" <?= $initialDoctorFilter === (string)$doc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>

            </div>



            <div class="table-responsive appointment-table-wrap">

                <table class="table table-hover align-middle mb-0 small">

                    <thead class="table-light sticky-top">

                        <tr>
                            <th class="ps-3">Serial No.</th>
                            <th>Time</th>

                            <th>Patient Name</th>

                            <th>Mobile</th>

                            <th>Department</th>

                            <th>Doctor</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($appointments)): ?>

                            <tr><td colspan="6" class="text-center py-5 text-muted appointment-empty-row">Select a doctor to view appointments.</td></tr>

                        <?php else: ?>

                            <?php foreach ($appointments as $appt_index => $row):

                                $sc = $row['status'] == 'Confirmed' ? 'success' : ($row['status'] == 'Cancelled' ? 'danger' : 'warning');

                            ?>

                                <?php
                                    $rowStatus = strtolower(trim((string)(($row['queue_tracking_status'] ?? '') ?: ($row['status'] ?? 'pending'))));
                                    $rowClass = (strpos($rowStatus, 'cancel') !== false) ? 'appt-row-cancelled' : ((strpos($rowStatus, 'check') !== false || strpos($rowStatus, 'inside cabin') !== false) ? 'appt-row-checked-in' : ((strpos($rowStatus, 'present') !== false || strpos($rowStatus, 'confirm') !== false) ? 'appt-row-present' : ((strpos($rowStatus, 'complete') !== false || strpos($rowStatus, 'success') !== false) ? 'appt-row-confirmed' : 'appt-row-pending')));
                                ?>
                                <tr class="appointment-context-row <?= $rowClass ?>" tabindex="0"
                                    data-context-type="appointment"
                                    data-doctor-id="<?= (int)($row['doctor_id'] ?? 0) ?>"
                                    data-appointment-id="<?= (int)$row['appointment_id'] ?>"
                                    data-appointment-status="<?= htmlspecialchars((string)$row['status'], ENT_QUOTES) ?>"
                                    data-payment-status="<?= htmlspecialchars((string)($row['opd_payment_status'] ?? ''), ENT_QUOTES) ?>"
                                    data-appointment-date="<?= htmlspecialchars((string)$date_filter, ENT_QUOTES) ?>"
                                    data-appointment-name="<?= htmlspecialchars((string)($row['patient_display_name'] ?? $row['patient_name']), ENT_QUOTES) ?>"
                                    data-patient-name="<?= htmlspecialchars((string)($row['patient_display_name'] ?? $row['patient_name']), ENT_QUOTES) ?>"
                                    data-patient-mobile="<?= htmlspecialchars((string)($row['patient_display_mobile'] ?? $row['mobile']), ENT_QUOTES) ?>"
                                    data-patient-uhid="<?= htmlspecialchars((string)($row['patient_uhid'] ?? ''), ENT_QUOTES) ?>"
                                    data-track-id="<?= (int)($row['queue_track_id'] ?? 0) ?>"
                                    data-queue-status="<?= htmlspecialchars((string)($row['queue_tracking_status'] ?? ''), ENT_QUOTES) ?>"
                                    data-present-time="<?= htmlspecialchars((string)($row['queue_present_time'] ?? ''), ENT_QUOTES) ?>"
                                    data-in-time="<?= htmlspecialchars((string)($row['queue_in_time'] ?? ''), ENT_QUOTES) ?>"
                                    data-out-time="<?= htmlspecialchars((string)($row['queue_out_time'] ?? ''), ENT_QUOTES) ?>">

                                    <td class="ps-3 fw-bold text-primary"><?= ($row['real_token_no'] !== null && $row['real_token_no'] !== '') ? htmlspecialchars(getFYToken($row['real_token_no'], $date_filter)) : 'N/A' ?></td>
                                    <td class="fw-bold text-primary">
                                        <?= !empty($row['appointment_time']) ? date('h:i:s A', strtotime($row['appointment_time'])) : 'N/A' ?>

                                    </td>

                                    <td>

                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['patient_display_name'] ?? $row['patient_name']) ?></div>
                                        <?php if (!empty($row['patient_uhid'])): ?><small class="text-muted"><?= htmlspecialchars($row['patient_uhid']) ?></small><?php endif; ?>
                                        <?php if (!empty($row['queue_present_time']) || !empty($row['queue_in_time']) || !empty($row['queue_out_time'])): ?>
                                            <div class="queue-time-stamps">
                                                <?php if (!empty($row['queue_present_time'])): ?><span><b>PRESENT</b> <?= htmlspecialchars(date('h:i:s A', strtotime($row['queue_present_time']))) ?></span><?php endif; ?>
                                                <?php if (!empty($row['queue_in_time'])): ?><span><b>IN</b> <?= htmlspecialchars(date('h:i:s A', strtotime($row['queue_in_time']))) ?></span><?php endif; ?>
                                                <?php if (!empty($row['queue_out_time'])): ?><span><b>OUT</b> <?= htmlspecialchars(date('h:i:s A', strtotime($row['queue_out_time']))) ?></span><?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                    </td>

                                    <td class="text-muted"><?= htmlspecialchars($row['patient_display_mobile'] ?? $row['mobile']) ?></td>

                                    <td class="text-secondary"><?= htmlspecialchars($row['dept_name'] ?? 'N/A') ?></td>

                                    <td><div class="fw-semibold text-dark"><?= htmlspecialchars($row['doctor_name'] ?? 'N/A') ?></div></td>


                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <!-- RIGHT SIDEBAR: Today's Status and Appointment Summary (Legend removed only) -->
        <aside class="appointment-side-summary" aria-label="Appointment status summary">
            <section class="appointment-summary-section" style="margin-top:0">
                <h6>Today's Status</h6>
                <div class="appointment-summary-line"><span>Scheduled / Pending</span><strong id="sidePendingCount"><?= (int)$apptStatusCounts['Pending'] ?></strong></div>
                
                <div class="appointment-summary-line"><span>Patient Present</span><strong id="sidePresentCount"><?= (int)$apptStatusCounts['Patient Present'] ?></strong></div>
                <div class="appointment-summary-line"><span>Patient Checked In</span><strong id="sideCheckedCount"><?= (int)$apptStatusCounts['Patient Checked In'] ?></strong></div>
                <div class="appointment-summary-line"><span>Cancelled</span><strong id="sideCancelledCount"><?= (int)$apptStatusCounts['Cancelled'] ?></strong></div>
                <div class="appointment-summary-line"><span>Completed</span><strong id="sideCompletedCount"><?= (int)$apptStatusCounts['Completed'] ?></strong></div>
            </section>
            <section class="appointment-summary-section">
                <h6>Appointment Summary</h6>
                <div class="appointment-summary-line"><span>Selected Date</span><strong><?= htmlspecialchars(strtoupper(date('d/M/Y', strtotime($date_filter)))) ?></strong></div>
                <div class="appointment-summary-line"><span>Total Appointments</span><strong id="sideTotalAppointments"><?= count($appointments) ?></strong></div>
                <?php if (!empty($apptDepartmentCounts)): foreach ($apptDepartmentCounts as $deptSummaryName => $deptSummaryCount): ?>
                    <div class="appointment-summary-line side-department-summary" data-department-summary="<?= htmlspecialchars($deptSummaryName, ENT_QUOTES) ?>"><span><?= htmlspecialchars($deptSummaryName) ?></span><strong><?= (int)$deptSummaryCount ?></strong></div>
                <?php endforeach; else: ?>
                    <div class="small text-muted">No appointments for this date.</div>
                <?php endif; ?>
                <div class="small text-muted mt-2">Right-click an appointment to open billing, saved bills, or patient queue actions.</div>
            </section>
        </aside>
      </div><!-- /.appointment-desk-layout -->

    </div>



    </div><!-- /.tab-content -->



<!-- VB.NET-style right-click context menu for appointment and queue rows -->
<div id="opdContextMenu" class="opd-context-menu" role="menu" aria-label="Appointment actions"></div>

<!-- Appointment-linked OPD Payment modal: stays on the Appointment Desk -->
<div class="modal fade" id="opdPaymentWorkspaceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl opd-payment-dialog">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header py-2 px-3 bg-white">
        <div><strong class="text-primary"><i class="bi bi-wallet2 me-2"></i>OPD PAYMENT</strong><span class="small text-muted ms-2">Appointment-linked billing</span></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0"><iframe id="opdPaymentWorkspaceFrame" title="OPD Payment" src="about:blank"></iframe></div>
    </div>
  </div>
</div>
<style>
.opd-payment-dialog{max-width:1180px;width:calc(100vw - 32px)}
#opdPaymentWorkspaceModal .modal-content{height:min(88vh,820px);border-radius:12px;overflow:hidden}
#opdPaymentWorkspaceModal .modal-body{min-height:0;overflow:hidden;background:#f8fafc}
#opdPaymentWorkspaceFrame{display:block;width:100%;height:100%;min-height:0;border:0;background:#fff}
@media(max-width:768px){.opd-payment-dialog{width:calc(100vw - 12px);margin:6px auto}#opdPaymentWorkspaceModal .modal-content{height:94vh}}

/* Final refinements: booking fields flow naturally without an inner scrollbar. */
.appointment-desk-layout {
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow: visible !important;
    align-items: start;
}
.appointment-desk-layout > .appointment-booking-card {
    height: auto !important;
    overflow: visible !important;
    align-self: start;
}
.appointment-booking-card .card-body {
    overflow: visible !important;
    max-height: none !important;
}
.appointment-desk-layout > .appointment-schedule-card {
    min-height: 460px;
    height: auto;
}
.appointment-schedule-card .table-responsive { overflow: auto !important; }
.appointment-side-summary { max-height: none; overflow: visible; }
#appointment_clear_patient_btn { white-space: normal; }
@media (max-width: 1100px) {
    .appointment-desk-layout { height: auto !important; }
    .appointment-desk-layout > .appointment-booking-card { height: auto !important; }
}
@media (max-width: 760px) {
    .appointment-desk-layout { height: auto !important; }
    .appointment-desk-layout > .appointment-booking-card { height: auto !important; }
    .appointment-booking-card .card-body { overflow: visible !important; }
}
</style>

<!-- Inline registration workspace: replaces the OPD tables in-place; no popup/modal. -->
<section id="opdRegistrationWorkspace" class="opd-registration-workspace" hidden>
  <div class="opd-workspace-toolbar">
    <div>
      <div class="opd-workspace-eyebrow">OPD SERVICES / APPOINTMENT CHECK-IN</div>
      <h5 class="mb-0 fw-bold"><i class="bi bi-person-vcard text-primary me-2"></i>Patient Registration</h5>
      <div class="small text-muted mt-1">Appointment details stay linked automatically. Choose Regular Consultation or eligible Follow-up in the registration panel.</div>
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" onclick="closeRegistrationWorkspace()"><i class="bi bi-arrow-left me-1"></i> BACK TO APPOINTMENTS</button>
  </div>
  <div class="opd-workspace-frame-wrap">
    <iframe id="opdRegistrationFrame" title="OPD Registration" src="about:blank"></iframe>
  </div>
</section>

<!-- FAMILY MEMBER POPUP -->

<div class="modal fade" id="familyMemberModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-sm">

        <div class="modal-content border-0 shadow">

            <div class="modal-header py-2">

                <h6 class="modal-title fw-bold"><i class="bi bi-people-fill text-primary me-2"></i>SELECT FAMILY MEMBER</h6>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>

            </div>

            <div class="modal-body p-2">

                <div class="small text-muted mb-2">MULTIPLE REGISTERED PATIENTS FOUND FOR <span id="familyModalMobile" class="fw-bold text-dark"></span></div>

                <div id="familyModalList" class="family-modal-list"></div>

                <button type="button" id="familyModalAddBtn" class="btn btn-outline-primary btn-sm w-100 fw-bold mt-1">+ ADD FAMILY MEMBER</button>

            </div>

        </div>

    </div>

</div>



<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Open OPD Payment as a full page, pre-linked to the selected appointment.
function openRegistrationPanel(appointmentId) {
    const frame = document.getElementById('opdPaymentWorkspaceFrame');
    const modalEl = document.getElementById('opdPaymentWorkspaceModal');
    if (!frame || !modalEl) {
        window.location.href = 'opd_registration.php?appointment_id=' + encodeURIComponent(appointmentId);
        return;
    }
    frame.src = 'opd_registration.php?appointment_id=' + encodeURIComponent(appointmentId) + '&embedded=1';
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}
function closeOpdPaymentModal(refreshDesk) {
    const frame = document.getElementById('opdPaymentWorkspaceFrame');
    const modalEl = document.getElementById('opdPaymentWorkspaceModal');
    if (frame) frame.src = 'about:blank';
    if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    if (refreshDesk) window.location.reload();
}
window.addEventListener('message', function (event) {
    if (!event.data || event.data.type !== 'opd-payment-saved') return;
    closeOpdPaymentModal(true);
});

function closeRegistrationWorkspace() {
    const frame = document.getElementById('opdRegistrationFrame');
    const workspace = document.getElementById('opdRegistrationWorkspace');
    const tabs = document.getElementById('pills-tab');
    const tabContent = document.getElementById('pills-tabContent');
    if (frame) frame.src = 'about:blank';
    if (workspace) workspace.hidden = true;
    if (tabs) tabs.style.display = '';
    if (tabContent) tabContent.style.display = '';
    document.body.classList.remove('opd-registration-open');
}

// Appointment list doctor filter: show all appointments by default; after booking, focus the selected doctor.
(function setupAppointmentDoctorFilter(){
    const select = document.getElementById('appointmentDoctorFilter');
    const table = document.querySelector('.appointment-schedule-card table');
    if (!select || !table) return;
    const rows = Array.from(table.querySelectorAll('tbody tr[data-context-type="appointment"]'));
    const emptyRow = table.querySelector('tbody tr.appointment-empty-row');
    function applyFilter(){
        const value = select.value || '__ALL__';
        let shown = 0;
        const counts = {pending:0, present:0, checked:0, cancelled:0, completed:0};

        rows.forEach(row => {
            const match = value === '__ALL__' || row.dataset.doctorId === value;
            row.style.display = match ? '' : 'none';
            if (!match) return;
            shown++;

            const queueStatus = (row.dataset.queueStatus || '').trim().toLowerCase();
            const appointmentStatus = (row.dataset.appointmentStatus || '').trim().toLowerCase();
            const hasPresentTime = !!String(row.dataset.presentTime || '').trim();
            const hasInTime = !!String(row.dataset.inTime || '').trim();
            const hasOutTime = !!String(row.dataset.outTime || '').trim();

            if (appointmentStatus.includes('cancel')) {
                counts.cancelled++;
                return;
            }

            // Count each recorded workflow milestone independently:
            // PRESENT remains counted even after the patient is checked in/completed.
            if (hasPresentTime || queueStatus.includes('waiting') || queueStatus.includes('present') ||
                appointmentStatus.includes('present') || appointmentStatus.includes('confirm')) {
                counts.present++;
            }
            if (hasInTime || queueStatus.includes('inside cabin') || queueStatus.includes('checked') ||
                appointmentStatus.includes('check')) {
                counts.checked++;
            }
            if (hasOutTime || queueStatus.includes('complete') || appointmentStatus.includes('complete') ||
                appointmentStatus.includes('success')) {
                counts.completed++;
            }

            const hasAnyProgress = hasPresentTime || hasInTime || hasOutTime ||
                queueStatus.includes('waiting') || queueStatus.includes('present') ||
                queueStatus.includes('inside cabin') || queueStatus.includes('checked') ||
                queueStatus.includes('complete') || appointmentStatus.includes('present') ||
                appointmentStatus.includes('confirm') || appointmentStatus.includes('check') ||
                appointmentStatus.includes('complete') || appointmentStatus.includes('success');
            if (!hasAnyProgress) counts.pending++;
        });

        if (emptyRow) {
            emptyRow.style.display = shown ? 'none' : '';
            const cell = emptyRow.querySelector('td');
            if (cell) cell.textContent = value === '__ALL__' ? 'No appointments found for this date.' : 'No appointments found for this doctor and date.';
        }
        const setCount = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        setCount('sidePendingCount', counts.pending);
        setCount('sidePresentCount', counts.present);
        setCount('sideCheckedCount', counts.checked);
        setCount('sideCancelledCount', counts.cancelled);
        setCount('sideCompletedCount', counts.completed);
        setCount('sideTotalAppointments', shown);
    }
    select.addEventListener('change', applyFilter);
    applyFilter();
})();

// Doctor dropdown + search + live status counts.
(function setupQueueFilters() {
    const table = document.getElementById('queueTable');
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('tbody tr[data-context-type="queue"]'));
    const doctor = document.getElementById('queueDoctorFilter');
    const search = document.getElementById('searchQueue');
    function update() {
        const doctorId = doctor ? doctor.value : '';
        const q = (search ? search.value : '').trim().toLowerCase();
        let waiting = 0, inside = 0, completed = 0;
        rows.forEach(row => {
            const status = row.dataset.queueStatus || '';
            if (status === 'Waiting') waiting++; else if (status === 'Inside Cabin') inside++; else if (status === 'Completed') completed++;
            const doctorMatch = !doctorId || row.dataset.doctorId === doctorId;
            const searchMatch = !q || row.innerText.toLowerCase().includes(q);
            row.style.display = doctorMatch && searchMatch ? '' : 'none';
        });
        const w = document.getElementById('countWaiting');
        const i = document.getElementById('countInside');
        const c = document.getElementById('countCompleted');
        if (w) w.textContent = waiting; if (i) i.textContent = inside; if (c) c.textContent = completed;
    }
    if (doctor) doctor.addEventListener('change', update);
    if (search) search.addEventListener('input', update);
    update();
})();

// VB.NET-style context menu: reuses only actions already present in this PHP page.
(function () {
    const menu = document.getElementById('opdContextMenu');
    if (!menu) return;

    let activeRow = null;
    const esc = (value) => String(value || '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

    function addItem(label, icon, action, dividerBefore) {
        if (dividerBefore && menu.children.length) {
            const divider = document.createElement('div');
            divider.className = 'context-divider';
            menu.appendChild(divider);
        }
        const button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('role', 'menuitem');
        button.innerHTML = '<i class="bi ' + icon + '"></i><span>' + esc(label) + '</span>';
        button.addEventListener('click', function () {
            const row = activeRow;
            hideMenu();
            if (row) action(row);
        });
        menu.appendChild(button);
    }

    function showMenu(row, x, y) {
        activeRow = row;
        menu.innerHTML = '';
        const type = row.dataset.contextType;
        if (type === 'appointment') {
            const id = Number(row.dataset.appointmentId || 0);
            const status = row.dataset.appointmentStatus || '';
            const date = row.dataset.appointmentDate || '';
            if (!id) return;

            if (status !== 'Cancelled') {
                addItem('Edit Appointment', 'bi-pencil-square', r => {
                    location.href = '?date_filter=' + encodeURIComponent(r.dataset.appointmentDate) + '&edit_appointment=' + encodeURIComponent(r.dataset.appointmentId);
                });
            }
            // Payment is available only before consultation is completed.
            // A completed queue visit must not offer another registration/payment entry.
            const queueStatus = String(row.dataset.queueStatus || '').trim().toLowerCase();
            const appointmentStatus = String(status || '').trim().toLowerCase();
            const consultationCompleted =
                queueStatus === 'completed' ||
                appointmentStatus === 'completed' ||
                appointmentStatus === 'complete';
            const paymentStatus = String(row.dataset.paymentStatus || '').trim();
            const alreadyPaid = paymentStatus === '1' || paymentStatus.toLowerCase() === 'paid';

            if (status.toLowerCase() !== 'cancelled' && !consultationCompleted && !alreadyPaid) {
                addItem('OPD Payment', 'bi-wallet2', r => {
                    openRegistrationPanel(r.dataset.appointmentId);
                }, true);
            }
            addItem('View Saved OPD Bills', 'bi-receipt', r => {
                const q = r.dataset.patientUhid || r.dataset.patientMobile || r.dataset.patientName || '';
                location.href = 'opd_bills.php?search=' + encodeURIComponent(q);
            });
            // Queue actions are also available directly from the Consultation Appointments row.
            const linkedTrackId = Number(row.dataset.trackId || 0);
            const linkedQueueStatus = String(row.dataset.queueStatus || '').trim();
            const hasPresentTime = !!String(row.dataset.presentTime || '').trim();
            if (linkedTrackId > 0 && linkedQueueStatus !== 'Completed' && appointmentStatus !== 'completed') {
                if (!hasPresentTime && linkedQueueStatus !== 'Inside Cabin') {
                    addItem('Patient Present', 'bi-person-check', () => goQueue('waiting', linkedTrackId), true);
                }
                if (linkedQueueStatus !== 'Inside Cabin') {
                    addItem('Call In / In Consultation', 'bi-door-open', () => goQueue('in', linkedTrackId), !hasPresentTime);
                }
                if (linkedQueueStatus === 'Inside Cabin') {
                    addItem('Consultation Complete', 'bi-check-circle', () => confirmQueue('out', linkedTrackId), true);
                }
            }
            if (status === 'Pending' || status === 'Confirmed') {
                addItem('Cancel Appointment', 'bi-x-circle', r => {
                    const url = '?date_filter=' + encodeURIComponent(r.dataset.appointmentDate) + '&update_status=Cancelled&id=' + encodeURIComponent(r.dataset.appointmentId);
                    if (window.Swal) {
                        Swal.fire({icon:'warning', title:'Cancel Appointment?', text:'Are you sure you want to cancel this appointment?', showCancelButton:true, confirmButtonText:'Yes, Cancel', cancelButtonText:'Keep Appointment', confirmButtonColor:'#dc3545', cancelButtonColor:'#64748b', reverseButtons:true}).then(result => { if (result.isConfirmed) location.href = url; });
                    } else if (confirm('Cancel this appointment?')) location.href = url;
                }, true);
            }
        } else if (type === 'queue') {
            const trackId = Number(row.dataset.trackId || 0);
            const status = row.dataset.queueStatus || '';
            if (!trackId) return;
            const hasPresentTime = !!String(row.dataset.presentTime || '').trim();
            if (status === 'Waiting') {
                if (!hasPresentTime) addItem('Patient Present', 'bi-person-check', () => goQueue('waiting', trackId));
                addItem('Call In / In Consultation', 'bi-door-open', () => goQueue('in', trackId), !hasPresentTime);
            } else if (status === 'Inside Cabin') {
                addItem('Consultation Complete', 'bi-check-circle', () => confirmQueue('out', trackId));
            }
        }

        if (!menu.children.length) {
            const empty = document.createElement('button');
            empty.type = 'button'; empty.disabled = true;
            empty.innerHTML = '<i class="bi bi-info-circle"></i><span>No actions available</span>';
            menu.appendChild(empty);
        }
        menu.classList.add('show');
        menu.style.left = '0px'; menu.style.top = '0px';
        const rect = menu.getBoundingClientRect();
        menu.style.left = Math.max(6, Math.min(x, window.innerWidth - rect.width - 6)) + 'px';
        menu.style.top = Math.max(6, Math.min(y, window.innerHeight - rect.height - 6)) + 'px';
        const first = menu.querySelector('button:not(:disabled)');
        if (first) first.focus({preventScroll:true});
    }

    function hideMenu() { menu.classList.remove('show'); activeRow = null; }
    function goQueue(action, trackId) {
        const doctorFilter = document.getElementById('appointmentDoctorFilter');
        const dateFilter = document.getElementById('date_filter');
        const selectedDate = dateFilter ? dateFilter.value : (new URLSearchParams(location.search).get('date_filter') || '');
        const selectedDoctor = doctorFilter && doctorFilter.value && doctorFilter.value !== '__ALL__'
            ? doctorFilter.value
            : (new URLSearchParams(location.search).get('doctor_filter') || '');
        const params = new URLSearchParams({q_action: action, track_id: String(trackId)});
        if (selectedDate) params.set('date_filter', selectedDate);
        if (selectedDoctor) params.set('doctor_filter', selectedDoctor);
        location.href = '?' + params.toString() + '#nav-appt';
    }
    function confirmQueue(action, trackId) {
        if (action !== 'out') return;
        if (window.Swal) {
            Swal.fire({icon:'question', title:'Complete Consultation?', text:'Mark this patient as completed and capture check-out time?', showCancelButton:true, confirmButtonText:'Complete Consultation', cancelButtonText:'Go Back', confirmButtonColor:'#2563eb', cancelButtonColor:'#64748b'}).then(result => { if (result.isConfirmed) goQueue(action, trackId); });
        } else if (confirm('Mark consultation complete and capture check-out time?')) goQueue(action, trackId);
    }

    document.addEventListener('contextmenu', function (event) {
        const row = event.target.closest('tr[data-context-type]');
        if (!row) return;
        event.preventDefault();
        showMenu(row, event.clientX, event.clientY);
    });
    document.addEventListener('click', function (event) { if (!menu.contains(event.target)) hideMenu(); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') hideMenu();
        if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('tr[data-context-type]')) {
            event.preventDefault();
            const rect = event.target.getBoundingClientRect();
            showMenu(event.target, rect.left + 20, rect.top + 20);
        }
    });
    window.addEventListener('resize', hideMenu);
    window.addEventListener('scroll', hideMenu, true);
})();

// Compact SweetAlert notifications for appointment actions and validation.
function confirmAppointmentCancel(event, link) {
    event.preventDefault();
    Swal.fire({
        icon: 'warning', title: 'Cancel Appointment?',
        text: 'Are you sure you want to cancel this appointment?',
        showCancelButton: true, confirmButtonText: 'Yes, Cancel', cancelButtonText: 'Keep Appointment',
        confirmButtonColor: '#dc3545', cancelButtonColor: '#64748b', reverseButtons: true
    }).then((result) => { if (result.isConfirmed) window.location.href = link.href; });
    return false;
}




function ddmmyyyyToISO(value) {

    const m = String(value || '').trim().match(/^(\d{2})-(\d{2})-(\d{4})$/);

    if (!m) return '';

    const day = Number(m[1]), month = Number(m[2]), year = Number(m[3]);

    const dt = new Date(year, month - 1, day);

    if (dt.getFullYear() !== year || dt.getMonth() !== month - 1 || dt.getDate() !== day) return '';

    return `${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`;

}

function isoToDDMMYYYY(value) {

    const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);

    return m ? `${m[3]}-${m[2]}-${m[1]}` : '';

}

function setupAppointmentDatePicker(displayId, hiddenId, pickerId, buttonId, submitForm) {

    const display = document.getElementById(displayId);

    const hidden = document.getElementById(hiddenId);

    const picker = document.getElementById(pickerId);

    const button = document.getElementById(buttonId);

    if (!display || !hidden || !picker || !button) return;



    const sync = function () {

        if (!picker.value) return;

        hidden.value = picker.value;

        display.value = isoToDDMMYYYY(picker.value);

        if (submitForm) {

            const form = display.closest('form');

            if (form) form.submit();

        }

    };



    display.addEventListener('input', function () {

        this.value = this.value.replace(/[^\d-]/g, '').slice(0, 10);

        if (this.value.length === 10) {

            const iso = ddmmyyyyToISO(this.value);

            if (iso) {

                hidden.value = iso;

                picker.value = iso;

            }

        }

    });

    display.addEventListener('blur', function () {

        const iso = ddmmyyyyToISO(this.value);

        if (iso) {

            hidden.value = iso;

            picker.value = iso;

            this.value = isoToDDMMYYYY(iso);

        } else {

            this.value = isoToDDMMYYYY(hidden.value);

        }

    });

    picker.addEventListener('change', sync);

    button.addEventListener('click', function () {

        try {

            picker.focus({preventScroll: true});

            if (typeof picker.showPicker === 'function') picker.showPicker();

            else picker.click();

        } catch (e) {

            picker.click();

        }

    });

}

let appointmentPatientTimer = null;



let appointmentFamilyPatients = [];



function getFamilyModal() {

    const el = document.getElementById('familyMemberModal');

    return el && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(el) : null;

}



function clearFamilyPanel() {

    appointmentFamilyPatients = [];

    const openBtn = document.getElementById('appointment_family_open_btn');

    if (openBtn) openBtn.classList.add('d-none');

    const list = document.getElementById('familyModalList');

    if (list) list.innerHTML = '';

}



function setAppointmentPatientFieldsLocked(locked) {
    const nameEl = document.getElementById('appointment_patient_name');
    const ageEl = document.getElementById('appointment_age');
    const genderEl = document.getElementById('appointment_gender');

    [nameEl, ageEl].forEach(function (el) {
        if (!el) return;
        el.readOnly = !!locked;
        el.classList.toggle('patient-locked-field', !!locked);
        el.setAttribute('aria-readonly', locked ? 'true' : 'false');
    });
    if (genderEl) {
        // Keep the select enabled so its value is submitted, but prevent changes for registered patients.
        genderEl.classList.toggle('patient-locked-field', !!locked);
        genderEl.style.pointerEvents = locked ? 'none' : '';
        genderEl.setAttribute('aria-readonly', locked ? 'true' : 'false');
        genderEl.tabIndex = locked ? -1 : 0;
    }
}

function selectAppointmentPatient(patient, closeModal = true) {

    const mobileEl = document.getElementById('appointment_mobile');

    const nameEl = document.getElementById('appointment_patient_name');

    const ageEl = document.getElementById('appointment_age');

    const genderEl = document.getElementById('appointment_gender');

    const uhidEl = document.getElementById('appointment_uhid');

    const newFamilyEl = document.getElementById('appointment_new_family_member');

    const statusEl = document.getElementById('appointment_patient_status');



    uhidEl.value = patient.uhid || '';

    if (newFamilyEl) newFamilyEl.value = '0';

    nameEl.value = String(patient.fullname || '').toUpperCase();

    ageEl.value = patient.age || '';
        const ageUnitEl = document.getElementById('appointment_age_unit'); if (ageUnitEl) ageUnitEl.value = 'years';

    genderEl.value = patient.gender || 'Male';
    setAppointmentPatientFieldsLocked(true);

    mobileEl.value = (patient.mobile || '').split(',')[0].trim() || mobileEl.value;
    mobileEl.readOnly = true;
    const clearPatientBtn = document.getElementById('appointment_clear_patient_btn');
    if (clearPatientBtn) clearPatientBtn.classList.remove('d-none');
    const countEl = document.getElementById('appointment_mobile_count');
    if (countEl) countEl.textContent = mobileEl.value.replace(/\D/g, '').length + ' / 10 digits • ' + (appointmentFamilyPatients.length || 1) + ' registered';



    statusEl.innerHTML = 'FAMILY MEMBER SELECTED <button type="button" id="appointment_add_family_inline" class="btn btn-link btn-sm p-0 ms-1">+ ADD FAMILY MEMBER</button>';
    statusEl.className = 'small mt-1 text-success fw-bold';
    const addInline = document.getElementById('appointment_add_family_inline');
    if (addInline) addInline.addEventListener('click', startNewFamilyMember);



    const modal = getFamilyModal();

    if (closeModal && modal) modal.hide();

}



function startNewFamilyMember() {

    const uhidEl = document.getElementById('appointment_uhid');

    const newFamilyEl = document.getElementById('appointment_new_family_member');

    const statusEl = document.getElementById('appointment_patient_status');

    const nameEl = document.getElementById('appointment_patient_name');

    const ageEl = document.getElementById('appointment_age');

    const genderEl = document.getElementById('appointment_gender');



    uhidEl.value = '';

    if (newFamilyEl) newFamilyEl.value = '1';

    // Unlock demographics when switching from a registered patient to a new family member.
    setAppointmentPatientFieldsLocked(false);

    nameEl.value = '';

    ageEl.value = '';

    genderEl.value = 'Male';

    statusEl.textContent = 'NEW FAMILY MEMBER — SAME MOBILE';

    statusEl.className = 'small mt-1 text-primary fw-bold';
    const mobileEl = document.getElementById('appointment_mobile');
    if (mobileEl) mobileEl.readOnly = true;
    const clearPatientBtn = document.getElementById('appointment_clear_patient_btn');
    if (clearPatientBtn) clearPatientBtn.classList.remove('d-none');



    const modal = getFamilyModal();

    if (modal) modal.hide();

    setTimeout(function(){ nameEl.focus(); }, 250);

}



function renderFamilyModal(patients, mobile) {

    const list = document.getElementById('familyModalList');

    const mobileText = document.getElementById('familyModalMobile');

    const openBtn = document.getElementById('appointment_family_open_btn');

    if (!list) return;



    appointmentFamilyPatients = patients || [];

    if (mobileText) mobileText.textContent = mobile;

    list.innerHTML = '';



    appointmentFamilyPatients.forEach(function(p) {

        const row = document.createElement('button');

        row.type = 'button';

        row.className = 'family-modal-item';

        const meta = [

            p.age ? p.age + ' YRS' : '',

            p.gender || '',

            p.uhid || 'NO UHID'

        ].filter(Boolean).join(' • ');

        row.innerHTML = '<div class="fm-name">' + String(p.fullname || '').toUpperCase() + '</div>' +

                        '<div class="fm-meta">' + String(meta).toUpperCase() + '</div>';

        row.addEventListener('click', function(){ selectAppointmentPatient(p, true); });

        list.appendChild(row);

    });



    if (openBtn) {

        openBtn.classList.remove('d-none');

        openBtn.onclick = function(){

            const modal = getFamilyModal();

            if (modal) modal.show();

        };

    }



    const modal = getFamilyModal();

    if (modal) modal.show();

}



async function lookupAppointmentPatient() {

    const mobileEl = document.getElementById('appointment_mobile');

    const uhidEl = document.getElementById('appointment_uhid');

    const newFamilyEl = document.getElementById('appointment_new_family_member');

    const statusEl = document.getElementById('appointment_patient_status');

    const nameEl = document.getElementById('appointment_patient_name');

    const ageEl = document.getElementById('appointment_age');

    const genderEl = document.getElementById('appointment_gender');

    if (!mobileEl) return;



    const mobile = mobileEl.value.replace(/\D/g, '');

    clearFamilyPanel();

    uhidEl.value = '';

    if (newFamilyEl) newFamilyEl.value = '0';

    statusEl.textContent = '';
    statusEl.className = 'small mt-1';
    // Do not erase a name typed manually while checking a mobile number.
    // If a registered patient is found, selectAppointmentPatient() will populate
    // the saved patient details. Existing selected-patient details are cleared
    // when the mobile input itself is changed.

    if (mobile.length < 10) { mobileEl.readOnly = false; return; }



    try {

        const r = await fetch('opd_appointments.php?ajax=patient_lookup&mobile=' + encodeURIComponent(mobile));

        const data = await r.json();

        // Ignore stale lookup responses if the user has cleared or changed the mobile field.
        const currentMobile = mobileEl.value.replace(/\D/g, '');
        if (currentMobile !== mobile || currentMobile.length < 10) return;

        const patients = Array.isArray(data.patients) ? data.patients : [];



        const countEl = document.getElementById('appointment_mobile_count');
        if (countEl) countEl.textContent = mobile.length + ' / 10 digits' + (patients.length ? ' • ' + patients.length + ' registered' : ' • not registered');
        if (patients.length === 0) {
            setAppointmentPatientFieldsLocked(false);
            mobileEl.readOnly = false;
            if (newFamilyEl) newFamilyEl.value = '1';
            return;
        }



        if (patients.length === 1) {

            selectAppointmentPatient(patients[0], false);

            return;

        }



        statusEl.textContent = 'MULTIPLE FAMILY MEMBERS FOUND — SELECT FROM POPUP';

        statusEl.className = 'small mt-1 text-warning fw-bold';

        renderFamilyModal(patients, mobile);

    } catch (e) {

        clearFamilyPanel();

        statusEl.textContent = '';

    }

}



document.addEventListener('DOMContentLoaded', function () {

    setupAppointmentDatePicker('appointment_date_display', 'appointment_date', 'appointment_date_picker', 'appointment_date_btn', false);

    setupAppointmentDatePicker('date_filter_display', 'date_filter', 'date_filter_picker', 'date_filter_btn', true);



    const familyAddBtn = document.getElementById('familyModalAddBtn');

    if (familyAddBtn) familyAddBtn.addEventListener('click', startNewFamilyMember);

    const clearPatientBtn = document.getElementById('appointment_clear_patient_btn');
    if (clearPatientBtn) {
        clearPatientBtn.addEventListener('click', function () {
            const mobile = document.getElementById('appointment_mobile');
            const name = document.getElementById('appointment_patient_name');
            const age = document.getElementById('appointment_age');
            const gender = document.getElementById('appointment_gender');
            const uhid = document.getElementById('appointment_uhid');
            const newFamily = document.getElementById('appointment_new_family_member');
            const status = document.getElementById('appointment_patient_status');
            if (mobile) { mobile.readOnly = false; mobile.value = ''; mobile.focus(); }
            if (name) name.value = '';
            if (age) age.value = '';
            if (gender) gender.value = 'Male';
            setAppointmentPatientFieldsLocked(false);
            if (uhid) uhid.value = '';
            if (newFamily) newFamily.value = '1';
            if (status) { status.textContent = ''; status.className = 'small mt-1'; }
            const count = document.getElementById('appointment_mobile_count');
            if (count) count.textContent = '0 / 10 digits';
            clearFamilyPanel();
            clearPatientBtn.classList.add('d-none');
        });
    }
    syncDepartmentFromDoctor();
    filterAppointmentInsurances();
    const mobileCountEl = document.getElementById('appointment_mobile_count');
    const mobileInitial = document.getElementById('appointment_mobile');
    const initialUhid = document.getElementById('appointment_uhid');
    const clearInitialPatientBtn = document.getElementById('appointment_clear_patient_btn');
    if (mobileInitial && mobileCountEl) mobileCountEl.textContent = mobileInitial.value.replace(/\D/g, '').length + ' / 10 digits';
    // When editing an appointment linked to a registered patient, keep the saved demographics locked.
    if (initialUhid && initialUhid.value.trim() !== '') {
        setAppointmentPatientFieldsLocked(true);
        if (mobileInitial) mobileInitial.readOnly = true;
        if (clearInitialPatientBtn) clearInitialPatientBtn.classList.remove('d-none');
    }



    const mobileEl = document.getElementById('appointment_mobile');

    if (!mobileEl) return;



    mobileEl.addEventListener('input', function () {

        this.readOnly = false;
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
        const countEl = document.getElementById('appointment_mobile_count');
        if (countEl) countEl.textContent = this.value.length + ' / 10 digits';

        const patientUhidEl = document.getElementById('appointment_uhid');
        const wasRegisteredPatientSelected = !!(patientUhidEl && patientUhidEl.value);

        // Clear the old saved patient's details only when changing away from a
        // selected registered patient. Preserve a name manually typed for a new
        // or unregistered patient while the mobile number is being entered.
        if (wasRegisteredPatientSelected) {
            setAppointmentPatientFieldsLocked(false);
            const patientNameEl = document.getElementById('appointment_patient_name');
            const patientAgeEl = document.getElementById('appointment_age');
            const patientGenderEl = document.getElementById('appointment_gender');
            if (patientNameEl) patientNameEl.value = '';
            if (patientAgeEl) patientAgeEl.value = '';
            if (patientGenderEl) patientGenderEl.value = 'Male';
        }
        if (patientUhidEl) patientUhidEl.value = '';

        const patientStatusEl = document.getElementById('appointment_patient_status');
        if (patientStatusEl) { patientStatusEl.textContent = ''; patientStatusEl.className = 'small mt-1'; }
        const clearPatientBtn = document.getElementById('appointment_clear_patient_btn');
        if (clearPatientBtn) clearPatientBtn.classList.add('d-none');
        const newFamilyEl = document.getElementById('appointment_new_family_member');
        if (newFamilyEl) newFamilyEl.value = this.value.length === 0 ? '1' : '0';

        clearFamilyPanel();

        document.getElementById('appointment_patient_status').textContent = '';

        clearTimeout(appointmentPatientTimer);

        if (this.value.length === 10) {

            appointmentPatientTimer = setTimeout(lookupAppointmentPatient, 250);

        }

    });



    mobileEl.addEventListener('blur', lookupAppointmentPatient);

});



// Keep active tab on reload

document.addEventListener("DOMContentLoaded", function() {

    let hash = window.location.hash;
    if (hash === '#nav-queue') hash = '#nav-appt';

    if (hash) {

        let triggerEl = document.querySelector('button[data-bs-target="' + hash + '"]');

        if (triggerEl) {

            let tab = new bootstrap.Tab(triggerEl);

            tab.show();

        }

    }

    let tabButtons = document.querySelectorAll('button[data-bs-toggle="pill"]');

    tabButtons.forEach(btn => {

        btn.addEventListener('shown.bs.tab', function(e) {

            window.location.hash = e.target.getAttribute('data-bs-target');

        });

    });

});



function syncDepartmentFromDoctor() {
    const doc = document.getElementById('doctor_id');
    const dept = document.getElementById('department_id');
    if (!doc || !dept) return;
    const opt = doc.options[doc.selectedIndex];
    const deptId = opt ? (opt.getAttribute('data-dept') || '') : '';
    if (deptId) dept.value = deptId;
    filterDoctors(false);
}

function filterAppointmentInsurances() {
    const cat = document.getElementById('appointment_category_id');
    const ins = document.getElementById('appointment_insurance_id');
    if (!cat || !ins) return;
    const catId = cat.value;
    if (ins.options[0]) {
        ins.options[0].textContent = catId
            ? '-- BASE CATEGORY / NO INSURANCE --'
            : '-- SELECT CATEGORY FIRST --';
    }
    Array.from(ins.options).forEach((opt, index) => {
        if (index === 0) {
            opt.hidden = false;
            opt.disabled = false;
            return;
        }
        const matches = !!catId && opt.getAttribute('data-cat') === catId;
        opt.hidden = !matches;
        opt.disabled = !matches;
    });
    // Keep the current insurance only when it belongs to the selected category.
    // Otherwise automatically select the first active provider for this category.
    const selectedOption = ins.options[ins.selectedIndex];
    const selectedIsValid = selectedOption &&
        selectedOption.value !== '' &&
        selectedOption.getAttribute('data-cat') === catId &&
        !selectedOption.disabled;

    if (!catId) {
        ins.value = '';
    } else if (!selectedIsValid) {
        const firstMatching = Array.from(ins.options).find((opt, index) =>
            index > 0 &&
            opt.getAttribute('data-cat') === catId &&
            !opt.disabled
        );
        ins.value = firstMatching ? firstMatching.value : '';
    }
}

function filterDoctors(autoPick = true) {

    const deptId = document.getElementById('department_id').value;

    const docSelect = document.getElementById('doctor_id');

    const currentValue = docSelect.value;



    for (let i = 0; i < docSelect.options.length; i++) {

        const opt = docSelect.options[i];

        if (opt.value === "") continue;



        const matches = !deptId || opt.getAttribute('data-dept') === deptId;

        opt.hidden = !matches;

        opt.disabled = !matches;



        if (!matches && String(opt.value) === String(currentValue)) {

            docSelect.value = "";

        }

    }



    // If exactly one doctor matches the selected department, select it automatically.

    const matching = Array.from(docSelect.options).filter(opt => opt.value !== "" && !opt.disabled);

    if (deptId && matching.length === 1) {

        docSelect.value = matching[0].value;

    }

}





document.addEventListener('DOMContentLoaded', function () {

    const appointmentForm = document.querySelector('.appointment-booking-card form');

    if (!appointmentForm) return;



    appointmentForm.addEventListener('submit', function (e) {

        const uhidEl = document.getElementById('appointment_uhid');

        const newFamily = document.getElementById('appointment_new_family_member');

        const mobile = document.getElementById('appointment_mobile');

        const name = document.getElementById('appointment_patient_name');



        if (!mobile || mobile.value.replace(/\D/g, '').length < 10) {

            e.preventDefault();

            Swal.fire({icon:'warning', title:'Invalid Mobile Number', text:'PLEASE ENTER A VALID MOBILE NUMBER.', confirmButtonColor:'#2563eb'});

            return;

        }



        if (!name || !name.value.trim()) {

            e.preventDefault();

            Swal.fire({icon:'warning', title:'Patient Name Required', text:'PLEASE ENTER PATIENT NAME.', confirmButtonColor:'#2563eb'});

            return;

        }



        // A blank UHID is allowed only for a newly added family member.

        // This keeps appointment booking independent from patient registration/UHID creation.

        if (!uhidEl.value && !(newFamily && newFamily.value === '1')) {

            e.preventDefault();

            Swal.fire({icon:'warning', title:'Select Family Member', text:'PLEASE SELECT A FAMILY MEMBER OR CLICK + ADD FAMILY MEMBER.', confirmButtonColor:'#2563eb'});

        }

    });

});



function handleQueueAction(selectEl) {

    const action = selectEl.value;

    const trackId = selectEl.getAttribute('data-track-id');

    if (!action || !trackId) return;



    const prompt = action === 'out'
        ? {icon:'question', title:'Complete Consultation?', text:'MARK THIS PATIENT AS COMPLETED?'}
        : (action === 'reset' ? {icon:'warning', title:'Re-queue Patient?', text:'RE-QUEUE THIS PATIENT?'} : null);

    const proceed = function () {
        window.location.href = '?q_action=' + encodeURIComponent(action) + '&track_id=' + encodeURIComponent(trackId) + '#nav-appt';
    };

    if (prompt) {
        Swal.fire({...prompt, showCancelButton:true, confirmButtonText:'Yes, Continue', cancelButtonText:'No, Go Back', confirmButtonColor:'#2563eb', cancelButtonColor:'#64748b'})
            .then(function (result) { if (result.isConfirmed) proceed(); else selectEl.value = ''; });
        return;
    }

    proceed();

}



function formatWaitSeconds(totalSeconds) {

    totalSeconds = Math.max(0, Math.floor(totalSeconds));

    const h = Math.floor(totalSeconds / 3600);

    const m = Math.floor((totalSeconds % 3600) / 60);

    const sec = totalSeconds % 60;

    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');

}



function updateWaitingTimers() {

    document.querySelectorAll('.waiting-timer[data-start]').forEach(function(el) {

        const start = el.getAttribute('data-start');

        if (!start) return;

        const running = el.getAttribute('data-running') === '1';

        const startTs = new Date(start.replace(' ', 'T')).getTime();

        if (Number.isNaN(startTs)) return;



        let endTs = Date.now();

        if (!running) {

            const end = el.getAttribute('data-end');

            if (end) {

                const parsedEnd = new Date(end.replace(' ', 'T')).getTime();

                if (!Number.isNaN(parsedEnd)) endTs = parsedEnd;

            }

        }

        el.textContent = formatWaitSeconds((endTs - startTs) / 1000);

    });

}



updateWaitingTimers();

setInterval(updateWaitingTimers, 1000);



document.getElementById('searchQueue')?.addEventListener('keyup', function() {

    let val = this.value.toLowerCase();

    document.querySelectorAll('#queueTable tbody tr').forEach(row => {

        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';

    });

});

</script>



<?php require_once __DIR__ . '/layout_footer.php'; ?>
