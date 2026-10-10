<?php

// admin/master_investigations.php

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php';



$page_title = "Investigation Master";



$org_id     = (int)($_SESSION['org_id'] ?? 1);

$center_id  = (int)($_SESSION['center_id'] ?? 1);

$created_by = (int)($_SESSION['user_id'] ?? 0);

// Page-specific SweetAlert messages; independent of browser alert() and layout flash handling.
function investigation_swal(string $type, string $message): void {
    $_SESSION['investigation_swal'] = ['type' => $type, 'message' => $message];
}




// SAVE / UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_investigation'])) {
    $investigation_name = strtoupper(trim($_POST['investigation_name'] ?? ''));
    $investigation_code = strtoupper(trim($_POST['investigation_code'] ?? ''));
    $investigation_type = strtoupper(trim($_POST['investigation_type'] ?? ''));
    $description = strtoupper(trim($_POST['description'] ?? ''));
    $status = isset($_POST['status']) ? 1 : 0;
    $edit_id = (int)($_POST['edit_id'] ?? 0);

    if ($investigation_name === '') {
        investigation_swal('error', 'Investigation Name is required.');
        header("Location: master_investigations.php");
        exit;
    }

    try {
        // Duplicate check is scoped to this organization and center; exclude the current row during edit.
        $dup = $tenant_pdo->prepare("
            SELECT id FROM master_investigations
            WHERE org_id = ? AND center_id = ? AND UPPER(TRIM(investigation_name)) = ?
              AND id <> ?
            LIMIT 1
        ");
        $dup->execute([$org_id, $center_id, $investigation_name, $edit_id]);
        if ($dup->fetch(PDO::FETCH_ASSOC)) {
            investigation_swal('error', 'This investigation already exists.');
            header("Location: master_investigations.php");
            exit;
        }

        if ($edit_id > 0) {
            $stmt = $tenant_pdo->prepare("
                UPDATE master_investigations
                SET investigation_name = ?, investigation_code = ?, investigation_type = ?,
                    description = ?, status = ?
                WHERE id = ? AND org_id = ? AND center_id = ?
            ");
            $stmt->execute([
                $investigation_name,
                $investigation_code !== '' ? $investigation_code : null,
                $investigation_type !== '' ? $investigation_type : null,
                $description !== '' ? $description : null,
                $status, $edit_id, $org_id, $center_id
            ]);
            investigation_swal('success', 'Investigation updated successfully.');
        } else {
            $stmt = $tenant_pdo->prepare("
                INSERT INTO master_investigations
                    (org_id, center_id, investigation_name, investigation_code,
                     investigation_type, description, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $org_id, $center_id, $investigation_name,
                $investigation_code !== '' ? $investigation_code : null,
                $investigation_type !== '' ? $investigation_type : null,
                $description !== '' ? $description : null,
                $status, $created_by ?: null
            ]);
            investigation_swal('success', 'Investigation added successfully.');
        }
        header("Location: master_investigations.php");
        exit;
    } catch (PDOException $e) {
        error_log('Investigation Master: ' . $e->getMessage());
        investigation_swal('error', 'Could not save investigation. Please check the database fields and try again.');
        header("Location: master_investigations.php");
        exit;
    }
}

// TOGGLE STATUS

if (isset($_GET['toggle_status'])) {



    $id = (int)$_GET['toggle_status'];

    $st = ((int)($_GET['st'] ?? 1) === 1) ? 0 : 1;



    $tenant_pdo->prepare("

        UPDATE master_investigations

        SET status = ?

        WHERE id = ?

          AND org_id = ?

          AND center_id = ?

    ")->execute([

        $st,

        $id,

        $org_id,

        $center_id

    ]);



    header("Location: master_investigations.php");

    exit;

}



