<?php
// admin/dashboard.php
$page_title = "Admin Dashboard";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/layout_header.php';

$total_patients = 0;
$today_visits = 0;
$total_users = 0;
$total_centers = 0;
$recent_opd = [];

try {
    $today = date('Y-m-d');
    $total_patients = $tenant_pdo->query("SELECT COUNT(*) FROM opd_patients")->fetchColumn() ?: 0;
    $today_visits   = $tenant_pdo->query("SELECT COUNT(*) FROM opd_visits WHERE visit_date = '{$today}'")->fetchColumn() ?: 0;
    $total_users    = $tenant_pdo->query("SELECT COUNT(*) FROM tenant_users WHERE status = 1")->fetchColumn() ?: 0;
    $total_centers  = $tenant_pdo->query("SELECT COUNT(*) FROM master_centers WHERE status = 1")->fetchColumn() ?: 0;

    $recent_opd = $tenant_pdo->query("
        SELECT v.token_no, v.status, v.consultation_fee, p.fullname, p.uhid, p.mobile 
        FROM opd_visits v 
        JOIN opd_patients p ON v.patient_id = p.patient_id 
        ORDER BY v.visit_id DESC LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {
    // Safe fallback if tables are not initialized yet
}
?>

<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="p-3 bg-primary bg-opacity-10 text-primary rounded-3"><i class="bi bi-people fs-3"></i></div>
                <div>
                    <div class="text-muted small fw-semibold">Total Patients</div>
                    <h4 class="mb-0 fw-bold"><?= $total_patients ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="p-3 bg-success bg-opacity-10 text-success rounded-3"><i class="bi bi-calendar2-check fs-3"></i></div>
                <div>
                    <div class="text-muted small fw-semibold">Today's OPD Visits</div>
                    <h4 class="mb-0 fw-bold"><?= $today_visits ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="p-3 bg-warning bg-opacity-10 text-warning rounded-3"><i class="bi bi-person-badge fs-3"></i></div>
                <div>
                    <div class="text-muted small fw-semibold">Active Staff</div>
                    <h4 class="mb-0 fw-bold"><?= $total_users ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="p-3 bg-info bg-opacity-10 text-info rounded-3"><i class="bi bi-geo-alt fs-3"></i></div>
                <div>
                    <div class="text-muted small fw-semibold">Centers / Branches</div>
                    <h4 class="mb-0 fw-bold"><?= $total_centers ?></h4>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Recent OPD Patients</h6>
        <a href="opd_registration.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle me-1"></i> New Registration</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Token</th>
                    <th>UHID</th>
                    <th>Patient Name</th>
                    <th>Mobile</th>
                    <th>Status</th>
                    <th>Fee</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_opd)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No recent OPD visits found.</td></tr>
                <?php else: ?>
                    <?php foreach ($recent_opd as $row): ?>
                        <tr>
                            <td><span class="badge bg-primary fs-6">#<?= $row['token_no'] ?></span></td>
                            <td><code><?= htmlspecialchars($row['uhid']) ?></code></td>
                            <td class="fw-semibold"><?= htmlspecialchars($row['fullname']) ?></td>
                            <td><?= htmlspecialchars($row['mobile']) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($row['status']) ?></span></td>
                            <td class="fw-bold">₹<?= number_format($row['consultation_fee'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/layout_footer.php'; ?>