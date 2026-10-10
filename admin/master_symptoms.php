<?php

// admin/master_symptoms.php

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php';



$page_title = "Symptom Master";

$org_id = (int)($_SESSION['org_id'] ?? 1);

$center_id = (int)($_SESSION['center_id'] ?? 1);

$created_by = (int)($_SESSION['user_id'] ?? 0);

// Seed common symptom master entries for each organization/center without overwriting existing data.
$defaultSymptoms = [['FEVER', 'Elevated body temperature'], ['HEADACHE', 'Pain in the head'], ['COUGH', 'Coughing'], ['COLD', 'Common cold symptoms'], ['RUNNY NOSE', 'Nasal discharge'], ['SORE THROAT', 'Throat pain or irritation'], ['SNEEZING', 'Repeated sneezing'], ['NASAL CONGESTION', 'Blocked nose'], ['BODY ACHE', 'Generalized body pain'], ['FATIGUE', 'Tiredness or lack of energy'], ['WEAKNESS', 'General weakness'], ['DIZZINESS', 'Feeling dizzy'], ['VERTIGO', 'Sensation of spinning'], ['NAUSEA', 'Feeling sick or urge to vomit'], ['VOMITING', 'Vomiting'], ['DIARRHEA', 'Frequent loose stools'], ['CONSTIPATION', 'Difficulty passing stools'], ['ABDOMINAL PAIN', 'Pain in abdominal region'], ['STOMACH BLOATING', 'Abdominal fullness or bloating'], ['ACIDITY', 'Burning or acid-related discomfort'], ['HEARTBURN', 'Burning sensation in chest or upper abdomen'], ['LOSS OF APPETITE', 'Reduced desire to eat'], ['INDIGESTION', 'Difficulty digesting food'], ['GAS', 'Excess intestinal gas'], ['CHEST PAIN', 'Pain or discomfort in chest'], ['SHORTNESS OF BREATH', 'Difficulty breathing'], ['WHEEZING', 'Whistling sound while breathing'], ['PALPITATION', 'Awareness of rapid or strong heartbeat'], ['SWELLING', 'Abnormal swelling'], ['LOCALIZED SWELLING', 'Localized lump or enlargement'], ['JOINT PAIN', 'Pain in a joint'], ['JOINT STIFFNESS', 'Reduced range of joint movement'], ['BACK PAIN', 'Pain in the back'], ['LOWER BACK PAIN', 'Pain in lower back'], ['NECK PAIN', 'Pain in the neck'], ['MUSCLE PAIN', 'Muscle aches'], ['LEG PAIN', 'Pain in leg'], ['KNEE PAIN', 'Pain in knee'], ['SHOULDER PAIN', 'Pain in shoulder'], ['TOOTHACHE', 'Pain in a tooth'], ['GUM PAIN', 'Pain in gums'], ['EAR PAIN', 'Pain in ear'], ['EAR DISCHARGE', 'Fluid discharge from ear'], ['EYE PAIN', 'Pain in eye'], ['RED EYE', 'Redness of eye'], ['BLURRED VISION', 'Reduced clarity of vision'], ['ITCHY EYES', 'Itching of eyes'], ['WATERY EYES', 'Excess tearing'], ['SKIN RASH', 'Abnormal skin eruption'], ['ITCHING', 'Skin itching'], ['DRY SKIN', 'Dryness of skin'], ['SKIN REDNESS', 'Redness of skin'], ['HAIR LOSS', 'Loss of hair'], ['BURNING URINATION', 'Burning sensation during urination'], ['FREQUENT URINATION', 'Urinating more frequently than usual'], ['PAINFUL URINATION', 'Pain during urination'], ['BLOOD IN URINE', 'Blood seen in urine'], ['URINARY URGENCY', 'Sudden urgent need to urinate'], ['INCONTINENCE', 'Loss of bladder control'], ['EXCESSIVE THIRST', 'Increased thirst'], ['EXCESSIVE HUNGER', 'Increased hunger'], ['UNINTENTIONAL WEIGHT LOSS', 'Weight loss without trying'], ['WEIGHT GAIN', 'Increase in body weight'], ['NIGHT SWEATS', 'Excessive sweating during sleep'], ['EXCESSIVE SWEATING', 'Sweating more than usual'], ['CHILLS', 'Feeling cold with shivering'], ['TREMORS', 'Involuntary shaking'], ['NUMBNESS', 'Reduced or absent sensation'], ['TINGLING', 'Pins-and-needles sensation'], ['ANXIETY', 'Feeling anxious or worried'], ['INSOMNIA', 'Difficulty sleeping'], ['LOW MOOD', 'Persistent low mood'], ['MEMORY PROBLEMS', 'Difficulty remembering'], ['FAINTING', 'Brief loss of consciousness'], ['SEIZURE', 'Episode of abnormal electrical activity'], ['BLEEDING', 'Bleeding symptom'], ['EASY BRUISING', 'Bruising easily'], ['PALLOR', 'Unusual paleness'], ['SWOLLEN LYMPH NODES', 'Enlarged lymph nodes'], ['MOUTH ULCERS', 'Ulcers inside the mouth'], ['DIFFICULTY SWALLOWING', 'Difficulty swallowing'], ['HOARSENESS', 'Change in voice quality'], ['SNORING', 'Noisy breathing during sleep'], ['SLEEPINESS', 'Excessive daytime sleepiness'], ['POOR FEEDING', 'Reduced feeding'], ['BEDWETTING', 'Involuntary urination during sleep'], ['MENSTRUAL PAIN', 'Pain during menstruation'], ['IRREGULAR MENSTRUATION', 'Irregular menstrual periods'], ['HEAVY MENSTRUAL BLEEDING', 'Heavy menstrual flow'], ['VAGINAL DISCHARGE', 'Vaginal discharge'], ['PELVIC PAIN', 'Pain in pelvic region'], ['BREAST PAIN', 'Pain in breast'], ['BREAST LUMP', 'Lump in breast'], ['RECTAL PAIN', 'Pain in rectal area'], ['HEMORRHOIDS', 'Symptoms related to hemorrhoids'], ['ANAL ITCHING', 'Itching around anus'], ['BLUNT TRAUMA', 'Pain or injury following blunt trauma'], ['BRADYCARDIA', 'Slow heart rate'], ['TACHYCARDIA', 'Fast heart rate'], ['RESPIRATORY DISTRESS', 'Difficulty or distress with breathing'], ['FEVER WITH RASH', 'Fever accompanied by rash'], ['DEHYDRATION', 'Symptoms related to fluid loss'], ['LOSS OF TASTE', 'Reduced or absent taste'], ['LOSS OF SMELL', 'Reduced or absent smell'], ['HOARSENESS OF VOICE', 'Hoarse voice'], ['GENERAL DISCOMFORT', 'General feeling of discomfort']];
try {
    $checkSymptom = $tenant_pdo->prepare('SELECT id FROM master_symptoms WHERE org_id = ? AND center_id = ? AND LOWER(TRIM(symptom_name)) = LOWER(TRIM(?)) LIMIT 1');
    $insertSymptom = $tenant_pdo->prepare('INSERT INTO master_symptoms (org_id, center_id, symptom_name, symptom_code, description, status, created_by) VALUES (?, ?, ?, NULL, ?, 1, ?)');
    foreach ($defaultSymptoms as $item) {
        $checkSymptom->execute([$org_id, $center_id, $item[0]]);
        if (!$checkSymptom->fetchColumn()) { $insertSymptom->execute([$org_id, $center_id, $item[0], $item[1], $created_by ?: null]); }
    }
} catch (PDOException $e) { error_log('Symptom defaults seed failed: ' . $e->getMessage()); }




