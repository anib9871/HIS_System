<?php
// superadmin/subscription_plans.php
require_once __DIR__ . '/../config/master_db.php';
require_once __DIR__ . '/../config/alerts.php';

// 1. Status Toggle
if (isset($_GET['toggle_status'])) {
    $toggle_id = (int)$_GET['toggle_status'];
    $current   = (int)($_GET['current'] ?? 1);
    $new_st    = ($current === 1) ? 0 : 1;

    try {
        $stmt = $master_pdo->prepare("UPDATE subscription_plans SET status = ? WHERE plan_id = ?");
        $stmt->execute([$new_st, $toggle_id]);
        set_flash_msg("Plan status updated successfully!");
    } catch (PDOException $e) {
        set_flash_err("Status update failed: " . $e->getMessage());
    }
    header("Location: subscription_plans.php");
    exit;
}

// 2. Fetch Edit Data
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $master_pdo->prepare("SELECT * FROM subscription_plans WHERE plan_id = ?");
    $stmt->execute([$edit_id]);
    $edit_data = $stmt->fetch();
}

// 3. Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $plan_name     = trim($_POST['plan_name'] ?? '');
    $plan_type     = (int)($_POST['plan_type'] ?? 1); // Strictly INT (1, 2, 3, 4)
    $duration_days = (int)($_POST['duration_days'] ?? 30);
    $max_users     = (int)($_POST['max_users'] ?? 5);
    $price         = (float)($_POST['price'] ?? 0.00);
    $status        = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    if (empty($plan_name)) {
        set_flash_err("Plan Name is required!");
    } else {
        if ($_POST['action'] === 'save_plan') {
            try {
                $stmt = $master_pdo->prepare("
                    INSERT INTO subscription_plans (plan_name, plan_type, duration_days, max_users, price, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$plan_name, $plan_type, $duration_days, $max_users, $price, $status]);
                set_flash_msg("Subscription Plan '{$plan_name}' added successfully!");
                header("Location: subscription_plans.php");
                exit;
            } catch (PDOException $e) {
                set_flash_err("Error: " . $e->getMessage());
            }
        } elseif ($_POST['action'] === 'update_plan') {
            $plan_id = (int)$_POST['plan_id'];
            try {
                $stmt = $master_pdo->prepare("
                    UPDATE subscription_plans 
                    SET plan_name = ?, plan_type = ?, duration_days = ?, max_users = ?, price = ?, status = ?
                    WHERE plan_id = ?
                ");
                $stmt->execute([$plan_name, $plan_type, $duration_days, $max_users, $price, $status, $plan_id]);
                set_flash_msg("Subscription Plan '{$plan_name}' updated successfully!");
                header("Location: subscription_plans.php");
                exit;
            } catch (PDOException $e) {
                set_flash_err("Update failed: " . $e->getMessage());
            }
        }
    }
}

$plan_types_map = [
    1 => 'Monthly',
    2 => 'Quarterly',
    3 => 'Half Yearly',
    4 => 'Annually'
];

$plans = $master_pdo->query("SELECT * FROM subscription_plans ORDER BY plan_id DESC")->fetchAll();
require_once __DIR__ . '/layout_header.php';
?>

<style>
.form-switch .form-check-input { width: 2.4em; height: 1.25em; cursor: pointer; }
.btn-action-edit { background-color: #e0f2fe; color: #0284c7; border: none; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; transition: 0.2s; }
.btn-action-edit:hover { background-color: #0284c7; color: #fff; }
</style>

<div class="container-fluid p-0">
    <div class="mb-3">
        <h4 class="fw-bold text-dark m-0">Subscription Plan</h4>
        <small class="text-muted">Define SaaS pricing packages for hospital organizations</small>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card-custom p-4">
                <h6 class="fw-bold text-dark mb-3">
                    <i class="bi <?= $edit_data ? 'bi-pencil-square text-primary' : 'bi-credit-card-2-front text-primary' ?> me-2"></i>
                    <?= $edit_data ? 'Edit Subscription Plan' : 'Add Subscription Plan' ?>
                </h6>
                <form method="POST" action="subscription_plans.php">
                    <input type="hidden" name="action" value="<?= $edit_data ? 'update_plan' : 'save_plan' ?>">
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="plan_id" value="<?= $edit_data['plan_id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Plan Name *</label>
                        <input type="text" name="plan_name" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['plan_name'] ?? '') ?>" placeholder="e.g. OPD Starter" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Plan Type *</label>
                        <select name="plan_type" class="form-select form-select-sm" required>
                            <option value="1" <?= (isset($edit_data['plan_type']) && (int)$edit_data['plan_type'] === 1) ? 'selected' : '' ?>>Monthly</option>
                            <option value="2" <?= (isset($edit_data['plan_type']) && (int)$edit_data['plan_type'] === 2) ? 'selected' : '' ?>>Quarterly</option>
                            <option value="3" <?= (isset($edit_data['plan_type']) && (int)$edit_data['plan_type'] === 3) ? 'selected' : '' ?>>Half Yearly</option>
                            <option value="4" <?= (isset($edit_data['plan_type']) && (int)$edit_data['plan_type'] === 4) ? 'selected' : '' ?>>Annually</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Duration (Days) *</label>
                        <input type="number" name="duration_days" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['duration_days'] ?? 30) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Max Users</label>
                        <input type="number" name="max_users" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['max_users'] ?? 5) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Price (INR) *</label>
                        <input type="number" step="0.01" name="price" class="form-control form-control-sm" value="<?= htmlspecialchars($edit_data['price'] ?? '') ?>" placeholder="1999.00" required>
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
                            <a href="subscription_plans.php" class="btn btn-light btn-sm border">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card-custom">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center gap-3">
                    <h6 class="fw-bold text-dark m-0"><i class="bi bi-tags text-primary me-2"></i>Subscription Plan Directory</h6>
                    <input type="text" id="searchPlanInput" class="form-control form-control-sm w-50" placeholder="Search plan name, duration, price...">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="planTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Plan Name</th>
                                <th>Plan Type</th>
                                <th>Duration</th>
                                <th>Price</th>
                                <th class="text-center" style="width: 120px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($plans)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No subscription plans found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($plans as $p): ?>
                                    <tr>
                                        <td><strong><?= $p['plan_id'] ?></strong></td>
                                        <td class="fw-semibold text-primary"><?= htmlspecialchars($p['plan_name']) ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?= $plan_types_map[$p['plan_type']] ?? 'Monthly' ?>
                                            </span>
                                        </td>
                                        <td><?= $p['duration_days'] ?> Days</td>
                                        <td class="fw-bold text-success">₹<?= number_format($p['price'], 2) ?></td>
                                        <td class="text-center">
                                            <div class="d-flex align-items-center justify-content-center gap-2">
                                                <a href="subscription_plans.php?edit=<?= $p['plan_id'] ?>" class="btn-action-edit" title="Edit">
                                                    <i class="bi bi-pencil-fill" style="font-size: 0.85rem;"></i>
                                                </a>
                                                <div class="form-check form-switch m-0" title="Toggle Status (Active/Inactive)">
                                                    <input class="form-check-input" type="checkbox" role="switch" 
                                                           <?= (int)$p['status'] === 1 ? 'checked' : '' ?> 
                                                           onchange="window.location.href='subscription_plans.php?toggle_status=<?= $p['plan_id'] ?>&current=<?= $p['status'] ?>'">
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
document.getElementById('searchPlanInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#planTable tbody tr');
    rows.forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>