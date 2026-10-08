<?php
// admin/master_diagnoses.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Diagnosis Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_diagnosis'])) {

    $diagnosis_name = strtoupper(trim($_POST['diagnosis_name'] ?? ''));
    $diagnosis_code = strtoupper(trim($_POST['diagnosis_code'] ?? ''));
    $description    = trim($_POST['description'] ?? '');
    $status         = isset($_POST['status']) ? 1 : 0;
    $edit_id        = (int)($_POST['edit_id'] ?? 0);

    if ($diagnosis_name === '') {
        set_flash_err("Diagnosis Name is required.");
    } else {
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

                set_flash_msg("Diagnosis updated successfully.");
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

                set_flash_msg("Diagnosis added successfully.");
            }

            header("Location: master_diagnoses.php");
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
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

    <div class="col-lg-7">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark">
                    <i class="bi bi-list-task text-primary me-1"></i> DIAGNOSES LIST
                </span>

                <span class="badge bg-light text-secondary border">
                    <?= count($diagnoses) ?> RECORDS
                </span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 small">
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

                        <tbody>
                            <?php if (empty($diagnoses)): ?>
                                <tr>
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

function resetForm() {
    document.getElementById('edit_id').value = '0';

    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-clipboard2-pulse text-primary me-1"></i> ADD DIAGNOSIS';

    document.getElementById('btnSubmit').innerText = 'SAVE DIAGNOSIS';
    document.getElementById('diagnosis_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
