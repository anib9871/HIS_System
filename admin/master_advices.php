<?php

// admin/master_advices.php

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php';



$page_title = "Advice Master";

$org_id = (int)($_SESSION['org_id'] ?? 1);

$center_id = (int)($_SESSION['center_id'] ?? 1);

$created_by = (int)($_SESSION['user_id'] ?? 0);



try {

    $tenant_pdo->exec("

        CREATE TABLE IF NOT EXISTS master_advices (

            id INT UNSIGNED NOT NULL AUTO_INCREMENT,

            org_id INT NOT NULL,

            center_id INT NOT NULL,

            advice_name VARCHAR(255) NOT NULL,

            description VARCHAR(500) NULL,

            status TINYINT(1) NOT NULL DEFAULT 1,

            created_by INT NULL,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_ma_org_center_name (org_id, center_id, advice_name),

            KEY idx_ma_org_center (org_id, center_id),

            KEY idx_ma_name (advice_name),

            KEY idx_ma_status (status)

        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

    ");

} catch (Throwable $e) {

    set_flash_err("Unable to prepare Advice Master: " . $e->getMessage());

}



// Insert common reusable advice entries once per organization and center. Existing records are preserved.
try {
    $defaultAdvices = [
        ['DRINK PLENTY OF WATER', 'Maintain hydration as appropriate for the patient.'],
        ['TAKE ADEQUATE REST', 'Get adequate rest and sleep.'],
        ['FOLLOW A BALANCED DIET', 'Choose a balanced diet with vegetables and fruits.'],
        ['AVOID OILY AND FRIED FOODS', 'Limit oily and fried foods.'],
        ['FOLLOW A LOW-SALT DIET', 'Limit added salt as advised by the clinician.'],
        ['REDUCE SUGAR INTAKE', 'Limit sugary foods and drinks.'],
        ['MONITOR BLOOD PRESSURE REGULARLY', 'Record blood pressure readings as advised.'],
        ['MONITOR BLOOD SUGAR REGULARLY', 'Check and record glucose as advised by the clinician.'],
        ['TAKE MEDICINES AS PRESCRIBED', 'Follow the prescription; do not change doses yourself.'],
        ['DO NOT STOP MEDICINES WITHOUT MEDICAL ADVICE', 'Discuss medicine changes with the treating clinician.'],
        ['FOLLOW UP AS ADVISED', 'Attend the recommended follow-up appointment.'],
        ['RETURN IF SYMPTOMS WORSEN', 'Seek medical review if symptoms worsen.'],
        ['MAINTAIN PERSONAL HYGIENE', 'Wash hands regularly and maintain personal hygiene.'],
        ['WASH HANDS FREQUENTLY', 'Wash hands with soap and water regularly.'],
        ['AVOID SMOKING', 'Avoid tobacco and smoking.'],
        ['AVOID ALCOHOL UNLESS CLEARED BY YOUR CLINICIAN', 'Check with your clinician about alcohol and prescribed medicines.'],
        ['DO REGULAR PHYSICAL ACTIVITY AS TOLERATED', 'Choose activity appropriate to the patient and clinical advice.'],
        ['AVOID HEAVY EXERCISE UNTIL REVIEW', 'Follow activity restrictions provided by the clinician.'],
        ['MAINTAIN A HEALTHY BODY WEIGHT', 'Follow an appropriate nutrition and activity plan.'],
        ['EAT SMALL REGULAR MEALS IF ADVISED', 'Follow meal guidance appropriate to the condition.'],
        ['AVOID KNOWN FOOD TRIGGERS', 'Avoid foods that worsen the patient’s symptoms.'],
        ['KEEP THE AFFECTED AREA CLEAN AND DRY', 'Follow the wound or skin-care instructions provided.'],
        ['DO NOT SCRATCH THE AFFECTED AREA', 'Avoid scratching irritated skin.'],
        ['AVOID SELF-MEDICATION', 'Consult a qualified clinician before starting new medicines.'],
        ['MAINTAIN A SYMPTOM DIARY', 'Record symptoms, timing and possible triggers.'],
        ['BRING CURRENT MEDICINES TO FOLLOW-UP', 'Bring a list or packages of current medicines to review.'],
        ['FOLLOW DIETARY INSTRUCTIONS PROVIDED', 'Follow the individual dietary plan recommended by your clinician.'],
        ['KEEP YOUR FOLLOW-UP APPOINTMENT', 'Attend the appointment on the date advised.'],
        ['MONITOR TEMPERATURE IF FEVERISH', 'Record temperature and seek review if symptoms worsen.'],
        ['REPORT POSSIBLE MEDICINE SIDE EFFECTS', 'Contact your clinician if you suspect a medicine reaction.'],
        ['MAINTAIN ADEQUATE SLEEP', 'Keep a regular sleep routine where possible.'],
        ['AVOID DRIVING IF DIZZY OR DROWSY', 'Do not drive or operate machinery when impaired.'],
        ['KEEP MEDICINES OUT OF CHILDREN’S REACH', 'Store medicines safely according to the label.'],
        ['CHECK MEDICINE LABELS BEFORE USE', 'Follow the label and prescription instructions.'],
        ['CONTACT THE CLINIC IF YOU HAVE QUESTIONS', 'Ask the care team if any instruction is unclear.'],
        ['FOLLOW INFECTION PREVENTION ADVICE', 'Follow hygiene and prevention instructions given by the care team.'],
        ['DISCUSS PREGNANCY OR BREASTFEEDING BEFORE MEDICINES', 'Tell the clinician before using medicines if pregnant or breastfeeding.'],
        ['USE ORAL REHYDRATION AS ADVISED', 'Use oral rehydration only when appropriate and as advised.'],
        ['KEEP A RECORD OF HOME READINGS', 'Bring relevant home measurements to the next consultation.'],
        ['FOLLOW WOUND DRESSING INSTRUCTIONS', 'Change dressings according to the clinician’s instructions.'],
        ['AVOID SHARING PERSONAL ITEMS', 'Do not share items that may spread infection.'],
        ['SEEK URGENT CARE FOR SEVERE SYMPTOMS', 'Use emergency services for severe or rapidly worsening symptoms.'],
        ['USE PROTECTIVE MEASURES AS ADVISED', 'Follow condition-specific prevention guidance from your clinician.'],
        ['FOLLOW THE PRESCRIBED PHYSIOTHERAPY PLAN', 'Perform exercises recommended by the treating professional.'],
        ['COMPLETE THE PRESCRIBED COURSE AS DIRECTED', 'Follow the clinician’s instructions for the prescribed course.'],
        ['USE MEDICINES ONLY AS DIRECTED', 'Use only medicines and doses approved by the treating clinician.'],
        ['KEEP A LIST OF CURRENT MEDICINES', 'Maintain an updated list for clinical reviews.'],
        ['FOLLOW THE CLINICIAN’S INDIVIDUAL INSTRUCTIONS', 'Follow the advice tailored to your condition.'],
        ['AVOID CLOSE CONTACT WHEN ADVISED', 'Follow condition-specific infection-control advice.'],
        ['KEEP THE TREATMENT AREA CLEAN', 'Follow the care instructions provided by your clinician.']
    ];
    $seedAdvice = $tenant_pdo->prepare("INSERT IGNORE INTO master_advices (org_id, center_id, advice_name, description, status, created_by) VALUES (?, ?, ?, ?, 1, ?)");
    foreach ($defaultAdvices as [$defaultName, $defaultDescription]) {
        $seedAdvice->execute([$org_id, $center_id, $defaultName, $defaultDescription, $created_by ?: null]);
    }
} catch (Throwable $e) {
    // Keep existing Advice Master functionality available if default seeding fails.
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_advice'])) {

    $advice_name = strtoupper(trim((string)($_POST['advice_name'] ?? '')));

    $description = trim((string)($_POST['description'] ?? ''));

    $status = isset($_POST['status']) ? 1 : 0;

    $edit_id = (int)($_POST['edit_id'] ?? 0);



    if ($advice_name === '') {

        $_SESSION['advice_swal'] = ['type' => 'error', 'message' => 'Advice Name is required.'];
        header('Location: master_advices.php'); exit;

    } else {

        try {

            if ($edit_id > 0) {

                $duplicateCheck = $tenant_pdo->prepare("SELECT id FROM master_advices WHERE org_id = ? AND center_id = ? AND UPPER(TRIM(advice_name)) = UPPER(TRIM(?)) AND id <> ? LIMIT 1");
                $duplicateCheck->execute([$org_id, $center_id, $advice_name, $edit_id]);
                if ($duplicateCheck->fetchColumn()) {
                    $_SESSION['advice_swal'] = ['type' => 'error', 'message' => 'This advice already exists in the current center.'];
                    header('Location: master_advices.php');
                    exit;
                }

                $stmt = $tenant_pdo->prepare("

                    UPDATE master_advices

                    SET advice_name = ?, description = ?, status = ?

                    WHERE id = ? AND org_id = ? AND center_id = ?

                ");

                $stmt->execute([

                    $advice_name,

                    $description !== '' ? $description : null,

                    $status,

                    $edit_id,

                    $org_id,

                    $center_id

                ]);

                $_SESSION['advice_swal'] = ['type' => 'success', 'message' => 'Advice updated successfully.'];

            } else {

                $duplicateCheck = $tenant_pdo->prepare("SELECT id FROM master_advices WHERE org_id = ? AND center_id = ? AND UPPER(TRIM(advice_name)) = UPPER(TRIM(?)) LIMIT 1");
                $duplicateCheck->execute([$org_id, $center_id, $advice_name]);
                if ($duplicateCheck->fetchColumn()) {
                    $_SESSION['advice_swal'] = ['type' => 'error', 'message' => 'This advice already exists in the current center.'];
                    header('Location: master_advices.php');
                    exit;
                }

                $stmt = $tenant_pdo->prepare("

                    INSERT INTO master_advices

                    (org_id, center_id, advice_name, description, status, created_by)

                    VALUES (?, ?, ?, ?, ?, ?)

                ");

                $stmt->execute([

                    $org_id,

                    $center_id,

                    $advice_name,

                    $description !== '' ? $description : null,

                    $status,

                    $created_by ?: null

                ]);

                $_SESSION['advice_swal'] = ['type' => 'success', 'message' => 'Advice added successfully.'];

            }



            header("Location: master_advices.php");

            exit;

        } catch (PDOException $e) {

            if ((int)($e->errorInfo[1] ?? 0) === 1062) {

                $_SESSION['advice_swal'] = ['type' => 'error', 'message' => 'This advice already exists in the current center.'];

            } else {

                $_SESSION['advice_swal'] = ['type' => 'error', 'message' => 'Database error while saving advice.'];

            }

        }

    }

}



if (isset($_GET['toggle_status'])) {

    $id = (int)$_GET['toggle_status'];

    $current_status = (int)($_GET['st'] ?? 1);

    $new_status = $current_status === 1 ? 0 : 1;



    $stmt = $tenant_pdo->prepare("

        UPDATE master_advices

        SET status = ?

        WHERE id = ? AND org_id = ? AND center_id = ?

    ");

    $stmt->execute([$new_status, $id, $org_id, $center_id]);
    $_SESSION['advice_swal'] = ['type' => 'success', 'message' => 'Advice status updated successfully.'];



    header("Location: master_advices.php");

    exit;

}



$stmt = $tenant_pdo->prepare("

    SELECT *

    FROM master_advices

    WHERE org_id = ? AND center_id = ?

    ORDER BY id DESC

");

$stmt->execute([$org_id, $center_id]);

$advices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];



require_once __DIR__ . '/layout_header.php';

$adviceSwal = $_SESSION['advice_swal'] ?? null;
unset($_SESSION['advice_swal']);
?>
<style>
#adviceAlertOverlay{position:fixed;inset:0;background:rgba(15,23,42,.42);display:flex;align-items:center;justify-content:center;z-index:20000;padding:16px}
#adviceAlertBox{width:min(340px,calc(100vw - 32px));background:#fff;border-radius:16px;padding:20px 22px 16px;text-align:center;box-shadow:0 18px 55px rgba(15,23,42,.24)}
#adviceAlertIcon{width:48px;height:48px;border:3px solid #16a34a;color:#16a34a;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:30px;line-height:1;margin:0 auto 12px}
#adviceAlertIcon.error{border-color:#dc2626;color:#dc2626;font-size:27px}
#adviceAlertTitle{font-size:20px;font-weight:750;color:#334155;margin-bottom:7px}
#adviceAlertMessage{font-size:13px;color:#64748b;line-height:1.45;margin-bottom:15px;overflow-wrap:anywhere}
#adviceAlertOk{border:0;border-radius:8px;background:#2563eb;color:white;font-weight:700;font-size:12px;padding:9px 25px;cursor:pointer}
#adviceAlertProgress{height:3px;background:#e2e8f0;margin-top:16px;overflow:hidden;border-radius:8px}
#adviceAlertProgress span{display:block;height:100%;width:100%;background:#16a34a}
</style>
<?php if (is_array($adviceSwal)): ?>
<div id="adviceAlertOverlay" role="alertdialog" aria-modal="true"><div id="adviceAlertBox">
<div id="adviceAlertIcon" class="<?= ($adviceSwal['type'] ?? '') === 'error' ? 'error' : '' ?>"><?= ($adviceSwal['type'] ?? '') === 'error' ? '!' : '✓' ?></div>
<div id="adviceAlertTitle"><?= ($adviceSwal['type'] ?? '') === 'error' ? 'Something went wrong' : 'Successful!' ?></div>
<div id="adviceAlertMessage"><?= htmlspecialchars((string)($adviceSwal['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
<?php if (($adviceSwal['type'] ?? '') === 'error'): ?><button type="button" id="adviceAlertOk" onclick="document.getElementById('adviceAlertOverlay').remove()">OK</button><?php else: ?><div id="adviceAlertProgress"><span></span></div><?php endif; ?>
</div></div>
<script>(function(){const o=document.getElementById('adviceAlertOverlay');if(!o)return;const e=o.querySelector('#adviceAlertIcon').classList.contains('error');if(!e){const b=o.querySelector('#adviceAlertProgress span');b.style.transition='width 5s linear';requestAnimationFrame(()=>b.style.width='0%');setTimeout(()=>o.remove(),5000);}})();</script>
<?php endif; ?>



<div class="row g-3">

    <div class="col-lg-5">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom">

                <span class="fw-bold small text-dark" id="formTitle">

                    <i class="bi bi-lightbulb text-primary me-1"></i> ADD ADVICE

                </span>

            </div>



            <div class="card-body p-3">

                <form method="POST" action="">

                    <input type="hidden" name="action_advice" value="1">

                    <input type="hidden" name="edit_id" id="edit_id" value="0">



                    <div class="mb-3">

                        <label class="form-label small fw-semibold text-secondary mb-1">ADVICE *</label>

                        <textarea

                            name="advice_name"

                            id="advice_name"

                            class="form-control form-control-sm text-uppercase"

                            rows="4"

                            maxlength="255"

                            placeholder="E.G. TAKE PLENTY OF FLUIDS AND MAINTAIN A LOW-SALT DIET"

                            required

                            autofocus

                        ></textarea>
                        <div id="adviceDuplicateWarning" class="small text-danger fw-bold mt-1" style="display:none;">THIS ADVICE ALREADY EXISTS.</div>

                    </div>



                    <div class="row g-2 mb-3">

                        <div class="col-12">

                            <label class="form-label small fw-semibold text-secondary mb-1">DESCRIPTION</label>

                            <input

                                type="text"

                                name="description"

                                id="description"

                                class="form-control form-control-sm"

                                maxlength="500"

                                placeholder="SHORT INTERNAL DESCRIPTION"

                            >

                        </div>

                    </div>



                    <div class="form-check form-switch mb-3">

                        <input class="form-check-input" type="checkbox" name="status" id="status" value="1" checked>

                        <label class="form-check-label small fw-semibold" for="status">ACTIVE STATUS</label>

                    </div>



                    <div class="d-flex gap-2">

                        <button type="submit" id="adviceSaveBtn" class="btn btn-primary btn-sm px-3">

                            <i class="bi bi-check2-circle me-1"></i> SAVE

                        </button>

                        <button type="button" class="btn btn-light border btn-sm px-3" onclick="resetAdviceForm()">

                            RESET

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>



    <div class="col-lg-7">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom">

                <div class="d-flex justify-content-between align-items-center">

                    <span class="fw-bold small text-dark">

                        <i class="bi bi-list-ul text-primary me-1"></i> ADVICE LIST

                    </span>

                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">

                        <?= count($advices) ?>

                    </span>

                </div>

            </div>



            <div class="card-body p-2">

                <div class="mb-2">

                    <input type="text" id="adviceSearch" class="form-control form-control-sm" placeholder="SEARCH ADVICE..." autocomplete="off">

                </div>



                <div class="table-responsive">

                    <table class="table table-sm table-bordered align-middle mb-0">

                        <thead class="table-light">

                            <tr>

                                <th style="width:55px;">#</th>

                                <th>ADVICE</th>

                                <th style="width:85px;">STATUS</th>

                                <th style="width:110px;">ACTION</th>

                            </tr>

                        </thead>

                        <tbody id="adviceTableBody">

                            <?php if (!$advices): ?>

                                <tr>

                                    <td colspan="4" class="text-center text-muted small py-4">NO ADVICE FOUND.</td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($advices as $index => $advice): ?>

                                    <tr data-search="<?= htmlspecialchars(strtolower(($advice['advice_name'] ?? '') . ' ' . ($advice['description'] ?? ''))) ?>">

                                        <td><?= (int)$advice['id'] ?></td>

                                        <td>

                                            <div class="fw-semibold small"><?= htmlspecialchars($advice['advice_name']) ?></div>

                                            <?php if (!empty($advice['description'])): ?>

                                                <div class="text-muted" style="font-size:10px;">

                                                    <?= htmlspecialchars($advice['description']) ?>

                                                </div>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <?php if ((int)$advice['status'] === 1): ?>

                                                <span class="badge bg-success-subtle text-success border border-success-subtle">ACTIVE</span>

                                            <?php else: ?>

                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">INACTIVE</span>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <div class="d-flex gap-1">

                                                <button

                                                    type="button"

                                                    class="btn btn-outline-primary btn-sm"

                                                    title="EDIT"

                                                    onclick='editAdvice(<?= json_encode($advice, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'

                                                >

                                                    <i class="bi bi-pencil"></i>

                                                </button>



                                                <a

                                                    href="master_advices.php?toggle_status=<?= (int)$advice['id'] ?>&st=<?= (int)$advice['status'] ?>"

                                                    class="btn btn-outline-<?= (int)$advice['status'] === 1 ? 'danger' : 'success' ?> btn-sm"

                                                    title="<?= (int)$advice['status'] === 1 ? 'DEACTIVATE' : 'ACTIVATE' ?>"

                                                >

                                                    <i class="bi bi-power"></i>

                                                </a>

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



<script>

function editAdvice(advice) {

    document.getElementById('formTitle').innerHTML =

        '<i class="bi bi-pencil-square text-primary me-1"></i> EDIT ADVICE';



    document.getElementById('edit_id').value = advice.id || 0;

    document.getElementById('advice_name').value = advice.advice_name || '';

    document.getElementById('description').value = advice.description || '';

    document.getElementById('status').checked = Number(advice.status) === 1;

    document.getElementById('advice_name').focus();

    window.scrollTo({ top: 0, behavior: 'smooth' });

}



function resetAdviceForm() {

    document.getElementById('formTitle').innerHTML =

        '<i class="bi bi-lightbulb text-primary me-1"></i> ADD ADVICE';



    document.getElementById('edit_id').value = 0;

    document.getElementById('advice_name').value = '';

    document.getElementById('description').value = '';

    document.getElementById('status').checked = true;

    document.getElementById('advice_name').focus();

}



document.getElementById('adviceSearch')?.addEventListener('input', function () {

    const term = this.value.toLowerCase().trim();

    document.querySelectorAll('#adviceTableBody tr[data-search]').forEach(function (row) {

        row.style.display = !term || (row.dataset.search || '').includes(term) ? '' : 'none';

    });

});


(function(){
 const field=document.getElementById('advice_name'), edit=document.getElementById('edit_id'), save=document.getElementById('adviceSaveBtn'), warning=document.getElementById('adviceDuplicateWarning');
 if(!field||!edit||!save||!warning)return;
 const norm=s=>String(s||'').trim().replace(/\s+/g,' ').toUpperCase();
 function check(){let dup=false;const value=norm(field.value);document.querySelectorAll('#adviceTableBody tr[data-search]').forEach(row=>{const name=row.querySelector('td:nth-child(2) > div.fw-semibold');const btn=row.querySelector('button[onclick^="editAdvice("]');if(!name)return;let rid='';if(btn){const m=btn.getAttribute('onclick').match(/editAdvice\((.*)\)$/);if(m){try{rid=String(JSON.parse(m[1]).id||'')}catch(e){}}}if(value&&norm(name.textContent)===value&&rid!==String(edit.value||'0'))dup=true;});warning.style.display=dup?'block':'none';save.disabled=dup;}
 field.addEventListener('input',check);const oldReset=window.resetAdviceForm;window.resetAdviceForm=function(){if(oldReset)oldReset();check();};const oldEdit=window.editAdvice;window.editAdvice=function(a){if(oldEdit)oldEdit(a);check();};check();
})();
</script>



<?php require_once __DIR__ . '/layout_footer.php'; ?>
