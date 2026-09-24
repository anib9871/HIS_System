<?php
// admin/org_profile.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$page_title = "Organization Profile";

require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

// 1. Ensure table and columns exist safely
try {
    $tenant_pdo->exec("
        CREATE TABLE IF NOT EXISTS org_profile (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_name VARCHAR(150) NOT NULL,
            mnemonic VARCHAR(4) NULL,
            tagline VARCHAR(255) NULL,
            email VARCHAR(100) NULL,
            phone VARCHAR(30) NULL,
            address TEXT NULL,
            city VARCHAR(100) NULL,
            state VARCHAR(100) NULL,
            pincode VARCHAR(20) NULL,
            gst_no VARCHAR(30) NULL,
            status TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");
    $tenant_pdo->exec("ALTER TABLE org_profile ADD COLUMN IF NOT EXISTS mnemonic VARCHAR(4) NULL AFTER org_name;");
    $tenant_pdo->exec("ALTER TABLE org_profile ADD COLUMN IF NOT EXISTS gst_no VARCHAR(30) NULL AFTER pincode;");
} catch (Exception $e) {}

// 2. Handle Form Submission & Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_org'])) {
    $org_name = trim($_POST['org_name'] ?? '');
    $mnemonic = strtoupper(trim($_POST['mnemonic'] ?? ''));
    $tagline  = trim($_POST['tagline'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $city     = trim($_POST['city'] ?? '');
    $state    = trim($_POST['state'] ?? '');
    $pincode  = trim($_POST['pincode'] ?? '');
    $gst_no   = trim($_POST['gst_no'] ?? '');

    if (empty($org_name)) {
        set_flash_err("Hospital / Organization Name is required.");
    } elseif (mb_strlen($mnemonic) > 4) {
        set_flash_err("Mnemonic code cannot exceed 4 characters.");
    } else {
        try {
            $chk = $tenant_pdo->query("SELECT COUNT(*) FROM org_profile WHERE id = 1")->fetchColumn();
            
            if ($chk > 0) {
                $stmt = $tenant_pdo->prepare("
                    UPDATE org_profile 
                    SET org_name = ?, mnemonic = ?, tagline = ?, email = ?, phone = ?, address = ?, city = ?, state = ?, pincode = ?, gst_no = ?
                    WHERE id = 1
                ");
                $stmt->execute([$org_name, $mnemonic, $tagline, $email, $phone, $address, $city, $state, $pincode, $gst_no]);
            } else {
                $stmt = $tenant_pdo->prepare("
                    INSERT INTO org_profile (id, org_name, mnemonic, tagline, email, phone, address, city, state, pincode, gst_no)
                    VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$org_name, $mnemonic, $tagline, $email, $phone, $address, $city, $state, $pincode, $gst_no]);
            }
            
            $_SESSION['org_name'] = $org_name;
            $_SESSION['mnemonic'] = $mnemonic;

            set_flash_msg("Hospital profile details successfully updated!");
            header("Location: org_profile.php");
            exit;
        } catch (PDOException $e) {
            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}

// 3. Fetch Current Tenant Profile Data
$org = $tenant_pdo->query("SELECT * FROM org_profile WHERE id = 1")->fetch() ?: [];

$val_org_name = $org['org_name'] ?? ($_SESSION['org_name'] ?? '');
$val_mnemonic = $org['mnemonic'] ?? ($_SESSION['mnemonic'] ?? '');

require_once __DIR__ . '/layout_header.php';
?>

<div class="row justify-content-center">
    <div class="col-xl-9 col-lg-10">
        <div class="card border-0 shadow-sm rounded-3">
            
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark small d-flex align-items-center gap-2">
                    <i class="bi bi-hospital text-primary fs-6"></i>
                    <span>Hospital / Facility Master Configuration</span>
                </span>
                <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.75rem;">
                    Config ID: #01
                </span>
            </div>

            <div class="card-body p-3">
                <form method="POST" action="" onsubmit="return validateMnemonicForm()">
                    <input type="hidden" name="save_org" value="1">

                    <div class="p-3 mb-3 rounded-2 bg-light border">
                        <div class="d-flex align-items-center gap-2 mb-2 pb-1 border-bottom">
                            <i class="bi bi-info-circle-fill text-primary" style="font-size: 0.85rem;"></i>
                            <span class="text-uppercase fw-bold text-secondary" style="font-size: 0.72rem; letter-spacing: 0.5px;">Identity & Registration</span>
                        </div>
                        
                        <div class="row g-2">
                            <div class="col-md-8">
                                <label class="form-label small fw-semibold text-secondary mb-1">
                                    Organization / Hospital Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="org_name" class="form-control form-control-sm fw-bold" 
                                       value="<?= htmlspecialchars($val_org_name) ?>" 
                                       placeholder="e.g. City Care Multispeciality Hospital" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold text-secondary mb-1">
                                    Mnemonic / Code <span class="text-muted font-monospace" style="font-size: 0.7rem;">(Max 4 Chars)</span>
                                </label>
                                <input type="text" name="mnemonic" id="mnemonic_input" class="form-control form-control-sm fw-bold text-primary font-monospace" 
                                       value="<?= htmlspecialchars($val_mnemonic) ?>" 
                                       placeholder="e.g. MAX" oninput="checkMnemonicLength(this)">
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">Tagline / Slogan</label>
                            <input type="text" name="tagline" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($org['tagline'] ?? '') ?>" 
                                   placeholder="e.g. Caring for life, always">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Official Email</label>
                            <input type="email" name="email" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($org['email'] ?? '') ?>" 
                                   placeholder="contact@hospital.com">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Emergency / Phone</label>
                            <input type="text" name="phone" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($org['phone'] ?? '') ?>" 
                                   placeholder="+91 9876543210">
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold text-secondary mb-1">Complete Facility Address</label>
                            <input type="text" name="address" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($org['address'] ?? '') ?>" 
                                   placeholder="Building, Plot / Sector, Landmark, Street Area">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">City</label>
                            <input type="text" name="city" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($org['city'] ?? '') ?>" 
                                   placeholder="e.g. Lucknow">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">State</label>
                            <input type="text" name="state" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($org['state'] ?? '') ?>" 
                                   placeholder="e.g. Uttar Pradesh">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold text-secondary mb-1">Pincode</label>
                            <input type="text" name="pincode" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($org['pincode'] ?? '') ?>" 
                                   placeholder="226001" maxlength="10">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">GSTIN / Reg No. (Optional)</label>
                            <input type="text" name="gst_no" class="form-control form-control-sm font-monospace" 
                                   value="<?= htmlspecialchars($org['gst_no'] ?? '') ?>" 
                                   placeholder="09AAAAA0000A1Z5">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                        <button type="reset" class="btn btn-light btn-sm border px-3">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm px-4 fw-semibold shadow-sm">
                            <i class="bi bi-check2-circle me-1"></i> Save Changes
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

<script>
function checkMnemonicLength(input) {
    input.value = input.value.toUpperCase();
    if (input.value.length > 4) {
        alert("Warning: Mnemonic code cannot exceed 4 characters!");
        input.value = input.value.substring(0, 4);
    }
}

function validateMnemonicForm() {
    let mnemonic = document.getElementById('mnemonic_input').value;
    if (mnemonic.length > 4) {
        alert("Error: Mnemonic code must be 4 characters or less.");
        return false;
    }
    return true;
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>