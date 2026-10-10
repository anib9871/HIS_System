<?php
// admin/master_meals.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Meal Master";

$org_id     = (int)($_SESSION['org_id'] ?? 1);
$center_id  = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

/*
 * DEFAULT MEAL INSTRUCTIONS: seed missing values for each org/center.
 * Existing records and user edits are preserved.
 */
$defaultMeals = [
    ['BEFORE MEAL', 'BM'],
    ['AFTER MEAL', 'AM'],
    ['WITH MEAL', 'WM'],
    ['EMPTY STOMACH', 'ES'],
    ['BEFORE BREAKFAST', 'BBF'],
    ['AFTER BREAKFAST', 'ABF'],
    ['BEFORE LUNCH', 'BL'],
    ['AFTER LUNCH', 'AL'],
    ['BEFORE DINNER', 'BD'],
    ['AFTER DINNER', 'AD'],
    ['WITH BREAKFAST', 'WB'],
    ['WITH LUNCH', 'WL'],
    ['WITH DINNER', 'WD'],
    ['BEFORE FOOD', 'AC'],
    ['AFTER FOOD', 'PC'],
    ['BETWEEN MEALS', 'BTM'],
    ['WITH OR AFTER FOOD', 'WAF'],
    ['ON EMPTY STOMACH', 'OES'],
    ['AT BEDTIME', 'HS'],
    ['AS DIRECTED', 'ADIR'],
];
try {
    $checkMeal = $tenant_pdo->prepare(
        "SELECT id FROM master_meals WHERE org_id = ? AND center_id = ? AND LOWER(TRIM(meal_name)) = LOWER(TRIM(?)) LIMIT 1"
    );
    $insertMeal = $tenant_pdo->prepare(
        "INSERT INTO master_meals (org_id, center_id, meal_name, meal_code, status, created_by) VALUES (?, ?, ?, ?, 1, ?)"
    );
    foreach ($defaultMeals as [$defaultName, $defaultCode]) {
        $checkMeal->execute([$org_id, $center_id, $defaultName]);
        if (!$checkMeal->fetchColumn()) {
            $insertMeal->execute([$org_id, $center_id, $defaultName, $defaultCode, $created_by ?: null]);
        }
    }
} catch (PDOException $e) {
    // Do not interrupt the master page if seeding is unavailable.
}


/*
 * SAVE / UPDATE
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_meal'])) {

    $meal_name = strtoupper(trim($_POST['meal_name'] ?? ''));
    $meal_code = strtoupper(trim($_POST['meal_code'] ?? ''));
    $status    = isset($_POST['status']) ? 1 : 0;
    $edit_id   = (int)($_POST['edit_id'] ?? 0);

    if ($meal_name === '') {
        $mealAlert = ['type' => 'error', 'message' => 'Meal Name is required.'];
    } else {
        try {
            // Case-insensitive duplicate check, excluding the current row during edit.
            $dup = $tenant_pdo->prepare("
                SELECT id FROM master_meals
                WHERE org_id = ? AND center_id = ? AND LOWER(TRIM(meal_name)) = LOWER(TRIM(?))
                  AND id <> ?
                LIMIT 1
            ");
            $dup->execute([$org_id, $center_id, $meal_name, $edit_id]);
            if ($dup->fetchColumn()) {
                $mealAlert = ['type' => 'error', 'message' => 'This meal name already exists.'];
            } else {

            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("
                    UPDATE master_meals
                    SET meal_name = ?, meal_code = ?, status = ?
                    WHERE id = ? AND org_id = ? AND center_id = ?
                ");
                $stmt->execute([
                    $meal_name,
                    $meal_code !== '' ? $meal_code : null,
                    $status,
                    $edit_id,
                    $org_id,
                    $center_id
                ]);
                header('Location: master_meals.php?meal_alert=success&meal_message=' . rawurlencode('Meal updated successfully.'));
                exit;
            } else {
                $stmt = $tenant_pdo->prepare("
                    INSERT INTO master_meals (org_id, center_id, meal_name, meal_code, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $org_id,
                    $center_id,
                    $meal_name,
                    $meal_code !== '' ? $meal_code : null,
                    $status,
                    $created_by ?: null
                ]);
                header('Location: master_meals.php?meal_alert=success&meal_message=' . rawurlencode('Meal added successfully.'));
                exit;
            }

            }
        } catch (PDOException $e) {
            $mealAlert = ['type' => 'error', 'message' => 'Database Error: ' . $e->getMessage()];
        }
    }
}

/*
 * TOGGLE STATUS
 */
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = ((int)($_GET['st'] ?? 1) === 1) ? 0 : 1;

    $tenant_pdo->prepare("
        UPDATE master_meals
        SET status = ?
        WHERE id = ? AND org_id = ? AND center_id = ?
    ")->execute([$st, $id, $org_id, $center_id]);

    header("Location: master_meals.php");
    exit;
}

