<?php
session_start();
include("./helpers/nocache.php");
include("./connection/config.php");
include("./helpers/SystemOperators.php");
include("./helpers/functions.php");
include("./helpers/auth.php");
$con = connection();
$so = new SystemOperators();
requireAdmin($con);          // only admins can open this page

$userId = (int) $_SESSION["user_id"];
$activePage = "staffs";
$perPage = 12;               // staff per page

// Sort + account filter options
$sortOptions = ["latest" => "Latest added", "oldest" => "Oldest added", "az" => "A to Z", "za" => "Z to A"];
$acctOptions = ["all" => "Any", "yes" => "Has an account", "no" => "No account yet"];

// ---------------------------------------------------------------------------
// Small helpers used only on this page (same ones as the Students page)
// ---------------------------------------------------------------------------
function e($text) { return htmlspecialchars((string) $text, ENT_QUOTES, "UTF-8"); }

function getStr($key) { return (isset($_GET[$key]) && is_string($_GET[$key])) ? $_GET[$key] : ""; }
function postStr($key) { return (isset($_POST[$key]) && is_string($_POST[$key])) ? $_POST[$key] : ""; }

// trims and turns many spaces into one
function cleanText($text) { return trim(preg_replace('/\s+/u', ' ', (string) $text)); }

// Reads a saved name/department. This page saves them encrypted (like the student names), but rows typed
// directly into the database as plain text are shown too. Gives back plain text ("" when empty).
function staffText($so, $value) {
    $value = (string) $value;
    if ($value === "") { return ""; }
    $plain = @$so->decrypt($value);
    if ($plain === false || $plain === "") { return html_entity_decode($value, ENT_QUOTES, "UTF-8"); }
    return html_entity_decode((string) $plain, ENT_QUOTES, "UTF-8");
}