// Save / Update

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_symptom'])) {



    $symptom_name = strtoupper(trim($_POST['symptom_name'] ?? ''));

    $symptom_code = strtoupper(trim($_POST['symptom_code'] ?? ''));

    $description  = trim($_POST['description'] ?? '');

    $status       = isset($_POST['status']) ? 1 : 0;

    $edit_id      = (int)($_POST['edit_id'] ?? 0);



    if ($symptom_name === '') {

        $_SESSION['symptom_alert'] = ['type' => 'error', 'message' => 'Symptom Name is required.'];

    } else {

        try {
            // Server-side duplicate guard; exclude the current row during edit.
            $duplicateCheck = $tenant_pdo->prepare("SELECT id FROM master_symptoms WHERE org_id = ? AND center_id = ? AND LOWER(TRIM(symptom_name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1");
            $duplicateCheck->execute([$org_id, $center_id, $symptom_name, $edit_id]);
            if ($duplicateCheck->fetchColumn()) {
                $_SESSION['symptom_alert'] = ['type' => 'error', 'message' => 'This symptom already exists. Please use the existing record.'];
                header("Location: master_symptoms.php");
                exit;
            }

            if ($edit_id > 0) {

                $stmt = $tenant_pdo->prepare("

                    UPDATE master_symptoms

                    SET symptom_name = ?,

                        symptom_code = ?,

                        description = ?,

                        status = ?

                    WHERE id = ? AND org_id = ? AND center_id = ?

                ");



                $stmt->execute([

                    $symptom_name,

                    $symptom_code !== '' ? $symptom_code : null,

                    $description !== '' ? $description : null,

                    $status,

                    $edit_id,

                    $org_id,

                    $center_id

                ]);



                $_SESSION['symptom_alert'] = ['type' => 'success', 'message' => 'Symptom updated successfully.'];

            } else {

                $stmt = $tenant_pdo->prepare("

                    INSERT INTO master_symptoms

                    (

                        org_id,

                        center_id,

                        symptom_name,

                        symptom_code,

                        description,

                        status,

                        created_by

                    )

                    VALUES (?, ?, ?, ?, ?, ?, ?)

                ");



                $stmt->execute([

                    $org_id,

                    $center_id,

                    $symptom_name,

                    $symptom_code !== '' ? $symptom_code : null,

                    $description !== '' ? $description : null,

                    $status,

                    $created_by ?: null

                ]);



                $_SESSION['symptom_alert'] = ['type' => 'success', 'message' => 'Symptom added successfully.'];

            }



            header("Location: master_symptoms.php");

            exit;

        } catch (PDOException $e) {

            $_SESSION['symptom_alert'] = ['type' => 'error', 'message' => 'Database error: ' . $e->getMessage()];

        }

    }

}



// Toggle Status

if (isset($_GET['toggle_status'])) {

    $id = (int)$_GET['toggle_status'];

    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;



    $tenant_pdo->prepare("

        UPDATE master_symptoms

        SET status = ?

        WHERE id = ? AND org_id = ? AND center_id = ?

    ")->execute([$st, $id, $org_id, $center_id]);



    header("Location: master_symptoms.php");

    exit;

}



// List

$symptoms = $tenant_pdo->prepare("

    SELECT *

    FROM master_symptoms

    WHERE org_id = ? AND center_id = ?

    ORDER BY id DESC

");

$symptoms->execute([$org_id, $center_id]);

$symptoms = $symptoms->fetchAll() ?: [];



require_once __DIR__ . '/layout_header.php';

?>



<div class="row g-2">

    <!-- Form Section -->

    <div class="col-lg-4">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-2 border-bottom">

                <span class="fw-bold small text-dark" id="formTitle">

                    <i class="bi bi-activity text-primary me-1"></i> ADD SYMPTOM

                </span>

            </div>



            <div class="card-body p-2">

                <form method="POST" action="">

                    <input type="hidden" name="action_symptom" value="1">

                    <input type="hidden" name="edit_id" id="edit_id" value="0">



                    <div class="row g-2 mb-2">

                        <div class="col-8">

                            <label class="form-label small fw-semibold text-secondary mb-1">SYMPTOM NAME *</label>

                            <input type="text" name="symptom_name" id="symptom_name" class="form-control form-control-sm text-uppercase" maxlength="200" required autofocus>
                            <div id="symptomDuplicateMsg">This symptom already exists.</div>

                        </div>

                        <div class="col-4">

                            <label class="form-label small fw-semibold text-secondary mb-1">CODE</label>

                            <input type="text" name="symptom_code" id="symptom_code" class="form-control form-control-sm font-monospace text-uppercase" maxlength="50">

                        </div>

                    </div>



                    <div class="mb-2">

                        <label class="form-label small fw-semibold text-secondary mb-1">DESCRIPTION</label>

                        <textarea name="description" id="description" class="form-control form-control-sm" rows="2" maxlength="255"></textarea>

                    </div>



                    <div class="form-check form-switch mb-2">

                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>

                        <label class="form-check-label small fw-semibold text-secondary" for="status">ACTIVE STATUS</label>

                    </div>



                    <div class="d-flex gap-2 border-top pt-2">

                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">RESET</button>

                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">SAVE</button>

                    </div>

                </form>

            </div>

        </div>

    </div>



    <!-- List Section -->

    <div class="col-lg-8">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-2 border-bottom d-flex justify-content-between align-items-center">

                <span class="fw-bold small text-dark text-nowrap">

                    <i class="bi bi-list-task text-primary me-1"></i> SYMPTOMS LIST

                </span>



                <div class="d-flex align-items-center gap-2 w-50">

                    <div class="input-group input-group-sm">

                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>

                        <input type="text" id="searchInput" class="form-control border-start-0" placeholder="Search symptom, code...">

                    </div>

                    <span class="badge bg-light text-secondary border text-nowrap" id="recordCount">

                        <?= count($symptoms) ?> RECORDS

                    </span>

                </div>

            </div>



            <div class="card-body p-0">

                <div class="table-responsive" style="max-height: 75vh; overflow-y: auto;">

                    <table class="table table-sm table-hover align-middle mb-0" id="symptomsTable" style="font-size: 0.85rem;">

                        <thead class="table-light sticky-top">

                            <tr>

                                <th class="ps-2" style="width: 50px;">#</th>

                                <th>SYMPTOM</th>

                                <th>CODE</th>

                                <th>DESCRIPTION</th>

                                <th>STATUS</th>

                                <th class="text-end pe-2">ACTION</th>

                            </tr>

                        </thead>



                        <tbody>

                            <?php if (empty($symptoms)): ?>

                                <tr id="noDataRow">

                                    <td colspan="6" class="text-center py-3 text-muted">NO SYMPTOMS FOUND.</td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($symptoms as $s): ?>

                                    <?php $is_act = (int)$s['status'] === 1; ?>

                                    <tr class="searchable-row">

                                        <td class="ps-2 font-monospace text-muted">#<?= (int)$s['id'] ?></td>

                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($s['symptom_name']) ?></td>

                                        <td>

                                            <?php if (!empty($s['symptom_code'])): ?>

                                                <span class="badge bg-light text-primary border font-monospace text-uppercase"><?= htmlspecialchars($s['symptom_code']) ?></span>

                                            <?php else: ?>

                                                <span class="text-muted">-</span>

                                            <?php endif; ?>

                                        </td>

                                        <td class="text-secondary text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($s['description'] ?? '') ?>">

                                            <?= htmlspecialchars($s['description'] ?? '') ?: '-' ?>

                                        </td>

                                        <td>

                                            <a href="?toggle_status=<?= (int)$s['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" 

                                               class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">

                                                <?= $is_act ? 'ACTIVE' : 'INACTIVE' ?>

                                            </a>

                                        </td>

                                        <td class="text-end pe-2">

                                            <button class="btn btn-outline-primary btn-sm py-0 px-1" 

                                                    onclick='editRow(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' 

                                                    title="EDIT">

                                                <i class="bi bi-pencil" style="font-size: 0.8rem;"></i>

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



<style>
#symptomAlertOverlay{position:fixed;inset:0;background:rgba(15,23,42,.28);display:none;align-items:center;justify-content:center;z-index:20000}
#symptomAlertBox{width:min(360px,calc(100vw - 32px));background:#fff;border-radius:16px;padding:22px 24px;box-shadow:0 18px 55px rgba(0,0,0,.22);text-align:center;font-family:inherit}
#symptomAlertIcon{width:48px;height:48px;border:3px solid #16a34a;color:#16a34a;border-radius:50%;font-size:30px;line-height:42px;margin:0 auto 12px}
#symptomAlertTitle{font-size:20px;font-weight:700;margin-bottom:7px;color:#172033}#symptomAlertText{font-size:14px;color:#64748b;margin-bottom:16px;overflow-wrap:anywhere}
#symptomAlertOk{border:0;border-radius:8px;padding:9px 28px;background:#2563eb;color:white;font-weight:600;display:none}
#symptomAlertProgress{height:3px;background:#e5e7eb;margin-top:17px;overflow:hidden;border-radius:3px}#symptomAlertProgress span{display:block;height:100%;width:100%;background:#16a34a}
#symptomDuplicateMsg{display:none;font-size:12px;color:#dc2626;margin-top:4px;font-weight:600}
</style>
<div id="symptomAlertOverlay" role="alertdialog" aria-modal="true"><div id="symptomAlertBox"><div id="symptomAlertIcon">✓</div><div id="symptomAlertTitle">Successful!</div><div id="symptomAlertText"></div><button id="symptomAlertOk" type="button">OK</button><div id="symptomAlertProgress"><span></span></div></div></div>
<script>
(function(){const overlay=document.getElementById('symptomAlertOverlay'),icon=document.getElementById('symptomAlertIcon'),title=document.getElementById('symptomAlertTitle'),msg=document.getElementById('symptomAlertText'),ok=document.getElementById('symptomAlertOk'),bar=document.querySelector('#symptomAlertProgress span');let timer=null;window.showSymptomAlert=function(type,message){clearTimeout(timer);const success=type==='success';overlay.style.display='flex';icon.textContent=success?'✓':'!';icon.style.borderColor=success?'#16a34a':'#dc2626';icon.style.color=success?'#16a34a':'#dc2626';title.textContent=success?'Successful!':'Unable to complete';msg.textContent=message;ok.style.display=success?'none':'inline-block';bar.style.background=success?'#16a34a':'#dc2626';document.getElementById('symptomAlertProgress').style.display=success?'block':'none';if(success){bar.style.transition='none';bar.style.width='100%';requestAnimationFrame(()=>{bar.style.transition='width 5s linear';bar.style.width='0%'});timer=setTimeout(()=>overlay.style.display='none',5000)}else{ok.onclick=()=>overlay.style.display='none'}}})();
</script>

<script>

// Search Functionality

document.getElementById('searchInput')?.addEventListener('input', function() {

    const filter = this.value.toUpperCase();

    const rows = document.querySelectorAll('.searchable-row');

    let visibleCount = 0;



    rows.forEach(row => {

        // Concatenate text content of Name, Code, and Description columns

        const textToSearch = (row.cells[1].textContent + ' ' + row.cells[2].textContent + ' ' + row.cells[3].textContent).toUpperCase();



        if (textToSearch.indexOf(filter) > -1) {

            row.style.display = '';

            visibleCount++;

        } else {

            row.style.display = 'none';

        }

    });



    document.getElementById('recordCount').innerText = visibleCount + ' RECORDS';

});



// Edit & Reset Logic

function editRow(d) {

    document.getElementById('edit_id').value = d.id;

    document.getElementById('symptom_name').value = d.symptom_name || '';

    document.getElementById('symptom_code').value = d.symptom_code || '';

    document.getElementById('description').value = d.description || '';

    document.getElementById('status').checked = (parseInt(d.status) === 1);



    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT SYMPTOM';

    document.getElementById('btnSubmit').innerText = 'UPDATE';

    document.getElementById('symptom_name').focus();

}



function resetForm() {

    document.getElementById('edit_id').value = '0';

    document.getElementById('formTitle').innerHTML = '<i class="bi bi-activity text-primary me-1"></i> ADD SYMPTOM';

    document.getElementById('btnSubmit').innerText = 'SAVE';

    document.getElementById('symptom_name').focus();
    if (typeof checkSymptomDuplicate === 'function') checkSymptomDuplicate();

}


    // Live duplicate detection, excluding the row currently being edited.
    const symptomNames = <?= json_encode(array_map(static fn($x) => ['id'=>(int)$x['id'], 'name'=>mb_strtolower(trim($x['symptom_name']))], $symptoms), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const symptomInput = document.getElementById('symptom_name');
    const symptomSave = document.getElementById('btnSubmit');
    const symptomDupMsg = document.getElementById('symptomDuplicateMsg');
    function checkSymptomDuplicate() {
        const name = symptomInput.value.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
        const editId = parseInt(document.getElementById('edit_id').value || '0', 10);
        const found = !!name && symptomNames.some(x => x.name === name && Number(x.id) !== editId);
        symptomDupMsg.style.display = found ? 'block' : 'none';
        symptomSave.disabled = found;
        symptomSave.title = found ? 'This symptom already exists' : '';
        return !found;
    }
    symptomInput.addEventListener('input', checkSymptomDuplicate);
    symptomInput.closest('form').addEventListener('submit', function(e) {
        if (!checkSymptomDuplicate()) { e.preventDefault(); showSymptomAlert('error', 'This symptom already exists. Please use the existing record.'); }
    });
    <?php if (!empty($_SESSION['symptom_alert'])): $sa = $_SESSION['symptom_alert']; unset($_SESSION['symptom_alert']); ?>
    showSymptomAlert(<?= json_encode($sa['type']) ?>, <?= json_encode($sa['message']) ?>);
    <?php endif; ?>
</script>



<?php require_once __DIR__ . '/layout_footer.php'; ?>
