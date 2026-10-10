<?php

// admin/frequency_master.php

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php';



$page_title = "Frequency Master";

$org_id = (int)($_SESSION['org_id'] ?? 1);

$center_id = (int)($_SESSION['center_id'] ?? 1);

$created_by = (int)($_SESSION['user_id'] ?? 0);

// Seed common prescription frequencies for each organization/center without duplicating existing records.
try {
    $defaultFrequencies = [
        ['ONCE DAILY', 'OD', 'Take one time a day'],
        ['TWICE DAILY', 'BD', 'Take two times a day (Morning and Evening)'],
        ['THREE TIMES A DAY', 'TDS', 'Take three times a day (Morning, Afternoon, and Night)'],
        ['FOUR TIMES A DAY', 'QID', 'Take four times a day, as prescribed'],
        ['AT BEDTIME', 'HS', 'Take at bedtime, as prescribed'],
        ['AS NEEDED', 'SOS', 'Take only when required, as prescribed'],
        ['EVERY 4 HOURS', 'Q4H', 'Every 4 hours, as prescribed'],
        ['EVERY 6 HOURS', 'Q6H', 'Every 6 hours, as prescribed'],
        ['EVERY 8 HOURS', 'Q8H', 'Every 8 hours, as prescribed'],
        ['EVERY 12 HOURS', 'Q12H', 'Every 12 hours, as prescribed'],
        ['EVERY 24 HOURS', 'Q24H', 'Every 24 hours, as prescribed'],
        ['ONCE WEEKLY', 'OW', 'Once a week, as prescribed'],
        ['TWICE WEEKLY', 'BIW', 'Two times a week, as prescribed'],
        ['THREE TIMES WEEKLY', 'TIW', 'Three times a week, as prescribed'],
        ['ONCE MONTHLY', 'OM', 'Once a month, as prescribed'],
        ['BEFORE FOOD', 'AC', 'Before food, as prescribed'],
        ['AFTER FOOD', 'PC', 'After food, as prescribed'],
        ['WITH FOOD', 'WF', 'Take with food, as prescribed'],
        ['EMPTY STOMACH', 'ES', 'Take on an empty stomach, as prescribed'],
        ['IN THE MORNING', 'AM', 'In the morning, as prescribed'],
        ['IN THE EVENING', 'PM', 'In the evening, as prescribed'],
        ['AT NIGHT', 'ON', 'At night, as prescribed'],
        ['IMMEDIATELY', 'STAT', 'Give immediately only when prescribed'],
        ['EVERY OTHER DAY', 'EOD', 'Every other day, as prescribed'],
        ['ALTERNATE DAYS', 'AD', 'On alternate days, as prescribed'],
        ['WEEKDAYS ONLY', 'WD', 'On weekdays only, as prescribed'],
        ['WEEKENDS ONLY', 'WE', 'On weekends only, as prescribed'],
        ['EVERY 2 HOURS', 'Q2H', 'Every 2 hours, as prescribed'],
        ['EVERY 3 HOURS', 'Q3H', 'Every 3 hours, as prescribed'],
        ['EVERY 6 HOURS WHEN AWAKE', 'Q6HWA', 'Every 6 hours while awake, as prescribed'],
        ['BEFORE MEALS', 'AC TID', 'Before meals, as prescribed'],
        ['AFTER MEALS', 'PC TID', 'After meals, as prescribed'],
        ['THIRTY MINUTES BEFORE FOOD', 'BBF', '30 minutes before food, as prescribed'],
        ['AT FIXED INTERVALS', 'FI', 'At prescribed fixed intervals'],
        ['AS DIRECTED', 'ADIR', 'Use exactly as directed by the prescriber'],
    ];
    $checkFrequency = $tenant_pdo->prepare("SELECT id FROM frequency_master WHERE org_id = ? AND center_id = ? AND UPPER(TRIM(frequency_name)) = UPPER(TRIM(?)) LIMIT 1");
    $insertFrequency = $tenant_pdo->prepare("INSERT INTO frequency_master (org_id, center_id, frequency_name, frequency_code, description, status, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)");
    foreach ($defaultFrequencies as $freq) {
        $checkFrequency->execute([$org_id, $center_id, $freq[0]]);
        if (!$checkFrequency->fetchColumn()) {
            $insertFrequency->execute([$org_id, $center_id, $freq[0], $freq[1], $freq[2], $created_by ?: null]);
        }
    }
} catch (PDOException $e) {
    error_log('Frequency Master default seed error: ' . $e->getMessage());
}




