<?php
session_start();

// Empty the session and also delete the session cookie from the browser
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
}
session_destroy();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Location: index.php");
exit();
