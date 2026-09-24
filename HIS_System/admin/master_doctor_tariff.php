<?php
// admin/master_doctor_tariff.php

require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';

$page_title = "Doctor Tariff Master";

$org_id    = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$user_id   = (int)($_SESSION['user_id'] ?? 1);

// =========================================================
// AJAX: FETCH LATEST TARIFF LIST / USED INSURANCES
// =========================================================
if (isset($_GET['ajax_tariffs'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $stmt = $tenant_pdo->prepare("
            SELECT
                t.id,
                t.doctor_id,
                t.service_id,
                t.category_id,
                t.insurance_id,
                t.rate,
                t.status,
                d.full_name,
                d.doc_code,
                s.service_name,
                c.category_name,
                i.insurance_name
            FROM doctor_tariff_master t
            INNER JOIN master_doctors d ON t.doctor_id = d.id
            INNER JOIN master_doctor_services s ON t.service_id = s.id
            INNER JOIN master_categories c ON t.category_id = c.id
            LEFT JOIN insurance i ON t.insurance_id = i.id
            WHERE t.org_id = ? AND t.center_id = ?
            ORDER BY t.id DESC
        ");
        $stmt->execute([$org_id, $center_id]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // When doctor + service + category are selected, return the
        // insurance mappings already used for that exact combination.
        $used = [];
        $base_exists = false;

        $doctor_id_ajax   = (int)($_GET['doctor_id'] ?? 0);
        $service_id_ajax  = (int)($_GET['service_id'] ?? 0);
        $category_id_ajax = (int)($_GET['category_id'] ?? 0);

        if ($doctor_id_ajax > 0 && $service_id_ajax > 0 && $category_id_ajax > 0) {
            $mapStmt = $tenant_pdo->prepare("
                SELECT insurance_id
                FROM doctor_tariff_master
                WHERE org_id = ?
                  AND center_id = ?
                  AND doctor_id = ?
                  AND service_id = ?
                  AND category_id = ?
            ");
            $mapStmt->execute([
                $org_id,
                $center_id,
                $doctor_id_ajax,
                $service_id_ajax,
                $category_id_ajax
            ]);

            foreach ($mapStmt->fetchAll(PDO::FETCH_COLUMN) as $insuranceId) {
                if ($insuranceId === null || $insuranceId === '') {
                    $base_exists = true;
                } else {
                    $used[] = (int)$insuranceId;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'rows' => $rows,
            'used_insurance_ids' => array_values(array_unique($used)),
            'base_exists' => $base_exists
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// =========================================================
// AJAX: AUTO-REFRESH DROPDOWNS FOR NEW TABS
// =========================================================
if (isset($_GET['ajax_masters'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $docs = $tenant_pdo->query("SELECT id, full_name, doc_code FROM master_doctors WHERE org_id = $org_id AND status = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        $srvs = $tenant_pdo->query("SELECT id, service_name FROM master_doctor_services WHERE org_id = $org_id AND status = 1 ORDER BY service_name")->fetchAll(PDO::FETCH_ASSOC);
        $cats = $tenant_pdo->query("SELECT id, category_name FROM master_categories WHERE org_id = $org_id AND status = 1 ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
        $ins  = $tenant_pdo->query("SELECT id, category_id, insurance_name FROM insurance WHERE org_id = $org_id AND status = 1 AND insurance_name IS NOT NULL AND insurance_name != '' ORDER BY insurance_name")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'doctors' => $docs ?: [],
            'services' => $srvs ?: [],
            'categories' => $cats ?: [],
            'insurances' => $ins ?: []
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================
// 1. SAVE / UPDATE TARIFF
// =========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_tariff'])) {

    $doctor_id     = (int)($_POST['doctor_id'] ?? 0);
    $service_id    = (int)($_POST['service_id'] ?? 0);
    $category_id   = (int)($_POST['category_id'] ?? 0);
    $rate          = (float)($_POST['rate'] ?? 0);
    $status        = isset($_POST['status']) ? 1 : 0;
    $edit_id       = (int)($_POST['edit_id'] ?? 0);
    $insurance_ids = $_POST['insurance_ids'] ?? [];

    // Make sure insurance_ids is always an array
    if (!is_array($insurance_ids)) {
        $insurance_ids = [];
    }

    // Clean insurance IDs
    $insurance_ids = array_values(
        array_filter(
            array_map('intval', $insurance_ids),
            function ($id) {
                return $id > 0;
            }
        )
    );


    // ---------------------------------------------------------
    // Validation
    // ---------------------------------------------------------

    if ($doctor_id <= 0 || $service_id <= 0 || $category_id <= 0) {

        set_flash_err("Doctor, Service, and Category are mandatory!");

    } else {

        try {

            // =================================================
            // UPDATE EXISTING TARIFF
            // =================================================

            if ($edit_id > 0) {

                /*
                 * During edit, one tariff row represents one
                 * insurance mapping.
                 *
                 * If no insurance is selected, save as
                 * Base Category tariff.
                 */

                $ins_id = !empty($insurance_ids)
                    ? (int)$insurance_ids[0]
                    : null;


                $stmt = $tenant_pdo->prepare("
                    UPDATE doctor_tariff_master
                    SET
                        doctor_id = ?,
                        service_id = ?,
                        category_id = ?,
                        insurance_id = ?,
                        rate = ?,
                        status = ?
                    WHERE id = ?
                      AND org_id = ?
                      AND center_id = ?
                ");


                $stmt->execute([
                    $doctor_id,
                    $service_id,
                    $category_id,
                    $ins_id,
                    $rate,
                    $status,
                    $edit_id,
                    $org_id,
                    $center_id
                ]);


                set_flash_msg("Tariff updated successfully.");


            // =================================================
            // INSERT NEW TARIFF
            // =================================================

            } else {

                $stmt = $tenant_pdo->prepare("
                    INSERT INTO doctor_tariff_master
                    (
                        org_id,
                        center_id,
                        doctor_id,
                        service_id,
                        category_id,
                        insurance_id,
                        rate,
                        status,
                        created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");


                // ---------------------------------------------
                // NO INSURANCE
                // Save as Base Category Rate
                // ---------------------------------------------

                if (empty($insurance_ids)) {

                    $stmt->execute([
                        $org_id,
                        $center_id,
                        $doctor_id,
                        $service_id,
                        $category_id,
                        null,
                        $rate,
                        $status,
                        $user_id
                    ]);


                // ---------------------------------------------
                // MULTIPLE INSURANCES
                // ---------------------------------------------

                } else {

                    foreach ($insurance_ids as $ins_id) {

                        $stmt->execute([
                            $org_id,
                            $center_id,
                            $doctor_id,
                            $service_id,
                            $category_id,
                            $ins_id,
                            $rate,
                            $status,
                            $user_id
                        ]);
                    }
                }


                set_flash_msg("Tariff rate(s) added successfully.");
            }


            // Redirect after successful operation
            header("Location: master_doctor_tariff.php");
            exit;


        } catch (PDOException $e) {

            set_flash_err("Database Error: " . $e->getMessage());
        }
    }
}


// =========================================================
// 2. STATUS TOGGLE
// =========================================================

if (isset($_GET['toggle_status'])) {

    $id = (int)$_GET['toggle_status'];

    $current_status = (int)($_GET['st'] ?? 1);

    $new_status = ($current_status === 1) ? 0 : 1;


    $stmt = $tenant_pdo->prepare("
        UPDATE doctor_tariff_master
        SET status = ?
        WHERE id = ?
          AND org_id = ?
          AND center_id = ?
    ");


    $stmt->execute([
        $new_status,
        $id,
        $org_id,
        $center_id
    ]);


    header("Location: master_doctor_tariff.php");
    exit;
}


// =========================================================
// 3. FETCH DOCTORS
// =========================================================

$doctorsStmt = $tenant_pdo->prepare("
    SELECT
        id,
        full_name,
        doc_code
    FROM master_doctors
    WHERE org_id = ?
      AND status = 1
    ORDER BY full_name
");

$doctorsStmt->execute([$org_id]);

$doctors = $doctorsStmt->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// 4. FETCH SERVICES
// =========================================================

$servicesStmt = $tenant_pdo->prepare("
    SELECT
        id,
        service_name
    FROM master_doctor_services
    WHERE org_id = ?
      AND status = 1
    ORDER BY service_name
");

$servicesStmt->execute([$org_id]);

$services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// 5. FETCH CATEGORIES
// =========================================================

$categoriesStmt = $tenant_pdo->prepare("
    SELECT
        id,
        category_name
    FROM master_categories
    WHERE org_id = ?
      AND status = 1
    ORDER BY category_name
");

$categoriesStmt->execute([$org_id]);

$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// 6. FETCH INSURANCES
// =========================================================

$insuranceStmt = $tenant_pdo->prepare("
    SELECT
        id,
        category_id,
        insurance_name
    FROM insurance
    WHERE org_id = ?
      AND status = 1
      AND insurance_name IS NOT NULL
      AND insurance_name != ''
    ORDER BY insurance_name
");

$insuranceStmt->execute([$org_id]);

$insurances = $insuranceStmt->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// 7. FETCH TARIFF LIST
// =========================================================

$tariffsStmt = $tenant_pdo->prepare("
    SELECT
        t.*,
        d.full_name,
        d.doc_code,
        s.service_name,
        c.category_name,
        i.insurance_name
    FROM doctor_tariff_master t

    INNER JOIN master_doctors d
        ON t.doctor_id = d.id

    INNER JOIN master_doctor_services s
        ON t.service_id = s.id

    INNER JOIN master_categories c
        ON t.category_id = c.id

    LEFT JOIN insurance i
        ON t.insurance_id = i.id

    WHERE t.org_id = ?
      AND t.center_id = ?

    ORDER BY t.id DESC
");

$tariffsStmt->execute([
    $org_id,
    $center_id
]);

$tariff_list = $tariffsStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$tariff_list) {
    $tariff_list = [];
}


require_once __DIR__ . '/layout_header.php';

?>



<div class="row g-3">

    <!-- =====================================================
         TOP : ADD / EDIT FORM
    ====================================================== -->
    <div class="col-12">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom">
                <span class="fw-bold small text-dark" id="formTitle">
                    <i class="bi bi-currency-rupee text-primary me-1"></i>
                    Add Doctor Tariff
                </span>
            </div>

            <div class="card-body p-3">

                <form method="POST" action="" id="doctorTariffForm">

                    <input type="hidden" name="action_tariff" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">

                    <div class="row g-3">

                        <!-- Doctor -->
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                Select Doctor *
                            </label>

                            <select
                                name="doctor_id"
                                id="doctor_id"
                                class="form-select form-select-sm"
                                required
                                autofocus
                                onchange="onCategoryChange()"
                            >
                                <option value="">-- Choose Doctor --</option>

                                <?php foreach ($doctors as $doc): ?>
                                    <option value="<?= (int)$doc['id'] ?>">
                                        <?= htmlspecialchars($doc['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>


                        <!-- Service -->
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                Select Service *
                            </label>

                            <select
                                name="service_id"
                                id="service_id"
                                class="form-select form-select-sm"
                                required
                                onchange="onCategoryChange()"
                            >
                                <option value="">-- Choose Service --</option>

                                <?php foreach ($services as $srv): ?>
                                    <option value="<?= (int)$srv['id'] ?>">
                                        <?= htmlspecialchars($srv['service_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>


                        <!-- Category -->
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                Select Category *
                            </label>

                            <select
                                name="category_id"
                                id="category_id"
                                class="form-select form-select-sm"
                                required
                                onchange="onCategoryChange()"
                            >
                                <option value="">-- Choose Category --</option>

                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>">
                                        <?= htmlspecialchars($cat['category_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>


                        <!-- Rate -->
                        <div class="col-lg-2 col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                Tariff Rate (₹) *
                            </label>

                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="rate"
                                id="rate"
                                class="form-control form-control-sm"
                                placeholder="0.00"
                                required
                            >
                        </div>


                        <!-- Status -->
                        <div class="col-lg-1 col-md-2 d-flex align-items-end">
                            <div class="form-check form-switch mb-1">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="status"
                                    id="status"
                                    checked
                                >

                                <label
                                    class="form-check-label small fw-semibold text-secondary"
                                    for="status"
                                >
                                    Active
                                </label>
                            </div>
                        </div>


                        <!-- Insurance -->
                        <div class="col-12">

                            <div
                                class="p-2 border rounded bg-light"
                                id="insurance_container"
                                style="display:none; max-height:200px; overflow-y:auto;"
                            >

                                <span class="d-block small fw-bold text-dark mb-2 border-bottom pb-1">
                                    Map Insurances / Providers
                                </span>

                                <div id="insurance_list" class="row g-2">
                                    <!-- JS will generate insurance checkboxes -->
                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- Buttons -->
                    <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">

                        <button
                            type="reset"
                            class="btn btn-light btn-sm border px-4"
                            onclick="resetForm()"
                        >
                            Reset
                        </button>

                        <button
                            type="submit"
                            class="btn btn-primary btn-sm px-4 fw-semibold"
                            id="btnSubmit"
                        >
                            Save Tariff
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>


    <!-- =====================================================
         BOTTOM : TARIFF LIST
    ====================================================== -->
    <div class="col-12">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">

                <span class="fw-bold small text-dark">
                    <i class="bi bi-list-columns text-primary me-1"></i>
                    Tariff List
                </span>

                <input
                    type="text"
                    id="searchInput"
                    class="form-control form-control-sm"
                    style="max-width:300px;"
                    placeholder="Search doctor, service, category..."
                >

            </div>


            <div class="card-body p-0">

                <div class="table-responsive">

                    <table
                        class="table table-hover align-middle mb-0 small"
                        id="tariffTable"
                    >

                        <thead class="table-light">

                            <tr>

                                <th class="ps-3" style="width:60px;">
                                    #
                                </th>

                                <th>
                                    Doctor
                                </th>

                                <th>
                                    Service
                                </th>

                                <th>
                                    Category
                                </th>

                                <th>
                                    Insurance
                                </th>

                                <th style="width:120px;">
                                    Rate (₹)
                                </th>

                                <th style="width:100px;">
                                    Status
                                </th>

                                <th class="text-end pe-3" style="width:80px;">
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody id="tariffTableBody">

                            <?php if (empty($tariff_list)): ?>

                                <tr>
                                    <td
                                        colspan="8"
                                        class="text-center py-4 text-muted"
                                    >
                                        No tariffs mapped yet.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($tariff_list as $row): ?>

                                    <?php
                                    $is_act = ((int)$row['status'] === 1);
                                    ?>

                                    <tr>

                                        <!-- ID -->
                                        <td class="ps-3 font-monospace">
                                            #<?= (int)$row['id'] ?>
                                        </td>


                                        <!-- Doctor -->
                                        <td>
                                            <div class="fw-bold text-dark">
                                                <?= htmlspecialchars($row['full_name']) ?>
                                            </div>
                                        </td>


                                        <!-- Service -->
                                        <td>
                                            <span class="badge bg-secondary-subtle text-dark border">
                                                <?= htmlspecialchars($row['service_name']) ?>
                                            </span>
                                        </td>


                                        <!-- Category -->
                                        <td>
                                            <div class="fw-semibold text-primary">
                                                <?= htmlspecialchars($row['category_name']) ?>
                                            </div>
                                        </td>


                                        <!-- Insurance -->
                                        <td>
                                            <small class="text-muted">
                                                <?= !empty($row['insurance_name'])
                                                    ? htmlspecialchars($row['insurance_name'])
                                                    : 'Base Category'
                                                ?>
                                            </small>
                                        </td>


                                        <!-- Rate -->
                                        <td class="fw-bold text-success">
                                            ₹<?= number_format((float)$row['rate'], 2) ?>
                                        </td>


                                        <!-- Status -->
                                        <td>
                                            <a
                                                href="?toggle_status=<?= (int)$row['id'] ?>&st=<?= $is_act ? 1 : 0 ?>"
                                                class="badge text-decoration-none
                                                <?= $is_act
                                                    ? 'bg-success-subtle text-success border border-success'
                                                    : 'bg-danger-subtle text-danger border border-danger'
                                                ?>"
                                            >
                                                <?= $is_act ? 'Active' : 'Inactive' ?>
                                            </a>
                                        </td>


                                        <!-- Action -->
                                        <td class="text-end pe-3">

                                            <button
                                                type="button"
                                                class="btn btn-outline-primary btn-sm py-0 px-2"
                                                onclick="editRowFromJson(this); return false;"
                                                data-row='<?= htmlspecialchars(json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, "UTF-8") ?>'
                                                title="Edit"
                                            >
                                                <i class="bi bi-pencil"></i>
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

// =========================================================
// LOAD INSURANCES FROM PHP
// =========================================================

let allInsurances =
    <?= json_encode($insurances, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;


// =========================================================
// AUTO-REFRESH DROPDOWNS (When returning from another tab)
// =========================================================

function refreshDropdownMasters() {
    fetch('?ajax_masters=1', { cache: 'no-store' })
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;

            // 1. Refresh Doctors Dropdown
            const docSel = document.getElementById('doctor_id');
            const docVal = docSel.value;
            let docHtml = '<option value="">-- Choose Doctor --</option>';
            data.doctors.forEach(d => {
                docHtml += `<option value="${d.id}">${escapeHtml(d.full_name)}</option>`;
            });
            docSel.innerHTML = docHtml;
            docSel.value = docVal;

            // 2. Refresh Services Dropdown
            const srvSel = document.getElementById('service_id');
            const srvVal = srvSel.value;
            let srvHtml = '<option value="">-- Choose Service --</option>';
            data.services.forEach(s => {
                srvHtml += `<option value="${s.id}">${escapeHtml(s.service_name)}</option>`;
            });
            srvSel.innerHTML = srvHtml;
            srvSel.value = srvVal;

            // 3. Refresh Categories Dropdown
            const catSel = document.getElementById('category_id');
            const catVal = catSel.value;
            let catHtml = '<option value="">-- Choose Category --</option>';
            data.categories.forEach(c => {
                catHtml += `<option value="${c.id}">${escapeHtml(c.category_name)}</option>`;
            });
            catSel.innerHTML = catHtml;
            catSel.value = catVal;

            // 4. Update the global Insurances array 
            allInsurances = data.insurances;

            // Re-run category change to refresh mapping checkboxes based on new data
            if (catVal) onCategoryChange();
        })
        .catch(err => console.error("Auto dropdown refresh error:", err));
}

// Fire the refresh automatically whenever the user clicks back onto this browser tab
window.addEventListener('focus', refreshDropdownMasters);


// =========================================================
// CATEGORY CHANGE
// =========================================================

function onCategoryChange(selectedInsId = null) {

    const catId = document.getElementById('category_id').value;
    const doctorId = document.getElementById('doctor_id').value;
    const serviceId = document.getElementById('service_id').value;
    const container = document.getElementById('insurance_container');
    const listDiv = document.getElementById('insurance_list');

    listDiv.innerHTML = '';

    if (!catId) {
        container.style.display = 'none';
        return;
    }

    const filtered = allInsurances.filter(function (ins) {
        return String(ins.category_id) === String(catId);
    });

    if (filtered.length === 0) {
        listDiv.innerHTML = '<div class="text-muted small">No specific insurances found. Rate will apply to base category.</div>';
        container.style.display = 'block';
        return;
    }

    // First render the insurance list. Existing mappings are fetched below.
    renderInsuranceList(filtered, selectedInsId, [], false);
    container.style.display = 'block';

    // Only check duplicate mappings when Doctor + Service + Category are selected.
    if (doctorId && serviceId && catId) {
        fetch('?ajax_tariffs=1'
            + '&doctor_id=' + encodeURIComponent(doctorId)
            + '&service_id=' + encodeURIComponent(serviceId)
            + '&category_id=' + encodeURIComponent(catId), {
                cache: 'no-store'
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) return;

                renderInsuranceList(
                    filtered,
                    selectedInsId,
                    data.used_insurance_ids || [],
                    !!data.base_exists
                );
            })
            .catch(function (error) {
                console.error('Insurance mapping check failed:', error);
            });
    }
}


function renderInsuranceList(filtered, selectedInsId, usedInsuranceIds, baseExists) {

    const listDiv = document.getElementById('insurance_list');
    const usedSet = new Set((usedInsuranceIds || []).map(String));

    let html = `
        <div class="col-12 mb-1">
            <div class="form-check border-bottom pb-2">
                <input class="form-check-input" type="checkbox" id="selectAllIns"
                       onchange="toggleAllIns(this)" ${baseExists ? 'disabled' : ''}>
                <label class="form-check-label fw-bold text-dark small" for="selectAllIns">
                    Select All Available Insurances
                </label>
            </div>
        </div>
    `;

    filtered.forEach(function (ins) {
        const alreadyUsed = usedSet.has(String(ins.id));
        const isEditingThis = selectedInsId && String(selectedInsId) === String(ins.id);
        const disabled = alreadyUsed && !isEditingThis;
        const checked = isEditingThis ? 'checked' : '';

        html += `
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="form-check">
                    <input
                        class="form-check-input ins-chk"
                        type="checkbox"
                        name="insurance_ids[]"
                        value="${ins.id}"
                        id="ins_${ins.id}"
                        ${checked}
                        ${disabled ? 'disabled' : ''}
                    >
                    <label class="form-check-label small ${disabled ? 'text-muted' : ''}" for="ins_${ins.id}">
                        ${escapeHtml(ins.insurance_name)}
                        ${disabled ? '<span class="badge bg-secondary ms-1">Already Added</span>' : ''}
                    </label>
                </div>
            </div>
        `;
    });

    if (baseExists) {
        html += `
            <div class="col-12 mt-2">
                <div class="alert alert-warning py-2 mb-0 small">
                    Base Category tariff already exists for this Doctor + Service + Category.
                    Add only the remaining available insurance mappings.
                </div>
            </div>
        `;
    }

    listDiv.innerHTML = html;
    updateSelectAll();
}


// Refresh the tariff list automatically so entries added from another tab
// appear here without manually refreshing the page.
let tariffRefreshBusy = false;

function refreshTariffList() {
    if (tariffRefreshBusy) return;
    tariffRefreshBusy = true;

    fetch('?ajax_tariffs=1', { cache: 'no-store' })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success && Array.isArray(data.rows)) {
                renderTariffTable(data.rows);
            }
        })
        .catch(function (error) {
            console.error('Tariff list refresh failed:', error);
        })
        .finally(function () {
            tariffRefreshBusy = false;
        });
}


function renderTariffTable(rows) {

    const tbody = document.getElementById('tariffTableBody');
    if (!tbody) return;

    if (!rows.length) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-4 text-muted">
                    No tariffs mapped yet.
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = rows.map(function (row) {
        const active = parseInt(row.status) === 1;
        const insurance = row.insurance_name
            ? escapeHtml(row.insurance_name)
            : 'Base Category';

        const safeJson = JSON.stringify(row)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        return `
            <tr>
                <td class="ps-3 font-monospace">#${parseInt(row.id)}</td>

                <td>
                    <div class="fw-bold text-dark">${escapeHtml(row.full_name)}</div>
                </td>

                <td>
                    <span class="badge bg-secondary-subtle text-dark border">
                        ${escapeHtml(row.service_name)}
                    </span>
                </td>

                <td>
                    <div class="fw-semibold text-primary">
                        ${escapeHtml(row.category_name)}
                    </div>
                </td>

                <td>
                    <small class="text-muted">${insurance}</small>
                </td>

                <td class="fw-bold text-success">
                    ₹${Number(row.rate || 0).toLocaleString('en-IN', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    })}
                </td>

                <td>
                    <a
                        href="?toggle_status=${parseInt(row.id)}&st=${active ? 1 : 0}"
                        class="badge text-decoration-none ${active
                            ? 'bg-success-subtle text-success border border-success'
                            : 'bg-danger-subtle text-danger border border-danger'}"
                    >
                        ${active ? 'Active' : 'Inactive'}
                    </a>
                </td>

                <td class="text-end pe-3">
                    <button
                        type="button"
                        class="btn btn-outline-primary btn-sm py-0 px-2"
                        onclick="editRowFromJson(this); return false;"
                        data-row="${safeJson}"
                        title="Edit"
                    >
                        <i class="bi bi-pencil"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}


function editRowFromJson(button) {
    try {
        if (!button) return;

        const raw = button.getAttribute('data-row');
        if (!raw) {
            console.error('Edit data not found.');
            return;
        }

        const data = JSON.parse(raw);
        editRow(data);

    } catch (error) {
        console.error('Unable to open tariff for edit:', error);
        console.error('Raw edit data:', button ? button.getAttribute('data-row') : null);
    }
}


// Poll every 3 seconds. This makes changes from another browser tab visible
// automatically without reloading the page.
setInterval(refreshTariffList, 3000);



// =========================================================
// ESCAPE HTML
// =========================================================

function escapeHtml(value) {

    const div = document.createElement('div');

    div.textContent = value ?? '';

    return div.innerHTML;
}



// =========================================================
// SELECT ALL INSURANCES
// =========================================================

function toggleAllIns(source) {

    const checkboxes =
        document.querySelectorAll('.ins-chk');


    checkboxes.forEach(function (cb) {

        cb.checked = source.checked;

    });

}



// =========================================================
// UPDATE SELECT ALL STATUS
// =========================================================

function updateSelectAll() {

    const selectAll =
        document.getElementById('selectAllIns');

    const checkboxes =
        document.querySelectorAll('.ins-chk');


    if (!selectAll || checkboxes.length === 0) {

        return;
    }


    const checked =
        document.querySelectorAll('.ins-chk:checked');


    selectAll.checked =
        checked.length === checkboxes.length;

}



// =========================================================
// INSURANCE CHECKBOX CHANGE
// =========================================================

document.addEventListener('change', function (e) {

    if (e.target.classList.contains('ins-chk')) {

        updateSelectAll();

    }

});



// =========================================================
// LIVE SEARCH
// =========================================================

const searchInput =
    document.getElementById('searchInput');


if (searchInput) {

    searchInput.addEventListener('keyup', function () {

        const val =
            this.value.toLowerCase().trim();


        const rows =
            document.querySelectorAll(
                '#tariffTable tbody tr'
            );


        rows.forEach(function (row) {

            const text =
                row.innerText.toLowerCase();


            row.style.display =
                text.includes(val)
                    ? ''
                    : 'none';

        });

    });

}



// =========================================================
// EDIT ROW
// =========================================================

function editRow(data) {

    if (!data || !data.id) {
        console.error('Invalid tariff data for edit:', data);
        return;
    }

    const editId   = document.getElementById('edit_id');
    const doctor   = document.getElementById('doctor_id');
    const service  = document.getElementById('service_id');
    const category = document.getElementById('category_id');
    const rate     = document.getElementById('rate');
    const status   = document.getElementById('status');
    const title    = document.getElementById('formTitle');
    const button   = document.getElementById('btnSubmit');

    if (!editId || !doctor || !service || !category || !rate || !status) {
        console.error('Doctor tariff form elements not found.');
        return;
    }

    // Put the selected row values into the form.
    editId.value = data.id;
    doctor.value = data.doctor_id || '';
    service.value = data.service_id || '';
    category.value = data.category_id || '';
    rate.value = data.rate ?? '';
    status.checked = parseInt(data.status, 10) === 1;

    // Important: mark this row as EDIT mode before rendering insurance.
    if (title) {
        title.innerHTML = `
            <i class="bi bi-pencil-square text-warning me-1"></i>
            Edit Doctor Tariff
        `;
    }

    if (button) {
        button.innerText = 'Update Tariff';
    }

    // Render the selected category's insurance and keep the current
    // insurance checked even though it is already present in DB.
    onCategoryChange(data.insurance_id ? parseInt(data.insurance_id, 10) : null);

    // Save edit state so the 3-second table refresh does not lose it.
    try {
        sessionStorage.setItem('doctor_tariff_form_edit_id', String(data.id));
        sessionStorage.setItem('doctor_tariff_form_doctor_id', String(data.doctor_id || ''));
        sessionStorage.setItem('doctor_tariff_form_service_id', String(data.service_id || ''));
        sessionStorage.setItem('doctor_tariff_form_category_id', String(data.category_id || ''));
        sessionStorage.setItem('doctor_tariff_form_rate', String(data.rate ?? ''));
        sessionStorage.setItem('doctor_tariff_form_status', status.checked ? '1' : '0');
        sessionStorage.setItem(
            'doctor_tariff_form_insurance_ids',
            JSON.stringify(data.insurance_id ? [parseInt(data.insurance_id, 10)] : [])
        );
    } catch (e) {}

    // Bring the user back to the form after clicking Edit from the list.
    const form = document.getElementById('doctorTariffForm');
    if (form) {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    setTimeout(function () {
        doctor.focus();
    }, 300);
}




// =========================================================
// RESET FORM
// =========================================================

function resetForm() {

    document.getElementById('edit_id').value =
        '0';


    document.getElementById('doctor_id').value =
        '';


    document.getElementById('service_id').value =
        '';


    document.getElementById('category_id').value =
        '';


    document.getElementById('rate').value =
        '';


    document.getElementById('status').checked =
        true;


    // Hide insurance

    document.getElementById(
        'insurance_container'
    ).style.display = 'none';


    document.getElementById(
        'insurance_list'
    ).innerHTML = '';


    // Reset title

    document.getElementById(
        'formTitle'
    ).innerHTML = `

        <i class="bi bi-currency-rupee text-primary me-1"></i>

        Add Doctor Tariff

    `;


    // Reset button

    document.getElementById(
        'btnSubmit'
    ).innerText =
        'Save Tariff';


    // Focus

    document.getElementById(
        'doctor_id'
    ).focus();

}


// =========================================================
// PERSIST FORM VALUES UNTIL USER CLICKS RESET
// =========================================================

(function () {

    const form = document.getElementById('doctorTariffForm');
    if (!form) return;

    const STORAGE_PREFIX = 'doctor_tariff_form_';

    const fieldIds = [
        'doctor_id',
        'service_id',
        'category_id',
        'rate',
        'status',
        'edit_id'
    ];

    let restoring = true;


    // ---------------------------------------------------------
    // Save current form values
    // ---------------------------------------------------------

    function saveFormState() {

        if (restoring) return;

        fieldIds.forEach(function (id) {

            const el = document.getElementById(id);
            if (!el) return;

            let value = '';

            if (el.type === 'checkbox') {
                value = el.checked ? '1' : '0';
            } else {
                value = el.value;
            }

            sessionStorage.setItem(
                STORAGE_PREFIX + id,
                value
            );
        });
    }


    // ---------------------------------------------------------
    // Restore form values
    // ---------------------------------------------------------

    function restoreFormState() {

        fieldIds.forEach(function (id) {

            const el = document.getElementById(id);
            if (!el) return;

            const saved =
                sessionStorage.getItem(STORAGE_PREFIX + id);

            if (saved === null) return;

            if (el.type === 'checkbox') {

                el.checked = saved === '1';

            } else {

                el.value = saved;
            }

        });


        // Restore insurance selections
        const category =
            document.getElementById('category_id');

        if (category && category.value) {

            const savedInsurance =
                sessionStorage.getItem(
                    STORAGE_PREFIX + 'insurance_ids'
                );

            let selectedIds = [];

            try {
                selectedIds =
                    savedInsurance
                        ? JSON.parse(savedInsurance)
                        : [];
            } catch (e) {
                selectedIds = [];
            }


            onCategoryChange(selectedIds);


            // Restore selected insurance checkboxes
            setTimeout(function () {

                selectedIds.forEach(function (id) {

                    const checkbox =
                        document.getElementById('ins_' + id);

                    if (checkbox && !checkbox.disabled) {
                        checkbox.checked = true;
                    }

                });

                updateSelectAll();

            }, 50);
        }
    }


    // ---------------------------------------------------------
    // Save insurance checkbox state
    // ---------------------------------------------------------

    function saveInsuranceState() {

        const selected = [];

        document
            .querySelectorAll('.ins-chk:checked')
            .forEach(function (checkbox) {

                selected.push(
                    parseInt(checkbox.value)
                );

            });

        sessionStorage.setItem(
            STORAGE_PREFIX + 'insurance_ids',
            JSON.stringify(selected)
        );
    }


    // ---------------------------------------------------------
    // Listen for changes
    // ---------------------------------------------------------

    fieldIds.forEach(function (id) {

        const el = document.getElementById(id);
        if (!el) return;

        el.addEventListener('change', function () {
            saveFormState();
        });

        el.addEventListener('input', function () {
            saveFormState();
        });

    });


    document.addEventListener('change', function (e) {

        if (
            e.target.classList &&
            e.target.classList.contains('ins-chk')
        ) {
            saveInsuranceState();
        }

    });


    // ---------------------------------------------------------
    // Save insurance state whenever category changes
    // ---------------------------------------------------------

    const originalOnCategoryChange =
        window.onCategoryChange;

    window.onCategoryChange = function (selectedInsId = null) {

        if (typeof originalOnCategoryChange === 'function') {

            originalOnCategoryChange(selectedInsId);

        }

        setTimeout(function () {

            saveFormState();

            if (selectedInsId) {

                const checkbox =
                    document.getElementById(
                        'ins_' + selectedInsId
                    );

                if (checkbox && !checkbox.disabled) {
                    checkbox.checked = true;
                }

            }

            saveInsuranceState();
            updateSelectAll();

        }, 30);
    };


    // ---------------------------------------------------------
    // Reset = clear saved state
    // ---------------------------------------------------------

    const originalResetForm =
        window.resetForm;

    window.resetForm = function () {

        fieldIds.forEach(function (id) {

            sessionStorage.removeItem(
                STORAGE_PREFIX + id
            );

        });

        sessionStorage.removeItem(
            STORAGE_PREFIX + 'insurance_ids'
        );


        // Call existing reset function
        if (typeof originalResetForm === 'function') {
            originalResetForm();
        }


        // Explicitly clear all form fields
        const doctor =
            document.getElementById('doctor_id');

        const service =
            document.getElementById('service_id');

        const category =
            document.getElementById('category_id');

        const rate =
            document.getElementById('rate');

        const status =
            document.getElementById('status');

        const editId =
            document.getElementById('edit_id');


        if (doctor) doctor.value = '';
        if (service) service.value = '';
        if (category) category.value = '';
        if (rate) rate.value = '';
        if (status) status.checked = true;
        if (editId) editId.value = '0';


        document
            .getElementById('insurance_container')
            .style.display = 'none';

        document
            .getElementById('insurance_list')
            .innerHTML = '';


        // Reset title/button
        document.getElementById('formTitle').innerHTML = `
            <i class="bi bi-currency-rupee text-primary me-1"></i>
            Add Doctor Tariff
        `;

        document.getElementById('btnSubmit').innerText =
            'Save Tariff';


        saveFormState();
    };


    // ---------------------------------------------------------
    // Restore after page load
    // ---------------------------------------------------------

    restoreFormState();

    restoring = false;

})();

</script>



<?php

require_once __DIR__ . '/layout_footer.php';

?>