<?php
// admin/layout_header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Check Tenant / Hospital Admin Authentication
if (!isset($_SESSION['user_id']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'superadmin')) {
    header("Location: ../login.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);

// OPD Submenu Pages Check (Added opd_appointments.php here)
$opd_pages = ['opd_appointments.php', 'opd_registration.php', 'opd_queue.php', 'opd_doctor.php', 'opd_billing.php'];
$is_opd_active = in_array($current_page, $opd_pages);

// Facility & Infrastructure Master Check
$facility_pages = ['master_buildings.php', 'master_blocks.php', 'master_floors.php', 'master_room_categories.php', 'master_rooms.php'];
$is_facility_active = in_array($current_page, $facility_pages);

// General Masters (Includes all doctor, services, and tariff masters)
$general_master_pages = [
    'org_profile.php', 
    'master_financial_year.php', // <--- Financial Year Master
    'master_centers.php', 
    'users.php', 
    'master_doctors.php', 
    'master_departments.php',
    'master_qualifications.php', 
    'master_specializations.php',
    'master_days.php',
    'master_payment_modes.php',
    'master_insurance_categories.php', 
    'master_doctor_services.php',
    'master_doctor_tariff.php', 
    'master_services.php', 
    'master_tariffs.php'
];

// NOTE: OPD is no longer merged into Master Active check!
$is_master_active = in_array($current_page, array_merge($general_master_pages, $facility_pages));

// User Menu Permissions (Dynamic Access Check)
$user_menus = $_SESSION['menu_access'] ?? [];
$is_full_admin = !empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;

function has_access($menu_key, $is_full_admin, $user_menus) {
    if ($is_full_admin) return true;
    return in_array($menu_key, $user_menus);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $page_title ?? 'Hospital Admin Console' ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --sidebar-width: 260px;
            --topbar-height: 60px;
            --sidebar-bg: #1e293b;
            --sidebar-hover: rgba(255, 255, 255, 0.07);
            --sidebar-active: #0284c7;
            --body-bg: #f1f5f9;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: var(--body-bg);
            font-size: 0.9rem;
            color: #334155;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Fixed Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: var(--sidebar-bg);
            display: flex;
            flex-direction: column;
            z-index: 1050;
            transition: transform 0.3s ease-in-out;
        }

        .sidebar-brand {
            height: var(--topbar-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            font-weight: 700;
            color: #fff;
            font-size: 1.15rem;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .sidebar-menu {
            list-style: none;
            padding: 15px 0;
            margin: 0;
            flex-grow: 1;
            overflow-y: auto;
        }

        .sidebar-menu li {
            margin-bottom: 2px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            color: #94a3b8;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.92rem;
            transition: 0.2s ease;
            background: transparent;
            border: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
        }

        .sidebar-link .link-inner {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #ffffff;
        }

        .sidebar-link.active {
            background: var(--sidebar-active);
            color: #ffffff;
        }

        /* Submenu Chevron Arrow */
        .submenu-arrow {
            font-size: 0.8rem;
            transition: transform 0.3s ease;
        }

        .sidebar-link[aria-expanded="true"] .submenu-arrow {
            transform: rotate(180deg);
        }

        /* Submenu List */
        .submenu-items {
            list-style: none;
            padding: 4px 0 6px 15px;
            margin: 0;
            background: rgba(0, 0, 0, 0.15);
        }

        .submenu-items a, .nested-toggle-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 15px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.2s;
            border-radius: 4px;
            background: transparent;
            border: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
        }

        .nested-toggle-btn {
            justify-content: space-between;
        }

        .nested-toggle-btn[aria-expanded="true"] .nested-arrow {
            transform: rotate(180deg);
        }

        .nested-arrow {
            font-size: 0.75rem;
            transition: transform 0.2s ease;
        }

        .submenu-items a:hover, .nested-toggle-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }

        .submenu-items a.active-child {
            color: #38bdf8;
            font-weight: 600;
            background: rgba(2, 132, 199, 0.15);
        }

        /* Nested Submenu inside Masters */
        .nested-submenu-items {
            list-style: none;
            padding: 3px 0 5px 12px;
            margin: 0;
            background: rgba(0, 0, 0, 0.25);
            border-left: 2px solid #0284c7;
            margin-left: 15px;
        }

        .nested-submenu-items a {
            padding: 6px 12px;
            font-size: 0.82rem;
        }

        /* Main Content Wrapper */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left 0.3s ease-in-out;
        }

        /* Topbar */
        .topbar {
            height: var(--topbar-height);
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .content {
            padding: 25px;
            flex-grow: 1;
        }

        .btn-logout {
            background-color: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }

        .btn-logout:hover {
            background-color: #dc2626;
            color: #ffffff;
            border-color: #dc2626;
        }

        /* Mobile Backdrop */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            z-index: 1040;
            display: none;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
                box-shadow: 4px 0 20px rgba(0, 0, 0, 0.3);
            }

            .main-wrapper {
                margin-left: 0 !important;
            }

            .topbar {
                padding: 0 15px;
            }

            .content {
                padding: 15px;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebarMenu">
    <div class="sidebar-brand">
        <span><i class="bi bi-hospital-fill text-primary me-2"></i> Clinic Panel</span>
        <button class="btn btn-sm text-white d-lg-none p-0" id="sidebarCloseBtn" type="button">
            <i class="bi bi-x-lg fs-5"></i>
        </button>
    </div>
    
    <ul class="sidebar-menu">
        <!-- 1. DASHBOARD -->
        <li>
            <a href="dashboard.php" class="sidebar-link <?= ($current_page == 'dashboard.php') ? 'active' : '' ?>">
                <div class="link-inner">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </div>
            </a>
        </li>

        <!-- 2. MASTERS MODULE -->
        <li>
            <button class="sidebar-link <?= $is_master_active ? 'text-white' : '' ?>" 
                    type="button" 
                    data-bs-toggle="collapse" 
                    data-bs-target="#adminMasterSubmenu" 
                    aria-expanded="<?= $is_master_active ? 'true' : 'false' ?>" 
                    aria-controls="adminMasterSubmenu">
                <div class="link-inner">
                    <i class="bi bi-database-fill-gear text-primary"></i>
                    <span>Masters</span>
                </div>
                <i class="bi bi-chevron-down submenu-arrow"></i>
            </button>
            
            <div class="collapse <?= $is_master_active ? 'show' : '' ?>" id="adminMasterSubmenu">
                <ul class="submenu-items">
                    <?php if (has_access('masters_org', $is_full_admin, $user_menus)): ?>
                    <li>
                        <a href="org_profile.php" class="<?= ($current_page == 'org_profile.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-building"></i> Hospital Profile
                        </a>
                    </li>
                    <?php endif; ?>

                    <li>
                        <a href="master_financial_year.php" class="<?= ($current_page == 'master_financial_year.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-calendar-check"></i> Financial Year Master
                        </a>
                    </li>

                    <?php if (has_access('masters_centers', $is_full_admin, $user_menus)): ?>
                    <li>
                        <a href="master_centers.php" class="<?= ($current_page == 'master_centers.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-geo-alt"></i> Centers / Branches
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_access('masters_users', $is_full_admin, $user_menus)): ?>
                    <li>
                        <a href="users.php" class="<?= ($current_page == 'users.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-people"></i> Staff & Users
                        </a>
                    </li>
                    <?php endif; ?>

                    <!-- Doctor Masters -->
                    <li>
                        <a href="master_doctors.php" class="<?= ($current_page == 'master_doctors.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-person-badge"></i> Doctors & Schedules
                        </a>
                    </li>
                    <li>
                        <a href="master_departments.php" class="<?= ($current_page == 'master_departments.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-diagram-3"></i> Departments
                        </a>
                    </li>
                    <li>
                        <a href="master_qualifications.php" class="<?= ($current_page == 'master_qualifications.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-mortarboard"></i> Qualifications
                        </a>
                    </li>
                    <li>
                        <a href="master_specializations.php" class="<?= ($current_page == 'master_specializations.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-heart-pulse"></i> Specializations
                        </a>
                    </li>
                    <li>
                        <a href="master_days.php" class="<?= ($current_page == 'master_days.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-calendar-day"></i> Working Days
                        </a>
                    </li>
                    <li>
                        <a href="master_payment_modes.php" class="<?= ($current_page == 'master_payment_modes.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-wallet2"></i> Payment Modes
                        </a>
                    </li>
                    <li>
                        <a href="master_insurance_categories.php" class="<?= ($current_page == 'master_insurance_categories.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-folder-check"></i> Category & Insurance
                        </a>
                    </li>
                    <li>
                        <a href="master_doctor_services.php" class="<?= ($current_page == 'master_doctor_services.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-gear-fill"></i> Doctor Service Master
                        </a>
                    </li>
                    <li>
                        <a href="master_doctor_tariff.php" class="<?= ($current_page == 'master_doctor_tariff.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-currency-rupee"></i> Doctor Tariff Master
                        </a>
                    </li>

                    <!-- Facility / Infrastructure Masters Submenu -->
                    <li>
                        <button class="nested-toggle-btn <?= $is_facility_active ? 'text-warning fw-bold' : '' ?>" 
                                type="button" 
                                data-bs-toggle="collapse" 
                                data-bs-target="#nestedFacilitySubmenu" 
                                aria-expanded="<?= $is_facility_active ? 'true' : 'false' ?>" 
                                aria-controls="nestedFacilitySubmenu">
                            <span><i class="bi bi-buildings text-warning me-2"></i> Facilities & Rooms</span>
                            <i class="bi bi-chevron-down nested-arrow"></i>
                        </button>

                        <div class="collapse <?= $is_facility_active ? 'show' : '' ?>" id="nestedFacilitySubmenu">
                            <ul class="nested-submenu-items">
                                <li><a href="master_buildings.php" class="<?= ($current_page == 'master_buildings.php') ? 'active-child' : '' ?>"><i class="bi bi-building"></i> Buildings</a></li>
                                <li><a href="master_blocks.php" class="<?= ($current_page == 'master_blocks.php') ? 'active-child' : '' ?>"><i class="bi bi-grid"></i> Blocks</a></li>
                                <li><a href="master_floors.php" class="<?= ($current_page == 'master_floors.php') ? 'active-child' : '' ?>"><i class="bi bi-layers"></i> Floors</a></li>
                                <li><a href="master_room_categories.php" class="<?= ($current_page == 'master_room_categories.php') ? 'active-child' : '' ?>"><i class="bi bi-tag"></i> Room Categories</a></li>
                                <li><a href="master_rooms.php" class="<?= ($current_page == 'master_rooms.php') ? 'active-child' : '' ?>"><i class="bi bi-door-open"></i> Rooms Master</a></li>
                            </ul>
                        </div>
                    </li>

                    <li>
                        <a href="master_services.php" class="<?= ($current_page == 'master_services.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-tags"></i> Services Catalog
                        </a>
                    </li>

                    <li>
                        <a href="master_tariffs.php" class="<?= ($current_page == 'master_tariffs.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-currency-rupee"></i> Tariff / Price List
                        </a>
                    </li>
                </ul>
            </div>
        </li>

        <!-- 3. OPD MODULE (Now Separate from Masters) -->
        <li>
            <button class="sidebar-link <?= $is_opd_active ? 'text-white' : '' ?>" 
                    type="button" 
                    data-bs-toggle="collapse" 
                    data-bs-target="#opdMainSubmenu" 
                    aria-expanded="<?= $is_opd_active ? 'true' : 'false' ?>" 
                    aria-controls="opdMainSubmenu">
                <div class="link-inner">
                    <i class="bi bi-person-wheelchair text-info"></i>
                    <span>OPD Services</span>
                </div>
                <i class="bi bi-chevron-down submenu-arrow"></i>
            </button>

            <div class="collapse <?= $is_opd_active ? 'show' : '' ?>" id="opdMainSubmenu">
                <ul class="submenu-items">
                    
                    <?php if (has_access('opd_appt', $is_full_admin, $user_menus)): ?>
                    <li><a href="opd_appointments.php" class="<?= ($current_page == 'opd_appointments.php') ? 'active-child' : '' ?>"><i class="bi bi-calendar-check"></i> Appointments</a></li>
                    <?php endif; ?>

                    <?php if (has_access('opd_reg', $is_full_admin, $user_menus)): ?>
                    <li><a href="opd_registration.php" class="<?= ($current_page == 'opd_registration.php') ? 'active-child' : '' ?>"><i class="bi bi-plus-circle"></i> Registration & Token</a></li>
                    <?php endif; ?>

                    <?php if (has_access('opd_queue', $is_full_admin, $user_menus)): ?>
                    <li><a href="opd_queue.php" class="<?= ($current_page == 'opd_queue.php') ? 'active-child' : '' ?>"><i class="bi bi-display"></i> Queue Display</a></li>
                    <?php endif; ?>

                    <?php if (has_access('opd_doctor', $is_full_admin, $user_menus)): ?>
                    <li><a href="opd_doctor.php" class="<?= ($current_page == 'opd_doctor.php') ? 'active-child' : '' ?>"><i class="bi bi-prescription2"></i> Doctor Desk (Rx)</a></li>
                    <?php endif; ?>

                    <?php if (has_access('opd_billing', $is_full_admin, $user_menus)): ?>
                    <li><a href="opd_billing.php" class="<?= ($current_page == 'opd_billing.php') ? 'active-child' : '' ?>"><i class="bi bi-receipt"></i> OPD Billing</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>
    </ul>
</aside>

<div class="main-wrapper">
    <header class="topbar">
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggleBtn" type="button">
                <i class="bi bi-list fs-5"></i>
            </button>
            <span class="fw-semibold text-secondary d-none d-sm-inline">
                <i class="bi bi-hospital me-1 text-primary"></i> <?= htmlspecialchars($_SESSION['org_name'] ?? 'Hospital Admin') ?>
            </span>
        </div>
        
        <div class="d-flex align-items-center gap-2 gap-sm-3">
            <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.9rem;">
                    <i class="bi bi-person-badge"></i>
                </div>
                <span class="fw-bold small text-dark d-none d-sm-inline"><?= htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin') ?></span>
            </div>

            <div class="vr my-1 text-muted d-none d-sm-block" style="height: 20px;"></div>

            <a href="../logout.php" class="btn-logout" title="Logout">
                <i class="bi bi-box-arrow-right"></i> <span class="d-none d-sm-inline">Logout</span>
            </a>
        </div>
    </header>
    
    <main class="content">