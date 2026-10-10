<?php

// admin/opd_appointments.php

$page_title = "OPD Appointments & Live Queue";

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php'; 



$msg = "";

$err = "";

$org_id = (int)($_SESSION['org_id'] ?? 1);

$center_id = (int)($_SESSION['center_id'] ?? 1);

$created_by = (int)($_SESSION['user_id'] ?? 0);

$today_date = date('Y-m-d');



try {

    $tenant_pdo->exec("ALTER TABLE opd_appointments ADD COLUMN IF NOT EXISTS uhid VARCHAR(50) NULL AFTER center_id");

    $tenant_pdo->exec("ALTER TABLE opd_appointments ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER remark");

    $tenant_pdo->exec("ALTER TABLE opd_queue_tracking ADD COLUMN IF NOT EXISTS waiting_since DATETIME NULL AFTER status");

    $tenant_pdo->exec("

        UPDATE opd_queue_tracking

        SET waiting_since = CASE

            WHEN waiting_since IS NOT NULL THEN waiting_since

            WHEN in_time IS NOT NULL THEN in_time

            ELSE NOW()

        END

        WHERE waiting_since IS NULL

    ");

} catch (Exception $e) {}



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

        INSERT IGNORE INTO opd_queue_tracking (org_id, center_id, visit_id, status, waiting_since)

        SELECT org_id, center_id, visit_id, 'Waiting', NOW()

        FROM opd_visits 

        WHERE visit_date = CURDATE() 

        AND visit_id NOT IN (SELECT visit_id FROM opd_queue_tracking)

    ");

} catch (Exception $e) {}



// ==============================================================

// 1. QUEUE TRACKING ACTIONS (Call In, Waiting Timer, Check Out, Re-queue)

// ==============================================================

if (isset($_GET['q_action']) && isset($_GET['track_id'])) {

    $action = $_GET['q_action'];

    $track_id = (int)$_GET['track_id'];



    try {

        if ($action === 'in') {

            $tenant_pdo->prepare(

                "UPDATE opd_queue_tracking

                 SET waiting_since = COALESCE(waiting_since, NOW()),

                     status = 'Inside Cabin',

                     in_time = NOW()

                 WHERE track_id = ? AND out_time IS NULL"

            )->execute([$track_id]);

            if (function_exists('set_flash_msg')) set_flash_msg("PATIENT CALLED IN!");

        } elseif ($action === 'waiting') {

            $tenant_pdo->prepare(

                "UPDATE opd_queue_tracking

                 SET waiting_since = COALESCE(waiting_since, NOW())

                 WHERE track_id = ?"

            )->execute([$track_id]);

            if (function_exists('set_flash_msg')) set_flash_msg("PATIENT PRESENT!");

        } elseif ($action === 'out') {

            $tenant_pdo->prepare(

                "UPDATE opd_queue_tracking

                 SET status = 'Completed', out_time = NOW()

                 WHERE track_id = ?"

            )->execute([$track_id]);

            if (function_exists('set_flash_msg')) set_flash_msg("CONSULTATION COMPLETED!");

        } elseif ($action === 'reset') {

            $tenant_pdo->prepare(

                "UPDATE opd_queue_tracking

                 SET status = 'Waiting', in_time = NULL, out_time = NULL, waiting_since = NOW()

                 WHERE track_id = ?"

            )->execute([$track_id]);

            if (function_exists('set_flash_msg')) set_flash_msg("PATIENT RE-QUEUED!");

        }

        header("Location: opd_appointments.php#nav-queue");

        exit;

    } catch (Exception $e) { $err = $e->getMessage(); }

}



// ==============================================================

 // EDIT APPOINTMENT LOAD

 // ==============================================================

$edit_appointment_id = (int)($_GET['edit_appointment'] ?? 0);

$edit_appointment = null;



