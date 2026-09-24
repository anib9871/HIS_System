<?php
// logout.php (Universal Central Logout)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Saare Session Variables empty karein
$_SESSION = array();

// 2. Session Cookie destroy karein
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

// 3. Session completely destroy karein
session_destroy();

// 4. Cache clear headers (Back button dabane par page na khule)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 5. Direct Absolute Path to Main Login (Chahe kisi bhi folder se logout karein)
header("Location: /HIS_System/login.php?msg=logged_out");
exit;