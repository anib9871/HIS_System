<?php
// admin/master_durations.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Duration Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_duration'])) {

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
        set_flash_err("Duration Name is required.");
    } else {
        try {
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

                set_flash_msg("Duration updated successfully.");
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

                set_flash_msg("Duration added successfully.");
            }

            header("Location: master_durations.php");
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
        UPDATE master_durations
        SET status = ?
        WHERE id = ? AND org_id = ? AND center_id = ?
    ")->execute([$st, $id, $org_id, $center_id]);

    header("Location: master_durations.php");
    exit;
}

// List
$durations = $tenant_pdo->prepare("
    SELECT *
    FROM master_durations
    WHERE org_id = ? AND center_id = ?
    ORDER BY id DESC
");
$durations->execute([$org_id, $center_id]);
$durations = $durations->fetchAll() ?: [];

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
                        <label class="form-label small fw-semibold text-secondary mb-1">DURATION NAME *</label>
                        <input
                            type="text"
                            name="duration_name"
                            id="duration_name"
                            class="form-control form-control-sm text-uppercase"
                            placeholder="E.G. 5 DAYS"
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
                                placeholder="E.G. 5"
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
                                placeholder="DAYS / WEEKS / MONTHS"
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
                            placeholder="E.G. CONTINUE MEDICINE FOR 5 DAYS"
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
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark">
                    <i class="bi bi-list-task text-primary me-1"></i> DURATIONS LIST
                </span>

                <span class="badge bg-light text-secondary border">
                    <?= count($durations) ?> RECORDS
                </span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 small">
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

                        <tbody>
                            <?php if (empty($durations)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        NO DURATIONS FOUND.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($durations as $d): ?>
                                    <?php $is_act = (int)$d['status'] === 1; ?>

                                    <tr>
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

<?php require_once __DIR__ . '/layout_footer.php'; ?>
