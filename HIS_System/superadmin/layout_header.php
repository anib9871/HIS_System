<?php
// superadmin/layout_header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check Superadmin Authentication
if (!isset($_SESSION['user_id']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'superadmin')) {
    header("Location: ../login.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);

// Check active status for Master sub-links
$master_pages = ['master_organization.php', 'master_role.php', 'master_user.php', 'subscription_plans.php', 'user_credentials.php'];
$is_master_active = in_array($current_page, $master_pages);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $page_title ?? 'HIS Super Admin Portal' ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --sidebar-width: 250px;
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
            padding: 4px 0 6px 36px;
            margin: 0;
            background: rgba(0, 0, 0, 0.15);
        }

        .submenu-items a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 15px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .submenu-items a:hover {
            color: #ffffff;
        }

        .submenu-items a.active-child {
            color: #38bdf8;
            font-weight: 600;
        }

        /* Main Content Area */
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

        /* Custom Cards */
        .card-custom {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        .btn-custom {
            background: #0284c7;
            color: white;
            border: none;
            font-weight: 600;
            border-radius: 6px;
            padding: 7px 18px;
        }

        .btn-custom:hover {
            background: #0369a1;
            color: white;
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

        /* Mobile Responsive Rules */
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
        <span><i class="bi bi-hospital-fill text-info me-2"></i> HIS Portal</span>
        <button class="btn btn-sm text-white d-lg-none p-0" id="sidebarCloseBtn" type="button">
            <i class="bi bi-x-lg fs-5"></i>
        </button>
    </div>
    
    <ul class="sidebar-menu">
        <li>
            <a href="dashboard.php" class="sidebar-link <?= ($current_page == 'dashboard.php') ? 'active' : '' ?>">
                <div class="link-inner">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </div>
            </a>
        </li>

        <li>
            <button class="sidebar-link <?= $is_master_active ? 'text-white' : '' ?>" 
                    type="button" 
                    data-bs-toggle="collapse" 
                    data-bs-target="#masterSubmenu" 
                    aria-expanded="<?= $is_master_active ? 'true' : 'false' ?>" 
                    aria-controls="masterSubmenu">
                <div class="link-inner">
                    <i class="bi bi-database-fill-gear text-info"></i>
                    <span>Masters</span>
                </div>
                <i class="bi bi-chevron-down submenu-arrow"></i>
            </button>
            
            <div class="collapse <?= $is_master_active ? 'show' : '' ?>" id="masterSubmenu">
                <ul class="submenu-items">
                    <li>
                        <a href="master_organization.php" class="<?= ($current_page == 'master_organization.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-buildings"></i> Master Organization
                        </a>
                    </li>
                    <li>
                        <a href="master_role.php" class="<?= in_array($current_page, ['master_role.php', 'master_roles.php']) ? 'active-child' : '' ?>">
                            <i class="bi bi-person-badge"></i> Master Role
                        </a>
                    </li>
                    <li>
                        <a href="master_user.php" class="<?= ($current_page == 'master_user.php' || $current_page == 'user_credentials.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-person-gear"></i> Master User
                        </a>
                    </li>
                    <li>
                        <a href="subscription_plans.php" class="<?= ($current_page == 'subscription_plans.php') ? 'active-child' : '' ?>">
                            <i class="bi bi-credit-card-2-front"></i> Subscription Plan
                        </a>
                    </li>
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
            <span class="fw-semibold text-secondary d-none d-sm-inline">Super Admin Console</span>
        </div>
        
        <div class="d-flex align-items-center gap-2 gap-sm-3">
            <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.9rem;">
                    <i class="bi bi-person-fill"></i>
                </div>
                <span class="fw-bold small text-dark d-none d-sm-inline"><?= htmlspecialchars($_SESSION['username'] ?? 'superadmin') ?></span>
            </div>

            <div class="vr my-1 text-muted d-none d-sm-block" style="height: 20px;"></div>

            <a href="../logout.php" class="btn-logout" title="Sign Out">
                <i class="bi bi-box-arrow-right"></i> <span class="d-none d-sm-inline">Sign Out</span>
            </a>
        </div>
    </header>
    
    <main class="content">