function fetchAll($con, $sql, $types = "", $params = []) {
    $stmt = $con->prepare($sql);
    if ($types !== "") { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function pageUrl($params) {
    $q = http_build_query($params);
    return "stafflist" . ($q !== "" ? "?" . $q : "");
}

function goBack($params, $extra = [], $anchor = "") {
    header("Location: " . pageUrl(array_merge($params, $extra)) . ($anchor !== "" ? "#" . $anchor : ""));
    exit();
}

// Reads + checks the Add/Edit form. Returns [cleaned values, error text ("" = all good)]
function readStaffForm() {
    $d = [
        "no"     => preg_replace('/\s+/', '', postStr("staff_no")),
        "last"   => cleanText(postStr("last_name")),
        "first"  => cleanText(postStr("first_name")),
        "middle" => cleanText(postStr("middle_name")),
        "dept"   => cleanText(postStr("department")),
    ];
    $err = "";

    if ($d["no"] === "" || $d["last"] === "" || $d["first"] === "" || $d["dept"] === "") {
        $err = "Please fill in all required fields.";
    } elseif (!ctype_digit($d["no"]) || strlen($d["no"]) < 3 || strlen($d["no"]) > 9) {
        $err = "Staff ID must be a number with 3 to 9 digits, for example 900001.";
    } elseif (mb_strlen($d["last"]) > 100 || mb_strlen($d["first"]) > 100 || mb_strlen($d["middle"]) > 100) {
        $err = "Names must be 100 characters or less.";
    } elseif (mb_strlen($d["dept"]) > 100) {
        $err = "Department must be 100 characters or less.";
    }

    if ($d["middle"] === "") { $d["middle"] = "N/A"; }   // the middle name is optional
    return [$d, $err];
}

// ---------------------------------------------------------------------------
// 1) Read the search + filters from the address bar
// ---------------------------------------------------------------------------
$departments = array_column(fetchAll($con, "SELECT DISTINCT Department FROM stafflist_info WHERE Department <> '' ORDER BY Department"), "Department");

$q = mb_substr(cleanText(getStr("q")), 0, 100);
$dept = in_array(getStr("dept"), $departments, true) ? getStr("dept") : "all";
$acct = isset($acctOptions[getStr("acct")]) ? getStr("acct") : "all";
$sort = isset($sortOptions[getStr("sort")]) ? getStr("sort") : "latest";

$baseParams = [];                                   // only non-default filters go in the URL
if ($q !== "")           { $baseParams["q"] = $q; }
if ($dept !== "all")     { $baseParams["dept"] = $dept; }
if ($acct !== "all")     { $baseParams["acct"] = $acct; }
if ($sort !== "latest")  { $baseParams["sort"] = $sort; }
$returnParams = $baseParams;                        // filters + the page of the list, so you stay where you were
$rawPage = (int) getStr("page");
if ($rawPage > 1) { $returnParams["page"] = $rawPage; }
$selfUrl = pageUrl($returnParams);                  // forms post here, so the filters and the page survive

// ---------------------------------------------------------------------------
// 2) Actions: add / edit / delete / admin access
// ---------------------------------------------------------------------------
$form = ["id" => 0, "no" => "", "last" => "", "first" => "", "middle" => "", "dept" => ""];
$formError = "";
$openForm = isset($_GET["new"]);
$editing = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !checkCsrf()) {
    $_SESSION["staff_flash"] = ["err", "Your session expired. Please try again."];
    goBack($returnParams);
}

// --- Add a staff member, or save changes of an existing one ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnStaff'])) {
    $editId = (int) postStr("student_id");     // (the form keeps the name "student_id" because the page script uses it)
    list($d, $err) = readStaffForm();

    // keep what was typed, in case there is an error to fix
    $form = ["id" => $editId, "no" => $d["no"], "last" => $d["last"], "first" => $d["first"],
             "middle" => ($d["middle"] === "N/A" ? "" : $d["middle"]), "dept" => $d["dept"]];
    $openForm = true;
    $editing = $editId;

    if ($err === "") {
        try {
            $staffNo = (int) $d["no"];
            $dup = fetchAll($con, "SELECT StaffListInfo_ID FROM stafflist_info WHERE StaffList_ID = ? AND StaffListInfo_ID <> ? LIMIT 1", "ii", [$staffNo, $editId]);

            if (count($dup) > 0) {
                $err = "That staff ID is already on the list.";
            } elseif ($editId > 0 && count(fetchAll($con, "SELECT StaffListInfo_ID FROM stafflist_info WHERE StaffListInfo_ID = ? LIMIT 1", "i", [$editId])) === 0) {
                $err = "That staff member is no longer on the list.";
            } else {
                $lastEnc   = $so->encrypt($d["last"]);
                $firstEnc  = $so->encrypt($d["first"]);
                $middleEnc = $so->encrypt($d["middle"]);

                if ($editId > 0) {
                    $save_stmt = $con->prepare("UPDATE `stafflist_info` SET `StaffList_ID` = ?, `Last_Name` = ?, `First_Name` = ?, `Middle_Name` = ?, `Department` = ? WHERE `StaffListInfo_ID` = ?");
                    $save_stmt->bind_param('issssi', $staffNo, $lastEnc, $firstEnc, $middleEnc, $d["dept"], $editId);
                    $save_stmt->execute();
                    $savedId = $editId;
                    $_SESSION["staff_flash"] = ["ok", "Changes saved."];
                } else {
                    $save_stmt = $con->prepare("INSERT INTO `stafflist_info` (`StaffList_ID`, `Last_Name`, `First_Name`, `Middle_Name`, `Department`) VALUES (?, ?, ?, ?, ?)");
                    $save_stmt->bind_param('issss', $staffNo, $lastEnc, $firstEnc, $middleEnc, $d["dept"]);
                    $save_stmt->execute();
                    $savedId = $save_stmt->insert_id;
                    $_SESSION["staff_flash"] = ["ok", "Staff member added to the list."];
                }
                goBack($returnParams, [], "staff-" . $savedId);
            }
        } catch (mysqli_sql_exception $ex) {
            $err = "Something went wrong. Please try again.";
        }
    }
    $formError = $err;
}

