<?php

// admin/master_diagnoses.php

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php';



$page_title = "Diagnosis Master";

$org_id = (int)($_SESSION['org_id'] ?? 1);

$center_id = (int)($_SESSION['center_id'] ?? 1);

$created_by = (int)($_SESSION['user_id'] ?? 0);
// Seed common diagnosis catalogue per organization/center; never overwrite existing rows.
$defaultDiagnoses = [["VIRAL FEVER", "DIA001", "Fever due to viral infection"], ["UPPER RESPIRATORY TRACT INFECTION", "DIA002", "Common upper respiratory infection"], ["ACUTE PHARYNGITIS", "DIA003", "Acute inflammation of pharynx"], ["ACUTE BRONCHITIS", "DIA004", "Inflammation of bronchial airways"], ["GASTRITIS", "DIA005", "Inflammation of stomach lining"], ["ACID PEPTIC DISEASE", "DIA006", "Acid-related gastrointestinal condition"], ["GASTROENTERITIS", "DIA007", "Inflammation of stomach and intestine"], ["HYPERTENSION", "DIA008", "High blood pressure"], ["TYPE 2 DIABETES MELLITUS", "DIA009", "Type 2 diabetes mellitus"], ["TYPE 1 DIABETES MELLITUS", "DIA010", "Type 1 diabetes mellitus"], ["ASTHMA", "DIA011", "Chronic inflammatory airway disease"], ["ALLERGIC RHINITIS", "DIA012", "Allergic inflammation of nasal passages"], ["MIGRAINE", "DIA013", "Recurrent headache disorder"], ["TENSION TYPE HEADACHE", "DIA014", "Tension-type headache"], ["LOW BACK PAIN", "DIA015", "Pain in lower back"], ["OSTEOARTHRITIS", "DIA016", "Degenerative joint disease"], ["RHEUMATOID ARTHRITIS", "DIA017", "Inflammatory autoimmune arthritis"], ["URINARY TRACT INFECTION", "DIA018", "Infection of urinary tract"], ["ACUTE CYSTITIS", "DIA019", "Inflammation of urinary bladder"], ["IRON DEFICIENCY ANEMIA", "DIA020", "Anemia due to iron deficiency"], ["HYPOTHYROIDISM", "DIA021", "Underactive thyroid"], ["HYPERTHYROIDISM", "DIA022", "Overactive thyroid"], ["DYSLIPIDEMIA", "DIA023", "Abnormal blood lipid levels"], ["OBESITY", "DIA024", "Excess body fat"], ["ANXIETY DISORDER", "DIA025", "Anxiety-related disorder"], ["INSOMNIA", "DIA026", "Difficulty initiating or maintaining sleep"], ["DEPRESSION", "DIA027", "Depressive disorder"], ["ACUTE SINUSITIS", "DIA028", "Acute inflammation of sinuses"], ["OTITIS MEDIA", "DIA029", "Middle ear inflammation"], ["CONJUNCTIVITIS", "DIA030", "Inflammation of conjunctiva"], ["DERMATITIS", "DIA031", "Inflammation of skin"], ["URTICARIA", "DIA032", "Itchy raised skin wheals"], ["FUNGAL SKIN INFECTION", "DIA033", "Fungal infection of skin"], ["ACNE VULGARIS", "DIA034", "Common acne"], ["ECZEMA", "DIA035", "Eczematous skin condition"], ["CELLULITIS", "DIA036", "Bacterial infection of skin and soft tissue"], ["PNEUMONIA", "DIA037", "Infection of lung tissue"], ["COVID-19", "DIA038", "Coronavirus disease 2019"], ["INFLUENZA", "DIA039", "Influenza infection"], ["TUBERCULOSIS", "DIA040", "Tuberculosis infection"], ["DENGUE FEVER", "DIA041", "Dengue viral infection"], ["MALARIA", "DIA042", "Malaria infection"], ["CHIKUNGUNYA", "DIA043", "Chikungunya viral infection"], ["TYPHOID FEVER", "DIA044", "Enteric fever"], ["HEPATITIS", "DIA045", "Inflammation of liver"], ["FATTY LIVER DISEASE", "DIA046", "Fat accumulation in liver"], ["IRRITABLE BOWEL SYNDROME", "DIA047", "Functional bowel disorder"], ["CONSTIPATION", "DIA048", "Infrequent or difficult bowel movements"], ["DIARRHEA", "DIA049", "Frequent loose stools"], ["HEMORRHOIDS", "DIA050", "Hemorrhoidal disease"], ["PEPTIC ULCER DISEASE", "DIA051", "Ulcer of stomach or duodenum"], ["GALLSTONES", "DIA052", "Gallbladder stones"], ["KIDNEY STONE", "DIA053", "Renal calculus"], ["CHRONIC KIDNEY DISEASE", "DIA054", "Chronic impairment of kidney function"], ["ACUTE KIDNEY INJURY", "DIA055", "Acute decline in kidney function"], ["CORONARY ARTERY DISEASE", "DIA056", "Disease of coronary arteries"], ["HEART FAILURE", "DIA057", "Clinical heart failure syndrome"], ["ATRIAL FIBRILLATION", "DIA058", "Irregular atrial heart rhythm"], ["BRADYCARDIA", "DIA059", "Slow heart rate"], ["TACHYCARDIA", "DIA060", "Fast heart rate"], ["PALPITATION", "DIA061", "Awareness of heartbeat"], ["CHEST PAIN", "DIA062", "Pain or discomfort in chest"], ["DYSPNEA", "DIA063", "Shortness of breath"], ["COUGH", "DIA064", "Cough symptom"], ["FEVER", "DIA065", "Elevated body temperature"], ["HEADACHE", "DIA066", "Pain in head"], ["DIZZINESS", "DIA067", "Dizziness symptom"], ["VERTIGO", "DIA068", "Sensation of spinning"], ["NAUSEA AND VOMITING", "DIA069", "Nausea with or without vomiting"], ["ABDOMINAL PAIN", "DIA070", "Pain in abdominal region"], ["JOINT PAIN", "DIA071", "Arthralgia"], ["MUSCLE PAIN", "DIA072", "Myalgia"], ["FATIGUE", "DIA073", "Fatigue or tiredness"], ["GENERALIZED WEAKNESS", "DIA074", "General weakness"], ["DEHYDRATION", "DIA075", "Deficit of body water"], ["EPILEPSY", "DIA076", "Seizure disorder"], ["STROKE", "DIA077", "Cerebrovascular accident"], ["PARKINSON DISEASE", "DIA078", "Parkinsonian neurodegenerative disorder"], ["OSTEOPOROSIS", "DIA079", "Reduced bone density"], ["GOUT", "DIA080", "Inflammatory crystal arthritis"], ["CERVICAL SPONDYLOSIS", "DIA081", "Degenerative cervical spine condition"], ["LUMBAR SPONDYLOSIS", "DIA082", "Degenerative lumbar spine condition"], ["SCIATICA", "DIA083", "Pain along sciatic nerve distribution"], ["VITAMIN D DEFICIENCY", "DIA084", "Low vitamin D level"], ["VITAMIN B12 DEFICIENCY", "DIA085", "Low vitamin B12 level"], ["PREGNANCY", "DIA086", "Pregnancy; clinical confirmation required"], ["POLYCYSTIC OVARY SYNDROME", "DIA087", "Polycystic ovary syndrome"], ["DYSMENORRHEA", "DIA088", "Painful menstruation"], ["MENORRHAGIA", "DIA089", "Heavy menstrual bleeding"], ["BENIGN PROSTATIC HYPERPLASIA", "DIA090", "Benign enlargement of prostate"], ["ERECTILE DYSFUNCTION", "DIA091", "Erectile dysfunction"], ["OTITIS EXTERNA", "DIA092", "External ear inflammation"], ["PERIODONTAL DISEASE", "DIA093", "Disease of supporting tissues of teeth"], ["DENTAL CARIES", "DIA094", "Tooth decay"], ["GLAUCOMA", "DIA095", "Glaucoma; ophthalmic evaluation required"], ["CATARACT", "DIA096", "Lens opacity"], ["ANAPHYLAXIS", "DIA097", "Severe systemic allergic reaction"], ["SEPSIS", "DIA098", "Life-threatening organ dysfunction due to infection"], ["BLUNT TRAUMA", "DIA099", "Internal pain or injury resulting from blunt force"], ["LOCALIZED SWELLING", "DIA100", "Abnormal enlargement or lump in a localized area"]];
try {
    $checkDiagnosis = $tenant_pdo->prepare("SELECT id FROM master_diagnoses WHERE org_id = ? AND center_id = ? AND UPPER(TRIM(diagnosis_name)) = ? LIMIT 1");
    $insertDiagnosis = $tenant_pdo->prepare("INSERT INTO master_diagnoses (org_id, center_id, diagnosis_name, diagnosis_code, description, status, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)");
    foreach ($defaultDiagnoses as $item) {
        $checkDiagnosis->execute([$org_id, $center_id, strtoupper(trim($item[0]))]);
        if (!$checkDiagnosis->fetchColumn()) $insertDiagnosis->execute([$org_id, $center_id, $item[0], $item[1], $item[2], $created_by ?: null]);
    }
} catch (PDOException $seedError) { error_log('Diagnosis seed failed: ' . $seedError->getMessage()); }


