<?php
// admin/print_prescription.php

require_once __DIR__ . '/../config/tenant_db.php';

$org_id    = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$visit_id  = (int)($_GET['visit_id'] ?? 0);

if ($visit_id <= 0) {
    die("Invalid Visit ID.");
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/*
 * Age/Sex display:
 * 20 Years / Male  -> 20y/m
 * 2 Months / Female -> 2m/f
 * 10 Days / Male    -> 10d/m
 */
function formatAgeSex($age, $ageUnit, $gender): string
{
    $age = (int)$age;

    $unit = strtolower(trim((string)$ageUnit));
    $unitMap = [
        'year'   => 'y',
        'years'  => 'y',
        'yr'     => 'y',
        'yrs'    => 'y',
        'y'      => 'y',
        'month'  => 'm',
        'months' => 'm',
        'mon'    => 'm',
        'm'      => 'm',
        'day'    => 'd',
        'days'   => 'd',
        'd'      => 'd',
    ];

    $unitShort = $unitMap[$unit] ?? ($unit !== '' ? strtolower(substr($unit, 0, 1)) : 'y');

    $sex = strtolower(trim((string)$gender));
    if (in_array($sex, ['male', 'm'], true)) {
        $sex = 'm';
    } elseif (in_array($sex, ['female', 'f'], true)) {
        $sex = 'f';
    } elseif (in_array($sex, ['other', 'others', 'o'], true)) {
        $sex = 'o';
    } else {
        $sex = $sex !== '' ? substr($sex, 0, 1) : '-';
    }

    return $age . $unitShort . '/' . $sex;
}

try {
    /*
     * Visit is restricted by org_id + center_id.
     * org_profile is tenant-scoped: each tenant DB has its own org_profile row (id=1).
     * Therefore the current session org_id selects the tenant, while the profile is
     * fetched from that tenant's org_profile.
     */
    $stmt = $tenant_pdo->prepare("
        SELECT
            v.*,
            p.uhid,
            p.fullname,
            p.gender,
            p.age,
            p.age_unit,
            p.mobile,
            p.address,
            d.full_name AS doctor_name,
            d.registration_no AS doctor_registration_no,
            q.qualification_name AS doctor_qualification,
            sp.specialization_name AS doctor_specialization,
            dept.dept_name
        FROM opd_visits v
        JOIN patient_master p ON p.patient_id = v.patient_id
        LEFT JOIN master_doctors d
            ON d.id = v.doctor_id
           AND d.org_id = v.org_id
           AND (d.center_id = v.center_id OR d.center_id IS NULL)
        LEFT JOIN master_qualifications q
            ON q.id = d.qualification_id
           AND q.org_id = d.org_id
        LEFT JOIN master_specializations sp
            ON sp.id = d.specialization_id
           AND sp.org_id = d.org_id
        LEFT JOIN master_departments dept
            ON dept.id = v.department_id
        WHERE v.visit_id = ?
          AND v.org_id = ?
          AND v.center_id = ?
        LIMIT 1
    ");

    $stmt->execute([$visit_id, $org_id, $center_id]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    // Backward-compatible recovery for visits created before clinical-note
    // columns were populated: use the best matching prescription template
    // only when the visit itself has no saved value.
    if ($visit) {
        try {
            $tplStmt = $tenant_pdo->prepare("
                SELECT default_symptoms, default_diagnosis, default_investigations, default_advice
                FROM prescription_template_master
                WHERE org_id = ? AND center_id = ? AND status = 1
                  AND (doctor_id = ? OR department_id = ? OR (doctor_id IS NULL AND department_id IS NULL))
                ORDER BY CASE WHEN doctor_id = ? THEN 1 WHEN department_id = ? THEN 2 ELSE 3 END, template_id DESC
                LIMIT 1
            ");
            $tplStmt->execute([$org_id, $center_id, (int)($visit['doctor_id'] ?? 0), (int)($visit['department_id'] ?? 0), (int)($visit['doctor_id'] ?? 0), (int)($visit['department_id'] ?? 0)]);
            $tpl = $tplStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
            // FIX: Only apply template defaults if the column is strictly NULL (old visits). 
            // If a doctor saved it as an empty string (''), it means they deliberately left it blank.
            if (!array_key_exists('symptoms', $visit) || is_null($visit['symptoms'])) {
                $visit['symptoms'] = $tpl['default_symptoms'] ?? '';
            }
            if (!array_key_exists('diagnosis', $visit) || is_null($visit['diagnosis'])) {
                $visit['diagnosis'] = $tpl['default_diagnosis'] ?? '';
            }
            if (!array_key_exists('investigations', $visit) || is_null($visit['investigations'])) {
                $visit['investigations'] = $tpl['default_investigations'] ?? '';
            }
            if (!array_key_exists('advice', $visit) || is_null($visit['advice'])) {
                $visit['advice'] = $tpl['default_advice'] ?? '';
            }
        } catch (Throwable $e) {}
    }

    if (!$visit) {
        die("Prescription not found.");
    }

    // Fetch organization profile from the current tenant.
    $orgStmt = $tenant_pdo->query("
        SELECT
            org_name,
            tagline,
            email,
            phone,
            address,
            city,
            pincode,
            gst_no
        FROM org_profile
        WHERE id = 1
        LIMIT 1
    ");

    $org = $orgStmt ? ($orgStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    // Decode prescription JSON.
    $rx_items = [];
    if (!empty($visit['prescription_json'])) {
        $rxDecoded = json_decode($visit['prescription_json'], true);
        if (is_array($rxDecoded) && isset($rxDecoded['items']) && is_array($rxDecoded['items'])) {
            $rx_items = $rxDecoded['items'];
        }
    }

    // Decode vitals JSON.
    $vitals = [];
    if (!empty($visit['vitals_json'])) {
        $vitals = json_decode($visit['vitals_json'], true) ?: [];
    }

    // Decode saved custom clinical fields.
    $customFields = [];
    if (!empty($visit['custom_fields_json'])) {
        $customFields = json_decode($visit['custom_fields_json'], true) ?: [];
    }

    $ageSex = formatAgeSex(
        $visit['age'] ?? 0,
        $visit['age_unit'] ?? 'Years',
        $visit['gender'] ?? ''
    );

    $orgName = trim($org['org_name'] ?? '');
    $orgTagline = trim($org['tagline'] ?? '');
    $orgAddress = trim($org['address'] ?? '');

    $orgLocation = trim(
        implode(', ', array_filter([
            trim($org['city'] ?? ''),
            trim($org['pincode'] ?? '')
        ]))
    );

    $doctorName = trim($visit['doctor_name'] ?? '');
    $doctorQualification = trim($visit['doctor_qualification'] ?? '');
    $doctorSpecialization = trim($visit['doctor_specialization'] ?? '');
    $department = trim($visit['dept_name'] ?? '');
    $doctorRegistrationNo = trim($visit['doctor_registration_no'] ?? '');

    $visitDate = !empty($visit['visit_date'])
        ? date('d M Y', strtotime($visit['visit_date']))
        : '';

    $followUpDate = !empty($visit['follow_up_date'])
        ? date('d M Y', strtotime($visit['follow_up_date']))
        : '';

    // Check if the sidebar needs to be displayed based on clinical content
    $has_sidebar = !empty($vitals) || !empty(trim((string)$visit['symptoms'])) || !empty(trim((string)$visit['diagnosis'])) || !empty(trim((string)$visit['investigations']));

} catch (Exception $e) {
    die("Error loading prescription: " . e($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prescription - <?= e($visit['fullname']) ?></title>

<style>
    :root {
        --primary: #16459a;
        --primary-dark: #103575;
        --accent: #e53935;
        --text: #243447;
        --muted: #718096;
        --line: #dfe7f1;
        --soft: #f6f8fb;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        padding: 18px;
        background: #eef2f7;
        color: var(--text);
        font-family: "Segoe UI", Arial, sans-serif;
        font-size: 11px;
    }

    .print-container {
        width: 100%;
        max-width: 780px;
        min-height: 1050px;
        margin: 0 auto;
        padding: 24px 26px 20px;
        background: #fff;
        box-shadow: 0 3px 18px rgba(15, 35, 65, .10);
    }

    /* HEADER */
    .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid var(--primary);
        position: relative;
    }

    .header::after {
        content: "";
        position: absolute;
        left: 0;
        bottom: -2px;
        width: 55px;
        height: 2px;
        background: var(--accent);
    }

    .clinic-name {
        margin: 0;
        color: var(--primary);
        font-size: 20px;
        line-height: 1.1;
        font-weight: 800;
        letter-spacing: .2px;
        text-transform: uppercase;
    }

    .clinic-tagline {
        margin-top: 4px;
        color: var(--muted);
        font-size: 9.5px;
    }

    .clinic-contact {
        margin-top: 3px;
        color: #58677a;
        font-size: 9px;
    }

    .doctor-block {
        text-align: right;
        min-width: 180px;
        padding-top: 1px;
    }

    .doctor-name {
        margin: 0;
        color: var(--primary-dark);
        font-size: 14px;
        font-weight: 800;
        text-transform: uppercase;
    }

    .doctor-qualification {
        margin-top: 2px;
        color: #52657d;
        font-size: 8px;
        line-height: 1.2;
        font-weight: 700;
        text-transform: uppercase;
    }

    .doctor-dept {
        margin-top: 2px;
        color: var(--muted);
        font-size: 8px;
        text-transform: uppercase;
    }

    .doctor-date {
        margin-top: 4px;
        color: #52657d;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
    }

    /* PATIENT STRIP */
    .patient-strip {
        display: grid;
        grid-template-columns: 1.55fr 1fr 1.35fr;
        gap: 0;
        margin: 13px 0 16px;
        border: 1px solid var(--line);
        border-radius: 6px;
        overflow: hidden;
        background: var(--soft);
    }

    .patient-cell {
        min-width: 0;
        padding: 8px 10px;
        border-right: 1px solid var(--line);
    }

    .patient-cell:last-child {
        border-right: 0;
    }

    .label {
        display: block;
        margin-bottom: 3px;
        color: #7b8798;
        font-size: 7.5px;
        line-height: 1;
        font-weight: 800;
        letter-spacing: .55px;
        text-transform: uppercase;
    }

    .value {
        display: block;
        color: #26384e;
        font-size: 10.5px;
        line-height: 1.2;
        font-weight: 700;
        text-transform: uppercase;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* CONTENT */
    .content-grid {
        display: grid;
        grid-template-columns: 30% 1fr;
        gap: 20px;
        min-height: 420px;
    }
    
    .content-grid.no-sidebar {
        grid-template-columns: 1fr;
    }

    .sidebar {
        padding-right: 16px;
        border-right: 1px dashed #cfd9e5;
    }

    .main-content {
        min-width: 0;
    }

    .section {
        margin-bottom: 14px;
    }

    .section-title {
        display: flex;
        align-items: center;
        gap: 6px;
        padding-bottom: 5px;
        margin-bottom: 7px;
        border-bottom: 1px solid var(--line);
        color: var(--primary);
        font-size: 8.5px;
        line-height: 1;
        font-weight: 800;
        letter-spacing: .45px;
        text-transform: uppercase;
    }

    .section-title::before {
        content: "";
        width: 3px;
        height: 10px;
        border-radius: 2px;
        background: var(--accent);
    }

    .side-value {
        color: #435268;
        font-size: 9.5px;
        line-height: 1.7;
        white-space: pre-line;
        text-transform: uppercase;
    }

    .vital-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        padding: 4px 0;
        border-bottom: 1px solid #f0f3f7;
    }

    .vital-key {
        color: #7a8798;
        font-size: 8px;
        text-transform: uppercase;
    }

    .vital-val {
        color: #30445d;
        font-size: 8.5px;
        font-weight: 700;
        text-transform: uppercase;
    }

    /* RX */
    .rx-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }

    .rx-symbol {
        color: var(--primary);
        font-family: Georgia, serif;
        font-size: 28px;
        line-height: 1;
        font-weight: 700;
    }

    .rx-label {
        color: #8591a2;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    th {
        padding: 6px 5px;
        border-bottom: 1px solid #cfd9e5;
        color: #758297;
        font-size: 7.5px;
        line-height: 1.1;
        font-weight: 800;
        text-align: left;
        letter-spacing: .35px;
        text-transform: uppercase;
    }

    td {
        padding: 7px 5px;
        border-bottom: 1px solid #edf1f5;
        color: #33465e;
        font-size: 9px;
        line-height: 1.25;
        font-weight: 600;
        vertical-align: top;
        text-transform: uppercase;
        word-break: break-word;
    }

    th:nth-child(1), td:nth-child(1) { width: 25%; }
    th:nth-child(2), td:nth-child(2) { width: 18%; }
    th:nth-child(3), td:nth-child(3) { width: 26%; }
    th:nth-child(4), td:nth-child(4) { width: 20%; }
    th:nth-child(5), td:nth-child(5) { width: 11%; }

    .med-name {
        color: var(--primary);
        font-weight: 800;
    }

    .empty-rx {
        padding: 16px 0;
        color: var(--muted);
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .advice {
        margin-top: 18px;
    }

    .advice-list {
        margin: 0;
        padding-left: 15px;
        color: #435268;
        font-size: 9px;
        line-height: 1.75;
        text-transform: uppercase;
    }

    .advice-list li {
        padding-left: 2px;
        margin-bottom: 1px;
    }

    /* FOOTER */
    .footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 20px;
        margin-top: 14px;
        padding-top: 12px;
        border-top: 1px solid var(--line);
    }

    .follow-up {
        color: var(--accent);
        font-size: 9.5px;
        font-weight: 800;
        text-transform: uppercase;
    }

    .signature {
        width: 170px;
        text-align: center;
    }

    .sig-line {
        height: 24px;
        margin-bottom: 4px;
        border-bottom: 1px solid #4a5a70;
    }

    .signature-label {
        color: #7b8798;
        font-size: 7.5px;
        font-weight: 800;
        letter-spacing: .35px;
        text-transform: uppercase;
    }

    .org-meta {
        margin-top: 2px;
        color: #8a95a5;
        font-size: 7.5px;
    }

    @media screen and (max-width: 700px) {
        body { padding: 8px; }
        .print-container { padding: 16px; }
        .patient-strip { grid-template-columns: 1fr 1fr; }
        .patient-cell:nth-child(2) { border-right: 0; }
        .patient-cell:last-child { border-right: 0; }
        .patient-cell:nth-child(-n+2) { border-bottom: 1px solid var(--line); }
        .content-grid { grid-template-columns: 1fr; }
        .sidebar { padding-right: 0; padding-bottom: 12px; border-right: 0; border-bottom: 1px dashed #cfd9e5; }
    }

    .download-bar { position:fixed;top:18px;right:18px;z-index:9999;display:flex;gap:8px }
    .download-btn { border:0;border-radius:8px;padding:10px 16px;background:#111827;color:#fff;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.15) }
    .download-btn:hover { opacity:.92 }

    @media print { .download-bar { display:none!important } }
    @media print {
        body { padding: 0; background: #fff; }
        .print-container { width: 100%; max-width: none; min-height: auto; margin: 0; padding: 10mm 8mm 7mm; box-shadow: none; }
        @page { size: A4 portrait; margin: 0; }
        .content-grid { min-height: 420px; }
        .patient-strip { display: grid; grid-template-columns: 1.55fr 1fr 1.35fr; }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    }

    .rx-table { width:100%;border-collapse:collapse;table-layout:fixed;margin-top:4px }
    .rx-table th { background:var(--primary);color:#fff;font-size:7px;padding:6px 5px;text-transform:uppercase;letter-spacing:.25px }
    .rx-table td { border-bottom:1px solid var(--line);padding:6px 5px;vertical-align:top;font-size:8px;line-height:1.25;word-break:break-word }
    .rx-table th:nth-child(1),.rx-table td:nth-child(1) { width:20% }
    .rx-table th:nth-child(2),.rx-table td:nth-child(2) { width:20% }
    .rx-table th:nth-child(3),.rx-table td:nth-child(3) { width:12% }
    .rx-table th:nth-child(4),.rx-table td:nth-child(4) { width:11% }
    .rx-table th:nth-child(5),.rx-table td:nth-child(5) { width:13% }
    .rx-table th:nth-child(6),.rx-table td:nth-child(6) { width:13% }
    .rx-table th:nth-child(7),.rx-table td:nth-child(7) { width:11% }
    .rx-table .med-name { font-weight:800;color:var(--primary-dark) }
    .rx-table .med-name small { display:block;margin-top:2px;font-size:6.5px;color:var(--muted);font-weight:600 }
    
    .doctor-notes,.custom-fields-print { margin-top:12px }
    .custom-print-row { display:grid;grid-template-columns:38% 62%;gap:8px;padding:3px 0;border-bottom:1px dotted var(--line);font-size:8px }
    .custom-print-row span { color:var(--muted);font-weight:700 }
    .custom-print-row strong { color:var(--text);font-weight:700 }
    
    @media print {
        body { background:#fff;padding:0 }
        .print-container { max-width:none;min-height:auto;margin:0;box-shadow:none;padding:12mm 10mm }
        .rx-table tr { page-break-inside:avoid }
        .section,.doctor-notes,.custom-fields-print { page-break-inside:avoid }
    }
</style>

</head>

<body onload="window.print()">

<div class="download-bar">
    <button type="button" class="download-btn" onclick="downloadPrescriptionPDF()">⬇ Download / Save PDF</button>
    <button type="button" class="download-btn" onclick="window.print()">🖨 Print</button>
</div>

<script>
function downloadPrescriptionPDF(){
    window.print();
}
</script>

<div class="print-container">

    <!-- HEADER -->
    <div class="header">
        <div>
            <h1 class="clinic-name">
                <?= e($orgName ?: 'Hospital / Clinic') ?>
            </h1>

            <?php if ($orgTagline !== ''): ?>
                <div class="clinic-tagline"><?= e($orgTagline) ?></div>
            <?php endif; ?>

            <?php if ($orgAddress !== '' || $orgLocation !== ''): ?>
                <div class="clinic-contact">
                    <?= e($orgAddress) ?>
                    <?php if ($orgAddress !== '' && $orgLocation !== ''): ?> · <?php endif; ?>
                    <?= e($orgLocation) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($org['phone'])): ?>
                <div class="clinic-contact">
                    Contact: <?= e($org['phone']) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="doctor-block">
            <div class="doctor-name">
                <?= e($doctorName ?: 'General Doctor') ?>
            </div>

            <?php if ($doctorQualification !== ''): ?>
                <div class="doctor-qualification">
                    <?= e($doctorQualification) ?>
                </div>
            <?php endif; ?>

            <div class="doctor-dept">
                <?= e($doctorSpecialization ?: ($department ?: 'OPD Department')) ?>
            </div>

            <div class="doctor-date">
                <?= e($visitDate) ?>
            </div>
        </div>
    </div>

    <!-- PATIENT DETAILS -->
    <div class="patient-strip">
        <div class="patient-cell">
            <span class="label">Patient Name</span>
            <span class="value"><?= e($visit['fullname']) ?></span>
        </div>

        <div class="patient-cell">
            <span class="label">Age / Sex</span>
            <!-- Added inline style here to force lowercase -->
            <span class="value" style="text-transform: lowercase;"><?= e($ageSex) ?></span>
        </div>

        <div class="patient-cell">
            <span class="label">UHID</span>
            <span class="value"><?= e($visit['uhid']) ?></span>
        </div>
    </div>

    <div class="content-grid <?= !$has_sidebar ? 'no-sidebar' : '' ?>">

        <?php if ($has_sidebar): ?>
        <!-- LEFT -->
        <aside class="sidebar">

            <?php if (!empty($vitals)): ?>
                <div class="section">
                    <div class="section-title">Vitals</div>
                    <?php foreach ($vitals as $key => $val): ?>
                        <?php if (trim((string)$val) === '') continue; ?>
                        <div class="vital-row">
                            <span class="vital-key">
                                <?= e(str_replace('_', ' ', $key)) ?>
                            </span>
                            <span class="vital-val">
                                <?= e($val) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty(trim((string)$visit['symptoms']))): ?>
                <div class="section">
                    <div class="section-title">Symptoms</div>
                    <div class="side-value"><?= nl2br(e($visit['symptoms'])) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty(trim((string)$visit['diagnosis']))): ?>
                <div class="section">
                    <div class="section-title">Diagnosis</div>
                    <div class="side-value"><?= nl2br(e($visit['diagnosis'])) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty(trim((string)$visit['investigations']))): ?>
                <div class="section">
                    <div class="section-title">Investigations</div>
                    <div class="side-value"><?= nl2br(e($visit['investigations'])) ?></div>
                </div>
            <?php endif; ?>

        </aside>
        <?php endif; ?>

        <!-- RIGHT -->
        <main class="main-content">

            <div class="rx-header">
                <div class="rx-symbol">℞</div>
                <div class="rx-label">Prescription</div>
            </div>

            <?php if (empty($rx_items)): ?>
                <div class="empty-rx">No medicines prescribed.</div>
            <?php else: ?>
                <table class="rx-table">
                    <thead><tr>
                        <th>Medicine</th><th>Dose / Strength</th><th>Frequency</th><th>Meal</th><th>Form</th><th>Duration</th><th>Qty</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rx_items as $item): ?>
                        <tr>
                            <td class="med-name">
                                <?= e($item['medicine_name'] ?? '') ?>
                                <?php if (!empty($item['generic_name'])): ?><small><?= e($item['generic_name']) ?></small><?php endif; ?>
                            </td>
                            <td><?= e(trim(implode(' ', array_filter([$item['dose'] ?? '', $item['strength'] ?? ''], fn($v) => trim((string)$v) !== '')))) ?></td>
                            <td><?= e($item['frequency'] ?? '') ?></td>
                            <td><?= e($item['meal'] ?? '') ?></td>
                            <td><?= e(trim(implode(' / ', array_filter([$item['unit'] ?? '', $item['dosage_form'] ?? ''])))) ?></td>
                            <td><?= e($item['duration'] ?? '') ?></td>
                            <td><?= e($item['quantity'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty(trim((string)$visit['doctor_notes']))): ?>
                <div class="section doctor-notes">
                    <div class="section-title">Doctor Notes</div>
                    <div class="side-value"><?= nl2br(e($visit['doctor_notes'])) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($customFields)): ?>
                <div class="section custom-fields-print">
                    <div class="section-title">Clinical Details</div>
                    <?php foreach ($customFields as $key => $value): ?>
                        <?php if (is_array($value)) $value = implode(', ', array_filter($value)); ?>
                        <?php $value = trim((string)$value); if ($value === '') continue; ?>
                        <div class="custom-print-row">
                            <span><?= e(ucwords(str_replace(['_', '-'], ' ', (string)$key))) ?></span>
                            <strong><?= e($value) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty(trim((string)$visit['advice']))): ?>
                <div class="section advice">
                    <div class="section-title">Advice / Instructions</div>
                    <ul class="advice-list">
                        <?php
                        $adviceLines = preg_split('/\r\n|\r|\n/', trim((string)$visit['advice']));
                        foreach ($adviceLines as $line):
                            $line = trim($line);
                            if ($line === '') continue;
                        ?>
                            <li><?= e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- FOOTER -->
    <div class="footer">

        <div>
            <?php if ($followUpDate !== ''): ?>
                <div class="follow-up">
                    Next Visit: <?= e($followUpDate) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="signature">
            <div class="sig-line"></div>
            <div class="signature-label">Doctor's Signature</div>
        </div>

    </div>

</div>

</body>
</html>