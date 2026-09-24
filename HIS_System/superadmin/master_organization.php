<?php
// superadmin/master_organization.php
require_once __DIR__ . '/../config/master_db.php';
require_once __DIR__ . '/../config/alerts.php';

// 1. Status Toggle via URL
if (isset($_GET['toggle_status'])) {
    $toggle_id = (int)$_GET['toggle_status'];
    $current   = (int)($_GET['current'] ?? 1);
    $new_st    = ($current === 1) ? 0 : 1;

    try {
        $stmt = $master_pdo->prepare("UPDATE master_organization SET status = ? WHERE org_id = ?");
        $stmt->execute([$new_st, $toggle_id]);
        set_flash_msg("Organization status updated!");
    } catch (PDOException $e) {
        set_flash_err("Status update failed: " . $e->getMessage());
    }
    header("Location: master_organization.php");
    exit;
}

// 2. Fetch Edit Data
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $master_pdo->prepare("SELECT * FROM master_organization WHERE org_id = ?");
    $stmt->execute([$edit_id]);
    $edit_data = $stmt->fetch();
}

// 3. Form Submit (Save / Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $org_name     = trim($_POST['org_name'] ?? '');
    $port         = (int)($_POST['port'] ?? 3306);
    $report_email = trim($_POST['report_email'] ?? '');
    $auto_report  = isset($_POST['auto_report']) ? 1 : 0;
    $report_time  = !empty($_POST['report_time']) ? $_POST['report_time'] : null;
    $status       = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    if (empty($org_name)) {
        set_flash_err("Organization Name bharna zaroori hai!");
    } else {
        if ($_POST['action'] === 'save_org') {
            try {
                // DB Name generate from Org Name
                $clean_name = strtolower(str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9 ]/', '', $org_name)));
                $db_name    = $clean_name . '_his';

                // 1. Insert into Central Master Organization
                $stmt = $master_pdo->prepare("
                    INSERT INTO master_organization (org_name, db_name, port, report_email, auto_report, report_time, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$org_name, $db_name, $port, $report_email, $auto_report, $report_time, $status]);
                $new_org_id = $master_pdo->lastInsertId();

                // 2. CREATE NEW DATABASE
                $master_pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

                // 3. COPY TABLES FROM TEMPLATE (his_tenant_demo)
                $template_db = 'his_tenant_demo';
                $tables_stmt = $master_pdo->query("SHOW TABLES FROM `{$template_db}`");
                $tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($tables as $table) {
                    $master_pdo->exec("CREATE TABLE IF NOT EXISTS `{$db_name}`.`{$table}` LIKE `{$template_db}`.`{$table}`");
                }

                // 4. Default Seeds in New DB
                $stmt_prof = $master_pdo->prepare("INSERT INTO `{$db_name}`.`org_profile` (id, org_name, email, status) VALUES (1, ?, ?, 1) ON DUPLICATE KEY UPDATE org_name = VALUES(org_name)");
                $stmt_prof->execute([$org_name, $report_email]);

                $master_pdo->exec("INSERT INTO `{$db_name}`.`tenant_roles` SELECT * FROM `{$template_db}`.`tenant_roles` ON DUPLICATE KEY UPDATE role_name = VALUES(role_name)");

                $admin_username = 'admin_' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $org_name), 0, 6) . '@hospital.com';
                $all_menus = json_encode(['dashboard', 'masters_org', 'masters_centers', 'masters_users', 'opd_reg', 'opd_queue', 'opd_doctor', 'opd_billing']);
                
                $stmt_usr = $master_pdo->prepare("
                    INSERT INTO `{$db_name}`.`tenant_users` (center_id, role_id, fullname, username, password, menu_access, is_admin, status) 
                    VALUES (1, 1, 'Hospital Administrator', ?, '1234', ?, 1, 1)
                ");
                $stmt_usr->execute([$admin_username, $all_menus]);

                $master_pdo->prepare("
                    INSERT INTO user_credentials (org_id, role_id, fullname, username, password, status) 
                    VALUES (?, 2, 'Hospital Administrator', ?, '1234', 1)
                ")->execute([$new_org_id, $admin_username]);

                set_flash_msg("Organization '{$org_name}' ban gayi aur naya database '{$db_name}' clone ho gaya! Admin: {$admin_username} | Pass: 1234");
                header("Location: master_organization.php");
                exit;
            } catch (Exception $e) {
                set_flash_err("DB Clone Error: " . $e->getMessage());
            }
        } elseif ($_POST['action'] === 'update_org') {
            $org_id = (int)$_POST['org_id'];
            try {
                $stmt = $master_pdo->prepare("
                    UPDATE master_organization 
                    SET org_name = ?, port = ?, report_email = ?, auto_report = ?, report_time = ?, status = ?
                    WHERE org_id = ?
                ");
                $stmt->execute([$org_name, $port, $report_email, $auto_report, $report_time, $status, $org_id]);
                set_flash_msg("Organization updated successfully!");
                header("Location: master_organization.php");
                exit;
            } catch (PDOException $e) {
                set_flash_err("Update failed: " . $e->getMessage());
            }
        }
    }
}

