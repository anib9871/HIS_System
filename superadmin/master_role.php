<?php
// superadmin/master_role.php
require_once __DIR__ . '/../config/master_db.php';
require_once __DIR__ . '/../config/alerts.php';

// 1. Status Toggle
if (isset($_GET['toggle_status'])) {
    $toggle_id = (int)$_GET['toggle_status'];
    $current   = (int)($_GET['current'] ?? 1);
    $new_st    = ($current === 1) ? 0 : 1;

    try {
        $stmt = $master_pdo->prepare("UPDATE master_role SET status = ? WHERE role_id = ?");
        $stmt->execute([$new_st, $toggle_id]);
        set_flash_msg("Role status updated successfully!");
    } catch (PDOException $e) {
        set_flash_err("Status update failed: " . $e->getMessage());
    }
    header("Location: master_role.php");
    exit;
}

// 2. Fetch Edit Data
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $master_pdo->prepare("SELECT * FROM master_role WHERE role_id = ?");
    $stmt->execute([$edit_id]);
    $edit_data = $stmt->fetch();
}

// 3. Form Submit (Add / Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $role_name = trim($_POST['role_name'] ?? '');
    $status    = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    if (empty($role_name)) {
        set_flash_err("Role Name is required!");
    } else {
        if ($_POST['action'] === 'save_role') {
            try {
                $stmt = $master_pdo->prepare("INSERT INTO master_role (role_name, status) VALUES (?, ?)");
                $stmt->execute([$role_name, $status]);
                set_flash_msg("Role '{$role_name}' added successfully!");
                header("Location: master_role.php");
                exit;
            } catch (PDOException $e) {
                set_flash_err("Error: " . $e->getMessage());
            }
        } elseif ($_POST['action'] === 'update_role') {
            $role_id = (int)$_POST['role_id'];
            try {
                $stmt = $master_pdo->prepare("UPDATE master_role SET role_name = ?, status = ? WHERE role_id = ?");
                $stmt->execute([$role_name, $status, $role_id]);
                set_flash_msg("Role '{$role_name}' updated successfully!");
                header("Location: master_role.php");
                exit;
            } catch (PDOException $e) {
                set_flash_err("Update failed: " . $e->getMessage());
            }
        }
    }
}

$roles = $master_pdo->query("SELECT * FROM master_role ORDER BY role_id ASC")->fetchAll();
require_once __DIR__ . '/layout_header.php';
?>

<style>
.form-switch .form-check-input { width: 2.2em; height: 1.15em; cursor: pointer; }
.btn-action-edit { background-color: #e0f2fe; color: #0284c7; border: none; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; transition: 0.2s; }
.btn-action-edit:hover { background-color: #0284c7; color: #fff; }
.table-compact th, .table-compact td { padding: 8px 12px !important; }
</style>

<div class="container-fluid p-0">
    <div class="mb-3">
        <h4 class="fw-bold text-dark m-0">Master Role</h4>
        <small class="text-muted">Define and manage system roles</small>
    </div>

    <div class="row g-3">
        <div class="col-lg-5 col-xl-4">
            <div class="card-custom p-3">
                <h6 class="fw-bold text-dark mb-3">
                    <i class="bi <?= $edit_data ? 'bi-pencil-square text-primary' : 'bi-shield-plus text-primary' ?> me-2"></i>
                    <?= $edit_data ? 'Edit Role' : 'Add Role' ?>
                </h6>
                <form method="POST" action="master_role.php">
                    <input type="hidden" name="action" value="<?= $edit_data ? 'update_role' : 'save_role' ?>">
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="role_id" value="<?= $edit_data['role_id'] ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Role Name *</label>
                        <input type="text" name="role_name" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['role_name'] ?? '') ?>" placeholder="e.g. Doctor / Receptionist" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Status *</label>
                        <select name="status" class="form-select form-select-sm" required>
                            <option value="1" <?= (!isset($edit_data['status']) || (int)$edit_data['status'] === 1) ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= (isset($edit_data['status']) && (int)$edit_data['status'] === 0) ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-custom btn-sm flex-grow-1">
                            <i class="bi <?= $edit_data ? 'bi-check-lg' : 'bi-save' ?> me-1"></i> <?= $edit_data ? 'Update' : 'Save' ?>
                        </button>
                        <?php if ($edit_data): ?>
                            <a href="master_role.php" class="btn btn-light btn-sm border">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-7 col-xl-6">
            <div class="card-custom">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold text-dark m-0"><i class="bi bi-list-stars text-primary me-2"></i>Role Directory</h6>
                    <span class="badge bg-light text-secondary border"><?= count($roles) ?> Roles</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-compact">
                        <thead>
                            <tr>
                                <th style="width: 45px;">#</th>
                                <th>Role Name</th>
                                <th class="text-end" style="width: 100px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($roles)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">No roles found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($roles as $r): ?>
                                    <tr>
                                        <td class="text-muted fw-bold"><?= $r['role_id'] ?></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($r['role_name']) ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex align-items-center gap-2">
                                                <a href="master_role.php?edit=<?= $r['role_id'] ?>" class="btn-action-edit" title="Edit">
                                                    <i class="bi bi-pencil-fill" style="font-size: 0.75rem;"></i>
                                                </a>
                                                <div class="form-check form-switch m-0" title="Toggle Status (Active/Inactive)">
                                                    <input class="form-check-input" type="checkbox" role="switch" 
                                                           <?= (int)($r['status'] ?? 1) === 1 ? 'checked' : '' ?> 
                                                           onchange="window.location.href='master_role.php?toggle_status=<?= $r['role_id'] ?>&current=<?= $r['status'] ?? 1 ?>'">
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

<?php require_once __DIR__ . '/layout_footer.php'; ?>