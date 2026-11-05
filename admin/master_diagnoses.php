<?php

// admin/master_diagnoses.php

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php';



$page_title = "Diagnosis Master";

$org_id = (int)($_SESSION['org_id'] ?? 1);

$center_id = (int)($_SESSION['center_id'] ?? 1);

$created_by = (int)($_SESSION['user_id'] ?? 0);

// Load active departments for this organization and center.
$departmentRows = [];
$departmentIdColumn = null;
$departmentNameColumn = null;
try {
    $deptCols = $tenant_pdo->query("SHOW COLUMNS FROM master_departments")->fetchAll(PDO::FETCH_ASSOC);
    $deptFields = array_column($deptCols, 'Field');
    foreach (['department_id', 'id'] as $candidate) {
        if (in_array($candidate, $deptFields, true)) { $departmentIdColumn = $candidate; break; }
    }
    foreach (['department_name', 'dept_name', 'name'] as $candidate) {
        if (in_array($candidate, $deptFields, true)) { $departmentNameColumn = $candidate; break; }
    }
    if ($departmentIdColumn && $departmentNameColumn) {
        $conditions = [];
        $params = [];
        if (in_array('org_id', $deptFields, true)) { $conditions[] = 'org_id = ?'; $params[] = $org_id; }
        if (in_array('center_id', $deptFields, true)) { $conditions[] = 'center_id = ?'; $params[] = $center_id; }
        if (in_array('status', $deptFields, true)) { $conditions[] = 'status = 1'; }
        $sql = "SELECT `{$departmentIdColumn}` AS department_id, `{$departmentNameColumn}` AS department_name FROM master_departments";
        if ($conditions) $sql .= " WHERE " . implode(" AND ", $conditions);
        $sql .= " ORDER BY `{$departmentNameColumn}`";
        $departmentStmt = $tenant_pdo->prepare($sql);
        $departmentStmt->execute($params);
        $departmentRows = $departmentStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('Diagnosis department lookup failed: ' . $e->getMessage());
}

// Seed the default diagnosis catalogue and backfill existing unmapped entries.
$defaultDiagnoses = [["VIRAL FEVER", "DIA001", "Fever due to viral infection"], ["UPPER RESPIRATORY TRACT INFECTION", "DIA002", "Common upper respiratory infection"], ["ACUTE PHARYNGITIS", "DIA003", "Acute inflammation of pharynx"], ["ACUTE BRONCHITIS", "DIA004", "Inflammation of bronchial airways"], ["GASTRITIS", "DIA005", "Inflammation of stomach lining"], ["ACID PEPTIC DISEASE", "DIA006", "Acid-related gastrointestinal condition"], ["GASTROENTERITIS", "DIA007", "Inflammation of stomach and intestine"], ["HYPERTENSION", "DIA008", "High blood pressure"], ["TYPE 2 DIABETES MELLITUS", "DIA009", "Type 2 diabetes mellitus"], ["TYPE 1 DIABETES MELLITUS", "DIA010", "Type 1 diabetes mellitus"], ["ASTHMA", "DIA011", "Chronic inflammatory airway disease"], ["ALLERGIC RHINITIS", "DIA012", "Allergic inflammation of nasal passages"], ["MIGRAINE", "DIA013", "Recurrent headache disorder"], ["TENSION TYPE HEADACHE", "DIA014", "Tension-type headache"], ["LOW BACK PAIN", "DIA015", "Pain in lower back"], ["OSTEOARTHRITIS", "DIA016", "Degenerative joint disease"], ["RHEUMATOID ARTHRITIS", "DIA017", "Inflammatory autoimmune arthritis"], ["URINARY TRACT INFECTION", "DIA018", "Infection of urinary tract"], ["ACUTE CYSTITIS", "DIA019", "Inflammation of urinary bladder"], ["IRON DEFICIENCY ANEMIA", "DIA020", "Anemia due to iron deficiency"], ["HYPOTHYROIDISM", "DIA021", "Underactive thyroid"], ["HYPERTHYROIDISM", "DIA022", "Overactive thyroid"], ["DYSLIPIDEMIA", "DIA023", "Abnormal blood lipid levels"], ["OBESITY", "DIA024", "Excess body fat"], ["ANXIETY DISORDER", "DIA025", "Anxiety-related disorder"], ["INSOMNIA", "DIA026", "Difficulty initiating or maintaining sleep"], ["DEPRESSION", "DIA027", "Depressive disorder"], ["ACUTE SINUSITIS", "DIA028", "Acute inflammation of sinuses"], ["OTITIS MEDIA", "DIA029", "Middle ear inflammation"], ["CONJUNCTIVITIS", "DIA030", "Inflammation of conjunctiva"], ["DERMATITIS", "DIA031", "Inflammation of skin"], ["URTICARIA", "DIA032", "Itchy raised skin wheals"], ["FUNGAL SKIN INFECTION", "DIA033", "Fungal infection of skin"], ["ACNE VULGARIS", "DIA034", "Common acne"], ["ECZEMA", "DIA035", "Eczematous skin condition"], ["CELLULITIS", "DIA036", "Bacterial infection of skin and soft tissue"], ["PNEUMONIA", "DIA037", "Infection of lung tissue"], ["COVID-19", "DIA038", "Coronavirus disease 2019"], ["INFLUENZA", "DIA039", "Influenza infection"], ["TUBERCULOSIS", "DIA040", "Tuberculosis infection"], ["DENGUE FEVER", "DIA041", "Dengue viral infection"], ["MALARIA", "DIA042", "Malaria infection"], ["CHIKUNGUNYA", "DIA043", "Chikungunya viral infection"], ["TYPHOID FEVER", "DIA044", "Enteric fever"], ["HEPATITIS", "DIA045", "Inflammation of liver"], ["FATTY LIVER DISEASE", "DIA046", "Fat accumulation in liver"], ["IRRITABLE BOWEL SYNDROME", "DIA047", "Functional bowel disorder"], ["CONSTIPATION", "DIA048", "Infrequent or difficult bowel movements"], ["DIARRHEA", "DIA049", "Frequent loose stools"], ["HEMORRHOIDS", "DIA050", "Hemorrhoidal disease"], ["PEPTIC ULCER DISEASE", "DIA051", "Ulcer of stomach or duodenum"], ["GALLSTONES", "DIA052", "Gallbladder stones"], ["KIDNEY STONE", "DIA053", "Renal calculus"], ["CHRONIC KIDNEY DISEASE", "DIA054", "Chronic impairment of kidney function"], ["ACUTE KIDNEY INJURY", "DIA055", "Acute decline in kidney function"], ["CORONARY ARTERY DISEASE", "DIA056", "Disease of coronary arteries"], ["HEART FAILURE", "DIA057", "Clinical heart failure syndrome"], ["ATRIAL FIBRILLATION", "DIA058", "Irregular atrial heart rhythm"], ["BRADYCARDIA", "DIA059", "Slow heart rate"], ["TACHYCARDIA", "DIA060", "Fast heart rate"], ["PALPITATION", "DIA061", "Awareness of heartbeat"], ["CHEST PAIN", "DIA062", "Pain or discomfort in chest"], ["DYSPNEA", "DIA063", "Shortness of breath"], ["COUGH", "DIA064", "Cough symptom"], ["FEVER", "DIA065", "Elevated body temperature"], ["HEADACHE", "DIA066", "Pain in head"], ["DIZZINESS", "DIA067", "Dizziness symptom"], ["VERTIGO", "DIA068", "Sensation of spinning"], ["NAUSEA AND VOMITING", "DIA069", "Nausea with or without vomiting"], ["ABDOMINAL PAIN", "DIA070", "Pain in abdominal region"], ["JOINT PAIN", "DIA071", "Arthralgia"], ["MUSCLE PAIN", "DIA072", "Myalgia"], ["FATIGUE", "DIA073", "Fatigue or tiredness"], ["GENERALIZED WEAKNESS", "DIA074", "General weakness"], ["DEHYDRATION", "DIA075", "Deficit of body water"], ["EPILEPSY", "DIA076", "Seizure disorder"], ["STROKE", "DIA077", "Cerebrovascular accident"], ["PARKINSON DISEASE", "DIA078", "Parkinsonian neurodegenerative disorder"], ["OSTEOPOROSIS", "DIA079", "Reduced bone density"], ["GOUT", "DIA080", "Inflammatory crystal arthritis"], ["CERVICAL SPONDYLOSIS", "DIA081", "Degenerative cervical spine condition"], ["LUMBAR SPONDYLOSIS", "DIA082", "Degenerative lumbar spine condition"], ["SCIATICA", "DIA083", "Pain along sciatic nerve distribution"], ["VITAMIN D DEFICIENCY", "DIA084", "Low vitamin D level"], ["VITAMIN B12 DEFICIENCY", "DIA085", "Low vitamin B12 level"], ["PREGNANCY", "DIA086", "Pregnancy; clinical confirmation required"], ["POLYCYSTIC OVARY SYNDROME", "DIA087", "Polycystic ovary syndrome"], ["DYSMENORRHEA", "DIA088", "Painful menstruation"], ["MENORRHAGIA", "DIA089", "Heavy menstrual bleeding"], ["BENIGN PROSTATIC HYPERPLASIA", "DIA090", "Benign enlargement of prostate"], ["ERECTILE DYSFUNCTION", "DIA091", "Erectile dysfunction"], ["OTITIS EXTERNA", "DIA092", "External ear inflammation"], ["PERIODONTAL DISEASE", "DIA093", "Disease of supporting tissues of teeth"], ["DENTAL CARIES", "DIA094", "Tooth decay"], ["GLAUCOMA", "DIA095", "Glaucoma; ophthalmic evaluation required"], ["CATARACT", "DIA096", "Lens opacity"], ["ANAPHYLAXIS", "DIA097", "Severe systemic allergic reaction"], ["SEPSIS", "DIA098", "Life-threatening organ dysfunction due to infection"], ["BLUNT TRAUMA", "DIA099", "Internal pain or injury resulting from blunt force"], ["LOCALIZED SWELLING", "DIA100", "Abnormal enlargement or lump in a localized area"],
    ["NEONATAL JAUNDICE", "DIA101", "Jaundice occurring in a newborn"],
    ["NEONATAL SEPSIS", "DIA102", "Suspected or confirmed infection in a newborn"],
    ["LOW BIRTH WEIGHT", "DIA103", "Birth weight below the expected range"],
    ["PREMATURITY", "DIA104", "Birth before 37 completed weeks of gestation"],
    ["FEBRILE SEIZURE", "DIA105", "Seizure associated with fever in a child"],
    ["ACUTE OTITIS MEDIA (PAEDIATRIC)", "DIA106", "Middle ear infection in a child"],
    ["CHILDHOOD ASTHMA", "DIA107", "Asthma presenting during childhood"],
    ["ACUTE BRONCHIOLITIS", "DIA108", "Acute inflammation of small airways, commonly in infants"],
    ["HAND FOOT AND MOUTH DISEASE", "DIA109", "Common viral illness in young children"],
    ["CHILDHOOD PNEUMONIA", "DIA110", "Pneumonia in a paediatric patient"],
    ["ACUTE GASTROENTERITIS (PAEDIATRIC)", "DIA111", "Gastrointestinal infection in a child"],
    ["PAEDIATRIC DEHYDRATION", "DIA112", "Fluid deficit in an infant or child"],
    ["FAILURE TO THRIVE", "DIA113", "Poor weight gain or growth in a child"],
    ["RICKETS", "DIA114", "Defective bone mineralization in a growing child"],
    ["CONGENITAL HYPOTHYROIDISM", "DIA115", "Thyroid hormone deficiency present from birth"],
    ["CHEST X-RAY ABNORMALITY", "DIA116", "Abnormal finding on chest radiograph"],
    ["PULMONARY INFILTRATES", "DIA117", "Abnormal opacity within the lung fields on imaging"],
    ["PLEURAL EFFUSION", "DIA118", "Fluid accumulation in the pleural space"],
    ["CARDIOMEGALY ON CHEST X-RAY", "DIA119", "Enlarged cardiac silhouette on chest radiograph"],
    ["FRACTURE ON X-RAY", "DIA120", "Bone fracture identified on radiographic imaging"],
    ["SPONDYLOSIS ON X-RAY", "DIA121", "Degenerative spinal changes on radiograph"],
    ["OSTEOARTHRITIS ON X-RAY", "DIA122", "Degenerative joint changes on radiograph"],
    ["RENAL CALCULUS ON ULTRASOUND", "DIA123", "Kidney stone identified on ultrasound"],
    ["CHOLELITHIASIS ON ULTRASOUND", "DIA124", "Gallstones identified on ultrasound"],
    ["FATTY LIVER ON ULTRASOUND", "DIA125", "Increased liver echogenicity suggestive of fatty change"],
    ["HEPATOMEGALY ON ULTRASOUND", "DIA126", "Enlargement of the liver on imaging"],
    ["HYDRONEPHROSIS", "DIA127", "Dilatation of the renal collecting system"],
    ["UTERINE FIBROID ON ULTRASOUND", "DIA128", "Uterine leiomyoma identified on ultrasound"],
    ["OVARIAN CYST ON ULTRASOUND", "DIA129", "Ovarian cystic lesion identified on ultrasound"],
    ["CT BRAIN ABNORMALITY", "DIA130", "Abnormal finding identified on CT brain imaging"],
    ["MRI BRAIN ABNORMALITY", "DIA131", "Abnormal finding identified on MRI brain imaging"],
    ["SINUSITIS ON CT", "DIA132", "Inflammatory changes in paranasal sinuses on CT"],
    ["DISC PROLAPSE ON MRI", "DIA133", "Intervertebral disc herniation identified on MRI"],
    ["APPENDICITIS ON ULTRASOUND", "DIA134", "Imaging findings suggestive of appendicitis"]];

$departmentIdByName = [];
foreach ($departmentRows as $department) {
    $departmentIdByName[strtoupper(trim((string)$department['department_name']))] = (int)$department['department_id'];
}

function getDiagnosisDepartmentId($diagnosisName, $departmentIdByName) {
    $name = strtoupper(trim((string)$diagnosisName));
    $paediatricTerms = ['PAEDIATRIC', 'PEDIATRIC', 'NEONATAL', 'NEWBORN', 'CHILD', 'FEBRILE SEIZURE', 'PREMATURITY', 'RICKETS', 'FAILURE TO THRIVE', 'HAND FOOT AND MOUTH'];
    foreach ($paediatricTerms as $term) {
        if (strpos($name, $term) !== false && isset($departmentIdByName['PAEDIATRICS'])) return $departmentIdByName['PAEDIATRICS'];
    }
    $cardiologyTerms = ['HYPERTENSION', 'CORONARY', 'HEART', 'ATRIAL', 'BRADYCARDIA', 'TACHYCARDIA', 'PALPITATION', 'CHEST PAIN'];
    foreach ($cardiologyTerms as $term) {
        if (strpos($name, $term) !== false && isset($departmentIdByName['CARDIOLOGY'])) return $departmentIdByName['CARDIOLOGY'];
    }
    $radiologyTerms = ['X-RAY', 'RADIOGRAPH', 'ULTRASOUND', 'ON CT', 'ON MRI', 'CT BRAIN', 'MRI BRAIN', 'ON IMAGING', 'ON X-RAY', 'ON ULTRASOUND', 'ON MRI'];
    foreach ($radiologyTerms as $term) {
        if (strpos($name, $term) !== false && isset($departmentIdByName['RADIOLOGY'])) return $departmentIdByName['RADIOLOGY'];
    }
    return $departmentIdByName['MEDICINE'] ?? ($departmentIdByName['GENERAL MEDICINE'] ?? 0);
}

try {
    $checkDiagnosis = $tenant_pdo->prepare("
        SELECT id, department_id FROM master_diagnoses
        WHERE org_id = ? AND center_id = ?
          AND UPPER(TRIM(diagnosis_name)) = ?
        LIMIT 1
    ");
    $insertDiagnosis = $tenant_pdo->prepare("
        INSERT INTO master_diagnoses
            (org_id, center_id, department_id, diagnosis_name, diagnosis_code, description, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 1, ?)
    ");
    $backfillDiagnosis = $tenant_pdo->prepare("
        UPDATE master_diagnoses SET department_id = ?
        WHERE id = ? AND (department_id IS NULL OR department_id = 0)
    ");

    foreach ($defaultDiagnoses as $item) {
        $targetDepartmentId = getDiagnosisDepartmentId($item[0], $departmentIdByName);
        $checkDiagnosis->execute([$org_id, $center_id, strtoupper(trim($item[0]))]);
        $existing = $checkDiagnosis->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $insertDiagnosis->execute([
                $org_id, $center_id, $targetDepartmentId ?: null,
                $item[0], $item[1], $item[2], $created_by ?: null
            ]);
        } elseif (empty($existing['department_id']) && $targetDepartmentId > 0) {
            $backfillDiagnosis->execute([$targetDepartmentId, (int)$existing['id']]);
        }
    }
} catch (PDOException $seedError) {
    error_log('Diagnosis seed/backfill failed: ' . $seedError->getMessage());
}

// Save / Update

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_diagnosis'])) {



    $diagnosis_name = strtoupper(trim($_POST['diagnosis_name'] ?? ''));
    $department_id = (int)($_POST['department_id'] ?? 0);

    $diagnosis_code = strtoupper(trim($_POST['diagnosis_code'] ?? ''));

    $description    = trim($_POST['description'] ?? '');

    $status         = isset($_POST['status']) ? 1 : 0;

    $edit_id        = (int)($_POST['edit_id'] ?? 0);



    if ($department_id <= 0) {
        $_SESSION['diagnosis_alert'] = ['type' => 'error', 'message' => 'Please select a department.'];
        header("Location: master_diagnoses.php"); exit;
    }

    if ($diagnosis_name === '') {
        $_SESSION['diagnosis_alert'] = ['type' => 'error', 'message' => 'Diagnosis Name is required.'];
        header("Location: master_diagnoses.php"); exit;
    } else {
        $dup = $tenant_pdo->prepare("SELECT id FROM master_diagnoses WHERE org_id = ? AND center_id = ? AND department_id = ? AND UPPER(TRIM(diagnosis_name)) = ? AND id <> ? LIMIT 1");
        $dup->execute([$org_id, $center_id, $department_id, $diagnosis_name, $edit_id]);
        if ($dup->fetchColumn()) {
            $_SESSION['diagnosis_alert'] = ['type' => 'error', 'message' => 'This diagnosis already exists.'];
            header("Location: master_diagnoses.php"); exit;
        }
        try {
            if ($edit_id > 0) {

                $stmt = $tenant_pdo->prepare("

                    UPDATE master_diagnoses

                    SET department_id = ?,

                        diagnosis_name = ?,

                        diagnosis_code = ?,

                        description = ?,

                        status = ?

                    WHERE id = ? AND org_id = ? AND center_id = ?

                ");



                $stmt->execute([

                    $department_id,

                    $diagnosis_name,

                    $diagnosis_code !== '' ? $diagnosis_code : null,

                    $description !== '' ? $description : null,

                    $status,

                    $edit_id,

                    $org_id,

                    $center_id

                ]);



                $_SESSION['diagnosis_alert'] = ['type' => 'success', 'message' => 'Diagnosis updated successfully.'];

            } else {

                $stmt = $tenant_pdo->prepare("

                    INSERT INTO master_diagnoses

                    (

                        org_id,

                        center_id,

                        department_id,

                        diagnosis_name,

                        diagnosis_code,

                        description,

                        status,

                        created_by

                    )

                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)

                ");



                $stmt->execute([

                    $org_id,

                    $center_id,

                    $department_id,

                    $diagnosis_name,

                    $diagnosis_code !== '' ? $diagnosis_code : null,

                    $description !== '' ? $description : null,

                    $status,

                    $created_by ?: null

                ]);



                $_SESSION['diagnosis_alert'] = ['type' => 'success', 'message' => 'Diagnosis added successfully.'];

            }



            header("Location: master_diagnoses.php");

            exit;

        } catch (PDOException $e) {

            $_SESSION['diagnosis_alert'] = ['type' => 'error', 'message' => 'Unable to save diagnosis. Please check the details and try again.'];

        }

    }

}



// Toggle Status

if (isset($_GET['toggle_status'])) {

    $id = (int)$_GET['toggle_status'];

    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;



    $tenant_pdo->prepare("

        UPDATE master_diagnoses

        SET status = ?

        WHERE id = ? AND org_id = ? AND center_id = ?

    ")->execute([$st, $id, $org_id, $center_id]);



    header("Location: master_diagnoses.php");

    exit;

}



// List

$diagnoses = $tenant_pdo->prepare("

    SELECT d.*, md.department_name AS department_name
    FROM master_diagnoses d
    LEFT JOIN (
        SELECT `{$departmentIdColumn}` AS department_id, `{$departmentNameColumn}` AS department_name
        FROM master_departments
    ) md ON md.department_id = d.department_id
    WHERE d.org_id = ? AND d.center_id = ?
    ORDER BY d.id DESC

");

$diagnoses->execute([$org_id, $center_id]);

$diagnoses = $diagnoses->fetchAll() ?: [];



require_once __DIR__ . '/layout_header.php';

?>



<div class="row g-3">

    <!-- ADD/EDIT DIAGNOSIS FORM -->

    <div class="col-lg-5">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom">

                <span class="fw-bold small text-dark" id="formTitle">

                    <i class="bi bi-clipboard2-pulse text-primary me-1"></i> ADD DIAGNOSIS

                </span>

            </div>



            <div class="card-body p-3">

                <form method="POST" action="">

                    <input type="hidden" name="action_diagnosis" value="1">

                    <input type="hidden" name="edit_id" id="edit_id" value="0">



                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">DEPARTMENT *</label>
                        <select name="department_id" id="department_id" class="form-select form-select-sm" required>
                            <option value="">SELECT DEPARTMENT</option>
                            <?php foreach ($departmentRows as $department): ?>
                                <option value="<?= (int)$department['department_id'] ?>"><?= htmlspecialchars($department['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">

                        <div class="col-md-8">

                            <label class="form-label small fw-semibold text-secondary mb-1">

                                DIAGNOSIS NAME *

                            </label>

                            <input

                                type="text"

                                name="diagnosis_name"

                                id="diagnosis_name"

                                class="form-control form-control-sm text-uppercase"

                                placeholder=""

                                maxlength="200"

                                required

                                autofocus

                            >
<div class="tw-existing-suggestions" id="twDiagnosisSuggestions" role="listbox"></div>


                        </div>



                        <div class="col-md-4">

                            <label class="form-label small fw-semibold text-secondary mb-1">

                                CODE

                            </label>

                            <input

                                type="text"

                                name="diagnosis_code"

                                id="diagnosis_code"

                                class="form-control form-control-sm font-monospace text-uppercase"

                                placeholder=""

                                maxlength="50"

                            >

                        </div>

                    </div>



                    <div class="mb-3">

                        <label class="form-label small fw-semibold text-secondary mb-1">

                            DESCRIPTION

                        </label>

                        <textarea

                            name="description"

                            id="description"

                            class="form-control form-control-sm"

                            rows="3"

                            maxlength="255"

                            placeholder=""

                        ></textarea>

                    </div>



                    <div class="form-check form-switch mb-3">

                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>

                        <label class="form-check-label small fw-semibold text-secondary" for="status">

                            ACTIVE STATUS

                        </label>

                    </div>



                    <div class="d-flex gap-2 border-top pt-2">

                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">

                            RESET

                        </button>



                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">

                            SAVE DIAGNOSIS

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>



    <!-- DIAGNOSES LIST TABLE -->

    <div class="col-lg-7">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">

                <span class="fw-bold small text-dark">

                    <i class="bi bi-list-task text-primary me-1"></i> DIAGNOSES LIST

                </span>



                <div class="d-flex align-items-center gap-2">

                    <input 

                        type="search" 

                        id="searchDiagnosis" 

                        class="form-control form-control-sm" 

                        placeholder="Search diagnoses..." 

                        style="width: 200px;"

                        onkeyup="filterDiagnoses()"

                    >

                    <span class="badge bg-light text-secondary border">

                        <?= count($diagnoses) ?> RECORDS

                    </span>

                </div>

            </div>



            <div class="card-body p-0">

                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">

                    <table class="table table-hover align-middle mb-0 small" id="diagnosesTable">

                        <thead class="table-light sticky-top">

                            <tr>

                                <th class="ps-3" style="width: 60px;">#</th>

                                <th>DEPARTMENT</th>
                                <th>DIAGNOSIS</th>

                                <th>CODE</th>

                                <th>DESCRIPTION</th>

                                <th>STATUS</th>

                                <th class="text-end pe-3">ACTION</th>

                            </tr>

                        </thead>



                        <tbody id="diagnosesTableBody">

                            <?php if (empty($diagnoses)): ?>

                                <tr id="noRecordsRow">

                                    <td colspan="7" class="text-center py-4 text-muted">

                                        NO DIAGNOSES FOUND.

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($diagnoses as $d): ?>

                                    <?php $is_act = (int)$d['status'] === 1; ?>



                                    <tr data-department-id="<?= (int)($d['department_id'] ?? 0) ?>">
                                        <td class="ps-3 font-monospace">#<?= (int)$d['id'] ?></td>
                                        <td><?= htmlspecialchars($d['department_name'] ?? 'NOT MAPPED') ?></td>



                                        <td class="fw-bold text-dark text-uppercase">

                                            <?= htmlspecialchars($d['diagnosis_name']) ?>

                                        </td>



                                        <td>

                                            <?php if (!empty($d['diagnosis_code'])): ?>

                                                <span class="badge bg-light text-primary border font-monospace text-uppercase">

                                                    <?= htmlspecialchars($d['diagnosis_code']) ?>

                                                </span>

                                            <?php else: ?>

                                                -

                                            <?php endif; ?>

                                        </td>



                                        <td class="text-secondary">

                                            <?= htmlspecialchars($d['description'] ?? '') ?: '-' ?>

                                        </td>



                                        <td>

                                            <a

                                                href="?toggle_status=<?= (int)$d['id'] ?>&st=<?= $is_act ? 1 : 0 ?>"

                                                class="badge text-decoration-none <?= $is_act

                                                    ? 'bg-success-subtle text-success border border-success'

                                                    : 'bg-danger-subtle text-danger border border-danger' ?>"

                                            >

                                                <?= $is_act ? 'ACTIVE' : 'INACTIVE' ?>

                                            </a>

                                        </td>



                                        <td class="text-end pe-3">

                                            <button

                                                class="btn btn-outline-primary btn-sm py-0 px-2"

                                                onclick='editRow(<?= json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'

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

// Real-time table search filter

function filterDiagnoses() {
    const input = document.getElementById('searchDiagnosis');
    const filter = (input?.value || '').toUpperCase();
    const selectedDepartment = document.getElementById('department_id')?.value || '';
    const tbody = document.getElementById('diagnosesTableBody');
    const rows = tbody ? tbody.getElementsByTagName('tr') : [];

    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        if (row.id === 'noRecordsRow') continue;

        const cells = row.getElementsByTagName('td');
        const txtName = (cells[2]?.textContent || '').toUpperCase();
        const txtCode = (cells[3]?.textContent || '').toUpperCase();
        const txtDesc = (cells[4]?.textContent || '').toUpperCase();
        const departmentMatch = !selectedDepartment ||
            String(row.dataset.departmentId || '') === String(selectedDepartment);
        const searchMatch = !filter || txtName.includes(filter) ||
            txtCode.includes(filter) || txtDesc.includes(filter);

        row.style.display = (departmentMatch && searchMatch) ? '' : 'none';
    }
}

// Populate form for editing

function editRow(d) {

    document.getElementById('edit_id').value = d.id;
    document.getElementById('department_id').value = d.department_id || '';

    document.getElementById('diagnosis_name').value = d.diagnosis_name || '';

    document.getElementById('diagnosis_code').value = d.diagnosis_code || '';

    document.getElementById('description').value = d.description || '';

    document.getElementById('status').checked = (parseInt(d.status) === 1);



    document.getElementById('formTitle').innerHTML =

        '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT DIAGNOSIS';



    document.getElementById('btnSubmit').innerText = 'UPDATE DIAGNOSIS';

    document.getElementById('diagnosis_name').focus();

}



// Reset form to Add mode

function resetForm() {

    document.getElementById('edit_id').value = '0';
    document.getElementById('department_id').value = '';



    document.getElementById('formTitle').innerHTML =

        '<i class="bi bi-clipboard2-pulse text-primary me-1"></i> ADD DIAGNOSIS';



    document.getElementById('btnSubmit').innerText = 'SAVE DIAGNOSIS';

    document.getElementById('diagnosis_name').focus();

}

</script>




<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
  const alertData = <?php $alertData = $_SESSION['diagnosis_alert'] ?? null; unset($_SESSION['diagnosis_alert']); echo json_encode($alertData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  if (alertData && window.Swal) Swal.fire({
    icon: alertData.type === 'success' ? 'success' : 'error',
    title: alertData.type === 'success' ? 'Successful!' : 'Error',
    text: alertData.message, showConfirmButton: alertData.type !== 'success',
    confirmButtonText: 'OK', timer: alertData.type === 'success' ? 5000 : undefined,
    timerProgressBar: alertData.type === 'success', width: 360
  });
  const nameInput = document.getElementById('diagnosis_name');
  const form = nameInput?.closest('form'), saveButton = document.getElementById('btnSubmit');
  const editId = document.getElementById('edit_id');
  const rows = Array.from(document.querySelectorAll('#diagnosesTableBody tr')).filter(r => !r.id);
  let warning = document.getElementById('diagnosisDuplicateWarning');
  if (!warning && nameInput) { warning = document.createElement('div'); warning.id='diagnosisDuplicateWarning'; warning.className='small text-danger mt-1'; nameInput.parentElement.appendChild(warning); }
  function checkDuplicate(showPopup) {
    const val = (nameInput?.value || '').trim().toUpperCase(), current = parseInt(editId?.value || '0',10);
    const dup = !!val && rows.some(row => {
      const id = parseInt((row.cells[0]?.textContent || '').replace(/\D/g,''),10);
      return (row.cells[2]?.textContent || '').trim().toUpperCase() === val && id !== current && String(row.dataset.departmentId || '') === String(document.getElementById('department_id')?.value || '');
    });
    if (warning) warning.textContent = dup ? 'This diagnosis already exists.' : '';
    if (saveButton) saveButton.disabled = dup;
    if (dup && showPopup && window.Swal) Swal.fire({icon:'warning',title:'Already exists',text:'This diagnosis is already in the list.',confirmButtonText:'OK',width:360});
    return dup;
  }
  nameInput?.addEventListener('input', () => checkDuplicate(false));
  document.getElementById('department_id')?.addEventListener('change', () => { checkDuplicate(false); filterDiagnoses(); });
  document.getElementById('department_id')?.addEventListener('change', () => checkDuplicate(false));
  form?.addEventListener('submit', e => { if (checkDuplicate(false)) { e.preventDefault(); if(window.Swal) Swal.fire({icon:'warning',title:'Already exists',text:'Please use a different diagnosis name.',confirmButtonText:'OK',width:360}); }});
  window.editRow = function(d) {
    editId.value=d.id; document.getElementById('department_id').value=d.department_id||''; nameInput.value=d.diagnosis_name||'';
    document.getElementById('diagnosis_code').value=d.diagnosis_code||'';
    document.getElementById('description').value=d.description||'';
    document.getElementById('status').checked=parseInt(d.status,10)===1;
    document.getElementById('formTitle').innerHTML='<i class="bi bi-pencil-square text-warning me-1"></i> EDIT DIAGNOSIS';
    saveButton.innerText='UPDATE DIAGNOSIS'; checkDuplicate(false); filterDiagnoses(); nameInput.focus();
  };
  window.resetForm = function() {
    form?.reset();
    editId.value = '0';
    document.getElementById('department_id').value = '';
    nameInput.value = '';
    document.getElementById('diagnosis_code').value = '';
    document.getElementById('description').value = '';
    document.getElementById('status').checked = true;
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-clipboard2-pulse text-primary me-1"></i> ADD DIAGNOSIS';
    saveButton.innerText = 'SAVE DIAGNOSIS';
    saveButton.disabled = false;
    if (warning) warning.textContent = '';
    const box = document.getElementById('twDiagnosisSuggestions');
    if (box) { box.classList.remove('show'); box.innerHTML = ''; }
    filterDiagnoses();
    nameInput.focus();
  };
})();
</script>

<script>
window.twDiagnosisRecords = <?= json_encode(array_map(static function($r) {
 return ['id'=>(int)($r['id']??0), 'diagnosis_name'=>(string)($r['diagnosis_name']??''), 'diagnosis_code'=>(string)($r['diagnosis_code']??''), 'description'=>(string)($r['description']??''), 'department_id'=>(int)($r['department_id']??0), 'department_name'=>(string)($r['department_name']??''), 'status'=>(int)($r['status']??0)];
}, $diagnoses), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
</script>

<style>
.tw-existing-suggestions{display:none;position:absolute;left:0;right:0;top:100%;z-index:1500;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 10px 25px rgba(15,23,42,.14);max-height:250px;overflow-y:auto}
.tw-existing-suggestions.show{display:block}
.tw-existing-option{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;padding:9px 11px;border:0;border-bottom:1px solid #f1f5f9;background:#fff;text-align:left;font-size:13px;color:#0f172a}
.tw-existing-option:hover{background:#eff6ff}
.tw-existing-option small{display:block;color:#64748b;font-size:11px;margin-top:2px}
.tw-existing-tag{white-space:nowrap;font-size:10px;font-weight:700;color:#b45309;background:#fef3c7;border-radius:20px;padding:3px 7px}
</style>
<script>
(function(){
 const input=document.getElementById('diagnosis_name');
 const box=document.getElementById('twDiagnosisSuggestions');
 const dep=document.getElementById('department_id');
 const records=window.twDiagnosisRecords||[];
 if(!input||!box)return;
 const norm=v=>String(v||'').trim().toLocaleLowerCase();
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const close=()=>{box.classList.remove('show');box.innerHTML='';};
 function render(){
   const q=norm(input.value); if(!q){close();return;}
   const selected=String(dep?.value||'');
   const found=records.filter(r=>(!selected||String(r.department_id)===selected)&&norm(r.diagnosis_name).includes(q)).slice(0,8);
   if(!found.length){close();return;}
   box.innerHTML=found.map((r,i)=>`<button type="button" class="tw-existing-option" data-i="${i}"><span><strong>${esc(r.diagnosis_name)}</strong><small>${esc(r.department_name||'Department not specified')} · ${esc(r.diagnosis_code||'No code')}</small></span><span class="tw-existing-tag">ALREADY TAKEN</span></button>`).join('');
   box.classList.add('show');
   box.querySelectorAll('button').forEach((b,i)=>b.addEventListener('click',()=>{
     if(typeof window.editRow==='function')window.editRow(found[i]);
     close();
     document.getElementById('formTitle')?.scrollIntoView({behavior:'smooth',block:'center'});
   }));
 }
 input.addEventListener('input',render);
 input.addEventListener('focus',render);
 dep?.addEventListener('change',render);
 document.addEventListener('click',e=>{if(e.target!==input&&!box.contains(e.target))close();});
 const parent=input.parentElement;
 if(parent){if(getComputedStyle(parent).position==='static')parent.style.position='relative';parent.appendChild(box);}
})();
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
