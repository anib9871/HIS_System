<?php
// admin/frequency_master.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Frequency Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_frequency'])) {
    $frequency_name = strtoupper(trim($_POST['frequency_name'] ?? ''));
    $frequency_code = strtoupper(trim($_POST['frequency_code'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    $status = isset($_POST['status']) ? 1 : 0;
    $edit_id = (int)($_POST['edit_id'] ?? 0);

    if ($frequency_name === '') {
        set_flash_err("Frequency Name is required.");
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
                set_flash_msg("Frequency updated successfully.");
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
                set_flash_msg("Frequency added successfully.");
            }

            header("Location: frequency_master.php");
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
                            <label class="form-label small fw-semibold text-secondary mb-1">FREQUENCY NAME \*</label>
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

<?php require_once __DIR__ . '/layout_footer.php'; ?>