// Seed common diagnosis catalogue per organization/center; existing entries are never overwritten.
$defaultDiagnoses = [["VIRAL FEVER", "DIA001", "Fever due to viral infection"], ["UPPER RESPIRATORY TRACT INFECTION", "DIA002", "Common upper respiratory infection"], ["ACUTE PHARYNGITIS", "DIA003", "Acute inflammation of pharynx"], ["ACUTE BRONCHITIS", "DIA004", "Inflammation of bronchial airways"], ["GASTRITIS", "DIA005", "Inflammation of stomach lining"], ["ACID PEPTIC DISEASE", "DIA006", "Acid-related gastrointestinal condition"], ["GASTROENTERITIS", "DIA007", "Inflammation of stomach and intestine"], ["HYPERTENSION", "DIA008", "High blood pressure"], ["TYPE 2 DIABETES MELLITUS", "DIA009", "Type 2 diabetes mellitus"], ["TYPE 1 DIABETES MELLITUS", "DIA010", "Type 1 diabetes mellitus"], ["ASTHMA", "DIA011", "Chronic inflammatory airway disease"], ["ALLERGIC RHINITIS", "DIA012", "Allergic inflammation of nasal passages"], ["MIGRAINE", "DIA013", "Recurrent headache disorder"], ["TENSION TYPE HEADACHE", "DIA014", "Tension-type headache"], ["LOW BACK PAIN", "DIA015", "Pain in lower back"], ["OSTEOARTHRITIS", "DIA016", "Degenerative joint disease"], ["RHEUMATOID ARTHRITIS", "DIA017", "Inflammatory autoimmune arthritis"], ["URINARY TRACT INFECTION", "DIA018", "Infection of urinary tract"], ["ACUTE CYSTITIS", "DIA019", "Inflammation of urinary bladder"], ["IRON DEFICIENCY ANEMIA", "DIA020", "Anemia due to iron deficiency"], ["HYPOTHYROIDISM", "DIA021", "Underactive thyroid"], ["HYPERTHYROIDISM", "DIA022", "Overactive thyroid"], ["DYSLIPIDEMIA", "DIA023", "Abnormal blood lipid levels"], ["OBESITY", "DIA024", "Excess body fat"], ["ANXIETY DISORDER", "DIA025", "Anxiety-related disorder"], ["INSOMNIA", "DIA026", "Difficulty initiating or maintaining sleep"], ["DEPRESSION", "DIA027", "Depressive disorder"], ["ACUTE SINUSITIS", "DIA028", "Acute inflammation of sinuses"], ["OTITIS MEDIA", "DIA029", "Middle ear inflammation"], ["CONJUNCTIVITIS", "DIA030", "Inflammation of conjunctiva"], ["DERMATITIS", "DIA031", "Inflammation of skin"], ["URTICARIA", "DIA032", "Itchy raised skin wheals"], ["FUNGAL SKIN INFECTION", "DIA033", "Fungal infection of skin"], ["ACNE VULGARIS", "DIA034", "Common acne"], ["ECZEMA", "DIA035", "Eczematous skin condition"], ["CELLULITIS", "DIA036", "Bacterial infection of skin and soft tissue"], ["PNEUMONIA", "DIA037", "Infection of lung tissue"], ["COVID-19", "DIA038", "Coronavirus disease 2019"], ["INFLUENZA", "DIA039", "Influenza infection"], ["TUBERCULOSIS", "DIA040", "Tuberculosis infection"], ["DENGUE FEVER", "DIA041", "Dengue viral infection"], ["MALARIA", "DIA042", "Malaria infection"], ["CHIKUNGUNYA", "DIA043", "Chikungunya viral infection"], ["TYPHOID FEVER", "DIA044", "Enteric fever"], ["HEPATITIS", "DIA045", "Inflammation of liver"], ["FATTY LIVER DISEASE", "DIA046", "Fat accumulation in liver"], ["IRRITABLE BOWEL SYNDROME", "DIA047", "Functional bowel disorder"], ["CONSTIPATION", "DIA048", "Infrequent or difficult bowel movements"], ["DIARRHEA", "DIA049", "Frequent loose stools"], ["HEMORRHOIDS", "DIA050", "Hemorrhoidal disease"], ["PEPTIC ULCER DISEASE", "DIA051", "Ulcer of stomach or duodenum"], ["GALLSTONES", "DIA052", "Gallbladder stones"], ["KIDNEY STONE", "DIA053", "Renal calculus"], ["CHRONIC KIDNEY DISEASE", "DIA054", "Chronic impairment of kidney function"], ["ACUTE KIDNEY INJURY", "DIA055", "Acute decline in kidney function"], ["CORONARY ARTERY DISEASE", "DIA056", "Disease of coronary arteries"], ["HEART FAILURE", "DIA057", "Clinical heart failure syndrome"], ["ATRIAL FIBRILLATION", "DIA058", "Irregular atrial heart rhythm"], ["BRADYCARDIA", "DIA059", "Slow heart rate"], ["TACHYCARDIA", "DIA060", "Fast heart rate"], ["PALPITATION", "DIA061", "Awareness of heartbeat"], ["CHEST PAIN", "DIA062", "Pain or discomfort in chest"], ["DYSPNEA", "DIA063", "Shortness of breath"], ["COUGH", "DIA064", "Cough symptom"], ["FEVER", "DIA065", "Elevated body temperature"], ["HEADACHE", "DIA066", "Pain in head"], ["DIZZINESS", "DIA067", "Dizziness symptom"], ["VERTIGO", "DIA068", "Sensation of spinning"], ["NAUSEA AND VOMITING", "DIA069", "Nausea with or without vomiting"], ["ABDOMINAL PAIN", "DIA070", "Pain in abdominal region"], ["JOINT PAIN", "DIA071", "Arthralgia"], ["MUSCLE PAIN", "DIA072", "Myalgia"], ["FATIGUE", "DIA073", "Fatigue or tiredness"], ["GENERALIZED WEAKNESS", "DIA074", "General weakness"], ["DEHYDRATION", "DIA075", "Deficit of body water"], ["EPILEPSY", "DIA076", "Seizure disorder"], ["STROKE", "DIA077", "Cerebrovascular accident"], ["PARKINSON DISEASE", "DIA078", "Parkinsonian neurodegenerative disorder"], ["OSTEOPOROSIS", "DIA079", "Reduced bone density"], ["GOUT", "DIA080", "Inflammatory crystal arthritis"], ["CERVICAL SPONDYLOSIS", "DIA081", "Degenerative cervical spine condition"], ["LUMBAR SPONDYLOSIS", "DIA082", "Degenerative lumbar spine condition"], ["SCIATICA", "DIA083", "Pain along sciatic nerve distribution"], ["VITAMIN D DEFICIENCY", "DIA084", "Low vitamin D level"], ["VITAMIN B12 DEFICIENCY", "DIA085", "Low vitamin B12 level"], ["PREGNANCY", "DIA086", "Pregnancy; clinical confirmation required"], ["POLYCYSTIC OVARY SYNDROME", "DIA087", "Polycystic ovary syndrome"], ["DYSMENORRHEA", "DIA088", "Painful menstruation"], ["MENORRHAGIA", "DIA089", "Heavy menstrual bleeding"], ["BENIGN PROSTATIC HYPERPLASIA", "DIA090", "Benign enlargement of prostate"], ["ERECTILE DYSFUNCTION", "DIA091", "Erectile dysfunction"], ["OTITIS EXTERNA", "DIA092", "External ear inflammation"], ["PERIODONTAL DISEASE", "DIA093", "Disease of supporting tissues of teeth"], ["DENTAL CARIES", "DIA094", "Tooth decay"], ["GLAUCOMA", "DIA095", "Glaucoma; ophthalmic evaluation required"], ["CATARACT", "DIA096", "Lens opacity"], ["ANAPHYLAXIS", "DIA097", "Severe systemic allergic reaction"], ["SEPSIS", "DIA098", "Life-threatening organ dysfunction due to infection"], ["BLUNT TRAUMA", "DIA099", "Internal pain or injury resulting from blunt force"], ["LOCALIZED SWELLING", "DIA100", "Abnormal enlargement or lump in a localized area"]];
try {
    $checkDiagnosis = $tenant_pdo->prepare("SELECT id FROM master_diagnoses WHERE org_id = ? AND center_id = ? AND UPPER(TRIM(diagnosis_name)) = ? LIMIT 1");
    $insertDiagnosis = $tenant_pdo->prepare("INSERT INTO master_diagnoses (org_id, center_id, diagnosis_name, diagnosis_code, description, status, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)");
    foreach ($defaultDiagnoses as $item) {
        $checkDiagnosis->execute([$org_id, $center_id, strtoupper(trim($item[0]))]);
        if (!$checkDiagnosis->fetchColumn()) {
            $insertDiagnosis->execute([$org_id, $center_id, $item[0], $item[1], $item[2], $created_by ?: null]);
        }
    }
} catch (PDOException $seedError) {
    error_log('Diagnosis seed failed: ' . $seedError->getMessage());
}