// --- Delete a staff member (the page asks first: see the confirm pop-up further down) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnDelete'])) {
    $delId = (int) postStr("student_id");
    $message = ["err", "Something went wrong. Please try again."];

    try {
        $row = fetchAll($con, "SELECT StaffListInfo_ID, User_ID FROM stafflist_info WHERE StaffListInfo_ID = ? LIMIT 1", "i", [$delId]);
        if (count($row) === 0) {
            $message = ["err", "That staff member is no longer on the list."];
        } else {
            $linkedUser = ($row[0]["User_ID"] === null) ? 0 : (int) $row[0]["User_ID"];

            if ($linkedUser > 0 && $linkedUser === $userId) {
                $message = ["err", "You can't delete your own record."];
            } else {
                $con->begin_transaction();
                if ($linkedUser > 0) {
                    // their account goes too (and with it that account's reports, comments and hearts)
                    $del_user = $con->prepare("DELETE FROM `users` WHERE `User_ID` = ?");
                    $del_user->bind_param('i', $linkedUser);
                    $del_user->execute();
                }
                $del_staff = $con->prepare("DELETE FROM `stafflist_info` WHERE `StaffListInfo_ID` = ?");
                $del_staff->bind_param('i', $delId);
                $del_staff->execute();
                $con->commit();
                $message = ["ok", "Staff member deleted."];
            }
        }
    } catch (mysqli_sql_exception $ex) {
        try { $con->rollback(); } catch (mysqli_sql_exception $ignore) { }
    }
    $_SESSION["staff_flash"] = $message;
    goBack($returnParams);
}

// --- Give or take away ADMIN access (the staff member needs an account first) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnAccess'])) {
    $accId = (int) postStr("student_id");
    $newRole = (postStr("role") === "admin") ? "admin" : "user";
    $message = ["err", "Something went wrong. Please try again."];

    try {
        $row = fetchAll($con, "SELECT f.User_ID, u.Role FROM stafflist_info f LEFT JOIN users u ON u.User_ID = f.User_ID WHERE f.StaffListInfo_ID = ? LIMIT 1", "i", [$accId]);
        if (count($row) === 0) {
            $message = ["err", "That staff member is no longer on the list."];
        } elseif ($row[0]["User_ID"] === null || $row[0]["Role"] === null) {
            $message = ["err", "This staff member has no account yet. They get one when they sign up with their staff ID."];
        } elseif ((int) $row[0]["User_ID"] === $userId) {
            $message = ["err", "You can't change your own access."];
        } else {
            $linked = (int) $row[0]["User_ID"];
            $upd = $con->prepare("UPDATE `users` SET `Role` = ? WHERE `User_ID` = ?");
            $upd->bind_param('si', $newRole, $linked);
            $upd->execute();
            $upd->close();
            $message = ["ok", $newRole === "admin" ? "Admin access given." : "Admin access removed. They are a regular user now."];
        }
    } catch (mysqli_sql_exception $ex) { }
    $_SESSION["staff_flash"] = $message;
    goBack($returnParams, [], "staff-" . $accId);
}

// --- Edit button: open the form with this staff member's details filled in ---
$editParam = (int) getStr("edit");
if ($editParam > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $found = fetchAll($con, "SELECT StaffListInfo_ID, StaffList_ID, Last_Name, First_Name, Middle_Name, Department FROM stafflist_info WHERE StaffListInfo_ID = ? LIMIT 1", "i", [$editParam]);
    if (count($found) === 0) {
        $_SESSION["staff_flash"] = ["err", "That staff member is no longer on the list."];
        goBack($returnParams);
    }
    $r = $found[0];
    $middle = staffText($so, $r["Middle_Name"]);
    $form = ["id" => $editParam, "no" => (string) $r["StaffList_ID"], "last" => staffText($so, $r["Last_Name"]),
             "first" => staffText($so, $r["First_Name"]), "middle" => (strcasecmp($middle, "N/A") === 0 ? "" : $middle),
             "dept" => (string) $r["Department"]];
    $openForm = true;
    $editing = $editParam;
}

// ---------------------------------------------------------------------------
// 3) Load the list
//    Names are encrypted in the database, so searching and A-Z sorting are done here in PHP
//    (department + account filters are done by MySQL).
// ---------------------------------------------------------------------------
$where = [];
$types = "";
$params = [];
if ($dept !== "all")   { $where[] = "f.Department = ?"; $types .= "s"; $params[] = $dept; }
if ($acct === "yes")   { $where[] = "f.User_ID IS NOT NULL"; }    // has an account
if ($acct === "no")    { $where[] = "f.User_ID IS NULL"; }        // no account yet

