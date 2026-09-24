<?php
// admin/users.php
$page_title = "Staff Users & Access Control";
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

// Hierarchical Modules / Sub-menus Matrix (Sidebar Structure jaisa)
$module_hierarchy = [
    'masters' => [
        'title'    => 'Hospital Masters',
        'icon'     => 'bi-gear-wide-connected',
        'submenus' => [
            'masters_org'     => ['name' => 'Hospital Profile Setup', 'desc' => 'View & edit clinic info'],
            'masters_centers' => ['name' => 'Centers & Branches', 'desc' => 'Manage hospital branches/wings'],
            'masters_users'   => ['name' => 'Staff & Access Control', 'desc' => 'Manage staff accounts & rights']
        ]
    ],
    'opd' => [
        'title'    => 'OPD Services Module',
        'icon'     => 'bi-person-wheelchair',
        'submenus' => [
            'opd_reg'     => ['name' => 'OPD Registration & Token', 'desc' => 'Patient entry & token generation'],
            'opd_queue'   => ['name' => 'Patient Live Queue', 'desc' => 'Real-time token display screen'],
            'opd_doctor'  => ['name' => 'Doctor Desk (Consultation & Rx)', 'desc' => 'Doctor diagnosis & prescription'],
            'opd_billing' => ['name' => 'OPD Billing & Invoices', 'desc' => 'Counter fee receipt & print']
        ]
    ]
];

// Flat key-to-name lookup for directory table
$flat_submenus = [
    'dashboard'       => 'Dashboard',
    'masters_org'     => 'Hospital Profile',
    'masters_centers' => 'Branches',
    'masters_users'   => 'Staff Control',
    'opd_reg'         => 'OPD Reg',
    'opd_queue'       => 'OPD Queue',
    'opd_doctor'      => 'Doctor Desk',
    'opd_billing'     => 'OPD Billing'
];

// 1. Status Toggle via URL
if (isset($_GET['toggle_status'])) {
    $toggle_id = (int)$_GET['toggle_status'];
    $current   = (int)($_GET['current'] ?? 1);
    $new_st    = ($current === 1) ? 0 : 1;

    try {
        $stmt = $tenant_pdo->prepare("UPDATE tenant_users SET status = ? WHERE user_id = ?");
        $stmt->execute([$new_st, $toggle_id]);
        set_flash_msg("User status update ho gaya!");
    } catch (PDOException $e) {
        set_flash_err("Status update failed: " . $e->getMessage());
    }
    header("Location: users.php");
    exit;
}

// 2. Fetch Edit User Data
$edit_data = null;
$user_assigned_menus = [];
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $tenant_pdo->prepare("SELECT * FROM tenant_users WHERE user_id = ?");
    $stmt->execute([$edit_id]);
    $edit_data = $stmt->fetch();
    if ($edit_data && !empty($edit_data['menu_access'])) {
        $user_assigned_menus = json_decode($edit_data['menu_access'], true) ?: [];
    }
}

