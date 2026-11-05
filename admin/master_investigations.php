<?php

// admin/master_investigations.php

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php';



$page_title = "Investigation Master";



$org_id     = (int)($_SESSION['org_id'] ?? 1);

$center_id  = (int)($_SESSION['center_id'] ?? 1);


$created_by = (int)($_SESSION['user_id'] ?? 0);

/* AJAX: add a department from the Investigation Master popup, or refresh its list. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['ajax_action'] ?? '', ['add_department', 'list_departments', 'check_investigation_duplicate'], true)) {
    header('Content-Type: application/json; charset=utf-8');

    // Live duplicate check: query the database, not only the currently rendered table.
    if (($_POST['ajax_action'] ?? '') === 'check_investigation_duplicate') {
        try {
            $name = strtoupper(trim((string)($_POST['investigation_name'] ?? '')));
            $departmentId = (int)($_POST['department_id'] ?? 0);
            $editId = (int)($_POST['edit_id'] ?? 0);
            if ($name === '' || $departmentId <= 0) {
                echo json_encode(['success' => true, 'duplicate' => false, 'needs_department' => ($departmentId <= 0)]);
                exit;
            }
            $check = $tenant_pdo->prepare("
                SELECT id, department_id
                FROM master_investigations
                WHERE org_id = ? AND center_id = ?
                  AND UPPER(TRIM(investigation_name)) = ?
                  AND id <> ?
                  AND (department_id = ? OR department_id IS NULL OR department_id = 0)
                LIMIT 1
            ");
            $check->execute([$org_id, $center_id, $name, $editId, $departmentId]);
            $found = $check->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                'success' => true,
                'duplicate' => (bool)$found,
                'message' => $found ? 'This investigation is already taken in the selected department (or exists in an older unmapped record).' : ''
            ]);
        } catch (Throwable $e) {
            error_log('Investigation duplicate AJAX: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Unable to check duplicate investigation.']);
        }
        exit;
    }

    try {
        $cols = $tenant_pdo->query("SHOW COLUMNS FROM master_departments")->fetchAll(PDO::FETCH_ASSOC);
        $fields = array_column($cols, 'Field');
        $idCol = in_array('id', $fields, true) ? 'id' : (in_array('department_id', $fields, true) ? 'department_id' : null);
        $nameCol = in_array('dept_name', $fields, true) ? 'dept_name' : (in_array('department_name', $fields, true) ? 'department_name' : (in_array('name', $fields, true) ? 'name' : null));
        if (!$idCol || !$nameCol) throw new RuntimeException('Department Master columns could not be identified.');

        $scopeWhere = [];
        $scopeParams = [];
        if (in_array('org_id', $fields, true)) { $scopeWhere[] = 'org_id = ?'; $scopeParams[] = $org_id; }
        if (in_array('center_id', $fields, true)) { $scopeWhere[] = 'center_id = ?'; $scopeParams[] = $center_id; }

        if (($_POST['ajax_action'] ?? '') === 'add_department') {
            $newName = strtoupper(trim((string)($_POST['department_name'] ?? '')));
            $newPrefix = strtoupper(trim((string)($_POST['department_prefix'] ?? '')));
            if ($newName === '') {
                echo json_encode(['success' => false, 'message' => 'Department name is required.']); exit;
            }
            if (in_array('dept_prefix', $fields, true) && $newPrefix === '') {
                echo json_encode(['success' => false, 'message' => 'Department prefix is required.']); exit;
            }
            $dupSql = "SELECT `{$idCol}` FROM master_departments WHERE " .
                ($scopeWhere ? implode(' AND ', $scopeWhere) . ' AND ' : '') .
                "UPPER(TRIM(`{$nameCol}`)) = ? LIMIT 1";
            $dup = $tenant_pdo->prepare($dupSql);
            $dup->execute(array_merge($scopeParams, [$newName]));
            if ($dup->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'This department already exists.']); exit;
            }

            $insertFields = [$nameCol];
            $insertValues = [$newName];
            if (in_array('dept_prefix', $fields, true)) { $insertFields[] = 'dept_prefix'; $insertValues[] = $newPrefix; }
            if (in_array('org_id', $fields, true)) { $insertFields[] = 'org_id'; $insertValues[] = $org_id; }
            if (in_array('center_id', $fields, true)) { $insertFields[] = 'center_id'; $insertValues[] = $center_id; }
            if (in_array('status', $fields, true)) { $insertFields[] = 'status'; $insertValues[] = 1; }
            if (in_array('created_by', $fields, true)) { $insertFields[] = 'created_by'; $insertValues[] = $created_by ?: null; }
            $quoted = array_map(static fn($f) => "`{$f}`", $insertFields);
            $marks = implode(', ', array_fill(0, count($insertFields), '?'));
            $ins = $tenant_pdo->prepare("INSERT INTO master_departments (" . implode(', ', $quoted) . ") VALUES ({$marks})");
            $ins->execute($insertValues);
        }

        $listWhere = $scopeWhere;
        if (in_array('status', $fields, true)) $listWhere[] = 'status = 1';
        $listSql = "SELECT `{$idCol}` AS department_id, `{$nameCol}` AS department_name FROM master_departments";
        if ($listWhere) $listSql .= ' WHERE ' . implode(' AND ', $listWhere);
        $listSql .= " ORDER BY `{$nameCol}`";
        $list = $tenant_pdo->prepare($listSql);
        $list->execute($scopeParams);
        echo json_encode(['success' => true, 'departments' => $list->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    } catch (Throwable $e) {
        error_log('Investigation department AJAX: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to process department request. Please check Department Master.']);
        exit;
    }
}


// Resolve department table columns safely so this page can work with the
// existing Department Master naming convention without changing that module.
$departmentRows = [];
$departmentIdColumn = null;
$departmentNameColumn = null;
try {
    $deptCols = $tenant_pdo->query("SHOW COLUMNS FROM master_departments")->fetchAll(PDO::FETCH_ASSOC);
    $deptNames = array_column($deptCols, 'Field');
    foreach (['department_id', 'id'] as $candidate) {
        if (in_array($candidate, $deptNames, true)) { $departmentIdColumn = $candidate; break; }
    }
    foreach (['department_name', 'name', 'dept_name'] as $candidate) {
        if (in_array($candidate, $deptNames, true)) { $departmentNameColumn = $candidate; break; }
    }
    if ($departmentIdColumn && $departmentNameColumn) {
        $where = [];
        $params = [];
        if (in_array('org_id', $deptNames, true)) { $where[] = 'org_id = ?'; $params[] = $org_id; }
        if (in_array('center_id', $deptNames, true)) { $where[] = 'center_id = ?'; $params[] = $center_id; }
        if (in_array('status', $deptNames, true)) { $where[] = 'status = 1'; }
        $sql = "SELECT `{$departmentIdColumn}` AS department_id, `{$departmentNameColumn}` AS department_name FROM master_departments";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= " ORDER BY `{$departmentNameColumn}`";
        $deptStmt = $tenant_pdo->prepare($sql);
        $deptStmt->execute($params);
        $departmentRows = $deptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $e) {
    error_log('Investigation department lookup: ' . $e->getMessage());
}


// Page-specific SweetAlert messages; independent of browser alert() and layout flash handling.
function investigation_swal(string $type, string $message): void {
    $_SESSION['investigation_swal'] = ['type' => $type, 'message' => $message];
}




// SAVE / UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_investigation'])) {
    $investigation_name = strtoupper(trim($_POST['investigation_name'] ?? ''));
    $investigation_code = strtoupper(trim($_POST['investigation_code'] ?? ''));
    $department_id = (int)($_POST['department_id'] ?? 0);
    $description = strtoupper(trim($_POST['description'] ?? ''));
    $status = isset($_POST['status']) ? 1 : 0;
    $edit_id = (int)($_POST['edit_id'] ?? 0);

    if ($department_id <= 0) {
        investigation_swal('error', 'Please select a department first.');
        header("Location: master_investigations.php");
        exit;
    }

    if ($investigation_name === '') {
        investigation_swal('error', 'Investigation Name is required.');
        header("Location: master_investigations.php");
        exit;
    }

    try {
        // Duplicate check is scoped to this organization and center; exclude the current row during edit.
        $dup = $tenant_pdo->prepare("
            SELECT id FROM master_investigations
            WHERE org_id = ? AND center_id = ? AND department_id = ?
              AND UPPER(TRIM(investigation_name)) = ? AND id <> ?
              AND (department_id = ? OR department_id IS NULL OR department_id = 0)
            LIMIT 1
        ");
        $dup->execute([$org_id, $center_id, $department_id, $investigation_name, $edit_id, $department_id]);
        if ($dup->fetch(PDO::FETCH_ASSOC)) {
            investigation_swal('error', 'This investigation is already taken in the selected department.');
            header("Location: master_investigations.php");
            exit;
        }

        if ($edit_id > 0) {
            $stmt = $tenant_pdo->prepare("
                UPDATE master_investigations
                SET department_id = ?, investigation_name = ?, investigation_code = ?,
                    description = ?, status = ?
                WHERE id = ? AND org_id = ? AND center_id = ?
            ");
            $stmt->execute([
                $department_id,
                $investigation_name,
                $investigation_code !== '' ? $investigation_code : null,
                $description !== '' ? $description : null,
                $status, $edit_id, $org_id, $center_id
            ]);
            investigation_swal('success', 'Investigation updated successfully.');
        } else {
            $stmt = $tenant_pdo->prepare("
                INSERT INTO master_investigations
                    (org_id, center_id, department_id, investigation_name, investigation_code,
                     description, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $org_id, $center_id, $department_id, $investigation_name,
                $investigation_code !== '' ? $investigation_code : null,
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
    // Paediatrics / Neonatal investigations
    ['NEWBORN SCREENING PANEL', 'INV101', 'PAEDIATRICS', 'Newborn screening panel; exact tests depend on the hospital screening protocol.'],
    ['NEONATAL BILIRUBIN (TOTAL AND DIRECT)', 'INV102', 'PAEDIATRICS', 'Total and direct bilirubin assessment in newborns.'],
    ['G6PD SCREENING', 'INV103', 'PAEDIATRICS', 'G6PD deficiency screening when clinically indicated.'],
    ['CORD BLOOD GROUP AND RH', 'INV104', 'PAEDIATRICS', 'ABO and Rh typing using cord blood when indicated.'],
    ['PAEDIATRIC BLOOD CULTURE', 'INV105', 'PAEDIATRICS', 'Blood culture collected using age-appropriate paediatric sampling.'],
    ['PAEDIATRIC URINE CULTURE', 'INV106', 'PAEDIATRICS', 'Urine culture with age-appropriate collection method.'],
    ['CSF ANALYSIS (PAEDIATRIC)', 'INV107', 'PAEDIATRICS', 'Cerebrospinal fluid analysis when clinically indicated.'],
    ['STOOL OVA AND PARASITE (PAEDIATRIC)', 'INV108', 'PAEDIATRICS', 'Stool microscopy for ova and parasites.'],
    ['MANTOUX TEST (PAEDIATRIC)', 'INV109', 'PAEDIATRICS', 'Tuberculin skin test; interpretation requires clinical assessment.'],
    ['NEONATAL RETICULOCYTE COUNT', 'INV110', 'PAEDIATRICS', 'Reticulocyte count for neonatal evaluation when indicated.'],
    ['NEONATAL BLOOD GLUCOSE', 'INV111', 'PAEDIATRICS', 'Blood glucose measurement in newborns.'],
    ['PAEDIATRIC SERUM ELECTROLYTES', 'INV112', 'PAEDIATRICS', 'Serum sodium, potassium and related electrolyte assessment.'],
];

try {
    // Resolve the available department IDs by their actual names.
    // The current Department Master contains Cardiology, Medicine,
    // Paediatrics and Radiology; investigations are linked accordingly.
    $departmentIdByName = [];
    foreach ($departmentRows as $department) {
        $departmentIdByName[strtoupper(trim((string)$department['department_name']))] = (int)$department['department_id'];
    }

    $medicineDepartmentId = $departmentIdByName['MEDICINE'] ?? 0;
    $cardiologyDepartmentId = $departmentIdByName['CARDIOLOGY'] ?? 0;
    $radiologyDepartmentId = $departmentIdByName['RADIOLOGY'] ?? 0;
    $paediatricsDepartmentId = $departmentIdByName['PAEDIATRICS'] ?? 0;

    $seedCheck = $tenant_pdo->prepare("
        SELECT id FROM master_investigations
        WHERE org_id = ? AND center_id = ?
          AND UPPER(TRIM(investigation_name)) = ?
        LIMIT 1
    ");
    $seedInsert = $tenant_pdo->prepare("
        INSERT INTO master_investigations
            (org_id, center_id, department_id, investigation_name, investigation_code,
             investigation_type, description, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
    ");
    $mapExisting = $tenant_pdo->prepare("
        UPDATE master_investigations
        SET department_id = ?
        WHERE org_id = ? AND center_id = ?
          AND UPPER(TRIM(investigation_name)) = ?
          AND (department_id IS NULL OR department_id = 0)
    ");

    foreach ($defaultInvestigations as $item) {
        // Clinical grouping: cardiology tests -> Cardiology; imaging -> Radiology;
        // remaining routine lab/specialty tests -> Medicine.
        $type = strtoupper(trim((string)$item[2]));
        $targetDepartmentId = ($type === 'CARDIOLOGY')
            ? $cardiologyDepartmentId
            : (($type === 'RADIOLOGY')
                ? $radiologyDepartmentId
                : (($type === 'PAEDIATRICS') ? $paediatricsDepartmentId : $medicineDepartmentId));

        $seedCheck->execute([$org_id, $center_id, $item[0]]);
        if (!$seedCheck->fetch(PDO::FETCH_ASSOC)) {
            if ($targetDepartmentId > 0) {
                $seedInsert->execute([
                    $org_id, $center_id, $targetDepartmentId,
                    $item[0], $item[1], $item[2], $item[3], $created_by ?: null
                ]);
            }
        } elseif ($targetDepartmentId > 0) {
            // Backfill the already-inserted default investigations without
            // overwriting a department that a user has deliberately selected.
            $mapExisting->execute([$targetDepartmentId, $org_id, $center_id, $item[0]]);
        }
    }
} catch (PDOException $e) {
    error_log('Investigation defaults seed/map error: ' . $e->getMessage());
}

// LIST

$stmt = $tenant_pdo->prepare("

    SELECT mi.*, md.`{$departmentNameColumn}` AS department_name

    FROM master_investigations mi
    LEFT JOIN master_departments md ON md.`{$departmentIdColumn}` = mi.department_id

    WHERE mi.org_id = ?

      AND mi.center_id = ?

    ORDER BY mi.id DESC

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



                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">DEPARTMENT *</label>
                        <div class="input-group input-group-sm">
                            <select name="department_id" id="department_id" class="form-select" required>
                                <option value="">SELECT DEPARTMENT</option>
                                <?php foreach ($departmentRows as $department): ?>
                                    <option value="<?= (int)$department['department_id'] ?>"><?= htmlspecialchars($department['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-primary" title="Add Department" onclick="openDepartmentModal()"><i class="bi bi-plus-lg"></i></button>
                            <button type="button" class="btn btn-outline-secondary" title="Refresh Departments" onclick="refreshDepartments()" id="refreshDepartmentsBtn"><i class="bi bi-arrow-clockwise"></i></button>
                        </div>
                    </div>

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
<div class="tw-existing-suggestions" id="twInvestigationSuggestions" role="listbox"></div>


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

                    <div class="input-group input-group-sm" style="width: 220px;">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input type="text" id="searchInvestigation" class="form-control" placeholder="Search records..." oninput="filterInvestigations()">
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

                                <th>DEPARTMENT</th>
                                <th>INVESTIGATION</th>

                                <th>CODE</th>


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

                                <tr data-department-id="<?= (int)($row['department_id'] ?? 0) ?>" data-investigation-name="<?= htmlspecialchars(mb_strtoupper(trim($row['investigation_name']), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>">



                                    <td class="ps-3 font-monospace">

                                        #<?= (int)$row['id'] ?>

                                    </td>



                                    <td class="text-uppercase">
                                        <?= htmlspecialchars($row['department_name'] ?? 'NOT MAPPED') ?>
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

                                            data-record-id="<?= (int)$row['id'] ?>" data-department-id="<?= (int)($row['department_id'] ?? 0) ?>"

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




<!-- Add Department popup -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1" aria-labelledby="addDepartmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <form id="addDepartmentForm">
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-bold" id="addDepartmentModalLabel"><i class="bi bi-diagram-3 text-primary me-1"></i> ADD DEPARTMENT</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label for="new_department_name" class="form-label small fw-semibold">DEPARTMENT NAME *</label>
                    <input type="text" class="form-control form-control-sm text-uppercase mb-2" id="new_department_name" maxlength="150" required placeholder="Enter department name">
                    <label for="new_department_prefix" class="form-label small fw-semibold">DEPARTMENT PREFIX *</label>
                    <input type="text" class="form-control form-control-sm text-uppercase" id="new_department_prefix" maxlength="10" required placeholder="e.g. CARD">
                    <div class="small text-muted mt-2">Department will be added to the current organization and center.</div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-light btn-sm border" data-bs-dismiss="modal">CANCEL</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="saveDepartmentBtn">SAVE DEPARTMENT</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let addDepartmentModalInstance = null;
function openDepartmentModal() {
    const modalEl = document.getElementById('addDepartmentModal');
    if (window.bootstrap && modalEl) {
        addDepartmentModalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
        addDepartmentModalInstance.show();
        setTimeout(() => document.getElementById('new_department_name')?.focus(), 250);
    } else {
        alert('Department popup could not be opened. Please refresh the page.');
    }
}
async function refreshDepartments(selectId = '') {
    const btn = document.getElementById('refreshDepartmentsBtn');
    if (btn) btn.disabled = true;
    try {
        const response = await fetch('master_investigations.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: new URLSearchParams({ajax_action: 'list_departments'})
        });
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Could not refresh departments.');
        const select = document.getElementById('department_id');
        const previous = selectId || select.value;
        select.innerHTML = '<option value="">SELECT DEPARTMENT</option>';
        (data.departments || []).forEach(dept => {
            const option = document.createElement('option');
            option.value = dept.department_id;
            option.textContent = dept.department_name;
            select.appendChild(option);
        });
        if (previous) select.value = String(previous);
        if (window.Swal) Swal.fire({icon:'success', title:'Refreshed', text:'Department list updated.', timer:1200, showConfirmButton:false});
    } catch (error) {
        if (window.Swal) Swal.fire({icon:'error', title:'Error', text:error.message || 'Unable to refresh departments.'});
        else alert(error.message || 'Unable to refresh departments.');
    } finally {
        if (btn) btn.disabled = false;
    }
}
document.getElementById('addDepartmentForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const name = document.getElementById('new_department_name').value.trim();
    const saveBtn = document.getElementById('saveDepartmentBtn');
    if (!name) return;
    saveBtn.disabled = true;
    saveBtn.textContent = 'SAVING...';
    try {
        const response = await fetch('master_investigations.php', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body:new URLSearchParams({ajax_action:'add_department', department_name:name, department_prefix:document.getElementById('new_department_prefix').value.trim()})
        });
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Department could not be added.');
        const select = document.getElementById('department_id');
        const newDept = (data.departments || []).find(d => String(d.department_name).trim().toUpperCase() === name.toUpperCase());
        select.innerHTML = '<option value="">SELECT DEPARTMENT</option>';
        (data.departments || []).forEach(dept => {
            const option = document.createElement('option');
            option.value = dept.department_id;
            option.textContent = dept.department_name;
            select.appendChild(option);
        });
        if (newDept) select.value = String(newDept.department_id);
        filterInvestigations();
        document.getElementById('new_department_name').value = '';
        document.getElementById('new_department_prefix').value = '';
        addDepartmentModalInstance?.hide();
        if (window.Swal) Swal.fire({icon:'success', title:'Department Added', text:'Department added and selected successfully.', timer:1500, showConfirmButton:false});
    } catch (error) {
        if (window.Swal) Swal.fire({icon:'error', title:'Unable to Add', text:error.message || 'Please try again.'});
        else alert(error.message || 'Please try again.');
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = 'SAVE DEPARTMENT';
    }
});

function filterInvestigations() {
    const input = document.getElementById('searchInvestigation');
    const searchText = (input?.value || '').trim().toUpperCase();
    const addDepartmentSelect = document.getElementById('department_id');
    const selectedDepartment = String(addDepartmentSelect?.value || '');
    const rows = document.querySelectorAll('#investigationTable tbody tr:not(.no-records)');
    let visible = 0;

    rows.forEach(row => {
        const rowDepartment = String(row.dataset.departmentId || '0');
        const text = [row.cells[1], row.cells[2], row.cells[3]]
            .map(cell => cell ? cell.textContent : '').join(' ').toUpperCase();
        const departmentMatch = !selectedDepartment || rowDepartment === selectedDepartment;
        const searchMatch = !searchText || text.includes(searchText);
        const show = departmentMatch && searchMatch;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    const count = document.getElementById('recordCount');
    if (count) count.textContent = visible + ' RECORDS';
}

// The Add/Edit department selector is the only department filter for the list.
document.getElementById('department_id')?.addEventListener('change', function () {
    filterInvestigations();
});
function editRow(d) {
    document.getElementById('edit_id').value = d.id || '0';
    document.getElementById('department_id').value = d.department_id || '';
    filterInvestigations();
    document.getElementById('investigation_name').value = d.investigation_name || '';
    document.getElementById('investigation_code').value = d.investigation_code || '';

    document.getElementById('description').value = d.description || '';
    document.getElementById('status').checked = parseInt(d.status, 10) === 1;
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT INVESTIGATION';
    document.getElementById('btnSubmit').innerText = 'UPDATE INVESTIGATION';
    document.getElementById('investigation_name').focus();
    document.querySelector('form [name="action_investigation"]').value = '1';
    window.scrollTo({top: 0, behavior: 'smooth'});
}
function resetForm() {
    const form = document.getElementById('investigation_name')?.closest('form');
    form?.reset();
    document.getElementById('edit_id').value = '0';
    document.getElementById('department_id').value = '';
    document.getElementById('investigation_name').value = '';
    document.getElementById('investigation_code').value = '';
    document.getElementById('description').value = '';
    document.getElementById('status').checked = true;
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-clipboard2-data text-primary me-1"></i> ADD INVESTIGATION';
    const submit = document.getElementById('btnSubmit');
    if (submit) { submit.innerText = 'SAVE INVESTIGATION'; submit.disabled = false; }
    const hint = document.getElementById('investigationDuplicateHint');
    if (hint) hint.textContent = '';
    const box = document.getElementById('twInvestigationSuggestions');
    if (box) { box.classList.remove('show'); box.innerHTML = ''; }
    filterInvestigations();
    document.getElementById('investigation_name').focus();
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
    let requestToken = 0;
    let duplicateHint = document.getElementById('investigationDuplicateHint');
    if (nameInput && form && submit) {
        duplicateHint = duplicateHint || document.createElement('div');
        duplicateHint.id = 'investigationDuplicateHint';
        duplicateHint.className = 'small text-danger mt-1';
        if (!duplicateHint.parentNode) nameInput.parentNode.appendChild(duplicateHint);

        async function checkDuplicate(showDepartmentHint = true) {
            const value = nameInput.value.trim().toUpperCase();
            const departmentId = String(document.getElementById('department_id')?.value || '');
            const currentId = String(editId?.value || '0');
            const token = ++requestToken;

            duplicate = false;
            submit.disabled = false;
            if (!value) {
                duplicateHint.textContent = '';
                return true;
            }
            if (!departmentId) {
                duplicateHint.textContent = showDepartmentHint ? 'Please select a department to check duplicate investigation.' : '';
                return true;
            }

            duplicateHint.textContent = 'Checking...';
            try {
                const response = await fetch('master_investigations.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: new URLSearchParams({
                        ajax_action: 'check_investigation_duplicate',
                        investigation_name: value,
                        department_id: departmentId,
                        edit_id: currentId
                    })
                });
                const data = await response.json();
                if (token !== requestToken) return !duplicate;
                if (!data.success) throw new Error(data.message || 'Duplicate check failed.');
                duplicate = !!data.duplicate;
                duplicateHint.textContent = duplicate ? (data.message || 'This investigation is already taken in the selected department.') : '';
                submit.disabled = duplicate;
                return !duplicate;
            } catch (error) {
                if (token === requestToken) {
                    duplicateHint.textContent = error.message || 'Could not verify duplicate. Save will still be validated on server.';
                    submit.disabled = false;
                }
                return true;
            }
        }

        form.addEventListener('reset', function () {
            clearTimeout(duplicateTimer);
            requestToken++;
            duplicate = false;
            submit.disabled = false;
            if (duplicateHint) duplicateHint.textContent = '';
        });
        nameInput.addEventListener('input', () => {
            clearTimeout(duplicateTimer);
            duplicateTimer = setTimeout(() => checkDuplicate(true), 250);
        });
        document.getElementById('department_id')?.addEventListener('change', () => checkDuplicate(true));
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const isValid = await checkDuplicate(true);
            if (!isValid) {
                if (window.Swal) Swal.fire({
                    icon:'error',
                    title:'Already exists',
                    text:duplicateHint.textContent,
                    confirmButtonText:'OK',
                    allowOutsideClick:false,
                    customClass:{popup:'tw-compact-alert'}
                });
                return;
            }
            form.submit();
        });
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

<script>
window.twInvestigationRecords = <?= json_encode(array_map(static function($r) {
 return ['id'=>(int)($r['id']??0), 'investigation_name'=>(string)($r['investigation_name']??''), 'investigation_code'=>(string)($r['investigation_code']??''), 'description'=>(string)($r['description']??''), 'department_id'=>(int)($r['department_id']??0), 'department_name'=>(string)($r['department_name']??''), 'status'=>(int)($r['status']??0)];
}, $investigations), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
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
 const input=document.getElementById('investigation_name');
 const box=document.getElementById('twInvestigationSuggestions');
 const dep=document.getElementById('department_id');
 const records=window.twInvestigationRecords||[];
 if(!input||!box)return;
 const norm=v=>String(v||'').trim().toLocaleLowerCase();
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const close=()=>{box.classList.remove('show');box.innerHTML='';};
 function render(){
   const q=norm(input.value); if(!q){close();return;}
   const selected=String(dep?.value||'');
   const found=records.filter(r=>(!selected||String(r.department_id)===selected)&&norm(r.investigation_name).includes(q)).slice(0,8);
   if(!found.length){close();return;}
   box.innerHTML=found.map((r,i)=>`<button type="button" class="tw-existing-option" data-i="${i}"><span><strong>${esc(r.investigation_name)}</strong><small>${esc(r.department_name||'Department not specified')} · ${esc(r.investigation_code||'No code')}</small></span><span class="tw-existing-tag">ALREADY TAKEN</span></button>`).join('');
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
