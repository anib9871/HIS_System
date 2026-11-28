<?php
$page_title = "System Audit Trail";
require_once __DIR__ . '/../config/tenant_db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 0);
if (empty($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) {
    http_response_code(403);
    exit('Access denied. Only authorized administrators can view the System Audit Trail.');
}

$from = trim((string)($_GET['from'] ?? date('Y-m-d')));
$to = trim((string)($_GET['to'] ?? date('Y-m-d')));
$scope = strtoupper(trim((string)($_GET['scope'] ?? 'ALL')));
if (!in_array($scope, ['ALL', 'OPD', 'IPD'], true)) $scope = 'ALL';
$module = trim((string)($_GET['module'] ?? ''));
$action = strtoupper(trim((string)($_GET['action'] ?? '')));
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['al.org_id = ?'];
if ($scope === 'OPD') $where[] = "al.module_name LIKE 'OPD%'";
elseif ($scope === 'IPD') $where[] = "al.module_name LIKE 'IPD%'";
$params = [$org_id];
if ($center_id > 0) {
    $where[] = '(al.center_id = ? OR al.center_id IS NULL)';
    $params[] = $center_id;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'al.changed_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'al.changed_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $to;
}
if ($module !== '') { $where[] = 'al.module_name = ?'; $params[] = $module; }
if ($action !== '') { $where[] = 'al.action_type = ?'; $params[] = $action; }
if ($q !== '') {
    $where[] = '(al.user_name LIKE ? OR al.patient_id LIKE ? OR al.transaction_id LIKE ? OR al.record_id LIKE ? OR al.reason LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$countStmt = $tenant_pdo->prepare("SELECT COUNT(*) FROM audit_logs al WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$listStmt = $tenant_pdo->prepare("SELECT al.*, (SELECT COUNT(*) FROM audit_log_changes c WHERE c.audit_id = al.audit_id) AS change_count FROM audit_logs al WHERE $whereSql ORDER BY al.changed_at DESC, al.audit_id DESC LIMIT $perPage OFFSET $offset");
$listStmt->execute($params);
$logs = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$moduleSql = 'SELECT DISTINCT module_name FROM audit_logs WHERE org_id = ?';
$moduleParams = [$org_id];
if ($scope === 'OPD') $moduleSql .= " AND module_name LIKE 'OPD%'";
elseif ($scope === 'IPD') $moduleSql .= " AND module_name LIKE 'IPD%'";
$moduleSql .= ' ORDER BY module_name';
$moduleStmt = $tenant_pdo->prepare($moduleSql);
$moduleStmt->execute($moduleParams);
$modules = $moduleStmt->fetchAll(PDO::FETCH_COLUMN);

$selected = null;
$changes = [];
$view = (int)($_GET['view'] ?? 0);
if ($view > 0) {
    $detailWhere = 'audit_id = ? AND org_id = ?';
    $detailParams = [$view, $org_id];
    if ($center_id > 0) { $detailWhere .= ' AND (center_id = ? OR center_id IS NULL)'; $detailParams[] = $center_id; }
    $detailStmt = $tenant_pdo->prepare("SELECT * FROM audit_logs WHERE $detailWhere LIMIT 1");
    $detailStmt->execute($detailParams);
    $selected = $detailStmt->fetch(PDO::FETCH_ASSOC);
    if ($selected) {
        $changeStmt = $tenant_pdo->prepare("SELECT field_name, old_value, new_value FROM audit_log_changes WHERE audit_id = ? ORDER BY change_id");
        $changeStmt->execute([$view]);
        $changes = $changeStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once __DIR__ . '/layout_header.php';
function audit_e($value) { return htmlspecialchars((string)($value ?? '—'), ENT_QUOTES, 'UTF-8'); }
function audit_color($value) {
    $value = strtoupper((string)$value);
    return $value === 'INSERT' ? 'success' : ($value === 'UPDATE' ? 'warning' : ($value === 'DELETE' ? 'danger' : 'info'));
}
?>
<style>
.audit-wrap { width:100%; min-width:0; }
.audit-wrap, .audit-wrap * { box-sizing:border-box; }
.audit-wrap .metric, .audit-wrap .panel { background:#fff; border:1px solid #e2e8f0; border-radius:12px; }
.audit-wrap .metric { padding:13px 15px; height:100%; min-width:0; }
.audit-wrap .num { font-size:24px; line-height:1.2; font-weight:800; color:#0f172a; margin-top:4px; }
.audit-wrap .lbl { font-size:10px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.35px; margin-bottom:4px; }
.audit-wrap .filter-grid { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:12px; align-items:end; }
.audit-wrap .filter-item { min-width:0; }
.audit-wrap .filter-item label { display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px; }
.audit-wrap .filter-item input, .audit-wrap .filter-item select { width:100%; min-width:0; }
.audit-wrap .filter-date { grid-column:span 1; }
.audit-wrap .filter-scope { grid-column:span 1; }
.audit-wrap .filter-module, .audit-wrap .filter-action { grid-column:span 2; }
.audit-wrap .filter-search { grid-column:span 3; }
.audit-wrap .filter-buttons { grid-column:span 2; display:flex; gap:7px; min-width:0; }
.audit-wrap .filter-buttons .btn { flex:1 1 0; min-width:0; white-space:nowrap; }
.audit-wrap .table-wrap { width:100%; overflow-x:auto; }
.audit-wrap .activity-table { min-width:1050px; margin-bottom:0; }
.audit-wrap .activity-table thead th { font-size:11px; white-space:nowrap; color:#475569; padding:11px 9px; }
.audit-wrap .activity-table tbody td { font-size:11px; vertical-align:middle; padding:10px 9px; }
.audit-wrap .activity-table .reason-cell { min-width:140px; max-width:210px; white-space:normal; overflow-wrap:anywhere; }
.audit-wrap .old { background:#fff1f2; color:#9f1239; padding:8px; border-radius:6px; white-space:pre-wrap; overflow-wrap:anywhere; }
.audit-wrap .new { background:#ecfdf5; color:#166534; padding:8px; border-radius:6px; white-space:pre-wrap; overflow-wrap:anywhere; }
.audit-wrap .detail-value { overflow-wrap:anywhere; word-break:break-word; }
.audit-wrap .table-footer { gap:10px; flex-wrap:wrap; }
@media (max-width: 1199.98px) {
  .audit-wrap .filter-date, .audit-wrap .filter-module, .audit-wrap .filter-action { grid-column:span 3; }
  .audit-wrap .filter-scope { grid-column:span 3; }
  .audit-wrap .filter-search { grid-column:span 6; }
  .audit-wrap .filter-buttons { grid-column:span 6; }
}
@media (max-width: 767.98px) {
  .audit-wrap .filter-date, .audit-wrap .filter-scope, .audit-wrap .filter-module, .audit-wrap .filter-action,
  .audit-wrap .filter-search, .audit-wrap .filter-buttons { grid-column:span 6; }
  .audit-wrap .filter-buttons { grid-column:span 12; }
  .audit-wrap .metric { padding:11px; }
  .audit-wrap .num { font-size:21px; }
  .audit-wrap .page-heading { align-items:flex-start !important; }
}
@media (max-width: 420px) {
  .audit-wrap .filter-date, .audit-wrap .filter-scope, .audit-wrap .filter-module, .audit-wrap .filter-action,
  .audit-wrap .filter-search { grid-column:span 12; }
  .audit-wrap .filter-buttons { grid-column:span 12; }
}
</style>

<div class="audit-wrap">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 page-heading">
    <div>
      <h5 class="fw-bold mb-0"><i class="bi bi-shield-check text-primary me-2"></i>SYSTEM AUDIT TRAIL</h5>
      <div class="text-muted small">OPD + IPD · WHO CHANGED WHAT, WHEN AND WHY</div>
    </div>
    <span class="badge bg-light text-dark border">READ ONLY · ADMIN</span>
  </div>

  <div class="row g-2 mb-3">
    <div class="col-6 col-xl-3"><div class="metric"><div class="lbl">Matching Activities</div><div class="num"><?= number_format($total) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="metric"><div class="lbl">Page</div><div class="num"><?= $page ?> / <?= $totalPages ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="metric"><div class="lbl">From</div><strong><?= audit_e($from) ?></strong></div></div>
    <div class="col-6 col-xl-3"><div class="metric"><div class="lbl">To</div><strong><?= audit_e($to) ?></strong></div></div>
  </div>

  <form method="GET" class="panel p-3 mb-3">
    <div class="filter-grid">
      <div class="filter-item filter-date"><label for="audit-from">From</label><input id="audit-from" type="date" name="from" class="form-control form-control-sm" value="<?= audit_e($from) ?>"></div>
      <div class="filter-item filter-date"><label for="audit-to">To</label><input id="audit-to" type="date" name="to" class="form-control form-control-sm" value="<?= audit_e($to) ?>"></div>
      <div class="filter-item filter-scope"><label for="audit-scope">System</label><select id="audit-scope" name="scope" class="form-select form-select-sm"><option value="ALL" <?= $scope === 'ALL' ? 'selected' : '' ?>>All Systems</option><option value="OPD" <?= $scope === 'OPD' ? 'selected' : '' ?>>OPD</option><option value="IPD" <?= $scope === 'IPD' ? 'selected' : '' ?>>IPD</option></select></div>
      <div class="filter-item filter-module"><label for="audit-module">Module</label><select id="audit-module" name="module" class="form-select form-select-sm"><option value="">All Modules</option><?php foreach ($modules as $m): ?><option value="<?= audit_e($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= audit_e($m) ?></option><?php endforeach; ?></select></div>
      <div class="filter-item filter-action"><label for="audit-action">Action</label><select id="audit-action" name="action" class="form-select form-select-sm"><option value="">All Actions</option><?php foreach (['INSERT','UPDATE','DELETE'] as $a): ?><option value="<?= $a ?>" <?= $action === $a ? 'selected' : '' ?>><?= $a ?></option><?php endforeach; ?></select></div>
      <div class="filter-item filter-search"><label for="audit-search">Search User / Patient / Visit / Receipt / IPD / Reason</label><input id="audit-search" name="q" class="form-control form-control-sm" value="<?= audit_e($q) ?>" placeholder="Type to search..."></div>
      <div class="filter-buttons"><button type="submit" class="btn btn-primary btn-sm">Apply</button><a href="audit_logs.php" class="btn btn-light border btn-sm">Reset</a></div>
    </div>
  </form>

  <script>
  (function () {
    const searchInput = document.getElementById('audit-search');
    if (!searchInput) return;
    let searchTimer = null;

    function runAuditSearch() {
      if (searchTimer) clearTimeout(searchTimer);
      const params = new URLSearchParams(window.location.search);
      const value = searchInput.value.trim();
      if (value) params.set('q', value);
      else params.delete('q');
      params.delete('page');
      params.delete('view');
      const query = params.toString();
      window.location.href = window.location.pathname + (query ? '?' + query : '');
    }

    searchInput.addEventListener('input', function () {
      if (searchTimer) clearTimeout(searchTimer);
      searchTimer = setTimeout(runAuditSearch, 400);
    });

    searchInput.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        runAuditSearch();
      }
    });
  })();
  </script>

  <div class="panel overflow-hidden">
    <div class="p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
      <strong class="small">ACTIVITY HISTORY</strong><span class="small text-muted"><?= number_format($total) ?> RECORDS</span>
    </div>
    <div class="table-wrap">
      <table class="table table-hover activity-table">
        <thead class="table-light"><tr><th>Date &amp; Time</th><th>User</th><th>Module</th><th>Action</th><th>Patient</th><th>OPD / IPD / Transaction</th><th>Reason</th><th>Changes</th><th></th></tr></thead>
        <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No audit activities found.</td></tr>
        <?php else: foreach ($logs as $log): ?>
          <tr>
            <td class="text-nowrap"><?= audit_e(date('d-M-Y h:i:s A', strtotime($log['changed_at']))) ?></td>
            <td><strong><?= audit_e($log['user_name']) ?></strong><div class="text-muted"><?= audit_e($log['user_role']) ?> · #<?= (int)$log['user_id'] ?></div></td>
            <td><?= audit_e($log['module_name']) ?><div class="text-muted"><?= audit_e($log['table_name']) ?></div></td>
            <td><span class="badge text-bg-<?= audit_color($log['action_type']) ?>"><?= audit_e($log['action_type']) ?></span></td>
            <td><?= audit_e($log['patient_id']) ?></td>
            <td><?= audit_e($log['transaction_id'] ?: $log['record_id']) ?></td>
            <td class="reason-cell"><?= audit_e($log['reason']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= (int)$log['change_count'] ?></span></td>
            <td><a class="btn btn-sm btn-outline-primary" href="?<?= audit_e(http_build_query(array_merge($_GET, ['view' => $log['audit_id']])) ) ?>">View</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="p-3 border-top d-flex justify-content-between align-items-center table-footer">
      <span class="small text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
      <div class="d-flex gap-1">
        <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= audit_e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?>
        <?php if ($page < $totalPages): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= audit_e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($selected): ?>
    <div class="panel p-3 mt-3" id="auditDetails">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div><h6 class="fw-bold mb-0">ACTIVITY DETAILS #<?= (int)$selected['audit_id'] ?></h6><small class="text-muted"><?= audit_e($selected['event_id']) ?></small></div>
        <a class="btn btn-sm btn-outline-secondary" href="audit_logs.php">Close</a>
      </div>
      <div class="row g-3 mb-3">
        <?php foreach (['user_name'=>'User','user_role'=>'Role','changed_at'=>'Date & Time','ip_address'=>'IP Address','machine_name'=>'Machine','patient_id'=>'Patient ID','record_id'=>'Record ID','transaction_id'=>'Transaction'] as $key => $label): ?>
          <div class="col-6 col-md-3"><div class="lbl"><?= $label ?></div><strong class="detail-value"><?= audit_e($selected[$key] ?? null) ?></strong></div>
        <?php endforeach; ?>
      </div>
      <div class="mb-3"><div class="lbl">Reason</div><div class="detail-value"><?= audit_e($selected['reason']) ?></div></div>
      <h6 class="fw-bold small">FIELD-BY-FIELD CHANGES</h6>
      <?php if (!$changes): ?>
        <div class="text-muted small">No field changes attached.</div>
      <?php else: ?>
        <div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Field</th><th>Old Value</th><th>New Value</th></tr></thead><tbody>
          <?php foreach ($changes as $change): ?><tr><td class="fw-bold"><?= audit_e($change['field_name']) ?></td><td><div class="old"><?= audit_e($change['old_value']) ?></div></td><td><div class="new"><?= audit_e($change['new_value']) ?></div></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
