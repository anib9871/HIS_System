<?php
// config/alerts.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Success message set karne ke liye (Top-Right Toast)
 */
function set_flash_msg($message) {
    $_SESSION['flash_msg'] =$message;
}

/**
 * Error message set karne ke liye (Center Modal with OK)
 */
function set_flash_err($error) {
    $_SESSION['flash_err'] =$error;
}