// Save / Update

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_frequency'])) {

    $frequency_name = strtoupper(trim($_POST['frequency_name'] ?? ''));

    $frequency_code = strtoupper(trim($_POST['frequency_code'] ?? ''));

    $description = trim($_POST['description'] ?? '');

    $status = isset($_POST['status']) ? 1 : 0;

    $edit_id = (int)($_POST['edit_id'] ?? 0);



    if ($frequency_name === '') {

        $_SESSION['frequency_sweet_alert'] = ['type' => 'error', 'message' => 'Frequency Name is required.'];

    } else {

        try {

            if ($edit_id > 0) {

                $stmt = $tenant_pdo->prepare("

                    UPDATE frequency_master

                    SET frequency_name = ?, frequency_code = ?, description = ?, status = ?

                    WHERE id = ? AND org_id = ? AND center_id = ?

                ");

                $stmt->execute([

                    $frequency_name,

                    $frequency_code !== '' ? $frequency_code : null,

                    $description !== '' ? $description : null,

                    $status,

                    $edit_id,

                    $org_id,

                    $center_id

                ]);

                $_SESSION['frequency_sweet_alert'] = ['type' => 'success', 'message' => 'Frequency updated successfully.'];

            } else {

                $stmt = $tenant_pdo->prepare("

                    INSERT INTO frequency_master

                    (

                        org_id, center_id, frequency_name, frequency_code, description, status, created_by

                    )

                    VALUES (?, ?, ?, ?, ?, ?, ?)

                ");

                $stmt->execute([

                    $org_id,

                    $center_id,

                    $frequency_name,

                    $frequency_code !== '' ? $frequency_code : null,

                    $description !== '' ? $description : null,

                    $status,

                    $created_by ?: null

                ]);

                $_SESSION['frequency_sweet_alert'] = ['type' => 'success', 'message' => 'Frequency added successfully.'];

            }



            header("Location: frequency_master.php");

            exit;

        } catch (PDOException $e) {

            $_SESSION['frequency_sweet_alert'] = ['type' => 'error', 'message' => 'Database Error: ' . $e->getMessage()];

        }

    }

}



// Toggle Status

if (isset($_GET['toggle_status'])) {

    $id = (int)$_GET['toggle_status'];

    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;



    $tenant_pdo->prepare("

        UPDATE frequency_master

        SET status = ?

        WHERE id = ? AND org_id = ? AND center_id = ?

    ")->execute([$st, $id, $org_id, $center_id]);



    header("Location: frequency_master.php");

    exit;

}



// List

$frequencies = $tenant_pdo->prepare("

    SELECT *

    FROM frequency_master

    WHERE org_id = ? AND center_id = ?

    ORDER BY id DESC

");

$frequencies->execute([$org_id, $center_id]);

$frequencies = $frequencies->fetchAll() ?: [];



require_once __DIR__ . '/layout_header.php';

$frequencySweetAlert = $_SESSION['frequency_sweet_alert'] ?? null;
unset($_SESSION['frequency_sweet_alert']);
?>
<style>
.frequency-alert-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:20000;padding:16px}
.frequency-alert-backdrop.show{display:flex;animation:freqFade .16s ease-out}
.frequency-alert-box{width:min(420px,100%);background:#fff;border-radius:16px;padding:25px 24px 22px;text-align:center;box-shadow:0 24px 70px rgba(15,23,42,.28);animation:freqPop .2s ease-out}
.frequency-alert-box.success{width:min(300px,100%);padding:17px 19px 13px;border-radius:14px}
.frequency-alert-icon{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;margin:0 auto 13px}
.frequency-alert-box.success .frequency-alert-icon{width:44px;height:44px;font-size:25px;margin-bottom:8px}
.frequency-alert-box.success .frequency-alert-actions{display:none}
.frequency-alert-icon.success{background:#dcfce7;color:#16a34a;border:3px solid #86efac}
.frequency-alert-icon.error{background:#fee2e2;color:#dc2626;border:3px solid #fca5a5}
.frequency-alert-title{font-size:20px;font-weight:700;color:#172033;margin-bottom:7px}
.frequency-alert-box.success .frequency-alert-title{font-size:17px;margin-bottom:4px}
.frequency-alert-message{font-size:14px;line-height:1.5;color:#64748b;overflow-wrap:anywhere}
.frequency-alert-box.success .frequency-alert-message{font-size:13px}
.frequency-alert-actions{margin-top:20px}
.frequency-alert-ok{min-width:110px;border:0;border-radius:8px;background:#dc2626;color:#fff;padding:9px 22px;font-size:13px;font-weight:700;cursor:pointer}
.frequency-alert-progress{height:3px;background:#e2e8f0;border-radius:8px;overflow:hidden;margin-top:14px}
.frequency-alert-progress span{display:block;height:100%;width:100%;background:#16a34a;transform-origin:left}
.frequency-alert-box.error .frequency-alert-progress{display:none}
@keyframes freqFade{from{opacity:0}to{opacity:1}}@keyframes freqPop{from{opacity:0;transform:translateY(8px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
</style>
<div class="frequency-alert-backdrop" id="frequencyAlertBackdrop" role="dialog" aria-modal="true" aria-labelledby="frequencyAlertTitle">
  <div class="frequency-alert-box" id="frequencyAlertBox">
    <div class="frequency-alert-icon" id="frequencyAlertIcon">✓</div>
    <div class="frequency-alert-title" id="frequencyAlertTitle">Successful!</div>
    <div class="frequency-alert-message" id="frequencyAlertMessage"></div>
    <div class="frequency-alert-actions"><button type="button" class="frequency-alert-ok" id="frequencyAlertOk">OK</button></div>
    <div class="frequency-alert-progress"><span id="frequencyAlertProgress"></span></div>
  </div>
</div>
<script>window.frequencySweetAlertPayload = <?php echo json_encode($frequencySweetAlert, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;</script>
<?php


?>



<div class="row g-3">

    <div class="col-lg-5">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom">

                <span class="fw-bold small text-dark" id="formTitle">

                    <i class="bi bi-arrow-repeat text-primary me-1"></i> ADD FREQUENCY

                </span>

            </div>



            <div class="card-body p-3">

                <form method="POST" action="">

                    <input type="hidden" name="action_frequency" value="1">

                    <input type="hidden" name="edit_id" id="edit_id" value="0">



                    <div class="row g-2 mb-3">

                        <div class="col-md-8">

                            <label class="form-label small fw-semibold text-secondary mb-1">FREQUENCY NAME \\*</label>

                            <input type="text" name="frequency_name" id="frequency_name" class="form-control form-control-sm text-uppercase" placeholder="E.G. TWICE DAILY" maxlength="100" required autofocus>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label small fw-semibold text-secondary mb-1">CODE</label>

                            <input type="text" name="frequency_code" id="frequency_code" class="form-control form-control-sm font-monospace text-uppercase" placeholder="E.G. BD" maxlength="30">

                        </div>

                    </div>



                    <div class="mb-3">

                        <label class="form-label small fw-semibold text-secondary mb-1">DESCRIPTION</label>

                        <textarea name="description" id="description" class="form-control form-control-sm" rows="3" placeholder="E.G. TAKE TWO TIMES A DAY"></textarea>

                    </div>



                    <div class="form-check form-switch mb-3">

                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>

                        <label class="form-check-label small fw-semibold text-secondary" for="status">

                            ACTIVE STATUS

                        </label>

                    </div>



                    <div class="d-flex gap-2 border-top pt-2">

                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">RESET</button>

                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">SAVE FREQUENCY</button>

                    </div>

                </form>

            </div>

        </div>

    </div>



    <div class="col-lg-7">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">

                <span class="fw-bold small text-dark">

                    <i class="bi bi-list-task text-primary me-1"></i> FREQUENCIES LIST

                </span>



                <!-- NEW SEARCH BAR ADDED HERE -->

                <div class="d-flex align-items-center gap-2">

                    <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search frequency..." onkeyup="filterTable()" style="width: 200px;">

                    <span class="badge bg-light text-secondary border">

                        <?= count($frequencies) ?> RECORDS

                    </span>

                </div>

            </div>



            <div class="card-body p-0">

                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">

                    <table class="table table-hover align-middle mb-0 small" id="frequencyTable">

                        <thead class="table-light sticky-top">

                            <tr>

                                <th class="ps-3" style="width: 60px;">#</th>

                                <th>FREQUENCY</th>

                                <th>CODE</th>

                                <th>DESCRIPTION</th>

                                <th>STATUS</th>

                                <th class="text-end pe-3">ACTION</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php if (empty($frequencies)): ?>

                                <tr id="noDataRow">

                                    <td colspan="6" class="text-center py-4 text-muted">

                                        NO FREQUENCIES FOUND.

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($frequencies as $f): ?>

                                    <?php $is_act = (int)$f['status'] === 1; ?>

                                    <tr class="data-row">

                                        <td class="ps-3 font-monospace">#<?= (int)$f['id'] ?></td>

                                        <td class="fw-bold text-dark text-uppercase search-col">

                                            <?= htmlspecialchars($f['frequency_name']) ?>

                                        </td>

                                        <td class="search-col">

                                            <?php if (!empty($f['frequency_code'])): ?>

                                                <span class="badge bg-light text-primary border font-monospace text-uppercase">

                                                    <?= htmlspecialchars($f['frequency_code']) ?>

                                                </span>

                                            <?php else: ?>

                                                -

                                            <?php endif; ?>

                                        </td>

                                        <td class="text-secondary search-col">

                                            <?= htmlspecialchars($f['description'] ?? '') ?: '-' ?>

                                        </td>

                                        <td>

                                            <a href="?toggle_status=<?= (int)$f['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">

                                                <?= $is_act ? 'ACTIVE' : 'INACTIVE' ?>

                                            </a>

                                        </td>

                                        <td class="text-end pe-3">

                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($f, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="EDIT">

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

function editRow(d) {

    document.getElementById('edit_id').value = d.id;

    document.getElementById('frequency_name').value = d.frequency_name || '';

    document.getElementById('frequency_code').value = d.frequency_code || '';

    document.getElementById('description').value = d.description || '';

    document.getElementById('status').checked = (parseInt(d.status) === 1);



    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT FREQUENCY';

    document.getElementById('btnSubmit').innerText = 'UPDATE FREQUENCY';

    document.getElementById('frequency_name').focus();

}



function resetForm() {

    document.getElementById('edit_id').value = '0';

    document.getElementById('formTitle').innerHTML = '<i class="bi bi-arrow-repeat text-primary me-1"></i> ADD FREQUENCY';

    document.getElementById('btnSubmit').innerText = 'SAVE FREQUENCY';

    document.getElementById('frequency_name').focus();

}



// NEW SEARCH FILTER FUNCTION

function filterTable() {

    let input = document.getElementById("searchInput").value.toUpperCase();

    let rows = document.querySelectorAll("#frequencyTable .data-row");



    rows.forEach(row => {

        let textContent = "";

        let cols = row.querySelectorAll(".search-col");



        cols.forEach(col => {

            textContent += col.textContent || col.innerText;

        });



        if (textContent.toUpperCase().indexOf(input) > -1) {

            row.style.display = "";

        } else {

            row.style.display = "none";

        }

    });

}

</script>



<script>
(function(){
 const payload=window.frequencySweetAlertPayload;
 if(payload){
  const backdrop=document.getElementById('frequencyAlertBackdrop'), box=document.getElementById('frequencyAlertBox'), icon=document.getElementById('frequencyAlertIcon');
  const title=document.getElementById('frequencyAlertTitle'), msg=document.getElementById('frequencyAlertMessage'), ok=document.getElementById('frequencyAlertOk'), bar=document.getElementById('frequencyAlertProgress');
  const success=payload.type==='success'; box.classList.add(success?'success':'error'); icon.classList.add(success?'success':'error'); icon.textContent=success?'✓':'!'; title.textContent=success?'Successful!':'Error!'; msg.textContent=payload.message||''; backdrop.classList.add('show');
  if(success){bar.style.transition='transform 5s linear'; requestAnimationFrame(()=>{bar.style.transform='scaleX(0)';}); setTimeout(()=>backdrop.classList.remove('show'),5000);} else {ok.addEventListener('click',()=>backdrop.classList.remove('show')); ok.focus();}
 }
})();
</script>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
