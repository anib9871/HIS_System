<?php
// admin/master_routes.php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Route Master";
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);

// Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_route'])) {
    $route_name = strtoupper(trim($_POST['route_name'] ?? ''));
    $route_code = strtoupper(trim($_POST['route_code'] ?? ''));
    $status = isset($_POST['status']) ? 1 : 0;
    $edit_id = (int)($_POST['edit_id'] ?? 0);

    if ($route_name === '') {
        set_flash_err("Route Name is required.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("
                    UPDATE master_routes
                    SET route_name = ?, route_code = ?, status = ?
                    WHERE id = ? AND org_id = ? AND center_id = ?
                ");
                $stmt->execute([
                    $route_name,
                    $route_code !== '' ? $route_code : null,
                    $status,
                    $edit_id,
                    $org_id,
                    $center_id
                ]);
                set_flash_msg("Route updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("
                    INSERT INTO master_routes
                    (org_id, center_id, route_name, route_code, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $org_id,
                    $center_id,
                    $route_name,
                    $route_code !== '' ? $route_code : null,
                    $status,
                    $created_by ?: null
                ]);
                set_flash_msg("Route added successfully.");
            }

            header("Location: master_routes.php");
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
        UPDATE master_routes
        SET status = ?
        WHERE id = ? AND org_id = ? AND center_id = ?
    ")->execute([$st, $id, $org_id, $center_id]);

    header("Location: master_routes.php");
    exit;
}

$routes = $tenant_pdo->prepare("
    SELECT * FROM master_routes
    WHERE org_id = ? AND center_id = ?
    ORDER BY id DESC
");
$routes->execute([$org_id, $center_id]);
$routes = $routes->fetchAll() ?: [];

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-arrow-left-right text-primary me-1"></i> ADD ROUTE
                </span>
            </div>

            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_route" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold text-secondary mb-1">ROUTE NAME *</label>
                            <input type="text" name="route_name" id="route_name"
                                   class="form-control form-control-sm text-uppercase"
                                   placeholder="E.G. ORAL" maxlength="100" required autofocus>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">ROUTE CODE</label>
                            <input type="text" name="route_code" id="route_code"
                                   class="form-control form-control-sm font-monospace text-uppercase"
                                   placeholder="E.G. PO" maxlength="30">
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">
                            ACTIVE STATUS
                        </label>
                    </div>

                    <div class="d-flex gap-2 border-top pt-2">
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">RESET</button>
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">SAVE ROUTE</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border shadow-sm">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-dark">
                    <i class="bi bi-list-task text-primary me-1"></i> ROUTES LIST
                </span>
                <span class="badge bg-light text-secondary border"><?= count($routes) ?> RECORDS</span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3" style="width: 60px;">#</th>
                                <th>ROUTE NAME</th>
                                <th>ROUTE CODE</th>
                                <th>STATUS</th>
                                <th class="text-end pe-3">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($routes)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">NO ROUTES FOUND.</td></tr>
                            <?php else: ?>
                                <?php foreach ($routes as $r): ?>
                                    <?php $is_act = (int)$r['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= (int)$r['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($r['route_name']) ?></td>
                                        <td>
                                            <?php if (!empty($r['route_code'])): ?>
                                                <span class="badge bg-light text-primary border font-monospace text-uppercase">
                                                    <?= htmlspecialchars($r['route_code']) ?>
                                                </span>
                                            <?php else: ?>-<?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?toggle_status=<?= (int)$r['id'] ?>&st=<?= $is_act ? 1 : 0 ?>"
                                               class="badge text-decoration-none <?= $is_act
                                                   ? 'bg-success-subtle text-success border border-success'
                                                   : 'bg-danger-subtle text-danger border border-danger' ?>">
                                                <?= $is_act ? 'ACTIVE' : 'INACTIVE' ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2"
                                                    onclick='editRow(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                    title="EDIT">
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
    document.getElementById('route_name').value = d.route_name || '';
    document.getElementById('route_code').value = d.route_code || '';
    document.getElementById('status').checked = (parseInt(d.status) === 1);

    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT ROUTE';
    document.getElementById('btnSubmit').innerText = 'UPDATE ROUTE';
    document.getElementById('route_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML =
        '<i class="bi bi-arrow-left-right text-primary me-1"></i> ADD ROUTE';
    document.getElementById('btnSubmit').innerText = 'SAVE ROUTE';
    document.getElementById('route_name').focus();
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
