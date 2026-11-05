<?php
// admin/master_roles.php - Tenant Role Master
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'superadmin')) {
    header('Location: ../login.php'); exit;
}
require_once __DIR__ . '/../config/tenant_db.php';
$page_title = 'Role Master';

$sessionMenus = $_SESSION['menu_access'] ?? [];
if (is_string($sessionMenus)) $sessionMenus = json_decode($sessionMenus, true) ?: [];
$canManageRoles = (!empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) || in_array('masters_users', $sessionMenus, true);
if (!$canManageRoles) { http_response_code(403); exit('Access denied.'); }

$tenant_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tenant_pdo->exec("CREATE TABLE IF NOT EXISTS tenant_roles (
    role_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_tenant_roles_role_name (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Safe migration: existing roles remain General Staff, legacy Doctor role is preserved as Doctor.
$roleTypeColumn = $tenant_pdo->query("SHOW COLUMNS FROM tenant_roles LIKE 'role_type'")->fetch(PDO::FETCH_ASSOC);
if (!$roleTypeColumn) {
    $tenant_pdo->exec("ALTER TABLE tenant_roles ADD COLUMN role_type VARCHAR(20) NOT NULL DEFAULT 'general' AFTER role_name");
    $tenant_pdo->exec("UPDATE tenant_roles SET role_type='doctor' WHERE LOWER(TRIM(role_name))='doctor'");
}

if (empty($_SESSION['role_csrf'])) $_SESSION['role_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['role_csrf'];
$flash = '';
$flashType = 'success';
$editRole = null;
$allowedRoleTypes = ['general', 'doctor', 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Invalid request token. Refresh and try again.'); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['role_id'] ?? 0);
            $name = trim($_POST['role_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $roleType = strtolower(trim((string)($_POST['role_type'] ?? 'general')));
            $status = (int)($_POST['status'] ?? 1) === 1 ? 1 : 0;
            if ($name === '') throw new RuntimeException('Role name is required.');
            if (!in_array($roleType, $allowedRoleTypes, true)) throw new RuntimeException('Please select a valid role behaviour.');
            if ($id > 0) {
                $stmt = $tenant_pdo->prepare('UPDATE tenant_roles SET role_name=?, role_type=?, description=?, status=? WHERE role_id=?');
                $stmt->execute([$name, $roleType, $description, $status, $id]);
                $flash = 'Role updated successfully.';
            } else {
                $stmt = $tenant_pdo->prepare('INSERT INTO tenant_roles (role_name, role_type, description, status) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $roleType, $description, $status]);
                $flash = 'Role created successfully.';
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['role_id'] ?? 0);
            $status = (int)($_POST['status'] ?? 0) === 1 ? 1 : 0;
            $stmt = $tenant_pdo->prepare('UPDATE tenant_roles SET status=? WHERE role_id=?');
            $stmt->execute([$status, $id]);
            $flash = 'Role status updated.';
        }
    } catch (PDOException $e) {
        $flash = 'Role name already exists or database operation failed.';
        $flashType = 'danger';
    } catch (RuntimeException $e) {
        $flash = $e->getMessage(); $flashType = 'danger';
    }
}

if (isset($_GET['edit'])) {
    $stmt = $tenant_pdo->prepare('SELECT * FROM tenant_roles WHERE role_id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editRole = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$roles = $tenant_pdo->query('SELECT * FROM tenant_roles ORDER BY role_name ASC')->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__ . '/layout_header.php';
?>
<div class="container-fluid p-0">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div><h4 class="mb-1 fw-bold">Role Master</h4><div class="text-muted small">Create and manage staff roles used while creating users.</div></div>
    <span class="badge bg-primary-subtle text-primary border">Total Roles: <?= count($roles) ?></span>
  </div>
  <?php if ($flash): ?><div class="alert alert-<?= htmlspecialchars($flashType) ?> py-2"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><strong><i class="bi bi-person-gear text-primary me-2"></i><?= $editRole ? 'Edit Role' : 'Create New Role' ?></strong></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="role_id" value="<?= (int)($editRole['role_id'] ?? 0) ?>">
            <div class="mb-3"><label class="form-label small fw-semibold">Role Name *</label><input class="form-control" name="role_name" maxlength="100" required placeholder="e.g. Receptionist" value="<?= htmlspecialchars($editRole['role_name'] ?? '') ?>"></div>
            <div class="mb-3"><label class="form-label small fw-semibold">Role Type / Behaviour *</label><select class="form-select" name="role_type" required>
              <option value="general" <?= (($editRole['role_type'] ?? 'general') === 'general') ? 'selected' : '' ?>>General Staff (Doctor dropdown visible)</option>
              <option value="doctor" <?= (($editRole['role_type'] ?? '') === 'doctor') ? 'selected' : '' ?>>Doctor (Only own patients)</option>
              <option value="admin" <?= (($editRole['role_type'] ?? '') === 'admin') ? 'selected' : '' ?>>Administrator (Doctor dropdown visible)</option>
            </select><div class="form-text">Doctor behaviour restricts the account to its own doctor record. Admin and General Staff can select doctors.</div></div>
            <div class="mb-3"><label class="form-label small fw-semibold">Description</label><textarea class="form-control" name="description" rows="3" maxlength="255" placeholder="Short description of this role"><?= htmlspecialchars($editRole['description'] ?? '') ?></textarea></div>
            <div class="mb-3"><label class="form-label small fw-semibold">Status</label><select class="form-select" name="status"><option value="1" <?= (!isset($editRole['status']) || (int)$editRole['status'] === 1) ? 'selected' : '' ?>>Active</option><option value="0" <?= (isset($editRole['status']) && (int)$editRole['status'] === 0) ? 'selected' : '' ?>>Inactive</option></select></div>
            <div class="d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-save me-1"></i><?= $editRole ? 'Update Role' : 'Save Role' ?></button><?php if ($editRole): ?><a class="btn btn-light border" href="master_roles.php">Cancel</a><?php else: ?><button class="btn btn-light border" type="reset">Reset</button><?php endif; ?></div>
          </form>
        </div>
      </div>
      <div class="alert alert-info small mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Role name Staff & Users page ke Role dropdown mein automatically available hoga. Doctor Desk ka dropdown selected Role Type ke according behave karega. Menu permissions user creation screen par assign hongi.</div>
    </div>
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><strong><i class="bi bi-list-check text-primary me-2"></i>Role Directory</strong></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th style="width:70px">#</th><th>Role Name</th><th>Behaviour</th><th>Description</th><th>Status</th><th class="text-center">Action</th></tr></thead>
          <tbody>
          <?php if (!$roles): ?><tr><td colspan="6" class="text-center text-muted py-4">No roles created yet. Create your first role.</td></tr><?php else: foreach ($roles as $i => $r): ?>
            <tr><td><?= $i + 1 ?></td><td class="fw-semibold"><?= htmlspecialchars($r['role_name']) ?></td><td><span class="badge bg-<?= $r['role_type'] === 'doctor' ? 'warning text-dark' : ($r['role_type'] === 'admin' ? 'danger' : 'info text-dark') ?>"><?= htmlspecialchars(ucfirst($r['role_type'])) ?></span></td><td class="text-muted small"><?= htmlspecialchars($r['description'] ?? '') ?></td><td><span class="badge bg-<?= (int)$r['status'] === 1 ? 'success' : 'secondary' ?>"><?= (int)$r['status'] === 1 ? 'Active' : 'Inactive' ?></span></td><td class="text-center"><div class="d-inline-flex gap-2"><a class="btn btn-sm btn-outline-primary" href="master_roles.php?edit=<?= (int)$r['role_id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a><form method="post" class="m-0"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="role_id" value="<?= (int)$r['role_id'] ?>"><input type="hidden" name="status" value="<?= (int)$r['status'] === 1 ? 0 : 1 ?>"><button class="btn btn-sm btn-outline-<?= (int)$r['status'] === 1 ? 'danger' : 'success' ?>" type="submit" title="<?= (int)$r['status'] === 1 ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-power"></i></button></form></div></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>
<?php if (!empty($_SESSION['swal_success'])): unset($_SESSION['swal_success']); endif; ?>