// 3. Create or Update Staff User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $fullname  = trim($_POST['fullname'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $password  = trim($_POST['password'] ?? '');
    $role_id   = (int)($_POST['role_id'] ?? 0);
    $center_id = (int)($_POST['center_id'] ?? 1);
    $mobile    = trim($_POST['mobile'] ?? '');
    $is_admin  = isset($_POST['is_admin']) ? 1 : 0;
    $status    = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    // Selected Menus Array to JSON (Always include 'dashboard')
    $raw_menus = $_POST['menu_access'] ?? [];
    if (!in_array('dashboard', $raw_menus)) {
        $raw_menus[] = 'dashboard';
    }
    $selected_menus = json_encode(array_values($raw_menus));

    if (empty($fullname) || empty($username) || empty($role_id)) {
        set_flash_err("Mandatory fields bharna zaroori hai!");
    } else {
        if ($_POST['action'] === 'save_user') {
            if (empty($password)) {
                set_flash_err("Password daalna zaroori hai!");
            } else {
                try {
                    $stmt = $tenant_pdo->prepare("
                        INSERT INTO tenant_users (center_id, role_id, fullname, username, password, mobile, menu_access, is_admin, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$center_id, $role_id, $fullname, $username, $password, $mobile, $selected_menus, $is_admin, $status]);
                    set_flash_msg("Staff user successfully ban gaya!");
                    header("Location: users.php");
                    exit;
                } catch (PDOException $e) {
                    set_flash_err("Error: Username pehle se maujood ho sakta hai.");
                }
            }
        } elseif ($_POST['action'] === 'update_user') {
            $user_id = (int)$_POST['user_id'];
            try {
                if (!empty($password)) {
                    $stmt = $tenant_pdo->prepare("
                        UPDATE tenant_users 
                        SET center_id = ?, role_id = ?, fullname = ?, username = ?, password = ?, mobile = ?, menu_access = ?, is_admin = ?, status = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$center_id, $role_id, $fullname, $username, $password, $mobile, $selected_menus, $is_admin, $status, $user_id]);
                } else {
                    $stmt = $tenant_pdo->prepare("
                        UPDATE tenant_users 
                        SET center_id = ?, role_id = ?, fullname = ?, username = ?, mobile = ?, menu_access = ?, is_admin = ?, status = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$center_id, $role_id, $fullname, $username, $mobile, $selected_menus, $is_admin, $status, $user_id]);
                }
                set_flash_msg("User permissions update ho gayi!");
                header("Location: users.php");
                exit;
            } catch (PDOException $e) {
                set_flash_err("Update failed: " . $e->getMessage());
            }
        }
    }
}

