<?php
    // Who is allowed where. There are 2 roles in users.Role: "user" and "admin".
    //   user  -> dashboard.php (Home) and the other user pages
    //   admin -> studentlist.php and the other admin pages
    // Include this file after session_start().

    function isAdminRole($role) {
        return strtolower(trim((string) $role)) === "admin";
    }

    // The first page a person sees after logging in
    function homePageFor($role) {
        return isAdminRole($role) ? "studentlist" : "dashboard";
    }

    // Put this at the top of every USER page. Visitors go to the login page, admins go to their own interface.
    // The account is looked up in the database every time, so a deleted account (or a changed role) takes effect right away.
    // $asJson = true is for the page's own background calls (the Home carousel's live update): instead of
    // sending the visitor to another page it just answers "401 not allowed".
    function requireUser($asJson = false) {
        if (!isset($_SESSION["user_id"])) {
            if ($asJson) { http_response_code(401); exit(); }
            header("Location: index");
            exit();
        }

        include_once __DIR__ . "/../connection/config.php";
        $con = connection();
        $uid = (int) $_SESSION["user_id"];
        $stmt = $con->prepare("SELECT Role FROM users WHERE User_ID = ? LIMIT 1");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {                       // the account no longer exists
            if ($asJson) { http_response_code(401); exit(); }
            header("Location: logout");
            exit();
        }
        $_SESSION["role"] = $row["Role"];
        if (isAdminRole($row["Role"])) {
            if ($asJson) { http_response_code(401); exit(); }
            header("Location: studentlist");
            exit();
        }
    }

    // Put this at the top of every ADMIN page. The role is checked in the database every time,
    // so an account that stops being an admin loses access right away.
    function requireAdmin($con) {
        if (!isset($_SESSION["user_id"])) {
            header("Location: index");
            exit();
        }

        $uid = (int) $_SESSION["user_id"];
        $stmt = $con->prepare("SELECT Role FROM users WHERE User_ID = ? LIMIT 1");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {                       // the account no longer exists
            header("Location: logout");
            exit();
        }
        $_SESSION["role"] = $row["Role"];
        if (!isAdminRole($row["Role"])) {
            header("Location: dashboard");
            exit();
        }
    }
?>
