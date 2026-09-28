<?php
// admin/master_medicines.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Medicine Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_medicine'])) {

    $medicine_name = strtoupper(trim($_POST['medicine_name'] ?? ''));
    $generic_name  = strtoupper(trim($_POST['generic_name'] ?? ''));
    $brand_name    = strtoupper(trim($_POST['brand_name'] ?? ''));
    $strength      = strtoupper(trim($_POST['strength'] ?? ''));
    $dosage_form   = strtoupper(trim($_POST['dosage_form'] ?? ''));
    $manufacturer  = strtoupper(trim($_POST['manufacturer'] ?? ''));
    $category      = strtoupper(trim($_POST['category'] ?? ''));
    $status        = isset($_POST['status']) ? 1 : 0;
    $edit_id       = (int)($_POST['edit_id'] ?? 0);

    if ($medicine_name === '') {
        set_flash_err("Medicine Name is required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("
                    UPDATE master_medicines
                    SET medicine_name = ?,
                        generic_name = ?,
                        brand_name = ?,
                        strength = ?,
                        dosage_form = ?,
                        manufacturer = ?,
                        category = ?,
                        status = ?
                    WHERE id = ? AND org_id = ? AND center_id = ?
                ");

                $stmt->execute([
                    $medicine_name,
                    $generic_name !== '' ? $generic_name : null,
                    $brand_name !== '' ? $brand_name : null,
                    $strength !== '' ? $strength : null,
                    $dosage_form !== '' ? $dosage_form : null,
                    $manufacturer !== '' ? $manufacturer : null,
                    $category !== '' ? $category : null,
                    $status,
                    $edit_id,
                    $org_id,
                    $center_id
                ]);

                set_flash_msg("Medicine updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("
                    INSERT INTO master_medicines
                    (
                        org_id,
                        center_id,
                        medicine_name,
                        generic_name,
                        brand_name,
                        strength,
                        dosage_form,
                        manufacturer,
                        category,
                        status,
                        created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $org_id,
                    $center_id,
                    $medicine_name,
                    $generic_name !== '' ? $generic_name : null,
                    $brand_name !== '' ? $brand_name : null,
                    $strength !== '' ? $strength : null,
                    $dosage_form !== '' ? $dosage_form : null,
                    $manufacturer !== '' ? $manufacturer : null,
                    $category !== '' ? $category : null,
                    $status,
                    $created_by ?: null
                ]);

                set_flash_msg("Medicine added successfully.");
            }

            header("Location: master_medicines.php");
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
        UPDATE master_medicines
        SET status = ?
        WHERE id = ? AND org_id = ? AND center_id = ?
    ")->execute([$st, $id, $org_id, $center_id]);

    header("Location: master_medicines.php");
    exit;
}

// List
$medicines = $tenant_pdo->prepare("
    SELECT *
    FROM master_medicines
    WHERE org_id = ? AND center_id = ?
    ORDER BY id DESC
");
$medicines->execute([$org_id, $center_id]);
$medicines = $medicines->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-capsule text-primary me-1"></i> ADD MEDICINE
                </span>
            </div>

            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_medicine" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold text-secondary mb-1">MEDICINE NAME *</label>
                            <input
                                type="text"
                                name="medicine_name"
                                id="medicine_name"
                                class="form-control form-control-sm text-uppercase"
                                placeholder="E.G. AZITHROMYCIN"
                                maxlength="200"
                                required
                                autofocus
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">STRENGTH</label>
                            <input
                                type="text"
                                name="strength"
                                id="strength"
                                class="form-control form-control-sm text-uppercase"
                                placeholder="E.G. 500 MG"
                                maxlength="100"
                            >
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">GENERIC / SALT NAME</label>
                            <input
                                type="text"
                                name="generic_name"
                                id="generic_name"
                                class="form-control form-control-sm text-uppercase"
                                placeholder="E.G. AZITHROMYCIN"
                                maxlength="200"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">BRAND NAME</label>
                            <input
                                type="text"
                                name="brand_name"
                                id="brand_name"
                                class="form-control form-control-sm text-uppercase"
                                placeholder="E.G. AZITHRAL"
                                maxlength="200"
                            >
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">DOSAGE FORM</label>
                            <input
                                type="text"
                                name="dosage_form"
                                id="dosage_form"
                                class="form-control form-control-sm text-uppercase"
                                placeholder="TABLET / CAPSULE / SYRUP"
                                maxlength="100"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">CATEGORY</label>
                            <input
                                type="text"
                                name="category"
                                id="category"
                                class="form-control form-control-sm text-uppercase"
                                placeholder="E.G. ANTIBIOTIC"
                                maxlength="100"
                            >
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold text-secondary mb-1">MANUFACTURER</label>
                            <input
                                type="text"
                                name="manufacturer"
                                id="manufacturer"
                                class="form-control form-control-sm text-uppercase"
                                placeholder="E.G. SUN PHARMA"
                                maxlength="200"
                            >
                        </div>

                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check form-switch mb-1">
                                <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                                <label class="form-check-label small fw-semibold text-secondary" for="status">
                                    ACTIVE STATUS
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">
                            RESET
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">
                            SAVE MEDICINE
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
                    <i class="bi bi-list-task text-primary me-1"></i> MEDICINES LIST
                </span>

                <span class="badge bg-light text-secondary border">
                    <?= count($medicines) ?> RECORDS
                </span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3" style="width: 55px;">#</th>
                                <th>MEDICINE</th>
                                <th>GENERIC / SALT</th>
                                <th>STRENGTH</th>
                                <th>FORM</th>
                                <th>STATUS</th>
                                <th class="text-end pe-3">ACTION</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($medicines)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        NO MEDICINES FOUND.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($medicines as $m): ?>
                                    <?php $is_act = (int)$m['status'] === 1; ?>

                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= (int)$m['id'] ?></td>

                                        <td>
                                            <div class="fw-bold text-dark text-uppercase">
                                                <?= htmlspecialchars($m['medicine_name']) ?>
                                            </div>

                                            <?php if (!empty($m['brand_name'])): ?>
                                                <div class="text-muted" style="font-size: 10px;">
                                                    <?= htmlspecialchars($m['brand_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-uppercase">
                                            <?= htmlspecialchars($m['generic_name'] ?? '') ?: '-' ?>
                                        </td>

                                        <td class="font-monospace">
                                            <?= htmlspecialchars($m['strength'] ?? '') ?: '-' ?>
                                        </td>

                                        <td class="text-uppercase">
                                            <?= htmlspecialchars($m['dosage_form'] ?? '') ?: '-' ?>
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
                                                class="btn btn-outline-primary btn-sm py-0 px-2"
                                                onclick='editRow(<?= json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
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
    document.getElementById('medicine_name').value = d.medicine_name || '';
    document.getElementById('generic_name').value = d.generic_name || '';
    document.getElementById('brand_name').value = d.brand_name || '';
    document.getElementById('strength').value = d.strength || '';
    document.getElementById('dosage_form').value = d.dosage_form || '';
    document.getElementById('manufacturer').value = d.manufacturer || '';
    document.getElementById('category').value = d.category || '';
    document.getElementById('status').checked = (parseInt(d.status) === 1);

    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT MEDICINE';

    document.getElementById('btnSubmit').innerText = 'UPDATE MEDICINE';
    document.getElementById('medicine_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';

    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-capsule text-primary me-1"></i> ADD MEDICINE';

    document.getElementById('btnSubmit').innerText = 'SAVE MEDICINE';
    document.getElementById('medicine_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
