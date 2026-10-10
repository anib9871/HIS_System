<?php

// admin/master_units.php

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php';



$page_title = "Unit Master";



$org_id    = (int)($_SESSION['org_id'] ?? 1);

$center_id = (int)($_SESSION['center_id'] ?? 1);

$created_by = (int)($_SESSION['user_id'] ?? 0);



// Default medical / prescription units: add only missing units for this organization and center.
// Runs on every page load, so existing records are preserved and missing defaults are added.
try {
    $defaultUnits = [
        // Solid oral dosage forms
        'TAB (TABLET)', 'CAP (CAPSULE)', 'PILL', 'CHEWABLE TABLET', 'DISPERSIBLE TABLET',
        'EFFERVESCENT TABLET', 'SUSTAINED RELEASE TABLET', 'ORALLY DISINTEGRATING TABLET',
        'SUBLINGUAL TABLET', 'BUCCAL TABLET', 'LOZ (LOZENGE)', 'TROCHE', 'GRANULES',
        'POWDER', 'SACHET', 'ORAL POWDER', 'DRY SYRUP',

        // Liquids
        'SYRUP', 'SUSPENSION', 'SOLUTION', 'ORAL DROPS', 'DROPS', 'ELIXIR', 'EMULSION',
        'MOUTHWASH', 'GARGLE', 'ORAL LIQUID', 'LINCTUS',

        // Injectable / infusion
        'INJECTION', 'INJ (INJECTION)', 'AMP (AMPOULE)', 'VIAL', 'PFS (PREFILLED SYRINGE)',
        'INFUSION', 'IV FLUID', 'NEBULE',

        // Topical forms
        'CREAM', 'OINTMENT', 'GEL', 'LOTION', 'LINIMENT', 'PASTE', 'BALM', 'TOPICAL SOLUTION',
        'DUSTING POWDER', 'MEDICATED SHAMPOO', 'SOAP', 'FACE WASH', 'FOAM', 'SPRAY',
        'AEROSOL', 'PATCH', 'TRANSDERMAL PATCH',

        // Respiratory / nasal / eye / ear
        'INHALER', 'ROTACAP', 'RESPULE', 'NEBULISATION SOLUTION', 'NASAL DROPS', 'NASAL SPRAY',
        'EYE DROPS', 'EYE OINTMENT', 'EAR DROPS', 'EAR SPRAY',

        // Rectal / vaginal / other routes
        'SUPP (SUPPOSITORY)', 'SUPPOSITORY', 'PESSARY', 'VAGINAL TABLET', 'VAGINAL CREAM',
        'VAGINAL GEL', 'ENEMA', 'DOUCHE',

        // Other common dosage forms
        'TINCTURE', 'EXTRACT', 'MEDICATED DRESSING', 'IMPLANT', 'PELLET', 'KIT', 'COMBIPACK',
        'CARTRIDGE', 'SUSPENSION FOR INJECTION', 'CONCENTRATE', 'ORAL JELLY', 'GUM',

        // Common dispensing / measurement units (useful for stock and consumables)
        'UNIT', 'PIECE', 'PCS', 'PACK', 'STRIP', 'BLISTER', 'BOTTLE', 'BOX', 'VIALS',
        'AMPOULE', 'TUBE', 'SACHETS', 'PAIR', 'SET', 'DOSE', 'PUFF', 'SPRAY DOSE',
        'ML', 'L', 'MG', 'G', 'MCG', 'KG', 'IU', 'UNIT DOSE'
    ];

    $insertDefault = $tenant_pdo->prepare("
        INSERT INTO master_units (org_id, center_id, unit_name, status, created_by)
        SELECT ?, ?, ?, 1, ?
        WHERE NOT EXISTS (
            SELECT 1 FROM master_units
            WHERE org_id = ? AND center_id = ? AND UPPER(TRIM(unit_name)) = UPPER(TRIM(?))
        )
    ");

    foreach (array_unique($defaultUnits) as $unit) {
        $insertDefault->execute([
            $org_id, $center_id, $unit, $created_by ?: null,
            $org_id, $center_id, $unit
        ]);
    }
} catch (PDOException $e) {
    error_log('Unit Master default seed error: ' . $e->getMessage());
}

// Save / Update

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_unit'])) {



    $unit_name = strtoupper(trim($_POST['unit_name'] ?? ''));

    $status    = isset($_POST['status']) ? 1 : 0;

    $edit_id   = (int)($_POST['edit_id'] ?? 0);



    if ($unit_name === '') {

        $_SESSION['unit_sweet_alert'] = ['type' => 'error', 'message' => 'Unit Name is required.'];

    } else {

        try {
            // Server-side duplicate check (case-insensitive), excluding the current row while editing.
            $duplicateStmt = $tenant_pdo->prepare("
                SELECT id FROM master_units
                WHERE org_id = ? AND center_id = ?
                  AND UPPER(TRIM(unit_name)) = UPPER(TRIM(?))
                  AND id <> ?
                LIMIT 1
            ");
            $duplicateStmt->execute([$org_id, $center_id, $unit_name, $edit_id]);
            $duplicateUnit = $duplicateStmt->fetchColumn();

            if ($duplicateUnit) {
                $_SESSION['unit_sweet_alert'] = ['type' => 'error', 'message' => 'This unit already exists. Please enter a different unit name.'];
            } elseif ($edit_id > 0) {

                $stmt = $tenant_pdo->prepare("

                    UPDATE master_units

                    SET unit_name = ?,

                        status = ?

                    WHERE id = ?

                      AND org_id = ?

                      AND center_id = ?

                ");



                $stmt->execute([

                    $unit_name,

                    $status,

                    $edit_id,

                    $org_id,

                    $center_id

                ]);



                $_SESSION['unit_sweet_alert'] = ['type' => 'success', 'message' => 'Unit updated successfully.'];

            } else {

                // FIXED: Removed unit_code and adjusted ? count to 5

                $stmt = $tenant_pdo->prepare("

                    INSERT INTO master_units

                    (

                        org_id,

                        center_id,

                        unit_name,

                        status,

                        created_by

                    )

                    VALUES (?, ?, ?, ?, ?) 

                ");



                $stmt->execute([

                    $org_id,

                    $center_id,

                    $unit_name,

                    $status,

                    $created_by ?: null

                ]);



                $_SESSION['unit_sweet_alert'] = ['type' => 'success', 'message' => 'Unit added successfully.'];

            }



            header("Location: master_units.php");

            exit;



        } catch (PDOException $e) {

            $_SESSION['unit_sweet_alert'] = ['type' => 'error', 'message' => 'Database Error: ' . $e->getMessage()];

        }

    }

}



// Toggle Status

if (isset($_GET['toggle_status'])) {

    $id = (int)$_GET['toggle_status'];

    $st = ((int)($_GET['st'] ?? 1) === 1) ? 0 : 1;



    $tenant_pdo->prepare("

        UPDATE master_units

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



    header("Location: master_units.php");

    exit;

}



// List

$units = $tenant_pdo->prepare("

    SELECT *

    FROM master_units

    WHERE org_id = ?

      AND center_id = ?

    ORDER BY id DESC

");



$units->execute([$org_id, $center_id]);

$units = $units->fetchAll() ?: [];



require_once __DIR__ . '/layout_header.php';
$unitSweetAlert = $_SESSION['unit_sweet_alert'] ?? null;
unset($_SESSION['unit_sweet_alert']);
?>
<style>
.unit-alert-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.48);display:none;align-items:center;justify-content:center;z-index:20000;padding:18px}.unit-alert-backdrop.show{display:flex;animation:unitFadeIn .18s ease-out}.unit-alert-box{width:min(420px,100%);background:#fff;border-radius:16px;padding:26px 24px 22px;text-align:center;box-shadow:0 24px 70px rgba(15,23,42,.28);animation:unitPopIn .22s ease-out}.unit-alert-box.success{width:min(300px,100%);padding:18px 20px 14px;border-radius:14px}.unit-alert-box.success .unit-alert-icon{width:44px;height:44px;font-size:25px;margin-bottom:9px}.unit-alert-box.success .unit-alert-title{font-size:17px;margin-bottom:5px}.unit-alert-box.success .unit-alert-message{font-size:13px}.unit-alert-box.success .unit-alert-actions{display:none}.unit-alert-box.success .unit-alert-progress{margin-top:13px}.unit-alert-icon{width:62px;height:62px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:700;margin:0 auto 14px}.unit-alert-box.success .unit-alert-icon{background:#dcfce7;color:#16a34a;border:3px solid #86efac}.unit-alert-box.error .unit-alert-icon{background:#fee2e2;color:#dc2626;border:3px solid #fca5a5}.unit-alert-title{font-size:20px;font-weight:700;color:#172033;margin-bottom:8px}.unit-alert-message{font-size:14px;line-height:1.55;color:#64748b;overflow-wrap:anywhere}.unit-alert-actions{margin-top:22px;display:flex;justify-content:center}.unit-alert-ok{min-width:110px;border:0;border-radius:8px;background:#2563eb;color:#fff;padding:9px 22px;font-size:13px;font-weight:700;cursor:pointer}.unit-alert-box.error .unit-alert-ok{background:#dc2626}.unit-alert-progress{height:3px;background:#e2e8f0;border-radius:8px;overflow:hidden;margin-top:18px}.unit-alert-progress span{display:block;height:100%;width:100%;background:#16a34a;transform-origin:left}.unit-alert-box.error .unit-alert-progress{display:none}@keyframes unitFadeIn{from{opacity:0}to{opacity:1}}@keyframes unitPopIn{from{opacity:0;transform:translateY(8px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
</style>
<div class="unit-alert-backdrop" id="unitAlertBackdrop" role="dialog" aria-modal="true" aria-labelledby="unitAlertTitle"><div class="unit-alert-box" id="unitAlertBox"><div class="unit-alert-icon" id="unitAlertIcon">✓</div><div class="unit-alert-title" id="unitAlertTitle">Success</div><div class="unit-alert-message" id="unitAlertMessage"></div><div class="unit-alert-actions"><button type="button" class="unit-alert-ok" id="unitAlertOk">OK</button></div><div class="unit-alert-progress"><span id="unitAlertProgress"></span></div></div></div>
<script>window.unitSweetAlertPayload = <?php echo json_encode($unitSweetAlert, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;</script>




<div class="row g-3">

    <div class="col-lg-4">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom">

                <span class="fw-bold small text-dark" id="formTitle">

                    <i class="bi bi-rulers text-primary me-1"></i>

                    ADD UNIT

                </span>

            </div>



            <div class="card-body p-3">

                <form method="POST" id="unitForm">

                    <input type="hidden" name="action_unit" value="1">

                    <input type="hidden" name="edit_id" id="edit_id" value="0">



                    <div class="row g-2 mb-3">

                        <div class="col-md-12">

                            <label class="form-label small fw-semibold text-secondary mb-1">

                                UNIT NAME *

                            </label>

                            <input

                                type="text"

                                name="unit_name"

                                id="unit_name"

                                class="form-control form-control-sm text-uppercase"

                                placeholder=""

                                maxlength="100"

                                required

                                autofocus

                            >
                            <div id="unitNameFeedback" class="small mt-1" aria-live="polite"></div>

                        </div>

                    </div>



                    <div class="form-check form-switch mb-3">

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

                            SAVE UNIT

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>



    <div class="col-lg-8">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">

                <span class="fw-bold small text-dark">

                    <i class="bi bi-list-task text-primary me-1"></i>

                    UNITS LIST

                </span>

                <div class="d-flex align-items-center gap-2">

                    <!-- Search Bar -->

                    <div class="input-group input-group-sm" style="width: 200px;">

                        <span class="input-group-text bg-light text-secondary"><i class="bi bi-search"></i></span>

                        <input type="text" id="searchUnit" class="form-control" placeholder="Search unit...">

                    </div>

                    <!-- Record Count -->

                    <span class="badge bg-light text-secondary border" id="recordCount">

                        <?= count($units) ?> RECORDS

                    </span>

                </div>

            </div>



            <div class="card-body p-0">

                <div class="table-responsive" style="max-height:70vh; overflow-y:auto;">

                    <table class="table table-hover align-middle mb-0 small" id="unitsTable">

                        <thead class="table-light sticky-top">

                            <tr>

                                <th class="ps-3" style="width:60px;">#</th>

                                <th>UNIT NAME</th>

                                <th>STATUS</th>

                                <th class="text-end pe-3">ACTION</th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php if (empty($units)): ?>

                            <tr>

                                <td colspan="4" class="text-center py-4 text-muted">

                                    NO UNITS FOUND.

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($units as $u): ?>

                                <?php $is_act = (int)$u['status'] === 1; ?>

                                <tr>

                                    <td class="ps-3 font-monospace">

                                        #<?= (int)$u['id'] ?>

                                    </td>

                                    <td class="fw-bold text-dark text-uppercase">

                                        <?= htmlspecialchars($u['unit_name']) ?>

                                    </td>

                                    <td>

                                        <a

                                            href="?toggle_status=<?= (int)$u['id'] ?>&st=<?= $is_act ? 1 : 0 ?>"

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

                                            onclick='editRow(<?= json_encode(

                                                $u,

                                                JSON_HEX_TAG |

                                                JSON_HEX_APOS |

                                                JSON_HEX_QUOT |

                                                JSON_HEX_AMP

                                            ) ?>)'

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
(function(){
  const payload = window.unitSweetAlertPayload;
  if (!payload || !payload.message) return;
  const backdrop = document.getElementById('unitAlertBackdrop');
  const box = document.getElementById('unitAlertBox');
  const icon = document.getElementById('unitAlertIcon');
  const title = document.getElementById('unitAlertTitle');
  const message = document.getElementById('unitAlertMessage');
  const ok = document.getElementById('unitAlertOk');
  const progress = document.getElementById('unitAlertProgress');
  const isSuccess = payload.type === 'success';
  box.classList.add(isSuccess ? 'success' : 'error');
  icon.textContent = isSuccess ? '✓' : '!';
  title.textContent = isSuccess ? 'Successful!' : 'Something went wrong';
  message.textContent = payload.message;
  backdrop.classList.add('show');
  let timer = null;
  function closeAlert(){ backdrop.classList.remove('show'); if(timer) clearTimeout(timer); }
  ok.addEventListener('click', closeAlert);
  backdrop.addEventListener('click', function(e){ if(e.target === backdrop && isSuccess) closeAlert(); });
  if(isSuccess){
    progress.style.transition = 'transform 5s linear';
    requestAnimationFrame(() => { progress.style.transform = 'scaleX(0)'; });
    timer = setTimeout(closeAlert, 5000);
  } else {
    ok.focus();
  }
})();

const unitInput = document.getElementById('unit_name');
const unitForm = document.getElementById('unitForm');
const unitFeedback = document.getElementById('unitNameFeedback');
const unitSubmit = document.getElementById('btnSubmit');

// Check existing rows as the user types; editing the same row is allowed.
function checkDuplicateUnit() {
    const value = (unitInput.value || '').trim().replace(/\s+/g, ' ').toUpperCase();
    const editingId = parseInt(document.getElementById('edit_id').value || '0', 10);
    let duplicate = false;
    if (value) {
        document.querySelectorAll('#unitsTable tbody tr').forEach(row => {
            if (row.cells.length < 2) return;
            const rowId = parseInt((row.cells[0].textContent || '').replace('#', '').trim(), 10) || 0;
            const existingName = (row.cells[1].textContent || '').trim().replace(/\s+/g, ' ').toUpperCase();
            if (existingName === value && rowId !== editingId) duplicate = true;
        });
    }
    if (duplicate) {
        unitInput.classList.add('is-invalid');
        unitFeedback.textContent = 'This unit already exists. Please enter a different name.';
        unitFeedback.className = 'small mt-1 text-danger';
        unitSubmit.disabled = true;
    } else {
        unitInput.classList.remove('is-invalid');
        unitFeedback.textContent = '';
        unitFeedback.className = 'small mt-1';
        unitSubmit.disabled = false;
    }
    return !duplicate;
}
unitInput.addEventListener('input', checkDuplicateUnit);
unitForm.addEventListener('submit', function(event) {
    if (!checkDuplicateUnit()) {
        event.preventDefault();
        unitInput.focus();
    }
});

function editRow(d) {

    document.getElementById('edit_id').value = d.id || '0';

    document.getElementById('unit_name').value = d.unit_name || '';

    document.getElementById('status').checked = parseInt(d.status) === 1;



    document.getElementById('formTitle').innerHTML =

        '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT UNIT';

    document.getElementById('btnSubmit').innerText = 'UPDATE UNIT';

    document.getElementById('unit_name').focus();

}



function resetForm() {

    document.getElementById('edit_id').value = '0';

    document.getElementById('formTitle').innerHTML =

        '<i class="bi bi-rulers text-primary me-1"></i> ADD UNIT';

    document.getElementById('btnSubmit').innerText = 'SAVE UNIT';

    document.getElementById('unit_name').focus();

}



// Search Filter Logic

document.getElementById('searchUnit').addEventListener('keyup', function() {

    let filter = this.value.toUpperCase();

    let rows = document.querySelectorAll('#unitsTable tbody tr');

    let visibleCount = 0;



    rows.forEach(row => {

        // Skip filtering if it's the "NO UNITS FOUND" empty state row

        if (row.cells.length === 1 && row.cells[0].colSpan === 4) return;



        // Target the second column (UNIT NAME)

        let unitNameCell = row.querySelector('td:nth-child(2)');



        if (unitNameCell) {

            let textValue = unitNameCell.textContent || unitNameCell.innerText;

            if (textValue.toUpperCase().indexOf(filter) > -1) {

                row.style.display = "";

                visibleCount++;

            } else {

                row.style.display = "none";

            }

        }

    });



    // Dynamically update the record count badge

    document.getElementById('recordCount').innerText = visibleCount + ' RECORDS';

});

</script>



<?php require_once __DIR__ . '/layout_footer.php'; ?>