// Insert common default investigations once per org/center; never overwrite existing entries.
$defaultInvestigations = [
    ['COMPLETE BLOOD COUNT (CBC)', 'INV007', 'PATHOLOGY', 'A standard blood panel used to evaluate overall health.'],
    ['HEMOGLOBIN (HB)', 'INV008', 'PATHOLOGY', 'Hemoglobin level test.'],
    ['ESR', 'INV009', 'PATHOLOGY', 'Erythrocyte sedimentation rate.'],
    ['CRP', 'INV010', 'PATHOLOGY', 'C-reactive protein test.'],
    ['BLOOD GROUP AND RH', 'INV011', 'PATHOLOGY', 'ABO and Rh blood grouping.'],
    ['FASTING BLOOD GLUCOSE', 'INV012', 'PATHOLOGY', 'Blood glucose after fasting.'],
    ['POSTPRANDIAL BLOOD GLUCOSE', 'INV013', 'PATHOLOGY', 'Blood glucose after a meal.'],
    ['RANDOM BLOOD GLUCOSE', 'INV014', 'PATHOLOGY', 'Random blood glucose test.'],
    ['HBA1C', 'INV015', 'PATHOLOGY', 'Glycated hemoglobin test.'],
    ['LIPID PROFILE', 'INV016', 'PATHOLOGY', 'Cholesterol and triglyceride panel.'],
    ['LIVER FUNCTION TEST (LFT)', 'INV017', 'PATHOLOGY', 'Liver enzyme and function panel.'],
    ['KIDNEY FUNCTION TEST (KFT)', 'INV018', 'PATHOLOGY', 'Renal function panel.'],
    ['UREA', 'INV019', 'PATHOLOGY', 'Blood urea test.'],
    ['SERUM CREATININE', 'INV020', 'PATHOLOGY', 'Serum creatinine test.'],
    ['ELECTROLYTES', 'INV021', 'PATHOLOGY', 'Serum sodium, potassium and related electrolytes.'],
    ['THYROID PROFILE (T3 T4 TSH)', 'INV022', 'PATHOLOGY', 'Thyroid function panel.'],
    ['TSH', 'INV023', 'PATHOLOGY', 'Thyroid stimulating hormone test.'],
    ['URINE ROUTINE AND MICROSCOPY', 'INV024', 'PATHOLOGY', 'Routine urine examination.'],
    ['URINE CULTURE AND SENSITIVITY', 'INV025', 'MICROBIOLOGY', 'Urine culture with sensitivity.'],
    ['BLOOD CULTURE', 'INV026', 'MICROBIOLOGY', 'Blood culture test.'],
    ['STOOL ROUTINE EXAMINATION', 'INV027', 'PATHOLOGY', 'Routine stool examination.'],
    ['STOOL OCCULT BLOOD', 'INV028', 'PATHOLOGY', 'Fecal occult blood test.'],
    ['MALARIA ANTIGEN', 'INV029', 'PATHOLOGY', 'Malaria antigen test.'],
    ['DENGUE NS1 ANTIGEN', 'INV030', 'PATHOLOGY', 'Dengue NS1 antigen test.'],
    ['DENGUE IGG IGM', 'INV031', 'PATHOLOGY', 'Dengue antibody test.'],
    ['WIDAL TEST', 'INV032', 'PATHOLOGY', 'Serology test for enteric fever; interpret clinically.'],
    ['HIV 1 AND 2 SCREENING', 'INV033', 'SEROLOGY', 'HIV screening test.'],
    ['HBsAg', 'INV034', 'SEROLOGY', 'Hepatitis B surface antigen.'],
    ['ANTI HCV', 'INV035', 'SEROLOGY', 'Hepatitis C antibody test.'],
    ['VITAMIN B12', 'INV036', 'PATHOLOGY', 'Vitamin B12 level.'],
    ['VITAMIN D (25-OH)', 'INV037', 'PATHOLOGY', '25-hydroxy vitamin D level.'],
    ['SERUM CALCIUM', 'INV038', 'PATHOLOGY', 'Serum calcium test.'],
    ['SERUM URIC ACID', 'INV039', 'PATHOLOGY', 'Serum uric acid test.'],
    ['IRON STUDIES', 'INV040', 'PATHOLOGY', 'Iron profile as clinically indicated.'],
    ['PROTHROMBIN TIME (PT/INR)', 'INV041', 'PATHOLOGY', 'Coagulation test.'],
    ['APTT', 'INV042', 'PATHOLOGY', 'Activated partial thromboplastin time.'],
    ['RETICULOCYTE COUNT', 'INV043', 'PATHOLOGY', 'Reticulocyte count.'],
    ['PERIPHERAL BLOOD SMEAR', 'INV044', 'PATHOLOGY', 'Peripheral blood smear examination.'],
    ['PREGNANCY TEST (URINE)', 'INV045', 'PATHOLOGY', 'Urine pregnancy test.'],
    ['BETA HCG', 'INV046', 'PATHOLOGY', 'Beta human chorionic gonadotropin.'],
    ['PAP SMEAR', 'INV047', 'CYTOLOGY', 'Cervical cytology screening.'],
    ['ECG', 'INV001', 'CARDIOLOGY', 'Electrocardiogram test to record the heart electrical activity.'],
    ['ECHOCARDIOGRAM (ECHO)', 'INV048', 'CARDIOLOGY', 'Ultrasound assessment of the heart.'],
    ['TREADMILL STRESS TEST (TMT)', 'INV049', 'CARDIOLOGY', 'Exercise stress test when clinically indicated.'],
    ['24 HOUR HOLTER MONITORING', 'INV050', 'CARDIOLOGY', 'Ambulatory ECG monitoring.'],
    ['CHEST X-RAY', 'INV051', 'RADIOLOGY', 'Chest radiograph.'],
    ['X-RAY ABDOMEN', 'INV052', 'RADIOLOGY', 'Abdominal radiograph when indicated.'],
    ['X-RAY SPINE', 'INV053', 'RADIOLOGY', 'Spine radiograph; specify region.'],
    ['ULTRASOUND WHOLE ABDOMEN', 'INV054', 'RADIOLOGY', 'Ultrasound of the abdomen.'],
    ['ULTRASOUND PELVIS', 'INV055', 'RADIOLOGY', 'Pelvic ultrasound.'],
    ['USG OBSTETRIC', 'INV056', 'RADIOLOGY', 'Obstetric ultrasound.'],
    ['CT HEAD', 'INV057', 'RADIOLOGY', 'CT scan of head when indicated.'],
    ['CT CHEST', 'INV058', 'RADIOLOGY', 'CT scan of chest when indicated.'],
    ['CT ABDOMEN', 'INV059', 'RADIOLOGY', 'CT scan of abdomen when indicated.'],
    ['MRI BRAIN', 'INV060', 'RADIOLOGY', 'MRI of brain when indicated.'],
    ['MRI SPINE', 'INV061', 'RADIOLOGY', 'MRI of spine; specify region.'],
    ['MAMMOGRAPHY', 'INV062', 'RADIOLOGY', 'Breast imaging.'],
    ['BONE DENSITY (DEXA)', 'INV063', 'RADIOLOGY', 'Bone mineral density test.'],
    ['PULMONARY FUNCTION TEST (PFT)', 'INV064', 'PULMONOLOGY', 'Lung function assessment.'],
    ['SPIROMETRY', 'INV065', 'PULMONOLOGY', 'Spirometry test.'],
    ['EEG', 'INV066', 'NEUROLOGY', 'Electroencephalogram.'],
    ['NERVE CONDUCTION STUDY (NCS)', 'INV067', 'NEUROLOGY', 'Nerve conduction testing.'],
    ['EMG', 'INV068', 'NEUROLOGY', 'Electromyography.'],
    ['FUNDUS EXAMINATION', 'INV069', 'OPHTHALMOLOGY', 'Retinal examination.'],
    ['VISUAL ACUITY TEST', 'INV070', 'OPHTHALMOLOGY', 'Visual acuity assessment.'],
    ['TONOMETRY', 'INV071', 'OPHTHALMOLOGY', 'Intraocular pressure measurement.'],
    ['AUDIOMETRY', 'INV072', 'ENT', 'Hearing assessment.'],
    ['TYMPANOMETRY', 'INV073', 'ENT', 'Middle-ear function test.'],
    ['ENDOSCOPY UPPER GI', 'INV074', 'GASTROENTEROLOGY', 'Upper gastrointestinal endoscopy.'],
    ['COLONOSCOPY', 'INV075', 'GASTROENTEROLOGY', 'Colonoscopy when indicated.'],
    ['SPIKE PROTEIN / INFECTIOUS TEST', 'INV076', 'PATHOLOGY', 'Select the specific validated assay before use.'],
    ['SEMEN ANALYSIS', 'INV077', 'PATHOLOGY', 'Semen analysis.'],
    ['PAP SMEAR / CYTOLOGY', 'INV078', 'CYTOLOGY', 'Cytology sample assessment.'],
    ['BIOPSY HISTOPATHOLOGY', 'INV079', 'HISTOPATHOLOGY', 'Tissue histopathology.'],
    ['FNAC', 'INV080', 'CYTOLOGY', 'Fine needle aspiration cytology.'],
    ['COOMBS TEST', 'INV081', 'PATHOLOGY', 'Direct or indirect Coombs test; specify.'],
    ['RETICULOCYTE COUNT', 'INV082', 'PATHOLOGY', 'Reticulocyte count.'],
    ['URINE ALBUMIN CREATININE RATIO', 'INV083', 'PATHOLOGY', 'Urine albumin-creatinine ratio.'],
    ['MICROALBUMIN URINE', 'INV084', 'PATHOLOGY', 'Urine microalbumin test.'],
    ['SERUM AMYLASE', 'INV085', 'PATHOLOGY', 'Serum amylase test.'],
    ['SERUM LIPASE', 'INV086', 'PATHOLOGY', 'Serum lipase test.'],
    ['LACTATE', 'INV087', 'PATHOLOGY', 'Lactate test when indicated.'],
    ['TROPONIN I/T', 'INV088', 'CARDIOLOGY', 'Cardiac troponin assay.'],
    ['CK-MB', 'INV089', 'CARDIOLOGY', 'Cardiac enzyme test.'],
    ['BNP / NT-PROBNP', 'INV090', 'CARDIOLOGY', 'Cardiac biomarker assay.'],
    ['D-DIMER', 'INV091', 'PATHOLOGY', 'D-dimer assay when indicated.'],
    ['PROCALCITONIN', 'INV092', 'PATHOLOGY', 'Procalcitonin assay.'],
    ['COVID-19 RT-PCR', 'INV093', 'MICROBIOLOGY', 'SARS-CoV-2 molecular test.'],
    ['INFLUENZA A/B TEST', 'INV094', 'MICROBIOLOGY', 'Influenza testing.'],
    ['THROAT SWAB CULTURE', 'INV095', 'MICROBIOLOGY', 'Throat swab culture.'],
    ['SPUTUM CULTURE', 'INV096', 'MICROBIOLOGY', 'Sputum culture.'],
    ['WOUND SWAB CULTURE', 'INV097', 'MICROBIOLOGY', 'Wound swab culture.'],
    ['CERVICAL SPINE X-RAY', 'INV098', 'RADIOLOGY', 'Cervical spine radiograph.'],
    ['LUMBAR SPINE X-RAY', 'INV099', 'RADIOLOGY', 'Lumbar spine radiograph.'],
    ['RENAL FUNCTION PANEL', 'INV100', 'PATHOLOGY', 'Renal function panel.'],
];

