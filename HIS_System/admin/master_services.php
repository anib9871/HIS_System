<?php
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Service Catalog Master";

// Add / Edit Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_service'])) {
    $service_code = trim($_POST['service_code'] ?? '');
    $service_name = trim($_POST['service_name'] ?? '');
    $service_type = $_POST['service_type'] ?? 'OPD_CONSULTATION';
    $doctor_id    = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;
    $default_rate = (float)($_POST['default_rate'] ?? 0);
    $tax_percent  = (float)($_POST['tax_percent'] ?? 0);
    $edit_id      = (int)($_POST['service_edit_id'] ?? 0);

    if (empty($service_code) || empty($service_name)) {
        set_flash_err("Service Code and Service Name are required fields.");
    } else {
        try {
            if ($edit_id > 0) {
                $stmt = $tenant_pdo->prepare("UPDATE master_services SET service_code = ?, service_name = ?, service_type = ?, doctor_id = ?, default_rate = ?, tax_percent = ? WHERE id = ?");
                $stmt->execute([$service_code, $service_name, $service_type, $doctor_id, $default_rate, $tax_percent, $edit_id]);
                set_flash_msg("Service updated successfully.");
            } else {
                $stmt = $tenant_pdo->prepare("INSERT INTO master_services (service_code, service_name, service_type, doctor_id, default_rate, tax_percent) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$service_code, $service_name, $service_type, $doctor_id, $default_rate, $tax_percent]);
                $new_service_id = $tenant_pdo->lastInsertId();

                // Auto General Cash tariff creation
                $stmt_tariff = $tenant_pdo->prepare("INSERT INTO master_tariffs (tariff_category, service_id, doctor_id, opd_rate, ipd_rate, emergency_rate) VALUES ('General Cash', ?, ?, ?, ?, ?)");
                $stmt_tariff->execute([$new_service_id, $doctor_id, $default_rate, $default_rate, $default_rate]);

                set_flash_msg("New service added to catalog successfully.");
            }
            header("Location: master_services.php");
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}

$doctors  = $tenant_pdo->query("SELECT id, doc_code, full_name FROM master_doctors ORDER BY full_name ASC")->fetchAll();
$services = $tenant_pdo->query("SELECT s.*, d.full_name as doctor_name FROM master_services s LEFT JOIN master_doctors d ON s.doctor_id = d.id ORDER BY s.id DESC")->fetchAll();

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-tag text-primary me-2"></i>Add / Edit Service</h6>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <input type="hidden" name="action_service" value="1">
                    <input type="hidden" name="service_edit_id" id="service_edit_id" value="0">

                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Service Code *</label>
                        <input type="text" name="service_code" id="service_code" class="form-control form-control-sm" placeholder="e.g. SRV-OPD-01" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Service Type *</label>
                        <select name="service_type" id="service_type" class="form-select form-select-sm">
                            <option value="OPD_CONSULTATION">OPD Consultation</option>
                            <option value="IPD_VISIT">IPD Doctor Visit</option>
                            <option value="EMERGENCY_VISIT">Emergency Visit</option>
                            <option value="PROCEDURE">Procedure / Nursing</option>
                            <option value="INVESTIGATION">Laboratory / Investigation</option>
                            <option value="ROOM_RENT">Room Rent</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Service Name *</label>
                        <input type="text" name="service_name" id="service_name" class="form-control form-control-sm" placeholder="e.g. General Consultation" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Assigned Doctor (Optional)</label>
                        <select name="doctor_id" id="service_doctor_id" class="form-select form-select-sm">
                            <option value="">-- None (General Hospital Service) --</option>
                            <?php foreach ($doctors as $doc): ?>
                                <option value="<?= $doc['id'] ?>"><?= htmlspecialchars($doc['full_name']) ?> (<?= htmlspecialchars($doc['doc_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Base Rate (₹) *</label>
                            <input type="number" step="0.01" name="default_rate" id="default_rate" class="form-control form-control-sm" placeholder="500.00" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Tax %</label>
                            <input type="number" step="0.01" name="tax_percent" id="tax_percent" class="form-control form-control-sm" value="0.00">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill" id="btnServiceSubmit"><i class="bi bi-check-circle me-1"></i> Save Service</button>
                        <button type="reset" class="btn btn-light btn-sm border" onclick="resetServiceForm()">Clear</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-list-check text-primary me-2"></i>Service Catalog</h6>
                <span class="badge bg-light text-dark border"><?= count($services) ?> Services</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Service Details</th>
                                <th>Category Type</th>
                                <th>Doctor</th>
                                <th>Rate</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($services)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No services found in catalog.</td></tr>
                            <?php else: ?>
                                <?php foreach ($services as $srv): ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($srv['service_code']) ?></span></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($srv['service_name']) ?></td>
                                        <td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($srv['service_type']) ?></span></td>
                                        <td><small><?= htmlspecialchars($srv['doctor_name'] ?? 'General') ?></small></td>
                                        <td class="fw-semibold text-primary">₹<?= number_format($srv['default_rate'], 2) ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick='editService(<?= json_encode($srv) ?>)'><i class="bi bi-pencil"></i></button>
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
function editService(s) {
    document.getElementById('service_edit_id').value = s.id;
    document.getElementById('service_code').value = s.service_code;
    document.getElementById('service_name').value = s.service_name;
    document.getElementById('service_type').value = s.service_type;
    document.getElementById('service_doctor_id').value = s.doctor_id || '';
    document.getElementById('default_rate').value = s.default_rate;
    document.getElementById('tax_percent').value = s.tax_percent || '0.00';
    document.getElementById('btnServiceSubmit').innerHTML = '<i class="bi bi-check-circle me-1"></i> Update Service';
}

function resetServiceForm() {
    document.getElementById('service_edit_id').value = '0';
    document.getElementById('btnServiceSubmit').innerHTML = '<i class="bi bi-check-circle me-1"></i> Save Service';
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>