<?php
// admin/master_meals.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Meal Master";

$org_id     = (int)($_SESSION['org_id'] ?? 1);
$center_id  = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

/*
 * SAVE / UPDATE
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_meal'])) {

    $meal_name = strtoupper(trim($_POST['meal_name'] ?? ''));
    $meal_code = strtoupper(trim($_POST['meal_code'] ?? ''));
    $status    = isset($_POST['status']) ? 1 : 0;
    $edit_id   = (int)($_POST['edit_id'] ?? 0);

    if ($meal_name === '') {
        set_flash_err("Meal Name is required.");
    } else {
        try {

            if ($edit_id > 0) {

                $stmt = $tenant_pdo->prepare("
                    UPDATE master_meals
                    SET meal_name = ?,
                        meal_code = ?,
                        status = ?
                    WHERE id = ?
                      AND org_id = ?
                      AND center_id = ?
                ");

                $stmt->execute([
                    $meal_name,
                    $meal_code !== '' ? $meal_code : null,
                    $status,
                    $edit_id,
                    $org_id,
                    $center_id
                ]);

                set_flash_msg("Meal updated successfully.");

            } else {

                $stmt = $tenant_pdo->prepare("
                    INSERT INTO master_meals
                    (
                        org_id,
                        center_id,
                        meal_name,
                        meal_code,
                        status,
                        created_by
                    )
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

                set_flash_msg("Meal added successfully.");
            }

            header("Location: master_meals.php");
            exit;

        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
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
        WHERE id = ?
          AND org_id = ?
          AND center_id = ?
    ")->execute([
        $st,
        $id,
        $org_id,
        $center_id
    ]);

    header("Location: master_meals.php");
    exit;
}

/*
 * LIST
 */
$meals = $tenant_pdo->prepare("
    SELECT *
    FROM master_meals
    WHERE org_id = ?
      AND center_id = ?
    ORDER BY id DESC
");

$meals->execute([$org_id, $center_id]);
$meals = $meals->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                MEAL NAME *
                            </label>

                            <input
                                type="text"
                                name="meal_name"
                                id="meal_name"
                                class="form-control form-control-sm text-uppercase"
                                placeholder="
                                "
                                maxlength="100"
                                required
                                autofocus
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                MEAL CODE
                            </label>

                            <input
                                type="text"
                                name="meal_code"
                                id="meal_code"
                                class="form-control form-control-sm text-uppercase"
                                placeholder=""
                                maxlength="30"
                            >
                        </div>

                    </div>

                    <div class="small text-muted mb-3">
                        USED FOR PRESCRIPTION / MEDICINE MEAL INSTRUCTION
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
                            SAVE MEAL
                        </button>

                    </div>

                </form>

            </div>
        </div>
    </div>


    <!-- LIST -->
    <div class="col-lg-8">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">

                <span class="fw-bold small text-dark">
                    <i class="bi bi-list-task text-primary me-1"></i>
                    MEALS LIST
                </span>

                <span class="badge bg-light text-secondary border">
                    <?= count($meals) ?> RECORDS
                </span>

            </div>

            <div class="card-body p-0">

                <div
                    class="table-responsive"
                    style="max-height:70vh; overflow-y:auto;"
                >

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

                        <tbody>

                        <?php if (empty($meals)): ?>

                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">
                                    NO MEALS FOUND.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($meals as $m): ?>

                                <?php $is_act = (int)$m['status'] === 1; ?>

                                <tr>

                                    <td class="ps-3 font-monospace">
                                        #<?= (int)$m['id'] ?>
                                    </td>

                                    <td class="fw-bold text-dark text-uppercase">
                                        <?= htmlspecialchars($m['meal_name']) ?>
                                    </td>

                                    <td class="font-monospace text-secondary">
                                        <?= htmlspecialchars($m['meal_code'] ?? '') ?: '-' ?>
                                    </td>

                                    <td>
                                        <a
                                            href="?toggle_status=<?= (int)$m['id'] ?>&st=<?= $is_act ? 1 : 0 ?>"
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
                                                $m,
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
function editRow(d) {
    document.getElementById('edit_id').value = d.id || '0';
    document.getElementById('meal_name').value = d.meal_name || '';
    document.getElementById('meal_code').value = d.meal_code || '';
    document.getElementById('status').checked = parseInt(d.status) === 1;

    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT MEAL';

    document.getElementById('btnSubmit').innerText = 'UPDATE MEAL';
    document.getElementById('meal_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-cup-hot text-primary me-1"></i> ADD MEAL';
    document.getElementById('btnSubmit').innerText = 'SAVE MEAL';
    document.getElementById('status').checked = true;
    document.getElementById('meal_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
