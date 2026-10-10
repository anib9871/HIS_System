<?php

// admin/master_durations.php

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php';



$page_title = "Duration Master";

$org_id = (int)($_SESSION['org_id'] ?? 1);

$center_id = (int)($_SESSION['center_id'] ?? 1);

$created_by = (int)($_SESSION['user_id'] ?? 0);

/* DEFAULT DURATION SEEDING: add only missing names for this org/center. */
$defaultDurations = [
    ['1DAY', 1, 'DAY', 'For a single day course'],
    ['2DAYS', 2, 'DAYS', 'Standard 2-day course'],
    ['3DAYS', 3, 'DAYS', 'Standard 3-day course'],
    ['4DAYS', 4, 'DAYS', 'Standard 4-day course'],
    ['5DAYS', 5, 'DAYS', 'Standard 5-day course'],
    ['6DAYS', 6, 'DAYS', 'Standard 6-day course'],
    ['7DAYS', 7, 'DAYS', 'Standard 7-day course'],
    ['10DAYS', 10, 'DAYS', 'Standard 10-day course'],
    ['14DAYS', 14, 'DAYS', 'Standard 14-day course'],
    ['15DAYS', 15, 'DAYS', 'Standard 15-day course'],
    ['21DAYS', 21, 'DAYS', 'Standard 21-day course'],
    ['30DAYS', 30, 'DAYS', 'Standard 30-day course'],
    ['45DAYS', 45, 'DAYS', 'Standard 45-day course'],
    ['60DAYS', 60, 'DAYS', 'Standard 60-day course'],
    ['90DAYS', 90, 'DAYS', 'Standard 90-day course'],
    ['1WEEK', 1, 'WEEK', 'One-week duration'],
    ['2WEEKS', 2, 'WEEKS', 'Two-week duration'],
    ['3WEEKS', 3, 'WEEKS', 'Three-week duration'],
    ['4WEEKS', 4, 'WEEKS', 'Four-week duration'],
    ['1MONTH', 1, 'MONTH', 'One-month duration'],
    ['2MONTHS', 2, 'MONTHS', 'Two-month duration'],
    ['3MONTHS', 3, 'MONTHS', 'Three-month duration'],
    ['6MONTHS', 6, 'MONTHS', 'Six-month duration'],
];
try {
    $checkDuration = $tenant_pdo->prepare("SELECT id FROM master_durations WHERE org_id = ? AND center_id = ? AND UPPER(TRIM(duration_name)) = ? LIMIT 1");
    $insertDuration = $tenant_pdo->prepare("INSERT INTO master_durations (org_id, center_id, duration_name, duration_value, duration_unit, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
    foreach ($defaultDurations as $item) {
        $checkDuration->execute([$org_id, $center_id, strtoupper(trim($item[0]))]);
        if (!$checkDuration->fetchColumn()) {
            $insertDuration->execute([$org_id, $center_id, $item[0], $item[1], $item[2], $item[3], $created_by ?: null]);
        }
    }
} catch (PDOException $e) {
    // Keep the page usable if defaults cannot be seeded; normal page logic continues.
}




// Save / Update

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_duration'])) {
    unset($_SESSION['duration_sweet_alert']);

    $duration_name  = strtoupper(trim($_POST['duration_name'] ?? ''));

    $duration_value = trim($_POST['duration_value'] ?? '');

    $duration_unit  = strtoupper(trim($_POST['duration_unit'] ?? ''));

    $description    = trim($_POST['description'] ?? '');

    $status         = isset($_POST['status']) ? 1 : 0;

    $edit_id        = (int)($_POST['edit_id'] ?? 0);



    $duration_value_db = ($duration_value !== '' && is_numeric($duration_value))

        ? (int)$duration_value

        : null;



    if ($duration_name === '') {
        $_SESSION['duration_sweet_alert'] = ['type' => 'error', 'title' => 'Required!', 'message' => 'Duration Name is required.'];
    } else {
        try {
            // Prevent duplicate names within this org/center, ignoring case and extra spaces.
            $dupStmt = $tenant_pdo->prepare("SELECT id FROM master_durations WHERE org_id = ? AND center_id = ? AND UPPER(TRIM(duration_name)) = ? AND id <> ? LIMIT 1");
            $dupStmt->execute([$org_id, $center_id, $duration_name, $edit_id]);
            if ($dupStmt->fetchColumn()) {
                $_SESSION['duration_sweet_alert'] = ['type' => 'error', 'title' => 'Already Exists!', 'message' => 'This duration name already exists. Please use a different name.'];
                header("Location: master_durations.php");
                exit;
            }

            if ($edit_id > 0) {

                $stmt = $tenant_pdo->prepare("

                    UPDATE master_durations

                    SET duration_name = ?,

                        duration_value = ?,

                        duration_unit = ?,

                        description = ?,

                        status = ?

                    WHERE id = ? AND org_id = ? AND center_id = ?

                ");



                $stmt->execute([

                    $duration_name,

                    $duration_value_db,

                    $duration_unit !== '' ? $duration_unit : null,

                    $description !== '' ? $description : null,

                    $status,

                    $edit_id,

                    $org_id,

                    $center_id

                ]);



                $_SESSION['duration_sweet_alert'] = ['type' => 'success', 'title' => 'Updated!', 'message' => 'Duration updated successfully.'];

            } else {

                $stmt = $tenant_pdo->prepare("

                    INSERT INTO master_durations

                    (

                        org_id,

                        center_id,

                        duration_name,

                        duration_value,

                        duration_unit,

                        description,

                        status,

                        created_by

                    )

                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)

                ");



                $stmt->execute([

                    $org_id,

                    $center_id,

                    $duration_name,

                    $duration_value_db,

                    $duration_unit !== '' ? $duration_unit : null,

                    $description !== '' ? $description : null,

                    $status,

                    $created_by ?: null

                ]);



                $_SESSION['duration_sweet_alert'] = ['type' => 'success', 'title' => 'Successful!', 'message' => 'Duration added successfully.'];

            }



            header("Location: master_durations.php");

            exit;

        } catch (PDOException $e) {

            $_SESSION['duration_sweet_alert'] = ['type' => 'error', 'title' => 'Database Error!', 'message' => $e->getMessage()];

        }

    }

}



// Toggle Status

if (isset($_GET['toggle_status'])) {

    $id = (int)$_GET['toggle_status'];

    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;



    $tenant_pdo->prepare("

        UPDATE master_durations

        SET status = ?

        WHERE id = ? AND org_id = ? AND center_id = ?

    ")->execute([$st, $id, $org_id, $center_id]);



    header("Location: master_durations.php");

    exit;

}



// List - Fetching all records for instant JS filtering

$durations = $tenant_pdo->prepare("

    SELECT *

    FROM master_durations

    WHERE org_id = ? AND center_id = ?

    ORDER BY id DESC

");

$durations->execute([$org_id, $center_id]);

$durations = $durations->fetchAll() ?: [];
$durationNamesForJs = array_map(static function ($row) { return ['id' => (int)$row['id'], 'name' => strtoupper(trim((string)$row['duration_name']))]; }, $durations);
$sweetAlert = $_SESSION['duration_sweet_alert'] ?? null;
unset($_SESSION['duration_sweet_alert']);



require_once __DIR__ . '/layout_header.php';

?>



<div class="row g-3">

    <div class="col-lg-5">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom">

                <span class="fw-bold small text-dark" id="formTitle">

                    <i class="bi bi-hourglass-split text-primary me-1"></i> ADD DURATION

                </span>

            </div>



            <div class="card-body p-3">

                <form method="POST" action="">

                    <input type="hidden" name="action_duration" value="1">

                    <input type="hidden" name="edit_id" id="edit_id" value="0">



                    <div class="mb-3">

                        <label class="form-label small fw-semibold text-secondary mb-1">DURATION NAME \*</label>

                        <input

                            type="text"

                            name="duration_name"

                            id="duration_name"

                            class="form-control form-control-sm text-uppercase"

                            placeholder=""

                            maxlength="100"

                            required

                            autofocus

                        >

                    </div>



                    <div class="row g-2 mb-3">

                        <div class="col-md-6">

                            <label class="form-label small fw-semibold text-secondary mb-1">VALUE</label>

                            <input

                                type="number"

                                name="duration_value"

                                id="duration_value"

                                class="form-control form-control-sm"

                                placeholder=""

                                min="1"

                                step="1"

                            >

                        </div>



                        <div class="col-md-6">

                            <label class="form-label small fw-semibold text-secondary mb-1">UNIT</label>

                            <input

                                type="text"

                                name="duration_unit"

                                id="duration_unit"

                                class="form-control form-control-sm text-uppercase"

                                placeholder=""

                                maxlength="30"

                            >

                        </div>

                    </div>



                    <div class="mb-3">

                        <label class="form-label small fw-semibold text-secondary mb-1">DESCRIPTION</label>

                        <textarea

                            name="description"

                            id="description"

                            class="form-control form-control-sm"

                            rows="3"

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

                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">RESET</button>

                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">SAVE DURATION</button>

                    </div>

                </form>

            </div>

        </div>

    </div>



    <div class="col-lg-7">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">

                <span class="fw-bold small text-dark">

                    <i class="bi bi-list-task text-primary me-1"></i> DURATIONS LIST

                </span>



                <!-- Instant Search Bar -->

                <div class="input-group input-group-sm" style="max-width: 250px;">

                    <span class="input-group-text bg-light border"><i class="bi bi-search"></i></span>

                    <input type="text" id="liveSearch" class="form-control" placeholder="Type to search instantly...">

                </div>

            </div>



            <div class="card-body p-0">

                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">

                    <table class="table table-hover align-middle mb-0 small" id="durationTable">

                        <thead class="table-light sticky-top">

                            <tr>

                                <th class="ps-3" style="width: 60px;">#</th>

                                <th>DURATION</th>

                                <th>VALUE / UNIT</th>

                                <th>DESCRIPTION</th>

                                <th>STATUS</th>

                                <th class="text-end pe-3">ACTION</th>

                            </tr>

                        </thead>



                        <tbody id="durationTbody">

                            <?php if (empty($durations)): ?>

                                <tr>

                                    <td colspan="6" class="text-center py-4 text-muted">

                                        NO DURATIONS FOUND.

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($durations as $d): ?>

                                    <?php $is_act = (int)$d['status'] === 1; ?>

                                    <!-- Added 'searchable-row' class for JS filtering -->

                                    <tr class="searchable-row">

                                        <td class="ps-3 font-monospace">#<?= (int)$d['id'] ?></td>



                                        <td class="fw-bold text-dark text-uppercase">

                                            <?= htmlspecialchars($d['duration_name']) ?>

                                        </td>



                                        <td>

                                            <?php if ($d['duration_value'] !== null && $d['duration_value'] !== ''): ?>

                                                <span class="font-monospace">

                                                    <?= (int)$d['duration_value'] ?>

                                                    <?= !empty($d['duration_unit']) ? ' ' . htmlspecialchars($d['duration_unit']) : '' ?>

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



                            <!-- Row to show when JS search yields no results -->

                            <tr id="noResultsJs" style="display: none;">

                                <td colspan="6" class="text-center py-4 text-muted">

                                    NO MATCHING RECORDS FOUND.

                                </td>

                            </tr>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</div>



<script>

// Live Search Logic

document.getElementById('liveSearch').addEventListener('input', function() {

    let filter = this.value.toLowerCase();

    let rows = document.querySelectorAll('.searchable-row');

    let hasVisible = false;



    rows.forEach(row => {

        let text = row.innerText.toLowerCase();

        if (text.includes(filter)) {

            row.style.display = '';

            hasVisible = true;

        } else {

            row.style.display = 'none';

        }

    });



    // Toggle 'No records found' message if needed

    let noResultsJs = document.getElementById('noResultsJs');

    if(noResultsJs) {

        if(!hasVisible && rows.length > 0) {

            noResultsJs.style.display = '';

        } else {

            noResultsJs.style.display = 'none';

        }

    }

});



// Edit Form Logic

function editRow(d) {

    document.getElementById('edit_id').value = d.id;

    document.getElementById('duration_name').value = d.duration_name || '';

    document.getElementById('duration_value').value = d.duration_value || '';

    document.getElementById('duration_unit').value = d.duration_unit || '';

    document.getElementById('description').value = d.description || '';

    document.getElementById('status').checked = (parseInt(d.status) === 1);



    document.getElementById('formTitle').innerHTML =

        '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT DURATION';



    document.getElementById('btnSubmit').innerText = 'UPDATE DURATION';

    document.getElementById('duration_name').focus();

}



function resetForm() {

    document.getElementById('edit_id').value = '0';



    document.getElementById('formTitle').innerHTML =

        '<i class="bi bi-hourglass-split text-primary me-1"></i> ADD DURATION';



    document.getElementById('btnSubmit').innerText = 'SAVE DURATION';

    document.getElementById('duration_name').focus();

}

</script>

<style>
#durationAlertOverlay{position:fixed;inset:0;background:rgba(15,23,42,.35);display:none;align-items:center;justify-content:center;z-index:99999;padding:16px}
#durationAlertBox{width:min(360px,94vw);background:#fff;border-radius:16px;padding:22px 24px 18px;text-align:center;box-shadow:0 18px 55px rgba(15,23,42,.25);font-family:inherit}
#durationAlertIcon{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:27px;font-weight:700}
#durationAlertTitle{font-size:20px;font-weight:700;color:#172033;margin-bottom:7px}
#durationAlertMessage{font-size:14px;color:#64748b;overflow-wrap:anywhere;margin-bottom:16px}
#durationAlertOk{display:none;border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:600;padding:9px 28px;cursor:pointer}
#durationAlertProgress{height:3px;background:#e2e8f0;border-radius:5px;overflow:hidden;margin-top:16px}
#durationAlertProgress span{display:block;height:100%;width:100%;background:#16a34a}
</style>
<div id="durationAlertOverlay" role="alertdialog" aria-modal="true" aria-labelledby="durationAlertTitle" aria-describedby="durationAlertMessage">
  <div id="durationAlertBox"><div id="durationAlertIcon"></div><div id="durationAlertTitle"></div><div id="durationAlertMessage"></div><button id="durationAlertOk" type="button">OK</button><div id="durationAlertProgress"><span></span></div></div>
</div>
<script>
(function(){
 const names = <?php echo json_encode($durationNamesForJs, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
 const nameInput = document.getElementById('duration_name');
 const editId = document.getElementById('edit_id');
 const saveBtn = document.getElementById('btnSubmit');
 let duplicate = false;
 function checkDurationDuplicate(){
   if(!nameInput || !saveBtn) return;
   const value = nameInput.value.trim().replace(/\s+/g,' ').toUpperCase();
   const currentId = parseInt(editId.value || '0',10);
   duplicate = value !== '' && names.some(x => x.id !== currentId && x.name.replace(/\s+/g,' ').toUpperCase() === value);
   let warning = document.getElementById('durationDuplicateWarning');
   if(!warning){ warning=document.createElement('div'); warning.id='durationDuplicateWarning'; warning.className='small text-danger mt-1 fw-semibold'; nameInput.parentNode.appendChild(warning); }
   warning.textContent = duplicate ? 'This duration already exists. Please enter a different name.' : '';
   saveBtn.disabled = duplicate;
   saveBtn.title = duplicate ? 'Duplicate duration name' : '';
 }
 if(nameInput){ nameInput.addEventListener('input', checkDurationDuplicate); }
 const payload = <?php echo json_encode($sweetAlert, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
 if(payload){
   const overlay=document.getElementById('durationAlertOverlay'), icon=document.getElementById('durationAlertIcon'), title=document.getElementById('durationAlertTitle'), msg=document.getElementById('durationAlertMessage'), ok=document.getElementById('durationAlertOk'), progress=document.querySelector('#durationAlertProgress span');
   const success=payload.type==='success';
   icon.textContent=success?'✓':'!'; icon.style.background=success?'#dcfce7':'#fee2e2'; icon.style.color=success?'#16a34a':'#dc2626';
   title.textContent=payload.title|| (success?'Successful!':'Error!'); msg.textContent=payload.message||'';
   ok.style.display=success?'none':'inline-block'; document.getElementById('durationAlertProgress').style.display=success?'block':'none'; overlay.style.display='flex';
   function close(){overlay.style.display='none';}
   ok.addEventListener('click',close);
   if(success){ progress.style.transition='width 5s linear'; requestAnimationFrame(()=>{progress.style.width='0%';}); setTimeout(close,5000); }
 }
})();
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
