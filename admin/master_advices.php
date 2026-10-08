<?php
// admin/master_advices.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Advice Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

try {
    $tenant_pdo->exec("
        CREATE TABLE IF NOT EXISTS master_advices (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id INT NOT NULL,
            center_id INT NOT NULL,
            advice_name VARCHAR(255) NOT NULL,
            description VARCHAR(500) NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ma_org_center_name (org_id, center_id, advice_name),
            KEY idx_ma_org_center (org_id, center_id),
            KEY idx_ma_name (advice_name),
            KEY idx_ma_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    set_flash_err("Unable to prepare Advice Master: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_advice'])) {
    $advice_name = strtoupper(trim((string)($_POST['advice_name'] ?? '')));
    $description = trim((string)($_POST['description'] ?? ''));
    $status = isset($_POST['status']) ? 1 : 0;
    $edit_id = (int)($_POST['edit_id'] ?? 0);

    if ($advice_name === '') {
        set_flash_err("Advice Name is required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("
                    UPDATE master_advices
                    SET advice_name = ?, description = ?, status = ?
                    WHERE id = ? AND org_id = ? AND center_id = ?
                ");
                $stmt->execute([
                    $advice_name,
                    $description !== '' ? $description : null,
                    $status,
                    $edit_id,
                    $org_id,
                    $center_id
                ]);
                set_flash_msg("Advice updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("
                    INSERT INTO master_advices
                    (org_id, center_id, advice_name, description, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $org_id,
                    $center_id,
                    $advice_name,
                    $description !== '' ? $description : null,
                    $status,
                    $created_by ?: null
                ]);
                set_flash_msg("Advice added successfully.");
            }

            header("Location: master_advices.php");
            exit;
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                set_flash_err("This advice already exists in the current center.");
            } else {
                set_flash_err("Database Error: " . $e->getMessage());
            }
        }
    }
}

if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $current_status = (int)($_GET['st'] ?? 1);
    $new_status = $current_status === 1 ? 0 : 1;

    $stmt = $tenant_pdo->prepare("
        UPDATE master_advices
        SET status = ?
        WHERE id = ? AND org_id = ? AND center_id = ?
    ");
    $stmt->execute([$new_status, $id, $org_id, $center_id]);

    header("Location: master_advices.php");
    exit;
}

$stmt = $tenant_pdo->prepare("
    SELECT *
    FROM master_advices
    WHERE org_id = ? AND center_id = ?
    ORDER BY id DESC
");
$stmt->execute([$org_id, $center_id]);
$advices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-lightbulb text-primary me-1"></i> ADD ADVICE
                </span>
            </div>

            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_advice" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">ADVICE *</label>
                        <textarea
                            name="advice_name"
                            id="advice_name"
                            class="form-control form-control-sm text-uppercase"
                            rows="4"
                            maxlength="255"
                            placeholder="E.G. TAKE PLENTY OF FLUIDS AND MAINTAIN A LOW-SALT DIET"
                            required
                            autofocus
                        ></textarea>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold text-secondary mb-1">DESCRIPTION</label>
                            <input
                                type="text"
                                name="description"
                                id="description"
                                class="form-control form-control-sm"
                                maxlength="500"
                                placeholder="SHORT INTERNAL DESCRIPTION"
                            >
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" value="1" checked>
                        <label class="form-check-label small fw-semibold" for="status">ACTIVE STATUS</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm px-3">
                            <i class="bi bi-check2-circle me-1"></i> SAVE
                        </button>
                        <button type="button" class="btn btn-light border btn-sm px-3" onclick="resetAdviceForm()">
                            RESET
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold small text-dark">
                        <i class="bi bi-list-ul text-primary me-1"></i> ADVICE LIST
                    </span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                        <?= count($advices) ?>
                    </span>
                </div>
            </div>

            <div class="card-body p-2">
                <div class="mb-2">
                    <input type="text" id="adviceSearch" class="form-control form-control-sm" placeholder="SEARCH ADVICE..." autocomplete="off">
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:55px;">#</th>
                                <th>ADVICE</th>
                                <th style="width:85px;">STATUS</th>
                                <th style="width:110px;">ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="adviceTableBody">
                            <?php if (!$advices): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted small py-4">NO ADVICE FOUND.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($advices as $index => $advice): ?>
                                    <tr data-search="<?= htmlspecialchars(strtolower(($advice['advice_name'] ?? '') . ' ' . ($advice['description'] ?? ''))) ?>">
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <div class="fw-semibold small"><?= htmlspecialchars($advice['advice_name']) ?></div>
                                            <?php if (!empty($advice['description'])): ?>
                                                <div class="text-muted" style="font-size:10px;">
                                                    <?= htmlspecialchars($advice['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ((int)$advice['status'] === 1): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle">ACTIVE</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">INACTIVE</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-primary btn-sm"
                                                    title="EDIT"
                                                    onclick='editAdvice(<?= json_encode($advice, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                                >
                                                    <i class="bi bi-pencil"></i>
                                                </button>

                                                <a
                                                    href="master_advices.php?toggle_status=<?= (int)$advice['id'] ?>&st=<?= (int)$advice['status'] ?>"
                                                    class="btn btn-outline-<?= (int)$advice['status'] === 1 ? 'danger' : 'success' ?> btn-sm"
                                                    title="<?= (int)$advice['status'] === 1 ? 'DEACTIVATE' : 'ACTIVATE' ?>"
                                                >
                                                    <i class="bi bi-power"></i>
                                                </a>
                                            </div>
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
function editAdvice(advice) {
    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-pencil-square text-primary me-1"></i> EDIT ADVICE';

    document.getElementById('edit_id').value = advice.id || 0;
    document.getElementById('advice_name').value = advice.advice_name || '';
    document.getElementById('description').value = advice.description || '';
    document.getElementById('status').checked = Number(advice.status) === 1;
    document.getElementById('advice_name').focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetAdviceForm() {
    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-lightbulb text-primary me-1"></i> ADD ADVICE';

    document.getElementById('edit_id').value = 0;
    document.getElementById('advice_name').value = '';
    document.getElementById('description').value = '';
    document.getElementById('status').checked = true;
    document.getElementById('advice_name').focus();
}

document.getElementById('adviceSearch')?.addEventListener('input', function () {
    const term = this.value.toLowerCase().trim();
    document.querySelectorAll('#adviceTableBody tr[data-search]').forEach(function (row) {
        row.style.display = !term || (row.dataset.search || '').includes(term) ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
