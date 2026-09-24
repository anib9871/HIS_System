<?php
// superadmin/dashboard.php
require_once __DIR__ . '/../config/master_db.php';
require_once __DIR__ . '/layout_header.php';

$total_orgs  = $master_pdo->query("SELECT COUNT(*) FROM master_organization")->fetchColumn() ?: 0;
$total_roles = $master_pdo->query("SELECT COUNT(*) FROM master_role")->fetchColumn() ?: 0;
$total_users = $master_pdo->query("SELECT COUNT(*) FROM user_credentials")->fetchColumn() ?: 0;
$total_plans = $master_pdo->query("SELECT COUNT(*) FROM subscription_plans")->fetchColumn() ?: 0;

$orgs = $master_pdo->query("SELECT * FROM master_organization ORDER BY org_id DESC LIMIT 10")->fetchAll();
?>

<div class="container-fluid p-0">
    <h4 class="fw-bold text-dark mb-4">Dashboard Overview</h4>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-custom p-3 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small fw-semibold">Organizations</span>
                    <h3 class="fw-bold text-primary my-1"><?= $total_orgs ?></h3>
                </div>
                <i class="bi bi-buildings fs-1 text-primary-emphasis"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-custom p-3 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small fw-semibold">Master Roles</span>
                    <h3 class="fw-bold text-success my-1"><?= $total_roles ?></h3>
                </div>
                <i class="bi bi-person-badge fs-1 text-success"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-custom p-3 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small fw-semibold">Active Users</span>
                    <h3 class="fw-bold text-warning my-1"><?= $total_users ?></h3>
                </div>
                <i class="bi bi-people fs-1 text-warning"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-custom p-3 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small fw-semibold">Active Plans</span>
                    <h3 class="fw-bold text-info my-1"><?= $total_plans ?></h3>
                </div>
                <i class="bi bi-credit-card fs-1 text-info"></i>
            </div>
        </div>
    </div>

    <div class="card-custom">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="fw-bold text-dark m-0"><i class="bi bi-buildings text-primary me-2"></i>Registered Organizations</h6>
            <a href="master_organization.php" class="btn btn-sm btn-outline-primary">Manage All</a>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Hospital / Org Name</th>
                        <th>Tenant Database</th>
                        <th>Port</th>
                        <th>Report Email</th>
                        <th>Auto Report</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orgs)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No organizations registered yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orgs as $o): ?>
                            <tr>
                                <td class="fw-bold">#<?= $o['org_id'] ?></td>
                                <td class="fw-semibold text-primary"><?= htmlspecialchars($o['org_name']) ?></td>
                                <td><code><?= htmlspecialchars($o['db_name']) ?></code></td>
                                <td><?= $o['port'] ?></td>
                                <td><?= htmlspecialchars($o['report_email'] ?: 'N/A') ?></td>
                                <td>
                                    <span class="badge <?= (int)$o['auto_report'] === 1 ? 'bg-info' : 'bg-light text-muted border' ?>">
                                        <?= (int)$o['auto_report'] === 1 ? 'Enabled (1)' : 'Disabled (0)' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= (int)$o['status'] === 1 ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= (int)$o['status'] === 1 ? 'Active (1)' : 'Inactive (0)' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layout_footer.php'; ?>