<?php
// superadmin/master_user.php
require_once __DIR__ . '/../config/master_db.php';
require_once __DIR__ . '/../config/alerts.php';

// 1. Status Toggle
if (isset($_GET['toggle_status'])) {
    $toggle_id = (int)$_GET['toggle_status'];
    $current   = (int)($_GET['current'] ?? 1);
    $new_st    = ($current === 1) ? 0 : 1;

    try {
        $stmt = $master_pdo->prepare("UPDATE user_credentials SET status = ? WHERE user_id = ?");
        $stmt->execute([$new_st, $toggle_id]);
        set_flash_msg("User status updated successfully!");
    } catch (PDOException $e) {
        set_flash_err("Status update failed: " . $e->getMessage());
    }
    header("Location: master_user.php");
    exit;
}

// 2. Fetch Edit Data
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $master_pdo->prepare("SELECT * FROM user_credentials WHERE user_id = ?");
    $stmt->execute([$edit_id]);
    $edit_data = $stmt->fetch();
}

// 3. Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role_id  = (int)($_POST['role_id'] ?? 0);
    $org_id   = !empty($_POST['org_id']) ? (int)$_POST['org_id'] : null;
    $status   = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    if (empty($username) || empty($role_id)) {
        set_flash_err("Username and Role are required!");
    } else {
        if ($_POST['action'] === 'save_user') {
            if (empty($password)) {
                set_flash_err("Password is required for new user!");
            } else {
                try {
                    $stmt = $master_pdo->prepare("
                        INSERT INTO user_credentials (org_id, role_id, username, password, status)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$org_id, $role_id, $username, $password, $status]);
                    set_flash_msg("User '{$username}' created successfully!");
                    header("Location: master_user.php");
                    exit;
                } catch (PDOException $e) {
                    set_flash_err("Error: " . $e->getMessage());
                }
            }
        } elseif ($_POST['action'] === 'update_user') {
            $user_id = (int)$_POST['user_id'];
            try {
                if (!empty($password)) {
                    $stmt = $master_pdo->prepare("
                        UPDATE user_credentials 
                        SET org_id = ?, role_id = ?, username = ?, password = ?, status = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$org_id, $role_id, $username, $password, $status, $user_id]);
                } else {
                    $stmt = $master_pdo->prepare("
                        UPDATE user_credentials 
                        SET org_id = ?, role_id = ?, username = ?, status = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$org_id, $role_id, $username, $status, $user_id]);
                }
                set_flash_msg("User '{$username}' updated successfully!");
                header("Location: master_user.php");
                exit;
            } catch (PDOException $e) {
                set_flash_err("Update failed: " . $e->getMessage());
            }
        }
    }
}

// Dropdowns
$roles = $master_pdo->query("SELECT * FROM master_role ORDER BY role_id ASC")->fetchAll();
$orgs  = $master_pdo->query("SELECT org_id, org_name FROM master_organization ORDER BY org_name ASC")->fetchAll();

// Users list
$users = $master_pdo->query("
    SELECT u.*, r.role_name, o.org_name 
    FROM user_credentials u
    JOIN master_role r ON u.role_id = r.role_id
    LEFT JOIN master_organization o ON u.org_id = o.org_id
    ORDER BY u.user_id DESC
")->fetchAll();

require_once __DIR__ . '/layout_header.php';
?>

<style>
.form-switch .form-check-input { width: 2.4em; height: 1.25em; cursor: pointer; }
.btn-action-edit { background-color: #e0f2fe; color: #0284c7; border: none; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; transition: 0.2s; }
.btn-action-edit:hover { background-color: #0284c7; color: #fff; }
</style>

<div class="container-fluid p-0">
    <div class="mb-3">
        <h4 class="fw-bold text-dark m-0">Master User (User Credentials)</h4>
        <small class="text-muted">Create logins and assign organization access</small>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card-custom p-4">
                <h6 class="fw-bold text-dark mb-3">
                    <i class="bi <?= $edit_data ? 'bi-pencil-square text-primary' : 'bi-person-plus text-primary' ?> me-2"></i>
                    <?= $edit_data ? 'Edit Master User' : 'Add Master User' ?>
                </h6>
                <form method="POST" action="master_user.php">
                    <input type="hidden" name="action" value="<?= $edit_data ? 'update_user' : 'save_user' ?>">
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="user_id" value="<?= $edit_data['user_id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Username *</label>
                        <input type="text" name="username" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['username'] ?? '') ?>" placeholder="e.g. hospital_admin" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Password <?= $edit_data ? '<span class="text-muted fw-normal">(Leave blank to keep old)</span>' : '*' ?></label>
                        <input type="password" name="password" class="form-control form-control-sm" placeholder="<?= $edit_data ? '••••••••' : 'Enter password' ?>" <?= $edit_data ? '' : 'required' ?>>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Organization</label>
                        <select name="org_id" class="form-select form-select-sm">
                            <option value="">None (Superadmin Level)</option>
                            <?php foreach ($orgs as $o): ?>
                                <option value="<?= $o['org_id'] ?>" <?= (isset($edit_data['org_id']) && $edit_data['org_id'] == $o['org_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($o['org_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Role *</label>
                        <select name="role_id" class="form-select form-select-sm" required>
                            <option value="">Select Role</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r['role_id'] ?>" <?= (isset($edit_data['role_id']) && $edit_data['role_id'] == $r['role_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['role_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                            <i class="bi <?= $edit_data ? 'bi-check-lg' : 'bi-save' ?> me-1"></i> <?= $edit_data ? 'Update' : 'Save' ?>
                        </button>
                        <?php if ($edit_data): ?>
                            <a href="master_user.php" class="btn btn-light btn-sm border">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card-custom">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center gap-3">
                    <h6 class="fw-bold text-dark m-0"><i class="bi bi-people text-primary me-2"></i>User Credentials Directory</h6>
                    <input type="text" id="searchUserInput" class="form-control form-control-sm w-50" placeholder="Search username, role, org...">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="userTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Organization</th>
                                <th class="text-center" style="width: 120px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No users found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td><strong><?= $u['user_id'] ?></strong></td>
                                        <td class="fw-semibold text-primary"><code><?= htmlspecialchars($u['username']) ?></code></td>
                                        <td><span class="badge bg-primary-subtle text-primary border"><?= htmlspecialchars($u['role_name']) ?></span></td>
                                        <td><?= $u['org_name'] ? htmlspecialchars($u['org_name']) : '<span class="text-muted">Global / Master</span>' ?></td>
                                        <td class="text-center">
                                            <div class="d-flex align-items-center justify-content-center gap-2">
                                                <a href="master_user.php?edit=<?= $u['user_id'] ?>" class="btn-action-edit" title="Edit">
                                                    <i class="bi bi-pencil-fill" style="font-size: 0.85rem;"></i>
                                                </a>
                                                <div class="form-check form-switch m-0" title="Toggle Status (Active/Inactive)">
                                                    <input class="form-check-input" type="checkbox" role="switch" 
                                                           <?= (int)$u['status'] === 1 ? 'checked' : '' ?> 
                                                           onchange="window.location.href='master_user.php?toggle_status=<?= $u['user_id'] ?>&current=<?= $u['status'] ?>'">
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
document.getElementById('searchUserInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#userTable tbody tr');
    rows.forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>