/*
 * FETCH ALL LIST (Live Search JS se hoga)
 */
$meals = $tenant_pdo->prepare("
    SELECT * FROM master_meals
    WHERE org_id = ? AND center_id = ?
    ORDER BY id DESC
");
$meals->execute([$org_id, $center_id]);
$meals = $meals->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Popup messages are passed through this page's own query parameters, independent of layout_header flash handling.
if (isset($_GET['meal_alert'], $_GET['meal_message']) && in_array($_GET['meal_alert'], ['success', 'error'], true)) {
    $mealAlert = [
        'type' => $_GET['meal_alert'],
        'message' => trim((string)$_GET['meal_message'])
    ];
}

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">

    <!-- FORM -->
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-cup-hot text-primary me-1"></i>
                    ADD MEAL
                </span>
            </div>

            <div class="card-body p-3">
                <form method="POST">
                    <input type="hidden" name="action_meal" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold text-secondary mb-1">MEAL NAME *</label>
                            <input type="text" name="meal_name" id="meal_name" class="form-control form-control-sm text-uppercase" maxlength="100" required autofocus>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">MEAL CODE</label>
                            <input type="text" name="meal_code" id="meal_code" class="form-control form-control-sm text-uppercase" maxlength="30">
                        </div>
                    </div>

                    <div class="small text-muted mb-3">
                        USED FOR PRESCRIPTION / MEDICINE MEAL INSTRUCTION
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">ACTIVE STATUS</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">RESET</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">SAVE MEAL</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- LIST -->
    <div class="col-lg-8">
        <div class="card border shadow-sm">
            
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-bold small text-dark">
                    <i class="bi bi-list-task text-primary me-1"></i>
                    MEALS LIST
                </span>

                <!-- LIVE SEARCH BAR -->
                <div class="d-flex align-items-center gap-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-search text-secondary"></i></span>
                        <input 
                            type="text" 
                            id="liveSearch" 
                            class="form-control form-control-sm" 
                            placeholder="Type to search..." 
                            onkeyup="filterTable()"
                        >
                    </div>
                    <span class="badge bg-light text-secondary border">
                        <span id="rowCount"><?= count($meals) ?></span> RECORDS
                    </span>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:70vh; overflow-y:auto;">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3" style="width:60px;">#</th>
                                <th>MEAL NAME</th>
                                <th>CODE</th>
                                <th>STATUS</th>
                                <th class="text-end pe-3">ACTION</th>
                            </tr>
                        </thead>

                        <tbody id="mealsTableBody">
                        <?php if (empty($meals)): ?>
                            <tr id="noDataRow">
                                <td colspan="5" class="text-center py-4 text-muted">NO MEALS FOUND.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($meals as $m): ?>
                                <?php $is_act = (int)$m['status'] === 1; ?>
                                <tr class="meal-row">
                                    <td class="ps-3 font-monospace">#<?= (int)$m['id'] ?></td>
                                    <td class="fw-bold text-dark text-uppercase meal-name">
                                        <?= htmlspecialchars($m['meal_name']) ?>
                                    </td>
                                    <td class="font-monospace text-secondary meal-code">
                                        <?= htmlspecialchars($m['meal_code'] ?? '') ?: '-' ?>
                                    </td>
                                    <td>
                                        <a href="?toggle_status=<?= (int)$m['id'] ?>&st=<?= $is_act ? 1 : 0 ?>"
                                           class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>"
                                        >
                                            <?= $is_act ? 'ACTIVE' : 'INACTIVE' ?>
                                        </a>
                                    </td>
                                    <td class="text-end pe-3">
                                        <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2"
                                            onclick='editRow(<?= json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="EDIT">
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
// --- LIVE SEARCH FUNCTION ---
function filterTable() {
    let input = document.getElementById("liveSearch").value.toUpperCase();
    let rows = document.querySelectorAll(".meal-row");
    let visibleCount = 0;

    rows.forEach(row => {
        let name = row.querySelector(".meal-name").textContent.toUpperCase();
        let code = row.querySelector(".meal-code").textContent.toUpperCase();
        
        if (name.includes(input) || code.includes(input)) {
            row.style.display = "";
            visibleCount++;
        } else {
            row.style.display = "none";
        }
    });

    // Update Record Count
    document.getElementById("rowCount").textContent = visibleCount;
}

// --- FORM FUNCTIONS ---
function editRow(d) {
    document.getElementById('edit_id').value = d.id || '0';
    document.getElementById('meal_name').value = d.meal_name || '';
    document.getElementById('meal_code').value = d.meal_code || '';
    document.getElementById('status').checked = parseInt(d.status) === 1;

    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT MEAL';
    document.getElementById('btnSubmit').innerText = 'UPDATE MEAL';
    document.getElementById('meal_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-cup-hot text-primary me-1"></i> ADD MEAL';
    document.getElementById('btnSubmit').innerText = 'SAVE MEAL';
    document.getElementById('status').checked = true;
    document.getElementById('meal_name').focus();
    const btn = document.getElementById('btnSubmit');
    btn.disabled = false;
    document.getElementById('meal_name').dispatchEvent(new Event('input'));
}

// Live duplicate check
const mealNameInput = document.getElementById('meal_name');
const mealSaveButton = document.getElementById('btnSubmit');
let mealDuplicateHint = document.getElementById('mealDuplicateHint');
if (!mealDuplicateHint) {
    mealDuplicateHint = document.createElement('div');
    mealDuplicateHint.id = 'mealDuplicateHint';
    mealDuplicateHint.className = 'small mt-1';
    mealNameInput.parentNode.appendChild(mealDuplicateHint);
}
const existingMeals = <?= json_encode(array_map(fn($m) => ['id'=>(int)$m['id'], 'name'=>mb_strtolower(preg_replace('/\\s+/', ' ', trim($m['meal_name'])))], $meals), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
mealNameInput.addEventListener('input', function () {
    const value = this.value.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
    const editId = parseInt(document.getElementById('edit_id').value || '0', 10);
    const duplicate = value !== '' && existingMeals.some(m => String(m.name).trim().replace(/\s+/g, ' ').toLocaleLowerCase() === value && m.id !== editId);
    mealDuplicateHint.textContent = duplicate ? 'This meal name already exists.' : '';
    mealDuplicateHint.className = 'small mt-1 ' + (duplicate ? 'text-danger' : 'text-success');
    mealSaveButton.disabled = duplicate;
});
mealNameInput.dispatchEvent(new Event('input')); 
</script>


<style>
.meal-alert-overlay{position:fixed;inset:0;background:rgba(15,23,42,.35);display:flex;align-items:center;justify-content:center;z-index:99999;padding:16px}
.meal-alert-box{background:#fff;border-radius:16px;width:min(360px,92vw);padding:22px 24px 18px;text-align:center;box-shadow:0 18px 55px rgba(15,23,42,.25);animation:mealAlertIn .18s ease-out}
.meal-alert-icon{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:30px;font-weight:700}
.meal-alert-success .meal-alert-icon{color:#16a34a;border:3px solid #86efac;background:#f0fdf4}
.meal-alert-error .meal-alert-icon{color:#dc2626;border:3px solid #fca5a5;background:#fef2f2}
.meal-alert-title{font-size:19px;font-weight:700;color:#1e293b;margin-bottom:7px}
.meal-alert-message{font-size:13px;color:#64748b;margin-bottom:15px;overflow-wrap:anywhere}
.meal-alert-ok{border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:600;padding:9px 28px;cursor:pointer}
.meal-alert-progress{height:3px;background:#e2e8f0;margin-top:16px;border-radius:5px;overflow:hidden}
.meal-alert-progress span{display:block;height:100%;background:#16a34a;width:100%;transform-origin:left}
@keyframes mealAlertIn{from{opacity:0;transform:translateY(6px) scale(.97)}to{opacity:1;transform:none}}
</style>
<script>
function showMealAlert(type, message) {
    const overlay = document.createElement('div');
    overlay.className = 'meal-alert-overlay';
    const success = type === 'success';
    overlay.innerHTML = `<div class="meal-alert-box ${success ? 'meal-alert-success' : 'meal-alert-error'}" role="alertdialog" aria-modal="true">
        <div class="meal-alert-icon">${success ? '✓' : '!'}</div>
        <div class="meal-alert-title">${success ? 'Successful!' : 'Something went wrong'}</div>
        <div class="meal-alert-message"></div>
        ${success ? '<div class="meal-alert-progress"><span></span></div>' : '<button type="button" class="meal-alert-ok">OK</button>'}
    </div>`;
    overlay.querySelector('.meal-alert-message').textContent = message;
    document.body.appendChild(overlay);
    if (success) {
        const bar = overlay.querySelector('.meal-alert-progress span');
        bar.style.transition = 'transform 5s linear';
        requestAnimationFrame(() => { bar.style.transform = 'scaleX(0)'; });
        setTimeout(() => overlay.remove(), 5000);
    } else {
        overlay.querySelector('.meal-alert-ok').addEventListener('click', () => overlay.remove());
    }
}
</script>
<?php if (!empty($mealAlert)): ?>
<script>
document.addEventListener('DOMContentLoaded', () => showMealAlert(
    <?= json_encode($mealAlert['type']) ?>,
    <?= json_encode($mealAlert['message'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
));
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
