<?php
// admin/master_city.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$page_title = "City Master";

require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);

// Auto-create table just in case
try {
    $tenant_pdo->exec("
        CREATE TABLE IF NOT EXISTS master_city (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL DEFAULT 1,
            center_id INT NOT NULL DEFAULT 1,
            state_id INT NOT NULL,
            city_name VARCHAR(150) NOT NULL,
            status TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");
} catch (Exception $e) {}

// ==========================================
// 1. AUTO-SEED TOP CITIES LOGIC
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seed_cities'])) {
    // List of major states and their top cities
    $top_cities_data = [
        'UTTAR PRADESH' => ['LUCKNOW', 'KANPUR', 'AGRA', 'VARANASI', 'PRAYAGRAJ', 'NOIDA', 'GHAZIABAD', 'GORAKHPUR', 'MEERUT', 'BAREILLY'],
        'DELHI' => ['NEW DELHI', 'SOUTH DELHI', 'EAST DELHI', 'NORTH DELHI'],
        'MAHARASHTRA' => ['MUMBAI', 'PUNE', 'NAGPUR', 'NASHIK', 'THANE', 'AURANGABAD'],
        'KARNATAKA' => ['BENGALURU', 'MYSURU', 'MANGALURU', 'HUBLI'],
        'GUJARAT' => ['AHMEDABAD', 'SURAT', 'VADODARA', 'RAJKOT', 'GANDHINAGAR'],
        'TELANGANA' => ['HYDERABAD', 'WARANGAL', 'NIZAMABAD'],
        'TAMIL NADU' => ['CHENNAI', 'COIMBATORE', 'MADURAI', 'SALEM'],
        'WEST BENGAL' => ['KOLKATA', 'HOWRAH', 'DARJEELING', 'ASANSOL'],
        'RAJASTHAN' => ['JAIPUR', 'JODHPUR', 'UDAIPUR', 'KOTA', 'AJMER'],
        'BIHAR' => ['PATNA', 'GAYA', 'MUZAFFARPUR', 'BHAGALPUR'],
        'MADHYA PRADESH' => ['BHOPAL', 'INDORE', 'GWALIOR', 'JABALPUR', 'UJJAIN'],
        'PUNJAB' => ['LUDHIANA', 'AMRITSAR', 'JALANDHAR', 'CHANDIGARH', 'PATIALA'],
        'HARYANA' => ['GURUGRAM', 'FARIDABAD', 'PANIPAT', 'AMBALA']
    ];
    
    $added_count = 0;
    try {
        foreach ($top_cities_data as $state_name => $city_list) {
            // Find the State ID for this state name
            $stmt = $tenant_pdo->prepare("SELECT id FROM master_states WHERE UPPER(state_name) = ? AND org_id = ? AND center_id = ? LIMIT 1");
            $stmt->execute([strtoupper($state_name), $org_id, $center_id]);
            $state_id = $stmt->fetchColumn();
            
            if ($state_id) {
                $chk_stmt = $tenant_pdo->prepare("SELECT id FROM master_city WHERE state_id = ? AND UPPER(city_name) = ? AND org_id = ? AND center_id = ?");
                $ins_stmt = $tenant_pdo->prepare("INSERT INTO master_city (org_id, center_id, state_id, city_name, status) VALUES (?, ?, ?, ?, 1)");
                
                foreach ($city_list as $city) {
                    $chk_stmt->execute([$state_id, strtoupper($city), $org_id, $center_id]);
                    if (!$chk_stmt->fetch()) { // Insert only if city doesn't exist
                        $ins_stmt->execute([$org_id, $center_id, $state_id, strtoupper($city)]);
                        $added_count++;
                    }
                }
            }
        }
        
        if ($added_count > 0) {
            set_flash_msg("{$added_count} Top Cities automatically added to your database!");
        } else {
            set_flash_msg("Cities already exist, or States are missing (Add States first).");
        }
    } catch (Exception $e) {
        set_flash_err("Auto-Seed Error: " . $e->getMessage());
    }
    header("Location: master_city.php");
    exit;
}

// ==========================================
// 2. HANDLE FORM SUBMISSION & UPDATE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_city'])) {
    $state_id  = (int)($_POST['state_id'] ?? 0);
    $city_name = strtoupper(trim($_POST['city_name'] ?? ''));
    $status    = isset($_POST['status']) ? 1 : 0;
    $edit_id   = (int)($_POST['edit_id'] ?? 0);

    if ($state_id === 0 || empty($city_name)) {
        set_flash_err("State select karna aur City name daalna zaroori hai.");
    } else {
        try {
            if ($edit_id > 0) {
                // Update existing city
                $stmt = $tenant_pdo->prepare("UPDATE master_city SET state_id = ?, city_name = ?, status = ? WHERE id = ? AND org_id = ? AND center_id = ?");
                $stmt->execute([$state_id, $city_name, $status, $edit_id, $org_id, $center_id]);
                set_flash_msg("City updated successfully.");
            } else {
                // Insert new city
                $chk = $tenant_pdo->prepare("SELECT id FROM master_city WHERE state_id = ? AND city_name = ? AND org_id = ? AND center_id = ?");
                $chk->execute([$state_id, $city_name, $org_id, $center_id]);
                
                if ($chk->fetch()) {
                    set_flash_err("Yeh city is state me pehle se added hai!");
                } else {
                    $stmt = $tenant_pdo->prepare("INSERT INTO master_city (org_id, center_id, state_id, city_name, status) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$org_id, $center_id, $state_id, $city_name, $status]);
                    set_flash_msg("Nayi City successfully add ho gayi.");
                }
            }
            header("Location: master_city.php");
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}

// 3. Toggle Status
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;
    $tenant_pdo->prepare("UPDATE master_city SET status = ? WHERE id = ? AND org_id = ? AND center_id = ?")->execute([$st, $id, $org_id, $center_id]);
    set_flash_msg("City status updated!");
    header("Location: master_city.php");
    exit;
}

// 4. Fetch States for Dropdown
$states = [];
try {
    $states_stmt = $tenant_pdo->prepare("SELECT id, state_name FROM master_states WHERE org_id = ? AND center_id = ? AND status = 1 ORDER BY state_name ASC");
    $states_stmt->execute([$org_id, $center_id]);
    $states = $states_stmt->fetchAll();
} catch (Exception $e) {}

// 5. Fetch Cities List with State Names via JOIN
$cities = [];
try {
    $cities_stmt = $tenant_pdo->prepare("
        SELECT c.*, s.state_name 
        FROM master_city c 
        LEFT JOIN master_states s ON c.state_id = s.id 
        WHERE c.org_id = ? AND c.center_id = ? 
        ORDER BY c.id DESC
    ");
    $cities_stmt->execute([$org_id, $center_id]);
    $cities = $cities_stmt->fetchAll();
} catch (Exception $e) {}

require_once __DIR__ . '/layout_header.php';
?>

<div class="row g-3">
    <!-- Form Card -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom">
                <span class="fw-bold text-dark" id="formTitle"><i class="bi bi-geo-alt text-primary me-2"></i> Add / Edit City</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="master_city.php">
                    <input type="hidden" name="action_city" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">Select State *</label>
                        <select name="state_id" id="state_id" class="form-select form-select-sm" required autofocus>
                            <option value="">-- Choose State --</option>
                            <?php foreach ($states as $s): ?>
                                <option value="<?= htmlspecialchars($s['id']) ?>"><?= htmlspecialchars($s['state_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary mb-1">City Name *</label>
                        <input type="text" name="city_name" id="city_name" class="form-control form-control-sm text-uppercase" placeholder="e.g. LUCKNOW" required>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="status" id="status" value="1" checked>
                        <label class="form-check-label small fw-semibold text-secondary" for="status">Active Status</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">
                            <i class="bi bi-save me-1"></i> Save City
                        </button>
                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">Reset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-bold text-dark"><i class="bi bi-list-ul text-primary me-2"></i> Cities List</span>
                
                <div class="d-flex align-items-center gap-2">
                    
                    <!-- NEW: AUTO-ADD TOP CITIES BUTTON -->
                    <form method="POST" action="master_city.php" class="m-0">
                        <input type="hidden" name="seed_cities" value="1">
                        <button type="submit" class="btn btn-sm btn-success py-0 px-2 fw-semibold shadow-sm" style="font-size: 0.8rem;" title="Automatically add Top Cities for existing States">
                            <i class="bi bi-magic me-1"></i> Auto-Add Top 50 Cities
                        </button>
                    </form>
                    
                    <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search City..." style="width: 180px;">
                    <span class="badge bg-light text-secondary border"><?= count($cities) ?> Cities</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 small" id="cityTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3" style="width: 60px;">#</th>
                                <th>City Name</th>
                                <th>State Name</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cities)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No cities found. Click "Auto-Add Top 50 Cities" to populate instantly!</td></tr>
                            <?php else: ?>
                                <?php foreach ($cities as $c): ?>
                                    <?php $is_act = (int)$c['status'] === 1; ?>
                                    <tr>
                                        <td class="ps-3 font-monospace text-muted">#<?= $c['id'] ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($c['city_name']) ?></td>
                                        <td><span class="badge bg-light text-secondary border"><?= htmlspecialchars($c['state_name'] ?? 'N/A') ?></span></td>
                                        <td>
                                            <div class="form-check form-switch m-0" title="Toggle Status">
                                                <input class="form-check-input" type="checkbox" role="switch" 
                                                       <?= $is_act ? 'checked' : '' ?> 
                                                       onchange="window.location.href='master_city.php?toggle_status=<?= $c['id'] ?>&st=<?= $is_act ? 1 : 0 ?>'">
                                            </div>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick='editRow(<?= json_encode($c) ?>)' title="Edit City">
                                                <i class="bi bi-pencil-fill" style="font-size: 0.85rem;"></i>
                                            </button>
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
function editRow(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('state_id').value = d.state_id;
    document.getElementById('city_name').value = d.city_name;
    document.getElementById('status').checked = (parseInt(d.status) === 1);
    
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Edit City';
    document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-check-lg me-1"></i> Update City';
    document.getElementById('city_name').focus();
}

function resetForm() {
    document.getElementById('edit_id').value = '0';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-geo-alt text-primary me-2"></i> Add / Edit City';
    document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-save me-1"></i> Save City';
    document.getElementById('state_id').focus();
}

document.getElementById('searchInput').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    let rows = document.querySelectorAll('#cityTable tbody tr');
    rows.forEach(row => {
        if(row.innerText.toLowerCase().includes(val)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>