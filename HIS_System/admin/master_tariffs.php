<?php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Tariff & Price Master";

// Add Tariff
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_tariff'])) {
    $tariff_category = trim($_POST['tariff_category'] ?? 'General Cash');
    $service_id      = (int)($_POST['service_id'] ?? 0);
    $opd_rate        = (float)($_POST['opd_rate'] ?? 0);
    $ipd_rate        = (float)($_POST['ipd_rate'] ?? 0);
    $emergency_rate  = (float)($_POST['emergency_rate'] ?? 0);
    $effective_from  = !empty($_POST['effective_from']) ? $_POST['effective_from'] : date('Y-m-d');

    if ($service_id > 0) {
        try {
            $stmt = $tenant_pdo->prepare("INSERT INTO master_tariffs (tariff_category, service_id, opd_rate, ipd_rate, emergency_rate, effective_from) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tariff_category, $service_id, $opd_rate, $ipd_rate, $emergency_rate, $effective_from]);
            set_flash_msg("Tariff rate saved successfully.");
            header("Location: master_tariffs.php");
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
    } else {
        set_flash_err("Please select a service.");
    }
}

// Delete Tariff
if (isset($_GET['del_tariff'])) {
    $del_id = (int)$_GET['del_tariff'];
    $tenant_pdo->prepare("DELETE FROM master_tariffs WHERE id = ?")->execute([$del_id]);
    set_flash_msg("Tariff record deleted successfully.");
    header("Location: master_tariffs.php");
    exit;
}

$services = $tenant_pdo->query("SELECT id, service_name, service_code, default_rate FROM master_services ORDER BY service_name ASC")->fetchAll();
$tariffs  = $tenant_pdo->query("SELECT t.*, s.service_name, s.service_code, s.service_type FROM master_tariffs t JOIN master_services s ON t.service_id = s.id ORDER BY t.id DESC")->fetchAll();

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-currency-rupee text-primary me-2"></i>Configure Tariff Rate</h6>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_tariff" value="1">
                    
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Tariff Category / Scheme *</label>
                        <select name="tariff_category" class="form-select form-select-sm">
                            <option value="General Cash">General Cash</option>
                            <option value="Ayushman Bharat">Ayushman Bharat (PMJAY)</option>
                            <option value="CGHS">CGHS</option>
                            <option value="Corporate / TPA">Corporate / TPA</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Select Service *</label>
                        <select name="service_id" id="tariff_service_select" class="form-select form-select-sm" required onchange="fillDefaultRate()">
                            <option value="">-- Select Service --</option>
                            <?php foreach ($services as $srv): ?>
                                <option value="<?= $srv['id'] ?>" data-rate="<?= $srv['default_rate'] ?>"><?= htmlspecialchars($srv['service_name']) ?> (<?= htmlspecialchars($srv['service_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <label class="form-label small fw-semibold">OPD Rate (₹)</label>
                            <input type="number" step="0.01" name="opd_rate" id="opd_rate" class="form-control form-control-sm" placeholder="0.00" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-semibold">IPD Rate (₹)</label>
                            <input type="number" step="0.01" name="ipd_rate" id="ipd_rate" class="form-control form-control-sm" placeholder="0.00">
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-semibold">Emergency (₹)</label>
                            <input type="number" step="0.01" name="emergency_rate" id="emergency_rate" class="form-control form-control-sm" placeholder="0.00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Effective Date</label>
                        <input type="date" name="effective_from" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-save me-1"></i> Save Tariff</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-receipt text-primary me-2"></i>Tariff / Price Matrix</h6>
                <span class="badge bg-light text-dark border"><?= count($tariffs) ?> Entries</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Category / Scheme</th>
                                <th>Service</th>
                                <th>OPD Price</th>
                                <th>IPD Price</th>
                                <th>Emergency</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tariffs)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No custom tariffs configured yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($tariffs as $t): ?>
                                    <tr>
                                        <td><span class="badge bg-info-subtle text-dark border"><?= htmlspecialchars($t['tariff_category']) ?></span></td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($t['service_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($t['service_code']) ?></small>
                                        </td>
                                        <td class="text-success fw-bold">₹<?= number_format($t['opd_rate'], 2) ?></td>
                                        <td>₹<?= number_format($t['ipd_rate'], 2) ?></td>
                                        <td>₹<?= number_format($t['emergency_rate'], 2) ?></td>
                                        <td class="text-end">
                                            <a href="?del_tariff=<?= $t['id'] ?>" onclick="return confirm('Are you sure you want to delete this tariff entry?')" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></a>
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
function fillDefaultRate() {
    let select = document.getElementById('tariff_service_select');
    let selectedOption = select.options[select.selectedIndex];
    let defaultRate = selectedOption.getAttribute('data-rate') || 0;
    document.getElementById('opd_rate').value = defaultRate;
    document.getElementById('ipd_rate').value = defaultRate;
    document.getElementById('emergency_rate').value = defaultRate;
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>