// Save / Update

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_diagnosis'])) {



    $diagnosis_name = strtoupper(trim($_POST['diagnosis_name'] ?? ''));

    $diagnosis_code = strtoupper(trim($_POST['diagnosis_code'] ?? ''));

    $description    = trim($_POST['description'] ?? '');

    $status         = isset($_POST['status']) ? 1 : 0;

    $edit_id        = (int)($_POST['edit_id'] ?? 0);



    if ($diagnosis_name === '') {
        $_SESSION['diagnosis_alert'] = ['type' => 'error', 'message' => 'Diagnosis Name is required.'];
        header("Location: master_diagnoses.php"); exit;
    } else {
        $dup = $tenant_pdo->prepare("SELECT id FROM master_diagnoses WHERE org_id = ? AND center_id = ? AND UPPER(TRIM(diagnosis_name)) = ? AND id <> ? LIMIT 1");
        $dup->execute([$org_id, $center_id, $diagnosis_name, $edit_id]);
        if ($dup->fetchColumn()) {
            $_SESSION['diagnosis_alert'] = ['type' => 'error', 'message' => 'This diagnosis already exists.'];
            header("Location: master_diagnoses.php"); exit;
        }
        try {
            if ($edit_id > 0) {

                $stmt = $tenant_pdo->prepare("

                    UPDATE master_diagnoses

                    SET diagnosis_name = ?,

                        diagnosis_code = ?,

                        description = ?,

                        status = ?

                    WHERE id = ? AND org_id = ? AND center_id = ?

                ");



                $stmt->execute([

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

                        diagnosis_name,

                        diagnosis_code,

                        description,

                        status,

                        created_by

                    )

                    VALUES (?, ?, ?, ?, ?, ?, ?)

                ");



                $stmt->execute([

                    $org_id,

                    $center_id,

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

    SELECT *

    FROM master_diagnoses

    WHERE org_id = ? AND center_id = ?

    ORDER BY id DESC

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

                                    <td colspan="6" class="text-center py-4 text-muted">

                                        NO DIAGNOSES FOUND.

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($diagnoses as $d): ?>

                                    <?php $is_act = (int)$d['status'] === 1; ?>



                                    <tr>

                                        <td class="ps-3 font-monospace">#<?= (int)$d['id'] ?></td>



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

    let input = document.getElementById('searchDiagnosis');

    let filter = input.value.toUpperCase();

    let tbody = document.getElementById('diagnosesTableBody');

    let tr = tbody.getElementsByTagName('tr');



    for (let i = 0; i < tr.length; i++) {

        // Skip the "No Records" placeholder if it exists

        if (tr[i].id === 'noRecordsRow') continue;



        // Columns for Diagnosis Name, Code, and Description

        let tdName = tr[i].getElementsByTagName('td')[1];

        let tdCode = tr[i].getElementsByTagName('td')[2];

        let tdDesc = tr[i].getElementsByTagName('td')[3];



        if (tdName || tdCode || tdDesc) {

            let txtName = tdName.textContent || tdName.innerText;

            let txtCode = tdCode.textContent || tdCode.innerText;

            let txtDesc = tdDesc.textContent || tdDesc.innerText;



            if (

                txtName.toUpperCase().indexOf(filter) > -1 || 

                txtCode.toUpperCase().indexOf(filter) > -1 || 

                txtDesc.toUpperCase().indexOf(filter) > -1

            ) {

                tr[i].style.display = "";

            } else {

                tr[i].style.display = "none";

            }

        }       

    }

}



// Populate form for editing

function editRow(d) {

    document.getElementById('edit_id').value = d.id;

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
      return (row.cells[1]?.textContent || '').trim().toUpperCase() === val && id !== current;
    });
    if (warning) warning.textContent = dup ? 'This diagnosis already exists.' : '';
    if (saveButton) saveButton.disabled = dup;
    if (dup && showPopup && window.Swal) Swal.fire({icon:'warning',title:'Already exists',text:'This diagnosis is already in the list.',confirmButtonText:'OK',width:360});
    return dup;
  }
  nameInput?.addEventListener('input', () => checkDuplicate(false));
  form?.addEventListener('submit', e => { if (checkDuplicate(false)) { e.preventDefault(); if(window.Swal) Swal.fire({icon:'warning',title:'Already exists',text:'Please use a different diagnosis name.',confirmButtonText:'OK',width:360}); }});
  window.editRow = function(d) {
    editId.value=d.id; nameInput.value=d.diagnosis_name||'';
    document.getElementById('diagnosis_code').value=d.diagnosis_code||'';
    document.getElementById('description').value=d.description||'';
    document.getElementById('status').checked=parseInt(d.status,10)===1;
    document.getElementById('formTitle').innerHTML='<i class="bi bi-pencil-square text-warning me-1"></i> EDIT DIAGNOSIS';
    saveButton.innerText='UPDATE DIAGNOSIS'; checkDuplicate(false); nameInput.focus();
  };
  window.resetForm = function() {
    form?.reset(); editId.value='0';
    document.getElementById('formTitle').innerHTML='<i class="bi bi-clipboard2-pulse text-primary me-1"></i> ADD DIAGNOSIS';
    saveButton.innerText='SAVE DIAGNOSIS'; saveButton.disabled=false;
    if(warning) warning.textContent=''; nameInput.focus();
  };
})();
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
