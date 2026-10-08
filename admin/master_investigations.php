<?php
// admin/master_investigations.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Investigation Master";

$org_id     = (int)($_SESSION['org_id'] ?? 1);
$center_id  = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

// SAVE / UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_investigation'])) {

    $investigation_name = strtoupper(trim($_POST['investigation_name'] ?? ''));
    $investigation_code = strtoupper(trim($_POST['investigation_code'] ?? ''));
    $investigation_type = strtoupper(trim($_POST['investigation_type'] ?? ''));
    $description        = strtoupper(trim($_POST['description'] ?? ''));
    $status             = isset($_POST['status']) ? 1 : 0;
    $edit_id            = (int)($_POST['edit_id'] ?? 0);

    if ($investigation_name === '') {
        set_flash_err("Investigation Name is required.");
    } else {
        try {
            if ($edit_id > 0) {

                $stmt = $tenant_pdo->prepare("
                    UPDATE master_investigations
                    SET investigation_name = ?,
                        investigation_code = ?,
                        investigation_type = ?,
                        description = ?,
                        status = ?
                    WHERE id = ?
                      AND org_id = ?
                      AND center_id = ?
                ");

                $stmt->execute([
                    $investigation_name,
                    $investigation_code !== '' ? $investigation_code : null,
                    $investigation_type !== '' ? $investigation_type : null,
                    $description !== '' ? $description : null,
                    $status,
                    $edit_id,
                    $org_id,
                    $center_id
                ]);

                set_flash_msg("Investigation updated successfully.");

            } else {

                $stmt = $tenant_pdo->prepare("
                    INSERT INTO master_investigations
                    (
                        org_id,
                        center_id,
                        investigation_name,
                        investigation_code,
                        investigation_type,
                        description,
                        status,
                        created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $org_id,
                    $center_id,
                    $investigation_name,
                    $investigation_code !== '' ? $investigation_code : null,
                    $investigation_type !== '' ? $investigation_type : null,
                    $description !== '' ? $description : null,
                    $status,
                    $created_by ?: null
                ]);

                set_flash_msg("Investigation added successfully.");
            }

            header("Location: master_investigations.php");
            exit;

        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
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

// LIST
$stmt = $tenant_pdo->prepare("
    SELECT *
    FROM master_investigations
    WHERE org_id = ?
      AND center_id = ?
    ORDER BY id DESC
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

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                INVESTIGATION TYPE
                            </label>
                            <input
                                type="text"
                                name="investigation_type"
                                id="investigation_type"
                                class="form-control form-control-sm text-uppercase"
                                maxlength="100"
                            >
                        </div>

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

                <span class="badge bg-light text-secondary border">
                    <?= count($investigations) ?> RECORDS
                </span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:70vh; overflow-y:auto;">
                    <table class="table table-hover align-middle mb-0 small">

                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3" style="width:60px;">#</th>
                                <th>INVESTIGATION</th>
                                <th>CODE</th>
                                <th>TYPE</th>
                                <th>STATUS</th>
                                <th class="text-end pe-3">ACTION</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (empty($investigations)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    NO INVESTIGATIONS FOUND.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($investigations as $row): ?>
                                <?php $is_act = (int)$row['status'] === 1; ?>
                                <tr>

                                    <td class="ps-3 font-monospace">
                                        #<?= (int)$row['id'] ?>
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

                                    <td class="text-uppercase">
                                        <?= htmlspecialchars($row['investigation_type'] ?? '') ?: '-' ?>
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

<script>
function editRow(d) {
    document.getElementById('edit_id').value = d.id || '0';
    document.getElementById('investigation_name').value = d.investigation_name || '';
    document.getElementById('investigation_code').value = d.investigation_code || '';
    document.getElementById('investigation_type').value = d.investigation_type || '';
    document.getElementById('description').value = d.description || '';
    document.getElementById('status').checked = parseInt(d.status) === 1;

    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT INVESTIGATION';

    document.getElementById('btnSubmit').innerText = 'UPDATE INVESTIGATION';
    document.getElementById('investigation_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-clipboard2-data text-primary me-1"></i> ADD INVESTIGATION';
    document.getElementById('btnSubmit').innerText = 'SAVE INVESTIGATION';
    document.getElementById('status').checked = true;
    document.getElementById('investigation_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