$raw = fetchAll(
    $con,
    "SELECT f.StaffListInfo_ID, f.StaffList_ID, f.User_ID, f.Last_Name, f.First_Name, f.Middle_Name, f.Department,
            u.Role AS Account_Role
     FROM stafflist_info f
     LEFT JOIN users u ON u.User_ID = f.User_ID " . (count($where) ? "WHERE " . implode(" AND ", $where) : "") . "
     ORDER BY f.StaffListInfo_ID " . ($sort === "oldest" ? "ASC" : "DESC"),
    $types,
    $params
);

$staff = [];
$tokens = ($q === "") ? [] : explode(" ", mb_strtolower($q));
foreach ($raw as $r) {
    $middle = staffText($so, $r["Middle_Name"]);
    if (strcasecmp($middle, "N/A") === 0) { $middle = ""; }

    $s = [
        "id"      => (int) $r["StaffListInfo_ID"],
        "no"      => (string) $r["StaffList_ID"],
        "last"    => staffText($so, $r["Last_Name"]),
        "first"   => staffText($so, $r["First_Name"]),
        "middle"  => $middle,
        "dept"    => (string) $r["Department"],
        "account" => ($r["User_ID"] !== null),
        "admin"   => ($r["User_ID"] !== null && isAdminRole($r["Account_Role"] ?? "")),
        "isMe"    => ($r["User_ID"] !== null && (int) $r["User_ID"] === $userId),
    ];
    $s["full"] = $s["last"] . ", " . $s["first"] . ($middle !== "" ? " " . $middle : "");

    // search: every word typed must appear somewhere in the ID, the name or the department (in any order)
    if (count($tokens) > 0) {
        $hay = mb_strtolower($s["no"] . " " . $s["first"] . " " . $middle . " " . $s["last"] . " " . $s["full"] . " " . $s["dept"]);
        $match = true;
        foreach ($tokens as $t) {
            if ($t !== "" && mb_strpos($hay, $t) === false) { $match = false; break; }
        }
        if (!$match) { continue; }
    }
    $s["key"] = mb_strtolower($s["last"] . "\0" . $s["first"] . "\0" . $middle);
    $staff[] = $s;
}

if ($sort === "az" || $sort === "za") {
    usort($staff, function ($a, $b) { return strcmp($a["key"], $b["key"]); });
    if ($sort === "za") { $staff = array_reverse($staff); }
}

$totalStaff = count($staff);
$totalPages = max(1, (int) ceil($totalStaff / $perPage));
$page = min(max(1, (int) getStr("page")), $totalPages);
$pageRows = array_slice($staff, ($page - 1) * $perPage, $perPage);

$everyone = (int) fetchAll($con, "SELECT COUNT(*) AS n FROM stafflist_info")[0]["n"];

// Delete pop-up (opens when the address has ?confirm=ID)
$confirmRow = null;
$confirmId = (int) getStr("confirm");
if ($confirmId > 0) {
    $c = fetchAll($con, "SELECT StaffListInfo_ID, StaffList_ID, User_ID, Last_Name, First_Name, Middle_Name FROM stafflist_info WHERE StaffListInfo_ID = ? LIMIT 1", "i", [$confirmId]);
    if (count($c) === 1) {
        $cm = staffText($so, $c[0]["Middle_Name"]);
        $confirmRow = [
            "id"      => $confirmId,
            "name"    => staffText($so, $c[0]["First_Name"]) . ($cm !== "" && strcasecmp($cm, "N/A") !== 0 ? " " . $cm : "") . " " . staffText($so, $c[0]["Last_Name"]),
            "no"      => (string) $c[0]["StaffList_ID"],
            "account" => ($c[0]["User_ID"] !== null),
            "isMe"    => ($c[0]["User_ID"] !== null && (int) $c[0]["User_ID"] === $userId),
            "reports" => ($c[0]["User_ID"] === null) ? 0 : (int) fetchAll($con, "SELECT COUNT(*) AS n FROM report_items WHERE User_ID = ?", "i", [(int) $c[0]["User_ID"]])[0]["n"],
        ];
    }
}