// Fetch Roles, Centers & All Users
$roles = $tenant_pdo->query("SELECT * FROM tenant_roles WHERE status = 1")->fetchAll();
$centers = $tenant_pdo->query("SELECT * FROM master_centers WHERE status = 1")->fetchAll();
$users = $tenant_pdo->query("
    SELECT u.*, r.role_name, c.center_name 
    FROM tenant_users u 
    LEFT JOIN tenant_roles r ON u.role_id = r.role_id 
    LEFT JOIN master_centers c ON u.center_id = c.center_id
    ORDER BY u.user_id DESC
")->fetchAll();

require_once __DIR__ . '/layout_header.php';
?>

<style>
.form-switch .form-check-input { width: 2.2em; height: 1.15em; cursor: pointer; }
.btn-action-edit { background-color: #e0f2fe; color: #0284c7; border: none; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; transition: 0.2s; text-decoration: none; }
.btn-action-edit:hover { background-color: #0284c7; color: #fff; }
.badge-axis { font-size: 0.72rem; padding: 3px 6px; margin: 2px; border-radius: 4px; background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; display: inline-block; }

/* Accordion Sidebar-style Cards */
.accordion-module-card {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-bottom: 10px;
    background: #ffffff;
    overflow: hidden;
}
.accordion-module-header {
    background: #f8fafc;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    user-select: none;
    border-bottom: 1px solid transparent;
    transition: background 0.2s;
}
.accordion-module-header:hover {
    background: #f1f5f9;
}
.accordion-module-header.active {
    border-bottom-color: #e2e8f0;
    background: #eef2ff;
}
.accordion-arrow {
    transition: transform 0.2s ease;
}
.accordion-arrow.rotated {
    transform: rotate(180deg);
}
.sub-menu-row {
    padding: 9px 14px;
    border-bottom: 1px dashed #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.sub-menu-row:last-child {
    border-bottom: none;
}
</style>

<div class="container-fluid p-0">
    <div class="row g-4">
        <div class="col-xl-5 col-lg-5">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="bi <?= $edit_data ? 'bi-pencil-square text-primary' : 'bi-person-plus-fill text-primary' ?> me-2"></i>
                        <?= $edit_data ? 'Edit User & Permissions' : 'Create New User / Doctor' ?>
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="users.php">
                        <input type="hidden" name="action" value="<?= $edit_data ? 'update_user' : 'save_user' ?>">
                        <?php if ($edit_data): ?>
                            <input type="hidden" name="user_id" value="<?= $edit_data['user_id'] ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Full Name *</label>
                            <input type="text" name="fullname" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['fullname'] ?? '') ?>" placeholder="Dr. Sameer Khan / Reena Staff" required>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
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
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Branch Center</label>
                                <select name="center_id" class="form-select form-select-sm">
                                    <?php foreach ($centers as $c): ?>
                                        <option value="<?= $c['center_id'] ?>" <?= (isset($edit_data['center_id']) && $edit_data['center_id'] == $c['center_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['center_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Username / Email *</label>
                                <input type="text" name="username" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['username'] ?? '') ?>" placeholder="user@clinic.com" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold"><?= $edit_data ? 'Change Password' : 'Password *' ?></label>
                                <input type="password" name="password" class="form-control form-control-sm" placeholder="<?= $edit_data ? 'Leave blank to keep same' : '1234' ?>" <?= $edit_data ? '' : 'required' ?>>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Mobile No.</label>
                                <input type="text" name="mobile" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['mobile'] ?? '') ?>" placeholder="9876543210">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Status *</label>
                                <select name="status" class="form-select form-select-sm" required>
                                    <option value="1" <?= (!isset($edit_data['status']) || (int)$edit_data['status'] === 1) ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= (isset($edit_data['status']) && (int)$edit_data['status'] === 0) ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="p-2 mb-3 bg-danger bg-opacity-10 border border-danger-subtle rounded-3 d-flex align-items-center justify-content-between">
                            <div>
                                <span class="small fw-bold text-danger"><i class="bi bi-shield-check me-1"></i> Full Hospital Admin Axis</span>
                                <div class="text-muted" style="font-size: 0.72rem;">Grant all permissions without restrictions</div>
                            </div>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" name="is_admin" value="1" id="is_admin_flag" <?= (!empty($edit_data['is_admin']) && $edit_data['is_admin'] == 1) ? 'checked' : '' ?> onchange="handleAdminToggle(this.checked)">
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label small fw-bold text-dark m-0">
                                    <i class="bi bi-diagram-3-fill text-primary me-1"></i> Menu & Sub-Menu Permissions:
                                </label>
                                <div class="form-check form-switch m-0 d-flex align-items-center gap-2">
                                    <label class="form-check-label small fw-semibold text-primary m-0" for="masterToggle" style="font-size: 0.75rem;">Toggle All</label>
                                    <input class="form-check-input" type="checkbox" id="masterToggle" onchange="toggleAllMenus(this.checked)">
                                </div>
                            </div>

                            <div class="p-2 bg-light border rounded-3" style="max-height: 320px; overflow-y: auto;">
                                <?php foreach ($module_hierarchy as $parent_key => $parent_val): ?>
                                    <div class="accordion-module-card">
                                        <div class="accordion-module-header active" onclick="toggleAccordion('<?= $parent_key ?>_body', '<?= $parent_key ?>_arrow', this)">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi <?= $parent_val['icon'] ?> text-primary fs-5"></i>
                                                <span class="fw-bold text-dark small"><?= $parent_val['title'] ?></span>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-light text-muted border small" style="font-size: 0.68rem;"><?= count($parent_val['submenus']) ?> Menus</span>
                                                <i class="bi bi-chevron-down accordion-arrow rotated fs-6 text-muted" id="<?= $parent_key ?>_arrow"></i>
                                            </div>
                                        </div>

                                        <div id="<?= $parent_key ?>_body" class="accordion-module-body" style="display: block;">
                                            <?php foreach ($parent_val['submenus'] as $sub_key => $sub_val): ?>
                                                <?php 
                                                    $checked = false;
                                                    if ($edit_data) {
                                                        $checked = in_array($sub_key, $user_assigned_menus);
                                                    } else {
                                                        $checked = in_array($sub_key, ['opd_reg']);
                                                    }
                                                ?>
                                                <div class="sub-menu-row">
                                                    <div>
                                                        <div class="fw-semibold text-dark small" style="line-height: 1.1;"><?= $sub_val['name'] ?></div>
                                                        <small class="text-muted" style="font-size: 0.7rem;"><?= $sub_val['desc'] ?></small>
                                                    </div>
                                                    <div class="form-check form-switch m-0">
                                                        <input class="form-check-input menu-toggle-switch" type="checkbox" name="menu_access[]" value="<?= $sub_key ?>" id="m_<?= $sub_key ?>" <?= $checked ? 'checked' : '' ?>>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm flex-grow-1 fw-semibold">
                                <i class="bi <?= $edit_data ? 'bi-check-lg' : 'bi-save' ?> me-1"></i> <?= $edit_data ? 'Update Axis & User' : 'Save Staff User' ?>
                            </button>
                            <?php if ($edit_data): ?>
                                <a href="users.php" class="btn btn-light btn-sm border">Cancel</a>
                            <?php else: ?>
                                <button type="reset" class="btn btn-light btn-sm border">Reset</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-7 col-lg-7">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center gap-3 bg-white rounded-top-3">
                    <h6 class="fw-bold text-dark m-0"><i class="bi bi-people-fill text-primary me-2"></i>Staff & Access Directory</h6>
                    <input type="text" id="searchUserInput" class="form-control form-control-sm w-50" placeholder="Search by name, role or username...">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="userTable">
                        <thead class="table-light">
                            <tr>
                                <th>User</th>
                                <th>Role & Branch</th>
                                <th>Assigned Sub-Menus</th>
                                <th>Status</th>
                                <th class="text-center" style="width: 100px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No staff users created yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                    <?php 
                                        $u_menus = json_decode($u['menu_access'] ?? '[]', true) ?: [];
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($u['fullname']) ?></div>
                                            <div class="small text-muted"><code><?= htmlspecialchars($u['username']) ?></code></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= htmlspecialchars($u['role_name'] ?? 'N/A') ?></span>
                                            <div class="small text-muted mt-1"><?= htmlspecialchars($u['center_name'] ?? 'Main') ?></div>
                                        </td>
                                        <td>
                                            <?php if ($u['is_admin'] == 1): ?>
                                                <span class="badge bg-danger">Full Admin Axis</span>
                                            <?php else: ?>
                                                <div style="max-width: 250px;">
                                                    <?php foreach ($u_menus as $m_key): ?>
                                                        <span class="badge-axis"><?= $flat_submenus[$m_key] ?? $m_key ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= (int)$u['status'] === 1 ? 'bg-success' : 'bg-danger' ?>">
                                                <?= (int)$u['status'] === 1 ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex align-items-center justify-content-center gap-2">
                                                <a href="users.php?edit=<?= $u['user_id'] ?>" class="btn-action-edit" title="Edit Axis">
                                                    <i class="bi bi-pencil-fill" style="font-size: 0.85rem;"></i>
                                                </a>
                                                <div class="form-check form-switch m-0" title="Toggle Status">
                                                    <input class="form-check-input" type="checkbox" role="switch" 
                                                           <?= (int)$u['status'] === 1 ? 'checked' : '' ?> 
                                                           onchange="window.location.href='users.php?toggle_status=<?= $u['user_id'] ?>&current=<?= $u['status'] ?>'">
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
// Live Search Bar for Staff Directory
document.getElementById('searchUserInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#userTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

// Accordion Click Toggle (Opens/Closes Submenus like Sidebar)
function toggleAccordion(bodyId, arrowId, headerElem) {
    const body = document.getElementById(bodyId);
    const arrow = document.getElementById(arrowId);
    if (body.style.display === 'none') {
        body.style.display = 'block';
        arrow.classList.add('rotated');
        headerElem.classList.add('active');
    } else {
        body.style.display = 'none';
        arrow.classList.remove('rotated');
        headerElem.classList.remove('active');
    }
}

// Master Toggle Switch (Select / Deselect All)
function toggleAllMenus(isChecked) {
    document.querySelectorAll('.menu-toggle-switch').forEach(toggle => {
        toggle.checked = isChecked;
    });
}

// When Full Admin toggle is turned on -> All menu toggles activate
function handleAdminToggle(isAdminChecked) {
    if (isAdminChecked) {
        document.getElementById('masterToggle').checked = true;
        toggleAllMenus(true);
    }
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>