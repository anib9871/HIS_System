<?php
// admin/master_vitals.php
$page_title = "Vital Master";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$org_id    = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$err = '';
$success = '';

function makeVitalKey(string $name): string {
    $key = strtolower(trim($name));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
    $key = trim($key, '_');
    return $key;
}

/* ==========================================================
   DATABASE SETUP
   ========================================================== */
try {
    $tenant_pdo->exec("CREATE TABLE IF NOT EXISTS master_vitals (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        org_id INT NOT NULL,
        center_id INT NOT NULL,
        vital_name VARCHAR(100) NOT NULL,
        vital_key VARCHAR(100) NOT NULL,
        unit VARCHAR(50) NULL,
        placeholder VARCHAR(100) NULL,
        sort_order INT NOT NULL DEFAULT 1,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_mv_org_center_key (org_id, center_id, vital_key),
        KEY idx_mv_org_center (org_id, center_id),
        KEY idx_mv_name (vital_name),
        KEY idx_mv_status (status),
        KEY idx_mv_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    $err = 'VITAL MASTER DATABASE SETUP ERROR: ' . $e->getMessage();
}

/* ==========================================================
   SAVE / UPDATE / DELETE
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id          = (int)($_POST['id'] ?? 0);
            $vital_name  = strtoupper(trim($_POST['vital_name'] ?? ''));
            $unit        = strtoupper(trim($_POST['unit'] ?? ''));
            $placeholder = strtoupper(trim($_POST['placeholder'] ?? ''));
            $sort_order  = max(1, (int)($_POST['sort_order'] ?? 1));
            $status      = !empty($_POST['status']) ? 1 : 0;
            $vital_key   = makeVitalKey($vital_name);

            if ($vital_name === '') {
                throw new Exception('VITAL NAME IS REQUIRED.');
            }
            if ($vital_key === '') {
                throw new Exception('PLEASE ENTER A VALID VITAL NAME.');
            }

            $dup = $tenant_pdo->prepare("SELECT id FROM master_vitals WHERE org_id = ? AND center_id = ? AND vital_key = ? AND id <> ? LIMIT 1");
            $dup->execute([$org_id, $center_id, $vital_key, $id]);
            if ($dup->fetch()) {
                throw new Exception('THIS VITAL ALREADY EXISTS.');
            }

            if ($id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_vitals
                    SET vital_name = ?, vital_key = ?, unit = ?, placeholder = ?, sort_order = ?, status = ?
                    WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([
                    $vital_name,
                    $vital_key,
                    $unit !== '' ? $unit : null,
                    $placeholder !== '' ? $placeholder : null,
                    $sort_order,
                    $status,
                    $id,
                    $org_id,
                    $center_id
                ]);
                $success = 'VITAL UPDATED SUCCESSFULLY.';
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_vitals
                    (org_id, center_id, vital_name, vital_key, unit, placeholder, sort_order, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $org_id,
                    $center_id,
                    $vital_name,
                    $vital_key,
                    $unit !== '' ? $unit : null,
                    $placeholder !== '' ? $placeholder : null,
                    $sort_order,
                    $status,
                    $user_id ?: null
                ]);
                $success = 'VITAL ADDED SUCCESSFULLY.';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('INVALID VITAL.');
            $stmt = $tenant_pdo->prepare("DELETE FROM master_vitals WHERE id = ? AND org_id = ? AND center_id = ?");
            $stmt->execute([$id, $org_id, $center_id]);
            $success = 'VITAL DELETED SUCCESSFULLY.';
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $tenant_pdo->prepare("UPDATE master_vitals SET status = IF(status = 1, 0, 1) WHERE id = ? AND org_id = ? AND center_id = ?");
            $stmt->execute([$id, $org_id, $center_id]);
            $success = 'VITAL STATUS UPDATED.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

/* ==========================================================
   EDIT LOAD
   ========================================================== */
$edit_id = (int)($_GET['edit'] ?? 0);
$edit_vital = null;
if ($edit_id > 0) {
    try {
        $stmt = $tenant_pdo->prepare("SELECT id, vital_name, vital_key, unit, placeholder, sort_order, status
            FROM master_vitals WHERE id = ? AND org_id = ? AND center_id = ? LIMIT 1");
        $stmt->execute([$edit_id, $org_id, $center_id]);
        $edit_vital = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

/* ==========================================================
   LIST
   ========================================================== */
$vitals = [];
try {
    $stmt = $tenant_pdo->prepare("SELECT id, vital_name, vital_key, unit, placeholder, sort_order, status
        FROM master_vitals WHERE org_id = ? AND center_id = ? ORDER BY sort_order ASC, vital_name ASC");
    $stmt->execute([$org_id, $center_id]);
    $vitals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}

require_once __DIR__ . '/layout_header.php';
?>

<style>
    body, .card, .form-label, .form-control, .btn, .badge, .table, th, td, .form-select { text-transform: uppercase; }
    input[type="number"], input[type="search"] { text-transform: none; }
    .mv-card { border:0; border-radius:12px; box-shadow:0 3px 18px rgba(15,23,42,.07); }
    .mv-card .card-header { background:#fff; border-bottom:1px solid #e5e7eb; }
    .mv-form-label { font-size:10px; font-weight:800; color:#64748b; margin-bottom:4px; }
    .mv-table th { font-size:10px; font-weight:800; color:#475569; white-space:nowrap; }
    .mv-table td { font-size:11px; vertical-align:middle; }
    .mv-empty { padding:40px 10px; text-align:center; color:#94a3b8; font-weight:700; }
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h5 class="fw-bold mb-0"><i class="bi bi-heart-pulse text-primary me-2"></i>VITAL MASTER</h5>
        <div class="text-muted small">MANAGE VITALS USED IN PRESCRIPTION TEMPLATES AND DOCTOR DESK</div>
    </div>
</div>

<?php if ($err): ?>
    <div class="alert alert-danger py-2 px-3 fw-bold"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success py-2 px-3 fw-bold"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mv-card">
            <div class="card-header py-3">
                <div class="fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i><?= $edit_vital ? 'EDIT VITAL' : 'ADD VITAL' ?></div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)($edit_vital['id'] ?? 0) ?>">

                    <div class="mb-2">
                        <label class="mv-form-label">VITAL NAME *</label>
                        <input type="text" name="vital_name" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_vital['vital_name'] ?? '') ?>" placeholder="E.G. BP" required>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="mv-form-label">UNIT</label>
                            <input type="text" name="unit" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_vital['unit'] ?? '') ?>" placeholder="E.G. MMHG">
                        </div>
                        <div class="col-md-6">
                            <label class="mv-form-label">SORT ORDER</label>
                            <input type="number" name="sort_order" min="1" class="form-control form-control-sm" value="<?= (int)($edit_vital['sort_order'] ?? (count($vitals) + 1)) ?>">
                        </div>
                    </div>

                    <div class="mt-2">
                        <label class="mv-form-label">INPUT PLACEHOLDER</label>
                        <input type="text" name="placeholder" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_vital['placeholder'] ?? '') ?>" placeholder="E.G. 120/80">
                    </div>

                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" name="status" id="vitalStatus" value="1" <?= !isset($edit_vital['status']) || (int)$edit_vital['status'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label small fw-bold" for="vitalStatus">ACTIVE STATUS</label>
                    </div>

                    <div class="border-top mt-3 pt-3 d-flex gap-2">
                        <?php if ($edit_vital): ?>
                            <a href="master_vitals.php" class="btn btn-sm btn-outline-secondary flex-grow-1">RESET</a>
                        <?php else: ?>
                            <button type="reset" class="btn btn-sm btn-outline-secondary flex-grow-1">RESET</button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1 fw-bold"><?= $edit_vital ? 'UPDATE VITAL' : 'SAVE VITAL' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card mv-card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="fw-bold"><i class="bi bi-list-ul text-primary me-2"></i>VITALS LIST</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="search" id="vitalSearch" class="form-control form-control-sm" placeholder="SEARCH VITALS..." style="width: 200px;">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle" id="recordCount"><?= count($vitals) ?> RECORDS</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 mv-table" id="vitalsTable">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>VITAL</th>
                            <th>UNIT</th>
                            <th>PLACEHOLDER</th>
                            <th>ORDER</th>
                            <th>STATUS</th>
                            <th>ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$vitals): ?>
                        <tr class="empty-row"><td colspan="7" class="mv-empty">NO VITALS FOUND.</td></tr>
                    <?php else: foreach ($vitals as $v): ?>
                        <tr class="vital-row">
                            <td><?= (int)$v['id'] ?></td>
                            <td>
                                <div class="fw-bold vital-name"><?= htmlspecialchars($v['vital_name']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($v['unit'] ?? '') ?: '—' ?></td>
                            <td><?= htmlspecialchars($v['placeholder'] ?? '') ?: '—' ?></td>
                            <td><?= (int)$v['sort_order'] ?></td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                    <button class="btn btn-sm <?= (int)$v['status'] === 1 ? 'btn-success' : 'btn-outline-secondary' ?> py-0 px-2" type="submit">
                                        <?= (int)$v['status'] === 1 ? 'ACTIVE' : 'INACTIVE' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="text-nowrap">
                                <a href="master_vitals.php?edit=<?= (int)$v['id'] ?>" class="btn btn-sm btn-outline-primary" title="EDIT"><i class="bi bi-pencil"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('DELETE THIS VITAL?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="DELETE"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const searchInput = document.getElementById("vitalSearch");
    const rows = document.querySelectorAll(".vital-row");
    const recordCount = document.getElementById("recordCount");

    if (searchInput) {
        searchInput.addEventListener("keyup", function() {
            let filter = this.value.toUpperCase();
            let visibleCount = 0;

            rows.forEach(row => {
                let vitalName = row.querySelector(".vital-name").textContent.toUpperCase();
                
                if (vitalName.indexOf(filter) > -1) {
                    row.style.display = "";
                    visibleCount++;
                } else {
                    row.style.display = "none";
                }
            });

            if (recordCount) {
                recordCount.textContent = visibleCount + " RECORDS";
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