$orgs = $master_pdo->query("SELECT * FROM master_organization ORDER BY org_id DESC")->fetchAll();
require_once __DIR__ . '/layout_header.php';
?>

<style>
.form-switch .form-check-input { width: 2.4em; height: 1.25em; cursor: pointer; }
.btn-action-edit { background-color: #e0f2fe; color: #0284c7; border: none; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; transition: 0.2s; text-decoration: none; }
.btn-action-edit:hover { background-color: #0284c7; color: #fff; }
</style>

<div class="container-fluid p-0">
    <div class="mb-3">
        <h4 class="fw-bold text-dark m-0">Master Organization</h4>
        <small class="text-muted">Register organizations & auto clone database from his_tenant_demo</small>
    </div>

    <div class="row g-4">
        <div class="col-xl-4 col-lg-5">
            <div class="card-custom p-4">
                <h6 class="fw-bold text-dark mb-3">
                    <i class="bi <?= $edit_data ? 'bi-pencil-square text-primary' : 'bi-building-add text-primary' ?> me-2"></i>
                    <?= $edit_data ? 'Edit Organization' : 'Add Organization' ?>
                </h6>
                <form method="POST" action="master_organization.php">
                    <input type="hidden" name="action" value="<?= $edit_data ? 'update_org' : 'save_org' ?>">
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="org_id" value="<?= $edit_data['org_id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Organization Name *</label>
                        <input type="text" name="org_name" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['org_name'] ?? '') ?>" placeholder="e.g. Apollo Hospital" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Port *</label>
                        <input type="number" name="port" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['port'] ?? 3306) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Report Email</label>
                        <input type="email" name="report_email" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['report_email'] ?? '') ?>" placeholder="reports@hospital.com">
                    </div>

                    <div class="mb-3 d-flex align-items-center justify-content-between p-2 bg-light rounded border">
                        <label class="form-label small fw-semibold m-0">Auto Report Send</label>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" name="auto_report" value="1" <?= (!empty($edit_data['auto_report']) && (int)$edit_data['auto_report'] === 1) ? 'checked' : '' ?>>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Report Timing</label>
                        <input type="time" name="report_time" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['report_time'] ?? '') ?>">
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-semibold">Status *</label>
                        <select name="status" class="form-select form-select-sm" required>
                            <option value="1" <?= (!isset($edit_data['status']) || (int)$edit_data['status'] === 1) ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= (isset($edit_data['status']) && (int)$edit_data['status'] === 0) ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-custom btn-sm flex-grow-1">
                            <i class="bi <?= $edit_data ? 'bi-check-lg' : 'bi-save' ?> me-1"></i> <?= $edit_data ? 'Update' : 'Save & Clone DB' ?>
                        </button>
                        <?php if ($edit_data): ?>
                            <a href="master_organization.php" class="btn btn-light btn-sm border">Cancel</a>
                        <?php else: ?>
                            <button type="reset" class="btn btn-light btn-sm border">Reset</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-xl-8 col-lg-7">
            <div class="card-custom">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center gap-3">
                    <h6 class="fw-bold text-dark m-0"><i class="bi bi-list-task text-primary me-2"></i>Organization Directory</h6>
                    <input type="text" id="searchInput" class="form-control form-control-sm w-50" placeholder="Search organization...">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="orgTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Org Name</th>
                                <th>DB Name</th>
                                <th>Port</th>
                                <th>Report Email</th>
                                <th>Status</th>
                                <th class="text-center" style="width: 100px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orgs)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No organizations found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($orgs as $o): ?>
                                    <tr>
                                        <td><strong><?= $o['org_id'] ?></strong></td>
                                        <td class="fw-semibold text-primary"><?= htmlspecialchars($o['org_name']) ?></td>
                                        <td><code><?= htmlspecialchars($o['db_name'] ?? '-') ?></code></td>
                                        <td><?= $o['port'] ?></td>
                                        <td><?= htmlspecialchars($o['report_email'] ?: '-') ?></td>
                                        <td>
                                            <span class="badge <?= (int)$o['status'] === 1 ? 'bg-success' : 'bg-danger' ?>">
                                                <?= (int)$o['status'] === 1 ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex align-items-center justify-content-center gap-2">
                                                <a href="master_organization.php?edit=<?= $o['org_id'] ?>" class="btn-action-edit" title="Edit">
                                                    <i class="bi bi-pencil-fill" style="font-size: 0.85rem;"></i>
                                                </a>
                                                <div class="form-check form-switch m-0" title="Toggle Status (Active/Inactive)">
                                                    <input class="form-check-input" type="checkbox" role="switch" 
                                                           <?= (int)$o['status'] === 1 ? 'checked' : '' ?> 
                                                           onchange="window.location.href='master_organization.php?toggle_status=<?= $o['org_id'] ?>&current=<?= $o['status'] ?>'">
                                                </div>
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
document.getElementById('searchInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#orgTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>