// Admin access pop-up (opens when the address has ?access=ID)
$accessRow = null;
$accessId = (int) getStr("access");
if ($accessId > 0) {
    $ac = fetchAll($con, "SELECT f.StaffListInfo_ID, f.StaffList_ID, f.User_ID, f.Last_Name, f.First_Name, f.Middle_Name, u.Role FROM stafflist_info f LEFT JOIN users u ON u.User_ID = f.User_ID WHERE f.StaffListInfo_ID = ? LIMIT 1", "i", [$accessId]);
    if (count($ac) === 1 && $ac[0]["User_ID"] !== null && (int) $ac[0]["User_ID"] !== $userId) {
        $am = staffText($so, $ac[0]["Middle_Name"]);
        $accessRow = [
            "id"    => $accessId,
            "name"  => staffText($so, $ac[0]["First_Name"]) . ($am !== "" && strcasecmp($am, "N/A") !== 0 ? " " . $am : "") . " " . staffText($so, $ac[0]["Last_Name"]),
            "no"    => (string) $ac[0]["StaffList_ID"],
            "admin" => isAdminRole($ac[0]["Role"] ?? ""),
        ];
    }
}
$closeConfirmUrl = $selfUrl;

// messages (shown once)
$flash = null;
if (isset($_SESSION["staff_flash"])) {
    $flash = $_SESSION["staff_flash"];
    unset($_SESSION["staff_flash"]);
}

