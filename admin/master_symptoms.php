<?php
// admin/master_symptoms.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Symptom Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_symptom'])) {

    $symptom_name = strtoupper(trim($_POST['symptom_name'] ?? ''));
    $symptom_code = strtoupper(trim($_POST['symptom_code'] ?? ''));
    $description  = trim($_POST['description'] ?? '');
    $status       = isset($_POST['status']) ? 1 : 0;
    $edit_id      = (int)($_POST['edit_id'] ?? 0);

    if ($symptom_name === '') {
        set_flash_err("Symptom Name is required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("
                    UPDATE master_symptoms
                    SET symptom_name = ?,
                        symptom_code = ?,
                        description = ?,
                        status = ?
                    WHERE id = ? AND org_id = ? AND center_id = ?
                ");

                $stmt->execute([
                    $symptom_name,
                    $symptom_code !== '' ? $symptom_code : null,
                    $description !== '' ? $description : null,
                    $status,
                    $edit_id,
                    $org_id,
                    $center_id
                ]);

                set_flash_msg("Symptom updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("
                    INSERT INTO master_symptoms
                    (
                        org_id,
                        center_id,
                        symptom_name,
                        symptom_code,
                        description,
                        status,
                        created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $org_id,
                    $center_id,
                    $symptom_name,
                    $symptom_code !== '' ? $symptom_code : null,
                    $description !== '' ? $description : null,
                    $status,
                    $created_by ?: null
                ]);

                set_flash_msg("Symptom added successfully.");
            }

            header("Location: master_symptoms.php");
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
        UPDATE master_symptoms
        SET status = ?
        WHERE id = ? AND org_id = ? AND center_id = ?
    ")->execute([$st, $id, $org_id, $center_id]);

    header("Location: master_symptoms.php");
    exit;
}

// List
$symptoms = $tenant_pdo->prepare("
    SELECT *
    FROM master_symptoms
    WHERE org_id = ? AND center_id = ?
    ORDER BY id DESC
");
$symptoms->execute([$org_id, $center_id]);
$symptoms = $symptoms->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-2">
    <!-- Form Section -->
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-2 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-activity text-primary me-1"></i> ADD SYMPTOM
                </span>
            </div>

            <div class="card-body p-2">
                <form method="POST" action="">
                    <input type="hidden" name="action_symptom" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="row g-2 mb-2">
                        <div class="col-8">
                            <label class="form-label small fw-semibold text-secondary mb-1">SYMPTOM NAME *</label>
                            <input type="text" name="symptom_name" id="symptom_name" class="form-control form-control-sm text-uppercase" maxlength="200" required autofocus>
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">CODE</label>
                            <input type="text" name="symptom_code" id="symptom_code" class="form-control form-control-sm font-monospace text-uppercase" maxlength="50">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">DESCRIPTION</label>
                        <textarea name="description" id="description" class="form-control form-control-sm" rows="2" maxlength="255"></textarea>
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">ACTIVE STATUS</label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">RESET</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">SAVE</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- List Section -->
    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-2 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark text-nowrap">
                    <i class="bi bi-list-task text-primary me-1"></i> SYMPTOMS LIST
                </span>
                
                <div class="d-flex align-items-center gap-2 w-50">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="searchInput" class="form-control border-start-0" placeholder="Search symptom, code...">
                    </div>
                    <span class="badge bg-light text-secondary border text-nowrap" id="recordCount">
                        <?= count($symptoms) ?> RECORDS
                    </span>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 75vh; overflow-y: auto;">
                    <table class="table table-sm table-hover align-middle mb-0" id="symptomsTable" style="font-size: 0.85rem;">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-2" style="width: 50px;">#</th>
                                <th>SYMPTOM</th>
                                <th>CODE</th>
                                <th>DESCRIPTION</th>
                                <th>STATUS</th>
                                <th class="text-end pe-2">ACTION</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($symptoms)): ?>
                                <tr id="noDataRow">
                                    <td colspan="6" class="text-center py-3 text-muted">NO SYMPTOMS FOUND.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($symptoms as $s): ?>
                                    <?php $is_act = (int)$s['status'] === 1; ?>
                                    <tr class="searchable-row">
                                        <td class="ps-2 font-monospace text-muted">#<?= (int)$s['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($s['symptom_name']) ?></td>
                                        <td>
                                            <?php if (!empty($s['symptom_code'])): ?>
                                                <span class="badge bg-light text-primary border font-monospace text-uppercase"><?= htmlspecialchars($s['symptom_code']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-secondary text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($s['description'] ?? '') ?>">
                                            <?= htmlspecialchars($s['description'] ?? '') ?: '-' ?>
                                        </td>
                                        <td>
                                            <a href="?toggle_status=<?= (int)$s['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" 
                                               class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'ACTIVE' : 'INACTIVE' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-2">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-1" 
                                                    onclick='editRow(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' 
                                                    title="EDIT">
                                                <i class="bi bi-pencil" style="font-size: 0.8rem;"></i>
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
// Search Functionality
document.getElementById('searchInput')?.addEventListener('input', function() {
    const filter = this.value.toUpperCase();
    const rows = document.querySelectorAll('.searchable-row');
    let visibleCount = 0;

    rows.forEach(row => {
        // Concatenate text content of Name, Code, and Description columns
        const textToSearch = (row.cells[1].textContent + ' ' + row.cells[2].textContent + ' ' + row.cells[3].textContent).toUpperCase();
        
        if (textToSearch.indexOf(filter) > -1) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('recordCount').innerText = visibleCount + ' RECORDS';
});

// Edit & Reset Logic
function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('symptom_name').value = d.symptom_name || '';
    document.getElementById('symptom_code').value = d.symptom_code || '';
    document.getElementById('description').value = d.description || '';
    document.getElementById('status').checked = (parseInt(d.status) === 1);

    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT SYMPTOM';
    document.getElementById('btnSubmit').innerText = 'UPDATE';
    document.getElementById('symptom_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-activity text-primary me-1"></i> ADD SYMPTOM';
    document.getElementById('btnSubmit').innerText = 'SAVE';
    document.getElementById('symptom_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
