<?php
// admin/master_units.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Unit Master";

$org_id    = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_unit'])) {

    $unit_name = strtoupper(trim($_POST['unit_name'] ?? ''));
    $status    = isset($_POST['status']) ? 1 : 0;
    $edit_id   = (int)($_POST['edit_id'] ?? 0);

    if ($unit_name === '') {
        set_flash_err("Unit Name is required.");
    } else {
        try {
            if ($edit_id > 0) {
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

                set_flash_msg("Unit updated successfully.");
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

                set_flash_msg("Unit added successfully.");
            }

            header("Location: master_units.php");
            exit;

        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
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
?>

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
                <form method="POST">
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