try {
    $seedCheck = $tenant_pdo->prepare("
        SELECT id FROM master_investigations
        WHERE org_id = ? AND center_id = ?
          AND UPPER(TRIM(investigation_name)) = ?
        LIMIT 1
    ");
    $seedInsert = $tenant_pdo->prepare("
        INSERT INTO master_investigations
            (org_id, center_id, investigation_name, investigation_code,
             investigation_type, description, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 1, ?)
    ");
    foreach ($defaultInvestigations as $item) {
        $seedCheck->execute([$org_id, $center_id, $item[0]]);
        if (!$seedCheck->fetch(PDO::FETCH_ASSOC)) {
            $seedInsert->execute([$org_id, $center_id, $item[0], $item[1], $item[2], $item[3], $created_by ?: null]);
        }
    }
} catch (PDOException $e) {
    error_log('Investigation defaults seed error: ' . $e->getMessage());
}

// LIST

$stmt = $tenant_pdo->prepare("

    SELECT *

    FROM master_investigations

    WHERE org_id = ?

      AND center_id = ?

    ORDER BY id DESC

");

$stmt->execute([$org_id, $center_id]);

$investigations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];



require_once __DIR__ . '/layout_header.php';

?>



<div class="row g-3">



    <div class="col-lg-5">

        <div class="card border shadow-sm">



            <div class="card-header bg-white py-2 px-3 border-bottom">

                <span class="fw-bold small text-dark" id="formTitle">

                    <i class="bi bi-clipboard2-data text-primary me-1"></i>

                    ADD INVESTIGATION

                </span>

            </div>



            <div class="card-body p-3">



                <form method="POST">



                    <input type="hidden" name="action_investigation" value="1">

                    <input type="hidden" name="edit_id" id="edit_id" value="0">



                    <div class="row g-2 mb-3">



                        <div class="col-md-8">

                            <label class="form-label small fw-semibold text-secondary mb-1">

                                INVESTIGATION NAME *

                            </label>

                            <input

                                type="text"

                                name="investigation_name"

                                id="investigation_name"

                                class="form-control form-control-sm text-uppercase"

                                maxlength="200"

                                required

                                autofocus

                            >

                        </div>



                        <div class="col-md-4">

                            <label class="form-label small fw-semibold text-secondary mb-1">

                                CODE

                            </label>

                            <input

                                type="text"

                                name="investigation_code"

                                id="investigation_code"

                                class="form-control form-control-sm font-monospace text-uppercase"

                                maxlength="50"

                            >

                        </div>



                    </div>



                    <div class="row g-2 mb-3">



                        <div class="col-md-6">

                            <label class="form-label small fw-semibold text-secondary mb-1">

                                INVESTIGATION TYPE

                            </label>

                            <input

                                type="text"

                                name="investigation_type"

                                id="investigation_type"

                                class="form-control form-control-sm text-uppercase"

                                maxlength="100"

                            >

                        </div>



                        <div class="col-md-6 d-flex align-items-end">

                            <div class="form-check form-switch mb-1">

                                <input

                                    class="form-check-input"

                                    type="checkbox"

                                    name="status"

                                    id="status"

                                    checked

                                >

                                <label

                                    class="form-check-label small fw-semibold text-secondary"

                                    for="status"

                                >

                                    ACTIVE STATUS

                                </label>

                            </div>

                        </div>



                    </div>



                    <div class="mb-3">

                        <label class="form-label small fw-semibold text-secondary mb-1">

                            DESCRIPTION

                        </label>

                        <textarea

                            name="description"

                            id="description"

                            class="form-control form-control-sm text-uppercase"

                            rows="3"

                            maxlength="255"

                        ></textarea>

                    </div>



                    <div class="d-flex gap-2 border-top pt-2">

                        <button

                            type="reset"

                            class="btn btn-light btn-sm border px-3"

                            onclick="resetForm()"

                        >

                            RESET

                        </button>



                        <button

                            type="submit"

                            class="btn btn-primary btn-sm flex-fill fw-semibold"

                            id="btnSubmit"

                        >

                            SAVE INVESTIGATION

                        </button>

                    </div>



                </form>



            </div>

        </div>

    </div>



    <div class="col-lg-7">

        <div class="card border shadow-sm">



            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">

                <span class="fw-bold small text-dark">

                    <i class="bi bi-list-task text-primary me-1"></i>

                    INVESTIGATIONS LIST

                </span>



                <!-- Search Bar added here -->

                <div class="d-flex align-items-center gap-2">

                    <div class="input-group input-group-sm" style="width: 250px;">

                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>

                        <input type="text" id="searchInvestigation" class="form-control" placeholder="Search records..." onkeyup="filterInvestigations()">

                    </div>

                    <span class="badge bg-light text-secondary border" id="recordCount">

                        <?= count($investigations) ?> RECORDS

                    </span>

                </div>

            </div>



            <div class="card-body p-0">

                <div class="table-responsive" style="max-height:70vh; overflow-y:auto;">

                    <table class="table table-hover align-middle mb-0 small" id="investigationTable">



                        <thead class="table-light sticky-top">

                            <tr>

                                <th class="ps-3" style="width:60px;">#</th>

                                <th>INVESTIGATION</th>

                                <th>CODE</th>

                                <th>TYPE</th>

                                <th>STATUS</th>

                                <th class="text-end pe-3">ACTION</th>

                            </tr>

                        </thead>



                        <tbody>

                        <?php if (empty($investigations)): ?>

                            <tr class="no-records">

                                <td colspan="6" class="text-center py-4 text-muted">

                                    NO INVESTIGATIONS FOUND.

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($investigations as $row): ?>

                                <?php $is_act = (int)$row['status'] === 1; ?>

                                <tr data-investigation-name="<?= htmlspecialchars(mb_strtoupper(trim($row['investigation_name']), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>">



                                    <td class="ps-3 font-monospace">

                                        #<?= (int)$row['id'] ?>

                                    </td>



                                    <td class="fw-bold text-dark text-uppercase">

                                        <?= htmlspecialchars($row['investigation_name']) ?>

                                        <?php if (!empty($row['description'])): ?>

                                            <div class="text-muted" style="font-size:10px;">

                                                <?= htmlspecialchars($row['description']) ?>

                                            </div>

                                        <?php endif; ?>

                                    </td>



                                    <td class="font-monospace text-uppercase">

                                        <?= htmlspecialchars($row['investigation_code'] ?? '') ?: '-' ?>

                                    </td>



                                    <td class="text-uppercase">

                                        <?= htmlspecialchars($row['investigation_type'] ?? '') ?: '-' ?>

                                    </td>



                                    <td>

                                        <a

                                            href="?toggle_status=<?= (int)$row['id'] ?>&st=<?= $is_act ? 1 : 0 ?>"

                                            class="badge text-decoration-none <?= $is_act

                                                ? 'bg-success-subtle text-success border border-success'

                                                : 'bg-danger-subtle text-danger border border-danger' ?>"

                                        >

                                            <?= $is_act ? 'ACTIVE' : 'INACTIVE' ?>

                                        </a>

                                    </td>



                                    <td class="text-end pe-3">

                                        <button

                                            type="button"

                                            class="btn btn-outline-primary btn-sm py-0 px-2"

                                            data-record-id="<?= (int)$row['id'] ?>"

                                            onclick='editRow(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'

                                            title="EDIT"

                                        >

                                            <i class="bi bi-pencil"></i>

                                        </button>

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



<script>
function filterInvestigations() {
    const input = document.getElementById('searchInvestigation');
    const filter = (input?.value || '').trim().toUpperCase();
    const rows = document.querySelectorAll('#investigationTable tbody tr:not(.no-records)');
    let visible = 0;
    rows.forEach(row => {
        const text = [row.cells[1], row.cells[2], row.cells[3]]
            .map(cell => cell ? cell.textContent : '').join(' ').toUpperCase();
        const match = text.includes(filter);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const count = document.getElementById('recordCount');
    if (count) count.textContent = visible + ' RECORDS';
}
function editRow(d) {
    document.getElementById('edit_id').value = d.id || '0';
    document.getElementById('investigation_name').value = d.investigation_name || '';
    document.getElementById('investigation_code').value = d.investigation_code || '';
    document.getElementById('investigation_type').value = d.investigation_type || '';
    document.getElementById('description').value = d.description || '';
    document.getElementById('status').checked = parseInt(d.status, 10) === 1;
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT INVESTIGATION';
    document.getElementById('btnSubmit').innerText = 'UPDATE INVESTIGATION';
    document.getElementById('investigation_name').focus();
    document.querySelector('form [name="action_investigation"]').value = '1';
    window.scrollTo({top: 0, behavior: 'smooth'});
}
function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('investigation_name').value = '';
    document.getElementById('investigation_code').value = '';
    document.getElementById('investigation_type').value = '';
    document.getElementById('description').value = '';
    document.getElementById('status').checked = true;
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-clipboard2-data text-primary me-1"></i> ADD INVESTIGATION';
    document.getElementById('btnSubmit').innerText = 'SAVE INVESTIGATION';
}
</script>



<?php
$investigationAlert = $_SESSION['investigation_swal'] ?? null;
unset($_SESSION['investigation_swal']);
?>
<style>
/* Compact SweetAlert for Investigation Master */
.swal2-popup.tw-compact-alert { width: 340px !important; max-width: calc(100vw - 32px) !important; padding: 1.05rem 1.1rem !important; border-radius: 14px !important; }
.tw-compact-alert .swal2-title { font-size: 1.2rem !important; padding: .35rem 0 0 !important; }
.tw-compact-alert .swal2-html-container { font-size: .9rem !important; margin: .45rem 0 .2rem !important; }
.tw-compact-alert .swal2-icon { width: 3rem !important; height: 3rem !important; margin: .2rem auto .5rem !important; }
.tw-compact-alert .swal2-icon .swal2-icon-content { font-size: 2.1rem !important; }
.tw-compact-alert .swal2-actions { margin: .65rem auto 0 !important; }
.tw-compact-alert .swal2-confirm { padding: .5rem 1.4rem !important; font-size: .9rem !important; }
@media (max-width: 420px) { .swal2-popup.tw-compact-alert { width: calc(100vw - 36px) !important; padding: 1rem !important; } }
</style>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    const nameInput = document.getElementById('investigation_name');
    const form = nameInput ? nameInput.closest('form') : null;
    const submit = document.getElementById('btnSubmit');
    const editId = document.getElementById('edit_id');
    let duplicate = false;
    let duplicateTimer = null;
    let duplicateHint = document.getElementById('investigationDuplicateHint');
    if (nameInput && form && submit) {
        duplicateHint = duplicateHint || document.createElement('div');
        duplicateHint.id = 'investigationDuplicateHint';
        duplicateHint.className = 'small text-danger mt-1';
        if (!duplicateHint.parentNode) nameInput.parentNode.appendChild(duplicateHint);
        const existing = Array.from(document.querySelectorAll('#investigationTable tbody tr:not(.no-records)')).map(row => ({
            id: row.querySelector('button[data-record-id]')?.dataset.recordId || '0',
            name: (row.dataset.investigationName || '').trim().toUpperCase()
        }));
        function checkDuplicate() {
            const value = nameInput.value.trim().toUpperCase();
            const currentId = String(editId?.value || '0');
            duplicate = !!value && existing.some(item => item.name === value &&
                !(currentId !== '0' && item.id === currentId));
            duplicateHint.textContent = duplicate ? 'This investigation already exists.' : '';
            submit.disabled = duplicate;
            return !duplicate;
        }
        nameInput.addEventListener('input', () => {
            clearTimeout(duplicateTimer);
            duplicateTimer = setTimeout(checkDuplicate, 120);
        });
        form.addEventListener('submit', function (e) {
            if (!checkDuplicate()) {
                e.preventDefault();
                if (window.Swal) Swal.fire({icon:'error', title:'Already exists', text:'This investigation already exists.', confirmButtonText:'OK', allowOutsideClick:false, customClass:{popup:'tw-compact-alert'}});
            }
        });
        // initial state is not duplicate until user types; edit of current row is allowed
    }
    const alertData = <?php echo json_encode($investigationAlert, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    if (alertData && window.Swal) {
        const success = alertData.type === 'success';
        Swal.fire({
            icon: success ? 'success' : 'error',
            title: success ? 'Successful!' : 'Error!',
            text: alertData.message || '',
            showConfirmButton: !success,
            confirmButtonText: 'OK',
            timer: success ? 5000 : undefined,
            timerProgressBar: success,
            allowOutsideClick: false,
            allowEscapeKey: false,
            customClass: { popup: 'tw-compact-alert' }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