if ($edit_appointment_id > 0) {

    try {

        $ea = $tenant_pdo->prepare("

            SELECT a.\*,

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

    $appt_date      = !empty($_POST['appointment_date']) ? $_POST['appointment_date'] : $today_date;

    $appt_time      = !empty($_POST['appointment_time']) ? $_POST['appointment_time'] : null;

    $department_id  = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;

    $doctor_id      = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;

    $remark         = trim($_POST['remark'] ?? '');

    $selected_uhid = trim((string)($_POST['uhid'] ?? ''));

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

                    SELECT patient_id, uhid, mobile

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

                    appointment_date = ?,

                    appointment_time = ?,

                    remark = ?

                WHERE appointment_id = ? AND org_id = ? AND center_id = ?

            ");

            $up->execute([

                ($selected_uhid !== '' ? $selected_uhid : null), $patient_name, $mobile, $age, $gender,

                $department_id, $doctor_id, $appt_date, $appt_time, $remark,

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

    $appt_date     = !empty($_POST['appointment_date']) ? $_POST['appointment_date'] : $today_date;

    $appt_time     = !empty($_POST['appointment_time']) ? $_POST['appointment_time'] : null;

    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;

    $doctor_id     = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;

    $remark        = trim($_POST['remark'] ?? '');

    $selected_uhid = trim((string)($_POST['uhid'] ?? ''));

    $new_family_member = (int)($_POST['new_family_member'] ?? 0);



    if ($patient_name !== '' && strlen($mobile) >= 10 && $doctor_id > 0) {

        try {

            $tenant_pdo->beginTransaction();



            if ($selected_uhid !== '') {

                // Existing patient: validate only. NEVER overwrite master fields.

                $find = $tenant_pdo->prepare("

                    SELECT patient_id, uhid, mobile

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

                $selected_uhid = (string)$patient['uhid'];

            } else {

                // New/unregistered family member: appointment only.

                // Existing mobile is allowed. No patient row and no UHID are created here.

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



            $stmt = $tenant_pdo->prepare("

                INSERT INTO opd_appointments

                    (org_id, center_id, uhid, patient_name, mobile, age, gender,

                     department_id, doctor_id, appointment_date, appointment_time, status, remark, created_by)

                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)

            ");

            $stmt->execute([

                $org_id, $center_id, ($selected_uhid !== '' ? $selected_uhid : null), $patient_name,

                $mobile, $age, $gender, $department_id, $doctor_id,

                $appt_date, $appt_time, $remark, $created_by

            ]);



            $tenant_pdo->commit();

            if (function_exists('set_flash_msg')) {

                set_flash_msg($selected_uhid !== ''

                    ? "Appointment Booked For Registered Patient!"

                    : "Appointment Booked For New Family Member. Registration Will Create UHID.");

            }

            header("Location: opd_appointments.php");

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

$departments = $tenant_pdo->query("SELECT id, dept_name FROM master_departments WHERE org_id = $org_id AND status = 1")->fetchAll();

$doctors = $tenant_pdo->query("SELECT id, full_name, department_id FROM master_doctors WHERE org_id = $org_id AND status = 1")->fetchAll();



// --- FETCH APPOINTMENTS ---

$date_filter = $_GET['date_filter'] ?? $today_date;

$appointments = $tenant_pdo->prepare("

    SELECT a.\*,

           COALESCE(p.fullname, a.patient_name) AS patient_display_name,

           COALESCE(p.mobile, a.mobile) AS patient_display_mobile,

           COALESCE(p.uhid, '') AS patient_uhid,

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



// --- FETCH LIVE QUEUE TRACKING ---

$queue_list = $tenant_pdo->prepare("

    SELECT qt.\*, v.token_no, p.fullname, p.age, p.gender, p.mobile, d.full_name as doctor_name

    FROM opd_queue_tracking qt

    JOIN opd_visits v ON qt.visit_id = v.visit_id

    JOIN patient_master p ON v.patient_id = p.patient_id

    LEFT JOIN master_doctors d ON v.doctor_id = d.id

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



/\* APPOINTMENT SCREEN: ALL TEXT IN CAPITALS \*/

.appointment-booking-card input[type="text"],

.appointment-booking-card textarea,

.appointment-schedule-card,

\#familyMemberModal,

\#familyMemberModal \* {

    text-transform: uppercase !important;

}



/\* Keep mobile/search/date/time/numeric values unaffected \*/

\#appointment_mobile,

\#appointment_date_display,

\#date_filter_display,

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

/\* BLINKING STATUS FOR PATIENT INSIDE CABIN \*/

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



/\* Keep appointment Action dropdown visible above the table/card. \*/

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

    <li class="nav-item" role="presentation">

        <button class="nav-link px-4" id="nav-queue-tab" data-bs-toggle="pill" data-bs-target="#nav-queue" type="button" role="tab"><i class="bi bi-display me-2"></i>Live Queue Tracking</button>

    </li>

</ul>



<div class="tab-content" id="pills-tabContent">



    <!-- ========================================== -->

    <!-- TAB 1: APPOINTMENT DESK -->

    <!-- ========================================== -->

    <div class="tab-pane fade show active" id="nav-appt" role="tabpanel">

        <!-- BOOK NEW APPOINTMENT -->

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

                            <label class="form-label small fw-semibold text-secondary mb-1">Date \*</label>

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



                        <div class="col-md-2">

                            <label class="form-label small fw-semibold text-secondary mb-1">Time</label>

                            <input type="time" name="appointment_time" class="form-control form-control-sm text-dark fw-bold" value="<?= htmlspecialchars($edit_appointment['appointment_time'] ?? '') ?>">

                        </div>



                        <div class="col-md-2">

                            <label class="form-label small fw-semibold text-secondary mb-1">Mobile No. / Search \*</label>

                            <input type="text" name="mobile" id="appointment_mobile" class="form-control form-control-sm" maxlength="10" placeholder="MOBILE NO." value="<?= htmlspecialchars($edit_appointment['patient_display_mobile'] ?? $edit_appointment['mobile'] ?? '') ?>" required>

                            <input type="hidden" name="uhid" id="appointment_uhid" value="<?= htmlspecialchars($edit_appointment['uhid'] ?? '') ?>">

                            <input type="hidden" name="new_family_member" id="appointment_new_family_member" value="<?= empty($edit_appointment['uhid']) ? '1' : '0' ?>">

                            <div id="appointment_patient_status" class="small mt-1"></div>

                            <div id="appointment_family_panel" class="family-panel d-none"></div>

                            <button type="button" id="appointment_family_open_btn" class="btn btn-outline-primary btn-sm w-100 mt-1 d-none" style="font-size:10px;font-weight:800;">VIEW FAMILY MEMBERS</button>

                        </div>



                        <div class="col-md-3">

                            <label class="form-label small fw-semibold text-secondary mb-1">Patient Name \*</label>

                            <input type="text" name="patient_name" id="appointment_patient_name" class="form-control form-control-sm" placeholder="PATIENT NAME" value="<?= htmlspecialchars($edit_appointment['patient_display_name'] ?? $edit_appointment['patient_name'] ?? '') ?>" required>

                        </div>



                        <div class="col-md-1">

                            <label class="form-label small fw-semibold text-secondary mb-1">Age</label>

                            <input type="number" name="age" id="appointment_age" class="form-control form-control-sm" placeholder="AGE" value="<?= htmlspecialchars($edit_appointment['patient_display_age'] ?? $edit_appointment['age'] ?? '') ?>">

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

                            <label class="form-label small fw-semibold text-secondary mb-1">Department</label>

                            <select name="department_id" id="department_id" class="form-select form-select-sm" onchange="filterDoctors()">

                                <option value="">-- All --</option>

                                <?php foreach ($departments as $dept): ?>

                                    <option value="<?= $dept['id'] ?>" <?= ((string)($edit_appointment['department_id'] ?? '') === (string)$dept['id']) ? 'selected' : '' ?>><?= htmlspecialchars($dept['dept_name']) ?></option>

                                <?php endforeach; ?>

                            </select>

                        </div>



                        <div class="col-md-3">

                            <label class="form-label small fw-semibold text-secondary mb-1">Doctor \*</label>

                            <select name="doctor_id" id="doctor_id" class="form-select form-select-sm" required>

                                <option value="">-- Select --</option>

                                <?php foreach ($doctors as $doc): ?>

                                    <option value="<?= $doc['id'] ?>" data-dept="<?= $doc['department_id'] ?>" <?= ((string)($edit_appointment['doctor_id'] ?? '') === (string)$doc['id']) ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>

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

                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-calendar2-week text-primary me-2"></i>Appointments Schedule</h6>

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

            </div>



            <div class="table-responsive appointment-table-wrap">

                <table class="table table-hover align-middle mb-0 small">

                    <thead class="table-light sticky-top">

                        <tr>

                            <th class="ps-3">Time</th>

                            <th>Patient Name</th>

                            <th>Mobile</th>

                            <th>Department</th>

                            <th>Doctor</th>

                            <th class="text-center">Status</th>

                            <th class="text-end pe-3">Action</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($appointments)): ?>

                            <tr><td colspan="7" class="text-center py-5 text-muted">No appointments found for the selected date.</td></tr>

                        <?php else: ?>

                            <?php foreach ($appointments as $row):

                                $sc = $row['status'] == 'Confirmed' ? 'success' : ($row['status'] == 'Cancelled' ? 'danger' : 'warning');

                            ?>

                                <tr>

                                    <td class="ps-3 fw-bold text-primary">

                                        <?= !empty($row['appointment_time']) ? date('h:i A', strtotime($row['appointment_time'])) : 'N/A' ?>

                                    </td>

                                    <td>

                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['patient_display_name'] ?? $row['patient_name']) ?></div><?php if (!empty($row['patient_uhid'])): ?><small class="text-muted"><?= htmlspecialchars($row['patient_uhid']) ?></small><?php endif; ?>

                                    </td>

                                    <td class="text-muted"><?= htmlspecialchars($row['patient_display_mobile'] ?? $row['mobile']) ?></td>

                                    <td class="text-secondary"><?= htmlspecialchars($row['dept_name'] ?? 'N/A') ?></td>

                                    <td><div class="fw-semibold text-dark"><?= htmlspecialchars($row['doctor_name'] ?? 'N/A') ?></div></td>

                                    <td class="text-center">

                                        <span class="badge bg-<?= $sc ?>-subtle text-<?= $sc ?> border border-<?= $sc ?>">

                                            <?= htmlspecialchars($row['status']) ?>

                                        </span>

                                    </td>

                                    <td class="text-end pe-3">

                                        <div class="d-inline-flex gap-1 align-items-center action-buttons">

                                            <?php if($row['status'] !== 'Cancelled'): ?>

                                                <a href="?date_filter=<?= urlencode($date_filter) ?>&edit_appointment=<?= (int)$row['appointment_id'] ?>"

                                                   class="btn btn-sm btn-outline-primary action-btn"

                                                   title="EDIT APPOINTMENT">

                                                    <i class="bi bi-pencil-square"></i><span class="action-label">EDIT</span>

                                                </a>

                                            <?php endif; ?>



                                            <?php if($row['status'] === 'Pending'): ?>

                                                <a href="?date_filter=<?= urlencode($date_filter) ?>&update_status=Confirmed&id=<?= (int)$row['appointment_id'] ?>"

                                                   class="btn btn-sm btn-outline-success action-btn"

                                                   title="CONFIRM APPOINTMENT">

                                                    <i class="bi bi-check-circle"></i><span class="action-label">CONFIRM</span>

                                                </a>

                                                <a href="?date_filter=<?= urlencode($date_filter) ?>&update_status=Cancelled&id=<?= (int)$row['appointment_id'] ?>"

                                                   class="btn btn-sm btn-outline-danger action-btn"

                                                   title="CANCEL APPOINTMENT"

                                                   onclick="return confirmAppointmentCancel(event, this);">

                                                    <i class="bi bi-x-circle"></i><span class="action-label">CANCEL</span>

                                                </a>

                                            <?php elseif($row['status'] === 'Confirmed'): ?>

                                                <a href="opd_registration.php?appointment_id=<?= (int)$row['appointment_id'] ?>"

                                                   class="btn btn-sm btn-outline-success action-btn"

                                                   title="CHECK-IN / REGISTER PATIENT">

                                                    <i class="bi bi-box-arrow-in-right"></i><span class="action-label">CHECK-IN</span>

                                                </a>

                                                <a href="?date_filter=<?= urlencode($date_filter) ?>&update_status=Cancelled&id=<?= (int)$row['appointment_id'] ?>"

                                                   class="btn btn-sm btn-outline-danger action-btn"

                                                   title="CANCEL APPOINTMENT"

                                                   onclick="return confirmAppointmentCancel(event, this);">

                                                    <i class="bi bi-x-circle"></i><span class="action-label">CANCEL</span>

                                                </a>

                                            <?php elseif($row['status'] === 'Checked In'): ?>

                                                <a href="opd_registration.php?appointment_id=<?= (int)$row['appointment_id'] ?>"

                                                   class="btn btn-sm btn-outline-success action-btn"

                                                   title="OPEN REGISTRATION">

                                                    <i class="bi bi-folder2-open"></i><span class="action-label">REGISTRATION</span>

                                                </a>

                                            <?php endif; ?>

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



    <!-- ========================================== -->

    <!-- TAB 2: LIVE QUEUE & TRACKING (TV DISPLAY) -->

    <!-- ========================================== -->

    <div class="tab-pane fade" id="nav-queue" role="tabpanel">



        <!-- Tracking Table -->

        <div class="card border-0 shadow-sm rounded-3">

            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">

                <h6 class="mb-0 fw-bold text-dark small"><i class="bi bi-clock-history text-primary me-2"></i>Patient Timeline & Tracking</h6>

                <input type="text" id="searchQueue" class="form-control form-control-sm w-25" placeholder="Search Token / Name...">

            </div>

            <div class="table-responsive" style="max-height: 600px;">

                <table class="table table-hover align-middle mb-0 small" id="queueTable">

                    <thead class="table-light sticky-top">

                        <tr>

                            <th class="ps-3">Token No.</th>

                            <th>Patient Name</th>

                            <th>Consulting Doctor</th>

                            <th class="text-center">Patient Present</th>

                            <th class="text-center">In Time</th>

                            <th class="text-center">Out Time</th>

                            <th class="text-center">Status</th>

                            <th class="text-end pe-3">Desk Actions</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($queue_list)): ?>

                            <tr><td colspan="8" class="text-center py-5 text-muted">Queue is empty today. Generate tokens from Registration desk.</td></tr>

                        <?php else: ?>

                            <?php foreach ($queue_list as $q): 

                                $sBadge = 'bg-secondary';

                                if($q['status'] == 'Waiting') $sBadge = 'bg-warning text-dark border-warning bg-opacity-10 border';

                                elseif($q['status'] == 'Inside Cabin') $sBadge = 'bg-primary text-white status-blink';

                                elseif($q['status'] == 'Completed') $sBadge = 'bg-success text-success border-success bg-opacity-10 border';

                            ?>

                                <?php

                                    $waitingSeconds = 0;

                                    if (!empty($q['waiting_since'])) {

                                        $waitStartTs = strtotime($q['waiting_since']);

                                        $endTs = !empty($q['in_time']) ? strtotime($q['in_time']) : time();

                                        if ($waitStartTs) $waitingSeconds = max(0, $endTs - $waitStartTs);

                                    }

                                    $waitHours = floor($waitingSeconds / 3600);

                                    $waitMinutes = floor(($waitingSeconds % 3600) / 60);

                                    $waitSecs = $waitingSeconds % 60;

                                    $waitDisplay = sprintf('%02d:%02d:%02d', $waitHours, $waitMinutes, $waitSecs);

                                ?>

                                <tr class="<?= $q['status'] == 'Inside Cabin' ? 'table-primary bg-opacity-10' : '' ?>">

                                    <td class="ps-3 fw-bold fs-6 text-dark"><?= getFYToken($q['token_no'], $today_date) ?></td>

                                    <td class="fw-bold text-secondary"><?= htmlspecialchars($q['fullname']) ?></td>

                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($q['doctor_name'] ?? 'General') ?></td>

                                    <td class="text-center text-warning fw-bold font-monospace">

                                        <span class="waiting-timer" data-start="<?= !empty($q['waiting_since']) ? htmlspecialchars($q['waiting_since'], ENT_QUOTES) : '' ?>" data-end="<?= !empty($q['in_time']) ? htmlspecialchars($q['in_time'], ENT_QUOTES) : '' ?>" data-running="<?= $q['status'] === 'Waiting' ? '1' : '0' ?>">

                                            <?= htmlspecialchars($waitDisplay) ?>

                                        </span>

                                    </td>

                                    <td class="text-center text-primary fw-bold font-monospace"><?= !empty($q['in_time']) ? date('h:i A', strtotime($q['in_time'])) : '--:--' ?></td>

                                    <td class="text-center text-success fw-bold font-monospace"><?= !empty($q['out_time']) ? date('h:i A', strtotime($q['out_time'])) : '--:--' ?></td>

                                    <td class="text-center"><span class="badge <?= $sBadge ?> px-2 py-1"><?= htmlspecialchars($q['status']) ?></span></td>

                                    <td class="text-end pe-3">

                                        <select class="form-select form-select-sm queue-action-select d-inline-block fw-bold" style="width: 145px;" data-track-id="<?= (int)$q['track_id'] ?>" onchange="handleQueueAction(this)">

                                            <option value="">ACTION</option>

                                            <?php if($q['status'] == 'Waiting'): ?>

                                                <option value="waiting">PATIENT PRESENT</option>

                                                <option value="in">CALL IN</option>

                                            <?php elseif($q['status'] == 'Inside Cabin'): ?>

                                                <option value="out">DONE</option>

                                            <?php else: ?>

                                                <option value="reset">RE-QUEUE</option>

                                            <?php endif; ?>

                                        </select>

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

    return \`${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}\`;

}

function isoToDDMMYYYY(value) {

    const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);

    return m ? \`${m[3]}-${m[2]}-${m[1]}\` : '';

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

    genderEl.value = patient.gender || 'Male';

    mobileEl.value = (patient.mobile || '').split(',')[0].trim() || mobileEl.value;



    statusEl.textContent = 'FAMILY MEMBER SELECTED';

    statusEl.className = 'small mt-1 text-success fw-bold';



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

    nameEl.value = '';

    ageEl.value = '';

    genderEl.value = 'Male';

    statusEl.textContent = 'NEW FAMILY MEMBER — APPOINTMENT ONLY';

    statusEl.className = 'small mt-1 text-primary fw-bold';



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



    if (mobile.length < 10) return;



    try {

        const r = await fetch('opd_appointments.php?ajax=patient_lookup&mobile=' + encodeURIComponent(mobile));

        const data = await r.json();

        const patients = Array.isArray(data.patients) ? data.patients : [];



        if (patients.length === 0) {

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



    const mobileEl = document.getElementById('appointment_mobile');

    if (!mobileEl) return;



    mobileEl.addEventListener('input', function () {

        this.value = this.value.replace(/\D/g, '').slice(0, 10);

        document.getElementById('appointment_uhid').value = '';

        const newFamilyEl = document.getElementById('appointment_new_family_member');

        if (newFamilyEl) newFamilyEl.value = '0';

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



function filterDoctors() {

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
        window.location.href = '?q_action=' + encodeURIComponent(action) + '&track_id=' + encodeURIComponent(trackId) + '#nav-queue';
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
