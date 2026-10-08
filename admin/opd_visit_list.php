<?php
// admin/opd_visit_list.php
$page_title = "OPD Consultations List";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$org_id    = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// Defaults
$from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-7 days'));
$to_date   = $_GET['to_date'] ?? date('Y-m-d');
$search    = trim($_GET['search'] ?? '');

$visits = [];
try {
    $sql = "
        SELECT 
            v.visit_id, v.visit_date, v.token_no, v.status,
            p.uhid, p.fullname, p.mobile,
            d.full_name AS doctor_name,
            dept.dept_name
        FROM opd_visits v
        JOIN patient_master p ON p.patient_id = v.patient_id
        LEFT JOIN master_doctors d ON d.id = v.doctor_id
        LEFT JOIN master_departments dept ON dept.id = v.department_id
        WHERE v.org_id = ? AND v.center_id = ?
          AND v.visit_date BETWEEN ? AND ?
    ";
    
    $params = [$org_id, $center_id, $from_date, $to_date];

    if ($search !== '') {
        $sql .= " AND (p.fullname LIKE ? OR p.uhid LIKE ? OR p.mobile LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY v.visit_date DESC, v.visit_id DESC";

    $stmt = $tenant_pdo->prepare($sql);
    $stmt->execute($params);
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (Exception $e) {
    $err = $e->getMessage();
}

require_once __DIR__ . '/layout_header.php';
?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="fw-bold mb-0 text-uppercase">
                <i class="bi bi-list-ul text-primary me-2"></i> OPD Consultations List
            </h5>
            <div class="text-muted small text-uppercase">VIEW, SEARCH AND EDIT ALL OPD VISITS</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3" style="border-radius: 12px;">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-secondary mb-1">FROM DATE</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-secondary mb-1">TO DATE</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-secondary mb-1">SEARCH (NAME / UHID / MOBILE)</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search Patient..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">
                        <i class="bi bi-search me-1"></i> SEARCH
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                    <thead class="table-light">
                        <tr class="text-uppercase" style="font-size: 11px;">
                            <th class="ps-3">DATE / TOKEN</th>
                            <th>PATIENT DETAILS</th>
                            <th>DOCTOR / DEPT</th>
                            <th>STATUS</th>
                            <th class="text-end pe-3">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($visits)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted fw-bold text-uppercase">
                                    <i class="bi bi-info-circle display-6 d-block mb-2 text-secondary"></i>
                                    NO VISITS FOUND FOR THE SELECTED CRITERIA.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($visits as $v): ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-bold text-dark"><?= date('d M Y', strtotime($v['visit_date'])) ?></div>
                                        <div class="small text-primary fw-bold">TOKEN #<?= (int)$v['token_no'] ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-uppercase"><?= htmlspecialchars($v['fullname']) ?></div>
                                        <div class="small text-muted font-monospace"><?= htmlspecialchars($v['uhid']) ?> | <i class="bi bi-telephone"></i> <?= htmlspecialchars($v['mobile']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-uppercase"><?= htmlspecialchars($v['doctor_name'] ?: 'GENERAL DOCTOR') ?></div>
                                        <div class="small text-muted text-uppercase"><?= htmlspecialchars($v['dept_name'] ?: 'N/A') ?></div>
                                    </td>
                                    <td>
                                        <?php
                                            $statusClass = 'bg-secondary';
                                            $st = strtolower($v['status']);
                                            if ($st === 'completed') $statusClass = 'bg-success';
                                            elseif ($st === 'inside cabin') $statusClass = 'bg-primary';
                                            elseif ($st === 'waiting') $statusClass = 'bg-warning text-dark';
                                        ?>
                                        <span class="badge <?= $statusClass ?> text-uppercase" style="font-size:10px;">
                                            <?= htmlspecialchars($v['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-3">
                                        <!-- Edit button seamlessly links into the doctor desk with the context of this visit -->
                                        <a href="opd_doctor.php?visit_id=<?= (int)$v['visit_id'] ?>" class="btn btn-sm btn-outline-primary fw-bold me-1" title="Open in Doctor Desk">
                                            <i class="bi bi-pencil-square"></i> EDIT
                                        </a>
                                        <!-- Direct Print button added here -->
                                        <a href="print_prescription.php?visit_id=<?= (int)$v['visit_id'] ?>" target="_blank" class="btn btn-sm btn-dark fw-bold" title="Print Prescription">
                                            <i class="bi bi-printer"></i> PRINT
                                        </a>
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

<?php require_once __DIR__ . '/layout_footer.php'; ?>