<?php
// logout.php - Universal Central Logout

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// ============================================================
// 1. CLEAR ALL SESSION VARIABLES
// ============================================================

$_SESSION = array();


// ============================================================
// 2. DESTROY SESSION COOKIE
// ============================================================

if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}


// ============================================================
// 3. DESTROY SESSION
// ============================================================

session_destroy();


// ============================================================
// 4. PREVENT CACHED PAGES
// ============================================================

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");


// ============================================================
// 5. REDIRECT TO LOGIN
// ============================================================

header("Location: login.php?msg=logged_out");
exit;