// filter badge + removable chips
$filterCount = ($dept !== "all" ? 1 : 0) + ($acct !== "all" ? 1 : 0) + ($sort !== "latest" ? 1 : 0);
$chips = [];
if ($q !== "")           { $p = $baseParams; unset($p["q"]);    $chips[] = ["Search: " . $q, pageUrl($p)]; }
if ($dept !== "all")     { $p = $baseParams; unset($p["dept"]); $chips[] = [$dept, pageUrl($p)]; }
if ($acct !== "all")     { $p = $baseParams; unset($p["acct"]); $chips[] = [$acctOptions[$acct], pageUrl($p)]; }
if ($sort !== "latest")  { $p = $baseParams; unset($p["sort"]); $chips[] = [$sortOptions[$sort], pageUrl($p)]; }
$resetFiltersUrl = pageUrl($q !== "" ? ["q" => $q] : []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>admin:Staff List</title>
    
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:title" content="TamFinds">
    <meta property="og:description" content="Lost something? Let’s help you find it.">
    <meta property="og:image" content="https://www.tamfinds.online/images/logo.jpg">
    <meta property="og:url" content="https://www.tamfinds.online/">
    <meta property="og:type" content="website">

    <!-- Icon for tab -->
    <link href="images/logo.jpg" rel="icon" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Rubik+Mono+One&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
</head>
<body class="plain page-soft <?php echo (($confirmRow && !$confirmRow["isMe"]) || $accessRow) ? "modal-open" : ""; ?>" data-student-page>
    <?php include("./includes/admin_navbar.php"); ?>

    <main class="students" id="students">
        <header class="reports-head">
            <p class="kicker">Admin</p>
            <h1 class="section-title">Staffs</h1>
            <p class="section-sub">The official staff list. Only staff listed here can sign up with their staff ID, and you choose who keeps admin access. (<?php echo $everyone; ?> in total)</p>
        </header>

        <?php if ($flash) { ?>
            <div class="notice notice--<?php echo $flash[0] === "ok" ? "ok" : "err"; ?>" role="status"><?php echo e($flash[1]); ?></div>
        <?php } ?>

        <!-- Search + filters + add button -->
        <div class="toolbar">
            <form class="toolbar-form" method="GET" action="stafflist" role="search">
                <div class="search">
                    <button class="search-btn" type="submit" aria-label="Search">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
                    </button>
                    <input class="search-input" type="search" name="q" value="<?php echo e($q); ?>" placeholder="Search name, staff ID or department" maxlength="100" aria-label="Search name, staff ID or department">
                </div>

                <details class="filter">
                    <summary class="btn-filter">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/><circle cx="9" cy="7" r="2.2" fill="#fff"/><circle cx="15" cy="12" r="2.2" fill="#fff"/><circle cx="8" cy="17" r="2.2" fill="#fff"/></svg>
                        <span class="btn-filter-label">Filters</span>
                        <?php if ($filterCount > 0) { ?><span class="filter-badge"><?php echo $filterCount; ?></span><?php } ?>
                    </summary>

                    <div class="filter-panel">
                        <fieldset class="fgroup">
                            <legend>Sort by</legend>
                            <?php foreach ($sortOptions as $key => $label) { ?>
                                <label class="chip"><input type="radio" name="sort" value="<?php echo e($key); ?>" <?php echo $sort === $key ? "checked" : ""; ?>><span><?php echo e($label); ?></span></label>
                            <?php } ?>
                        </fieldset>

                        <?php if (count($departments) > 0) { ?>
                            <fieldset class="fgroup">
                                <legend>Department</legend>
                                <label class="chip"><input type="radio" name="dept" value="all" <?php echo $dept === "all" ? "checked" : ""; ?>><span>Any</span></label>
                                <?php foreach ($departments as $dp) { ?>
                                    <label class="chip"><input type="radio" name="dept" value="<?php echo e($dp); ?>" <?php echo $dept === $dp ? "checked" : ""; ?>><span><?php echo e($dp); ?></span></label>
                                <?php } ?>
                            </fieldset>
                        <?php } ?>

                        <fieldset class="fgroup">
                            <legend>Account</legend>
                            <?php foreach ($acctOptions as $key => $label) { ?>
                                <label class="chip"><input type="radio" name="acct" value="<?php echo e($key); ?>" <?php echo $acct === $key ? "checked" : ""; ?>><span><?php echo e($label); ?></span></label>
                            <?php } ?>
                        </fieldset>

                        <div class="filter-actions">
                            <a class="reset-link" href="<?php echo e($resetFiltersUrl); ?>">Reset filters</a>
                            <button class="btn" type="submit">Apply</button>
                        </div>
                    </div>
                </details>
            </form>

            <a class="btn btn--report" href="<?php echo e(pageUrl(array_merge($baseParams, ["new" => 1]))); ?>#student-form" data-open-student>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Staff
            </a>
        </div>

        <!-- Add / Edit form: opens right here on the page -->
        <section class="report-panel <?php echo $openForm ? "is-open" : ""; ?>" id="student-form" data-config="{}" aria-label="<?php echo $editing ? "Edit staff member" : "Add a staff member"; ?>">
            <form class="report-form" method="POST" action="<?php echo e($selfUrl); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="student_id" value="<?php echo (int) $editing; ?>">

                <h2 class="form-title"><?php echo $editing ? "Edit Staff Member" : "Add a Staff Member"; ?></h2>
                <p class="form-lead"><?php echo $editing
                    ? "Change the details below, then save."
                    : "Add a staff member to the official list. Once they are on it, they can sign up with their staff ID and get an admin account."; ?></p>

                <?php if ($formError !== "") { ?>
                    <div class="notice notice--err" role="alert"><?php echo e($formError); ?></div>
                <?php } ?>

                <div class="form-grid">
                    <div class="span-2">
                        <label class="label" for="staff_no">Staff ID *</label>
                        <input class="field" type="text" id="staff_no" name="staff_no" maxlength="9" inputmode="numeric" placeholder="e.g. 900001" autocomplete="off" value="<?php echo e($form["no"]); ?>" required>
                    </div>

                    <div>
                        <label class="label" for="last_name">Last name *</label>
                        <input class="field" type="text" id="last_name" name="last_name" maxlength="100" placeholder="e.g. Santos" value="<?php echo e($form["last"]); ?>" required>
                    </div>
                    <div>
                        <label class="label" for="first_name">First name *</label>
                        <input class="field" type="text" id="first_name" name="first_name" maxlength="100" placeholder="e.g. Maria" value="<?php echo e($form["first"]); ?>" required>
                    </div>

                    <div>
                        <label class="label" for="middle_name">Middle name (optional)</label>
                        <input class="field" type="text" id="middle_name" name="middle_name" maxlength="100" placeholder="Leave empty if none" value="<?php echo e($form["middle"]); ?>">
                    </div>
                    <div>
                        <label class="label" for="department">Department *</label>
                        <input class="field" type="text" id="department" name="department" maxlength="100" list="department-list" placeholder="e.g. Guidance Office" value="<?php echo e($form["dept"]); ?>" required>
                        <datalist id="department-list">
                            <?php foreach ($departments as $dp) { ?><option value="<?php echo e($dp); ?>"><?php } ?>
                        </datalist>
                    </div>
                </div>

                <div class="form-foot">
                    <p class="form-note">The staff ID must be unique. Staff sign up with this number.</p>
                    <div class="form-buttons">
                        <?php if ($editing) { ?>
                            <a class="btn btn--outline" href="<?php echo e($selfUrl); ?>">Cancel</a>
                        <?php } else { ?>
                            <a class="btn btn--outline" href="<?php echo e($selfUrl); ?>" data-close-student>Cancel</a>
                        <?php } ?>
                        <button type="submit" class="btn" name="btnStaff"><?php echo $editing ? "Save Changes" : "Add Staff"; ?></button>
                    </div>
                </div>
            </form>
        </section>

        <!-- Active filters -->
        <?php if (count($chips) > 0) { ?>
            <div class="chips-row">
                <?php foreach ($chips as $chip) { ?>
                    <a class="tag" href="<?php echo e($chip[1]); ?>" aria-label="Remove filter <?php echo e($chip[0]); ?>"><?php echo e($chip[0]); ?> <span aria-hidden="true">&times;</span></a>
                <?php } ?>
                <a class="tag tag--clear" href="stafflist">Clear all</a>
            </div>
        <?php } ?>

        <p class="result-count">
            <?php
                if ($totalStaff === 0) { echo "No staff"; }
                else { echo "Showing " . (($page - 1) * $perPage + 1) . "&ndash;" . (($page - 1) * $perPage + count($pageRows)) . " of " . $totalStaff . " staff"; }
            ?>
        </p>

        <!-- The list: thin cards -->
        <?php if (count($pageRows) === 0) { ?>
            <div class="empty">
                <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                <h2 class="empty-title"><?php echo ($everyone === 0) ? "No staff yet" : "No staff match your search"; ?></h2>
                <p><?php echo ($everyone === 0) ? "Add the first staff member so they can create an account." : "Try a different word or clear the filters."; ?></p>
                <?php if ($everyone > 0) { ?><a class="btn" href="stafflist">Clear filters</a><?php } ?>
            </div>
        <?php } else { ?>
            <div class="student-list student-list--staff">
                <div class="student-head" aria-hidden="true">
                    <span>Staff ID</span>
                    <span>Full Name</span>
                    <span>Department</span>
                    <span>Access</span>
                    <span></span>
                </div>

                <?php foreach ($pageRows as $s) { ?>
                    <article class="student-card <?php echo ($editing === $s["id"]) ? "is-editing" : ""; ?>" id="staff-<?php echo $s["id"]; ?>">
                        <div class="s-cell s-no" data-label="Staff ID"><?php echo e($s["no"]); ?></div>

                        <div class="s-cell s-name" data-label="Full Name">
                            <span class="s-name-text"><?php echo e($s["full"]); ?></span>
                            <?php if ($s["account"]) { ?>
                                <span class="s-account" title="Has an account" aria-label="Has an account">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                                </span>
                            <?php } ?>
                        </div>

                        <div class="s-cell" data-label="Department"><?php echo $s["dept"] !== "" ? e($s["dept"]) : '<span class="s-muted">&mdash;</span>'; ?></div>

                        <div class="s-cell" data-label="Access">
                            <?php if (!$s["account"]) { ?>
                                <span class="s-muted" title="They get an account when they sign up with their staff ID">No account</span>
                            <?php } elseif ($s["isMe"]) { ?>
                                <span class="access access--admin" title="This is your account">Admin (you)</span>
                            <?php } else { ?>
                                <a class="access <?php echo $s["admin"] ? "access--admin" : "access--user"; ?>" href="<?php echo e(pageUrl(array_merge($returnParams, ["access" => $s["id"]]))); ?>" title="Change access" aria-label="Change access for <?php echo e($s["full"]); ?>. Now: <?php echo $s["admin"] ? "Admin" : "User"; ?>">
                                    <?php echo $s["admin"] ? "Admin" : "User"; ?>
                                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                                </a>
                            <?php } ?>
                        </div>

                        <div class="s-actions">
                            <a class="icon-btn icon-btn--sm" href="<?php echo e(pageUrl(array_merge($returnParams, ["edit" => $s["id"]]))); ?>#student-form" aria-label="Edit <?php echo e($s["full"]); ?>" title="Edit">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                            </a>
                            <?php if (!$s["isMe"]) { ?>
                                <a class="icon-btn icon-btn--sm icon-btn--danger" href="<?php echo e(pageUrl(array_merge($returnParams, ["confirm" => $s["id"]]))); ?>" aria-label="Delete <?php echo e($s["full"]); ?>" title="Delete">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                </a>
                            <?php } ?>
                        </div>
                    </article>
                <?php } ?>
            </div>

            <?php if ($totalPages > 1) { ?>
                <nav class="pager" aria-label="Pages">
                    <?php if ($page > 1) { ?>
                        <a class="icon-btn" href="<?php echo e(pageUrl(array_merge($baseParams, ["page" => $page - 1]))); ?>" aria-label="Previous page"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></a>
                    <?php } else { ?>
                        <span class="icon-btn is-disabled" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></span>
                    <?php } ?>

                    <span class="pager-text">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>

                    <?php if ($page < $totalPages) { ?>
                        <a class="icon-btn" href="<?php echo e(pageUrl(array_merge($baseParams, ["page" => $page + 1]))); ?>" aria-label="Next page"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></a>
                    <?php } else { ?>
                        <span class="icon-btn is-disabled" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    <?php } ?>
                </nav>
            <?php } ?>
        <?php } ?>
    </main>

    <?php if ($accessRow) { ?>
        <!-- Admin access (opened by the Access button of a staff member that has an account) -->
        <div class="modal is-open" role="dialog" aria-modal="true" aria-labelledby="accessTitle" data-close-url="<?php echo e($closeConfirmUrl); ?>">
            <a class="modal-backdrop" href="<?php echo e($closeConfirmUrl); ?>" aria-label="Close"></a>

            <div class="modal-box">
                <div class="modal-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                </div>
                <h2 class="modal-title" id="accessTitle">Admin access</h2>
                <p class="modal-text">
                    <strong><?php echo e($accessRow["name"]); ?></strong> (<?php echo e($accessRow["no"]); ?>)<br>
                    Admins can open the admin pages. While someone is an admin they can't use the student pages (Home, Report, Lost Items).
                </p>

                <form method="POST" action="<?php echo e($closeConfirmUrl); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="student_id" value="<?php echo $accessRow["id"]; ?>">
                    <div class="access-choice">
                        <label class="chip"><input type="radio" name="role" value="user" <?php echo !$accessRow["admin"] ? "checked" : ""; ?>><span>User</span></label>
                        <label class="chip"><input type="radio" name="role" value="admin" <?php echo $accessRow["admin"] ? "checked" : ""; ?>><span>Admin</span></label>
                    </div>
                    <div class="modal-actions">
                        <a class="btn btn--outline" href="<?php echo e($closeConfirmUrl); ?>" autofocus>Cancel</a>
                        <button type="submit" class="btn" name="btnAccess">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php } ?>

    <?php if ($confirmRow && !$confirmRow["isMe"]) { ?>
        <!-- Delete confirmation (opened by the red trash button) -->
        <div class="modal is-open" role="alertdialog" aria-modal="true" aria-labelledby="deleteTitle" data-close-url="<?php echo e($closeConfirmUrl); ?>">
            <a class="modal-backdrop" href="<?php echo e($closeConfirmUrl); ?>" aria-label="Close"></a>

            <div class="modal-box">
                <div class="modal-icon modal-icon--danger" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </div>
                <h2 class="modal-title" id="deleteTitle">Delete this staff member?</h2>
                <p class="modal-text">
                    <strong><?php echo e($confirmRow["name"]); ?></strong> (<?php echo e($confirmRow["no"]); ?>)
                    will be removed from the staff list. This can't be undone.
                </p>
                <?php if ($confirmRow["account"]) { ?>
                    <p class="modal-warn">This staff member has an account. The account will be deleted too<?php echo $confirmRow["reports"] > 0 ? ", along with " . $confirmRow["reports"] . " report" . ($confirmRow["reports"] === 1 ? "" : "s") . " they posted" : ""; ?>.</p>
                <?php } ?>

                <form class="modal-actions" method="POST" action="<?php echo e($closeConfirmUrl); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="student_id" value="<?php echo $confirmRow["id"]; ?>">
                    <a class="btn btn--outline" href="<?php echo e($closeConfirmUrl); ?>" id="deleteCancel" autofocus>Cancel</a>
                    <button type="submit" class="btn btn--danger" name="btnDelete">Delete</button>
                </form>
            </div>
        </div>
    <?php } ?>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
</body>
</html>
