<?php
session_start();
include("./helpers/nocache.php");

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

include("./connection/config.php");
include("./helpers/SystemOperators.php");
include("./helpers/functions.php");
$con = connection();
$so = new SystemOperators();

$userId = (int) $_SESSION["user_id"];
$activePage = "report";

$categories = ["Electronics", "Wallets & Bags", "IDs & Cards", "Keys", "Clothing", "Books & School Supplies", "Accessories", "Bottles & Containers", "Others"];
$maxDesc = 500;     // description limit (characters)
$perPage = 8;       // reports per page
$maxPhoto = 5 * 1024 * 1024;   // 5 MB

// ---------------------------------------------------------------------------
// Small helpers used only on this page
// ---------------------------------------------------------------------------

// Runs a SELECT with optional bound values and returns every row
function fetchAll($con, $sql, $types = "", $params = []) {
    $stmt = $con->prepare($sql);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Turns any uploaded image (JPG/PNG/WEBP) into a smaller JPEG. Returns null if GD isn't available.
function resizeToJpeg($bin, $maxSide, $quality, $fixRotation = false) {
    if (!function_exists("imagecreatefromstring")) { return null; }
    @ini_set("memory_limit", "256M");

    $src = @imagecreatefromstring($bin);
    if (!$src) { return null; }

    // Phone photos can be "rotated" by a flag in the file: turn them the right way up
    if ($fixRotation && function_exists("exif_read_data")) {
        $exif = @exif_read_data("data://image/jpeg;base64," . base64_encode($bin));
        $turn = [3 => 180, 6 => -90, 8 => 90];
        if (!empty($exif["Orientation"]) && isset($turn[$exif["Orientation"]])) {
            $rotated = imagerotate($src, $turn[$exif["Orientation"]], 0);
            if ($rotated) { $src = $rotated; }
        }
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // white behind transparent PNGs
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    imagejpeg($dst, null, $quality);
    $out = ob_get_clean();
    return $out;
}

// "Oct 19" style short date from a DATETIME string
function shortDate($dt) { return date("M j", strtotime($dt)); }

// First ~160 characters of a long description (null when it is short enough to show fully)
function shortText($text, $limit = 160) {
    $flat = trim(preg_replace('/\s+/u', ' ', $text));
    if (mb_strlen($flat) <= $limit) { return null; }
    $cut = mb_substr($flat, 0, $limit);
    $space = mb_strrpos($cut, " ");
    if ($space !== false && $space > $limit * 0.6) { $cut = mb_substr($cut, 0, $space); }
    return rtrim($cut, " .,;:-") . "…";
}

function initialOf($name) {
    return mb_strtoupper(mb_substr(html_entity_decode($name, ENT_QUOTES, "UTF-8"), 0, 1));
}

function pageUrl($params) {
    $q = http_build_query($params);
    return "report.php" . ($q !== "" ? "?" . $q : "");
}

function e($text) { return htmlspecialchars((string) $text, ENT_QUOTES, "UTF-8"); }

// Checks the text fields shared by "Report Item" and "Edit report". Returns an error message ("" = all good).
function validateReportFields($form, $categories, $maxDesc) {
    $dateObj = DateTime::createFromFormat("Y-m-d", $form["date"]);
    $dateOk = $dateObj && $dateObj->format("Y-m-d") === $form["date"];

    if ($form["name"] === "" || $form["description"] === "" || $form["location"] === "" || $form["date"] === "" || $form["category"] === "") {
        return "Please fill in all required fields.";
    } elseif (mb_strlen($form["name"]) > 100) {
        return "Item name must be 100 characters or less.";
    } elseif (!in_array($form["category"], $categories, true)) {
        return "Please choose a category from the list.";
    } elseif (mb_strlen($form["description"]) > $maxDesc) {
        return "Description must be " . $maxDesc . " characters or less.";
    } elseif (mb_strlen($form["location"]) > 150) {
        return "Location must be 150 characters or less.";
    } elseif (!$dateOk || $form["date"] > date("Y-m-d", strtotime("+1 day"))) {
        return "Please pick a valid date that is not in the future.";
    }
    return "";
}

// Checks an optional uploaded photo. Returns [error message, image bytes]. No file chosen = ["", null].
function readPhotoUpload($key, $maxPhoto) {
    if (!isset($_FILES[$key]) || $_FILES[$key]["error"] === UPLOAD_ERR_NO_FILE) { return ["", null]; }

    $file = $_FILES[$key];
    if ($file["error"] === UPLOAD_ERR_INI_SIZE || $file["error"] === UPLOAD_ERR_FORM_SIZE) {
        return ["That photo is larger than this server allows (" . ini_get("upload_max_filesize") . "). Please choose a smaller one.", null];
    }
    if ($file["size"] > $maxPhoto) {
        return ["The photo is larger than 5 MB.", null];
    }
    if ($file["error"] !== UPLOAD_ERR_OK || !is_uploaded_file($file["tmp_name"])) {
        return ["The photo could not be uploaded. Please try again.", null];
    }

    $info = @getimagesize($file["tmp_name"]);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return ["The photo must be a JPG, PNG, or WEBP image.", null];
    }
    if ($info[0] * $info[1] > 24000000) {
        return ["The photo's resolution is too large.", null];
    }

    $raw = file_get_contents($file["tmp_name"]);
    $small = resizeToJpeg($raw, 1600, 82, $info[2] === IMAGETYPE_JPEG);   // keeps the database small
    return ["", ($small !== null) ? $small : $raw];
}

// Every reply under $id (any depth), in conversation order, each paired with the name of who it answers.
function flattenReplies($id, $parentName, $ctx, &$out) {
    foreach ($ctx["replies"][$id] ?? [] as $kid) {
        $out[] = [$kid, $parentName];
        $kidName = displayName($ctx["so"], $kid["First_Name"], $kid["Last_Name"], $kid["Username"]);
        flattenReplies((int) $kid["Comment_ID"], $kidName, $ctx, $out);
    }
}

// A comment plus everything under it. Replies are indented for 2 levels; anything deeper stays at the
// same indent (as a flat list with "@name") so long threads never get squeezed on a phone.
function renderBranch($c, $depth, $ctx, $replyTo = "") {
    $html = renderComment($c, $depth, $ctx, $replyTo);

    if ($depth >= 2) {
        $name = displayName($ctx["so"], $c["First_Name"], $c["Last_Name"], $c["Username"]);
        $flat = [];
        flattenReplies((int) $c["Comment_ID"], $name, $ctx, $flat);
        foreach ($flat as $pair) {
            $html .= renderComment($pair[0], $depth, $ctx, $pair[1]);
        }
    }
    return $html;
}

// Draws ONE comment (its replies are drawn by renderBranch).
// $ctx = ["so" => ..., "self" => form action, "uid" => logged in user, "rid" => report id, "replies" => [comment_id => replies]]
function renderComment($c, $depth, $ctx, $replyTo = "") {
    $so = $ctx["so"];
    $cid = (int) $c["Comment_ID"];
    $rid = (int) $ctx["rid"];
    $isMine = ((int) $c["User_ID"] === (int) $ctx["uid"]);
    $liked = ((int) $c["Liked"] > 0);
    $cName = displayName($so, $c["First_Name"], $c["Last_Name"], $c["Username"]);
    $ago = timeAgo($c["Age"]);
    $kids = $ctx["replies"][$cid] ?? [];

    ob_start();
?>
    <div class="comment <?php echo $depth > 0 ? "comment--reply" : ""; ?>" id="c-<?php echo $cid; ?>">
        <span class="avatar <?php echo $depth > 0 ? "avatar--sm" : ""; ?>" aria-hidden="true"><?php echo e(initialOf($cName)); ?></span>
        <div class="comment-body">
            <div class="comment-bubble">
                <span class="comment-name"><?php echo $cName; ?></span>
                <span class="comment-time"><?php echo e($ago !== "" ? $ago : shortDate($c["Posted_At"])); ?><?php echo !empty($c["Edited_At"]) ? " &middot; edited" : ""; ?></span>
                <p><?php if ($replyTo !== "") { ?><span class="reply-to">@<?php echo $replyTo; ?></span> <?php } ?><?php echo e($c["Comment_Text"]); ?></p>
            </div>

            <div class="comment-actions">
                <form class="inline-form" method="POST" action="<?php echo e($ctx["self"]); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="comment_id" value="<?php echo $cid; ?>">
                    <button type="submit" name="btnLike" class="like-btn <?php echo $liked ? "is-liked" : ""; ?>" aria-pressed="<?php echo $liked ? "true" : "false"; ?>" aria-label="<?php echo $liked ? "Remove heart" : "Heart this comment"; ?>">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 5.6a5.5 5.5 0 0 0-7.8 0L12 6.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 22l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
                        <span><?php echo (int) $c["Like_Count"]; ?></span>
                    </button>
                </form>
                <button type="button" class="reply-btn" data-toggle-reply="<?php echo $cid; ?>">Reply</button>

                <?php if ($isMine) { ?>
                    <button type="button" class="reply-btn" data-toggle-edit-comment="<?php echo $cid; ?>">Edit</button>
                    <form class="inline-form" method="POST" action="<?php echo e($ctx["self"]); ?>">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="comment_id" value="<?php echo $cid; ?>">
                        <input type="hidden" name="btnDeleteComment" value="1">
                        <button type="submit" class="reply-btn reply-btn--danger" data-confirm-delete data-confirm-title="Delete this <?php echo $depth > 0 ? "reply" : "comment"; ?>?" data-confirm-text="<?php echo count($kids) > 0 ? "This will also remove the replies under it. This can't be undone." : "This can't be undone."; ?>">Delete</button>
                    </form>
                <?php } ?>
            </div>

            <?php if ($isMine) { ?>
                <form class="edit-form" id="edit-<?php echo $cid; ?>" method="POST" action="<?php echo e($ctx["self"]); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="comment_id" value="<?php echo $cid; ?>">
                    <textarea class="comment-input" name="comment_text" rows="2" maxlength="500" aria-label="Edit your comment" required><?php echo e($c["Comment_Text"]); ?></textarea>
                    <button type="submit" class="btn btn--sm" name="btnEditComment">Save</button>
                    <button type="button" class="btn btn--outline btn--sm cancel-btn" data-cancel-edit-comment>Cancel</button>
                </form>
            <?php } ?>

            <?php if (count($kids) > 0 && $depth < 2) { ?>
                <div class="replies">
                    <?php foreach ($kids as $kid) { echo renderBranch($kid, $depth + 1, $ctx); } ?>
                </div>
            <?php } ?>

            <form class="reply-form" id="reply-<?php echo $cid; ?>" method="POST" action="<?php echo e($ctx["self"]); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="report_id" value="<?php echo $rid; ?>">
                <input type="hidden" name="parent_id" value="<?php echo $cid; ?>">
                <textarea class="comment-input" name="comment_text" rows="1" maxlength="500" placeholder="Reply to <?php echo e($cName); ?>" aria-label="Write a reply" required></textarea>
                <button type="submit" class="btn btn--sm" name="btnComment">Reply</button>
            </form>
        </div>
    </div>
<?php
    return ob_get_clean();
}

// ---------------------------------------------------------------------------
// 1) Photo endpoint: report.php?img=ID  (or &thumb=1 for the small card version)
//    The photo lives in the database, so this sends it to the browser.
// ---------------------------------------------------------------------------
if (isset($_GET["img"])) {
    $imgId = (int) $_GET["img"];
    $isThumb = isset($_GET["thumb"]);
    $etag = '"r' . $imgId . ($isThumb ? "t" : "f") . '"';

    // photos never change, so let the browser keep them for a day
    header_remove("Pragma");
    header_remove("Expires");
    header("Cache-Control: private, max-age=86400");
    header("ETag: " . $etag);

    if (isset($_SERVER["HTTP_IF_NONE_MATCH"]) && trim($_SERVER["HTTP_IF_NONE_MATCH"]) === $etag) {
        http_response_code(304);
        exit();
    }

    $rows = [];
    try {
        $rows = fetchAll($con, "SELECT Picture FROM report_items WHERE Report_ID = ? AND Picture IS NOT NULL LIMIT 1", "i", [$imgId]);
    } catch (mysqli_sql_exception $ex) {
        $rows = [];
    }

    if (count($rows) === 0) {
        header("Cache-Control: no-store");
        http_response_code(404);
        exit();
    }

    $bin = $rows[0]["Picture"];
    if ($isThumb) {
        $small = resizeToJpeg($bin, 520, 78);
        if ($small !== null) { $bin = $small; }
    }

    $mime = "application/octet-stream";
    if (substr($bin, 0, 3) === "\xFF\xD8\xFF") { $mime = "image/jpeg"; }
    elseif (substr($bin, 0, 8) === "\x89PNG\r\n\x1a\n") { $mime = "image/png"; }
    elseif (substr($bin, 0, 4) === "RIFF" && substr($bin, 8, 4) === "WEBP") { $mime = "image/webp"; }

    header("Content-Type: " . $mime);
    header("X-Content-Type-Options: nosniff");
    header("Content-Length: " . strlen($bin));
    echo $bin;
    exit();
}

// ---------------------------------------------------------------------------
// 2) Read the filters from the address bar (search + filter button)
// ---------------------------------------------------------------------------
$q = mb_substr(trim((string) ($_GET["q"] ?? "")), 0, 100);
$scope = (($_GET["scope"] ?? "all") === "mine") ? "mine" : "all";
$status = in_array($_GET["status"] ?? "all", ["Unresolved", "Resolved"], true) ? $_GET["status"] : "all";
$category = in_array($_GET["category"] ?? "all", $categories, true) ? $_GET["category"] : "all";

$sortOptions = [
    "latest" => ["Latest first", "r.Posted_At DESC, r.Report_ID DESC"],
    "oldest" => ["Oldest first", "r.Posted_At ASC, r.Report_ID ASC"],
    "az"     => ["A to Z",       "r.Item_Name ASC, r.Report_ID DESC"],
    "za"     => ["Z to A",       "r.Item_Name DESC, r.Report_ID DESC"],
];
$sort = isset($sortOptions[$_GET["sort"] ?? ""]) ? $_GET["sort"] : "latest";

// "View details" on the Home page opens report.php?item=1000901: only that one report is shown (search/filters are ignored)
$itemId = (int) ($_GET["item"] ?? 0);
if ($itemId > 0) {
    $q = "";
    $scope = "all";
    $status = "all";
    $category = "all";
    $sort = "latest";
}

// only the filters that are not the default go in the URL (keeps links short)
$baseParams = [];
if ($q !== "")            { $baseParams["q"] = $q; }
if ($scope !== "all")     { $baseParams["scope"] = $scope; }
if ($status !== "all")    { $baseParams["status"] = $status; }
if ($category !== "all")  { $baseParams["category"] = $category; }
if ($sort !== "latest")   { $baseParams["sort"] = $sort; }
if ($itemId > 0)          { $baseParams["item"] = $itemId; }

// Forms post here and go back here, so the filters AND the page number survive the reload
$returnParams = $baseParams;
if ((int) ($_GET["page"] ?? 1) > 1) { $returnParams["page"] = (int) $_GET["page"]; }
$selfUrl = pageUrl($returnParams);

function goBack($params, $extra = [], $anchor = "") {
    $url = pageUrl(array_merge($params, $extra));
    header("Location: " . $url . ($anchor !== "" ? "#" . $anchor : ""));
    exit();
}

// ---------------------------------------------------------------------------
// 3) Things people can do: post a report, change status, comment/reply, like
// ---------------------------------------------------------------------------
$form = ["name" => "", "category" => "", "description" => "", "location" => "", "date" => ""];
$formError = "";
$openForm = isset($_GET["new"]);

// If saving an edit fails, that card opens again with what the person typed
$editRid = 0;
$editError = "";
$editForm = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // When the upload is bigger than the server allows, PHP throws the whole form away
    if (empty($_POST) && empty($_FILES) && isset($_SERVER["CONTENT_LENGTH"]) && (int) $_SERVER["CONTENT_LENGTH"] > 0) {
        $_SESSION["report_flash"] = ["err", "That upload was too large for the server (limit " . ini_get("post_max_size") . "). Photos can be up to 5 MB."];
        goBack($returnParams, ["new" => 1]);
    }
    if (!checkCsrf()) {
        $_SESSION["report_flash"] = ["err", "Your session expired. Please try again."];
        goBack($returnParams);
    }
}

// --- Submit a new report ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnReport'])) {
    $openForm = true;
    $form["name"]        = trim((string) ($_POST["item_name"] ?? ""));
    $form["category"]    = (string) ($_POST["category"] ?? "");
    $form["description"] = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST["description"] ?? "")));
    $form["location"]    = trim((string) ($_POST["location_lost"] ?? ""));
    $form["date"]        = (string) ($_POST["date_lost"] ?? "");

    $formError = validateReportFields($form, $categories, $maxDesc);

    // Optional photo
    $picBin = null;
    if ($formError === "") {
        list($formError, $picBin) = readPhotoUpload("picture", $maxPhoto);
    }

    if ($formError === "") {
        // The poster's Student_ID comes from the student_info table
        $student = fetchAll($con, "SELECT Student_ID FROM student_info WHERE User_ID = ? LIMIT 1", "i", [$userId]);

        if (count($student) === 0) {
            $formError = "Your account has no student record yet, so a report can't be posted.";
        } else {
            $studentId = (int) $student[0]["Student_ID"];
            $dtLost = $form["date"] . " 00:00:00";

            try {
                // The report and its Unique_ID are saved together (all or nothing)
                $con->begin_transaction();

                if ($picBin !== null) {
                    $insert_query = "INSERT INTO `report_items` (`Student_ID`, `User_ID`, `Item_Name`, `Category`, `Description`, `Location_Lost`, `Datetime_Lost`, `Picture`, `Status`, `Posted_At`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Unresolved', NOW())";
                    $insert_stmt = $con->prepare($insert_query);
                    $insert_stmt->bind_param('iissssss', $studentId, $userId, $form["name"], $form["category"], $form["description"], $form["location"], $dtLost, $picBin);
                } else {
                    $insert_query = "INSERT INTO `report_items` (`Student_ID`, `User_ID`, `Item_Name`, `Category`, `Description`, `Location_Lost`, `Datetime_Lost`, `Status`, `Posted_At`) VALUES (?, ?, ?, ?, ?, ?, ?, 'Unresolved', NOW())";
                    $insert_stmt = $con->prepare($insert_query);
                    $insert_stmt->bind_param('iisssss', $studentId, $userId, $form["name"], $form["category"], $form["description"], $form["location"], $dtLost);
                }
                $insert_stmt->execute();
                $newId = (int) $insert_stmt->insert_id;
                $insert_stmt->close();

                // Unique_ID: 1000901, 1000902, 1000903 ... (the Report_ID counted up from UNIQUE_ID_START)
                $uid_stmt = $con->prepare("UPDATE `report_items` SET `Unique_ID` = `Report_ID` + " . (UNIQUE_ID_START - 1) . " WHERE `Report_ID` = ?");
                $uid_stmt->bind_param('i', $newId);
                $uid_stmt->execute();
                $uid_stmt->close();

                $con->commit();

                $_SESSION["report_flash"] = ["ok", "Your report was posted. It starts as Unresolved."];
                goBack([], [], "reports");
            } catch (mysqli_sql_exception $ex) {
                try { $con->rollback(); } catch (mysqli_sql_exception $ignored) { }

                if (stripos($ex->getMessage(), "max_allowed_packet") !== false || stripos($ex->getMessage(), "packet") !== false) {
                    $formError = "That photo is too big for the database settings. Try a smaller photo (or raise max_allowed_packet in XAMPP's my.ini).";
                } else {
                    $formError = "Something went wrong. Please try again.";
                }
            }
        }
    }
}

// --- Poster changes the status (Unresolved <-> Resolved) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnStatus'])) {
    $rid = (int) ($_POST["report_id"] ?? 0);
    $newStatus = (($_POST["new_status"] ?? "") === "Resolved") ? "Resolved" : "Unresolved";

    try {
        $owner = fetchAll($con, "SELECT User_ID FROM report_items WHERE Report_ID = ? LIMIT 1", "i", [$rid]);
        if (count($owner) === 0) {
            $_SESSION["report_flash"] = ["err", "That report no longer exists."];
        } elseif ((int) $owner[0]["User_ID"] !== $userId) {
            $_SESSION["report_flash"] = ["err", "Only the person who posted this report can change its status."];
        } else {
            $update_stmt = $con->prepare("UPDATE `report_items` SET `Status` = ? WHERE `Report_ID` = ? AND `User_ID` = ?");
            $update_stmt->bind_param('sii', $newStatus, $rid, $userId);
            $update_stmt->execute();
            $_SESSION["report_flash"] = ["ok", "Report marked as " . $newStatus . "."];
        }
    } catch (mysqli_sql_exception $ex) {
        $_SESSION["report_flash"] = ["err", "Something went wrong. Please try again."];
    }
    goBack($returnParams, [], "report-" . $rid);
}

// --- Post a comment or a reply ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnComment'])) {
    $rid = (int) ($_POST["report_id"] ?? 0);
    $parentId = (int) ($_POST["parent_id"] ?? 0);
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST["comment_text"] ?? "")));
    $anchor = "comments-" . $rid;

    try {
        if ($text === "") {
            $_SESSION["report_flash"] = ["err", "Write something before posting."];
        } elseif (mb_strlen($text) > 500) {
            $_SESSION["report_flash"] = ["err", "Comments can be up to 500 characters."];
        } elseif (count(fetchAll($con, "SELECT Report_ID FROM report_items WHERE Report_ID = ? LIMIT 1", "i", [$rid])) === 0) {
            $_SESSION["report_flash"] = ["err", "That report no longer exists."];
        } else {
            $parentOk = true;
            if ($parentId > 0) {
                // a reply can answer any comment or reply, as long as it belongs to the SAME report
                $parent = fetchAll($con, "SELECT Report_ID FROM report_comments WHERE Comment_ID = ? LIMIT 1", "i", [$parentId]);
                $parentOk = count($parent) === 1 && (int) $parent[0]["Report_ID"] === $rid;
            }

            if (!$parentOk) {
                $_SESSION["report_flash"] = ["err", "You can't reply to that comment."];
            } else {
                $parentValue = ($parentId > 0) ? $parentId : null;
                $comment_stmt = $con->prepare("INSERT INTO `report_comments` (`Report_ID`, `User_ID`, `Parent_ID`, `Comment_Text`, `Posted_At`) VALUES (?, ?, ?, ?, NOW())");
                $comment_stmt->bind_param('iiis', $rid, $userId, $parentValue, $text);
                $comment_stmt->execute();
                $anchor = "c-" . $comment_stmt->insert_id;
            }
        }
    } catch (mysqli_sql_exception $ex) {
        $_SESSION["report_flash"] = ["err", "Something went wrong. Please try again."];
    }
    goBack($returnParams, ["open" => $rid], $anchor);
}

// --- Heart / un-heart a comment ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnLike'])) {
    $cid = (int) ($_POST["comment_id"] ?? 0);
    $rid = 0;

    try {
        $found = fetchAll($con, "SELECT Report_ID FROM report_comments WHERE Comment_ID = ? LIMIT 1", "i", [$cid]);
        if (count($found) === 1) {
            $rid = (int) $found[0]["Report_ID"];
            $already = fetchAll($con, "SELECT 1 FROM report_comment_likes WHERE Comment_ID = ? AND User_ID = ? LIMIT 1", "ii", [$cid, $userId]);

            if (count($already) > 0) {
                $like_stmt = $con->prepare("DELETE FROM `report_comment_likes` WHERE `Comment_ID` = ? AND `User_ID` = ?");
            } else {
                $like_stmt = $con->prepare("INSERT INTO `report_comment_likes` (`Comment_ID`, `User_ID`, `Liked_At`) VALUES (?, ?, NOW())");
            }
            $like_stmt->bind_param('ii', $cid, $userId);
            $like_stmt->execute();
        }
    } catch (mysqli_sql_exception $ex) {
        // double click = same row twice: nothing to do
    }
    goBack($returnParams, $rid > 0 ? ["open" => $rid] : [], $rid > 0 ? "c-" . $cid : "");
}

// --- Poster edits their report ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnEditReport'])) {
    $rid = (int) ($_POST["report_id"] ?? 0);
    $editVals = [
        "name"        => trim((string) ($_POST["item_name"] ?? "")),
        "category"    => (string) ($_POST["category"] ?? ""),
        "description" => trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST["description"] ?? ""))),
        "location"    => trim((string) ($_POST["location_lost"] ?? "")),
        "date"        => (string) ($_POST["date_lost"] ?? ""),
    ];

    try {
        $old = fetchAll($con, "SELECT User_ID, Item_Name, Category, Description, Location_Lost, Datetime_Lost, (Picture IS NOT NULL) AS Has_Pic FROM report_items WHERE Report_ID = ? LIMIT 1", "i", [$rid]);

        if (count($old) === 0) {
            $_SESSION["report_flash"] = ["err", "That report no longer exists."];
            goBack($returnParams);
        }
        if ((int) $old[0]["User_ID"] !== $userId) {
            $_SESSION["report_flash"] = ["err", "Only the person who posted this report can edit it."];
            goBack($returnParams, [], "report-" . $rid);
        }
        $old = $old[0];

        $err = validateReportFields($editVals, $categories, $maxDesc);
        $newPic = null;
        if ($err === "") {
            list($err, $newPic) = readPhotoUpload("picture", $maxPhoto);
        }
        $removePic = ($newPic === null && isset($_POST["remove_picture"]) && (int) $old["Has_Pic"] === 1);

        if ($err !== "") {
            $editRid = $rid;           // show the same card again, still open, with what was typed
            $editError = $err;
            $editForm = $editVals;
        } else {
            $unchanged = $editVals["name"] === $old["Item_Name"]
                && $editVals["category"] === $old["Category"]
                && $editVals["description"] === $old["Description"]
                && $editVals["location"] === $old["Location_Lost"]
                && $editVals["date"] === date("Y-m-d", strtotime($old["Datetime_Lost"]))
                && $newPic === null
                && !$removePic;

            if ($unchanged) {
                $_SESSION["report_flash"] = ["ok", "No changes to save."];
            } else {
                $dtLost = $editVals["date"] . " 00:00:00";

                if ($newPic !== null) {
                    $edit_stmt = $con->prepare("UPDATE `report_items` SET `Item_Name` = ?, `Category` = ?, `Description` = ?, `Location_Lost` = ?, `Datetime_Lost` = ?, `Picture` = ?, `Edited_At` = NOW() WHERE `Report_ID` = ? AND `User_ID` = ?");
                    $edit_stmt->bind_param('ssssssii', $editVals["name"], $editVals["category"], $editVals["description"], $editVals["location"], $dtLost, $newPic, $rid, $userId);
                } elseif ($removePic) {
                    $edit_stmt = $con->prepare("UPDATE `report_items` SET `Item_Name` = ?, `Category` = ?, `Description` = ?, `Location_Lost` = ?, `Datetime_Lost` = ?, `Picture` = NULL, `Edited_At` = NOW() WHERE `Report_ID` = ? AND `User_ID` = ?");
                    $edit_stmt->bind_param('sssssii', $editVals["name"], $editVals["category"], $editVals["description"], $editVals["location"], $dtLost, $rid, $userId);
                } else {
                    $edit_stmt = $con->prepare("UPDATE `report_items` SET `Item_Name` = ?, `Category` = ?, `Description` = ?, `Location_Lost` = ?, `Datetime_Lost` = ?, `Edited_At` = NOW() WHERE `Report_ID` = ? AND `User_ID` = ?");
                    $edit_stmt->bind_param('sssssii', $editVals["name"], $editVals["category"], $editVals["description"], $editVals["location"], $dtLost, $rid, $userId);
                }
                $edit_stmt->execute();
                $edit_stmt->close();
                $_SESSION["report_flash"] = ["ok", "Your report was updated."];
            }
            goBack($returnParams, [], "report-" . $rid);
        }
    } catch (mysqli_sql_exception $ex) {
        $editRid = $rid;
        $editForm = $editVals;
        if (stripos($ex->getMessage(), "packet") !== false) {
            $editError = "That photo is too big for the database settings. Try a smaller photo (or raise max_allowed_packet in XAMPP's my.ini).";
        } else {
            $editError = "Something went wrong. Please try again.";
        }
    }
}

// --- Poster deletes their report (its comments and hearts go with it) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnDeleteReport'])) {
    $rid = (int) ($_POST["report_id"] ?? 0);

    try {
        $owner = fetchAll($con, "SELECT User_ID FROM report_items WHERE Report_ID = ? LIMIT 1", "i", [$rid]);
        if (count($owner) === 0) {
            $_SESSION["report_flash"] = ["err", "That report no longer exists."];
        } elseif ((int) $owner[0]["User_ID"] !== $userId) {
            $_SESSION["report_flash"] = ["err", "Only the person who posted this report can delete it."];
        } else {
            $delete_stmt = $con->prepare("DELETE FROM `report_items` WHERE `Report_ID` = ? AND `User_ID` = ?");
            $delete_stmt->bind_param('ii', $rid, $userId);
            $delete_stmt->execute();
            $delete_stmt->close();
            $_SESSION["report_flash"] = ["ok", "Your report was deleted."];
        }
    } catch (mysqli_sql_exception $ex) {
        $_SESSION["report_flash"] = ["err", "Something went wrong. Please try again."];
    }

    $back = $returnParams;
    unset($back["item"]);          // the single report is gone, so go back to the full list
    goBack($back);
}

// --- Edit your own comment or reply ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnEditComment'])) {
    $cid = (int) ($_POST["comment_id"] ?? 0);
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST["comment_text"] ?? "")));
    $rid = 0;

    try {
        $found = fetchAll($con, "SELECT Report_ID, User_ID, Comment_Text FROM report_comments WHERE Comment_ID = ? LIMIT 1", "i", [$cid]);

        if (count($found) === 0) {
            $_SESSION["report_flash"] = ["err", "That comment no longer exists."];
        } else {
            $rid = (int) $found[0]["Report_ID"];

            if ((int) $found[0]["User_ID"] !== $userId) {
                $_SESSION["report_flash"] = ["err", "You can only edit your own comments."];
            } elseif ($text === "") {
                $_SESSION["report_flash"] = ["err", "A comment can't be empty."];
            } elseif (mb_strlen($text) > 500) {
                $_SESSION["report_flash"] = ["err", "Comments can be up to 500 characters."];
            } elseif ($text !== $found[0]["Comment_Text"]) {
                $cedit_stmt = $con->prepare("UPDATE `report_comments` SET `Comment_Text` = ?, `Edited_At` = NOW() WHERE `Comment_ID` = ? AND `User_ID` = ?");
                $cedit_stmt->bind_param('sii', $text, $cid, $userId);
                $cedit_stmt->execute();
                $cedit_stmt->close();
            }
        }
    } catch (mysqli_sql_exception $ex) {
        $_SESSION["report_flash"] = ["err", "Something went wrong. Please try again."];
    }
    goBack($returnParams, $rid > 0 ? ["open" => $rid] : [], $rid > 0 ? "c-" . $cid : "");
}

// --- Delete your own comment or reply (replies under it go with it) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnDeleteComment'])) {
    $cid = (int) ($_POST["comment_id"] ?? 0);
    $rid = 0;

    try {
        $found = fetchAll($con, "SELECT Report_ID, User_ID FROM report_comments WHERE Comment_ID = ? LIMIT 1", "i", [$cid]);

        if (count($found) === 0) {
            $_SESSION["report_flash"] = ["err", "That comment no longer exists."];
        } else {
            $rid = (int) $found[0]["Report_ID"];

            if ((int) $found[0]["User_ID"] !== $userId) {
                $_SESSION["report_flash"] = ["err", "You can only delete your own comments."];
            } else {
                $cdel_stmt = $con->prepare("DELETE FROM `report_comments` WHERE `Comment_ID` = ? AND `User_ID` = ?");
                $cdel_stmt->bind_param('ii', $cid, $userId);
                $cdel_stmt->execute();
                $cdel_stmt->close();
            }
        }
    } catch (mysqli_sql_exception $ex) {
        $_SESSION["report_flash"] = ["err", "Something went wrong. Please try again."];
    }
    goBack($returnParams, $rid > 0 ? ["open" => $rid] : [], $rid > 0 ? "comments-" . $rid : "");
}

// ---------------------------------------------------------------------------
// 4) Load the reports for the list (filters + search + sort + page)
// ---------------------------------------------------------------------------
$where = [];
$types = "";
$params = [];

if ($itemId > 0) {
    // opened from a Home page card: just that one report
    $where[] = "r.Unique_ID = ?";
    $types .= "i";
    $params[] = $itemId;
}
if ($itemId === 0 && $q !== "") {
    $where[] = "r.Item_Name LIKE ?";
    $types .= "s";
    $params[] = "%" . addcslashes($q, "%_\\") . "%";
}
if ($itemId === 0 && $scope === "mine") {
    $where[] = "r.User_ID = ?";
    $types .= "i";
    $params[] = $userId;
}
if ($itemId === 0 && $status !== "all") {
    $where[] = "r.Status = ?";
    $types .= "s";
    $params[] = $status;
}
if ($itemId === 0 && $category !== "all") {
    $where[] = "r.Category = ?";
    $types .= "s";
    $params[] = $category;
}
$whereSql = count($where) ? "WHERE " . implode(" AND ", $where) : "";

$countRow = fetchAll($con, "SELECT COUNT(*) AS n FROM report_items r " . $whereSql, $types, $params);
$totalReports = (int) $countRow[0]["n"];
$totalPages = max(1, (int) ceil($totalReports / $perPage));
$page = min(max(1, (int) ($_GET["page"] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;

$reports = fetchAll(
    $con,
    "SELECT r.Report_ID, r.User_ID, r.Item_Name, r.Category, r.Description, r.Location_Lost, r.Datetime_Lost, r.Status, r.Posted_At, r.Edited_At,
            COALESCE(UNIX_TIMESTAMP(r.Edited_At), 0) AS Edited_Ts,
            (r.Picture IS NOT NULL) AS Has_Pic,
            s.First_Name, s.Last_Name, u.Username
     FROM report_items r
     LEFT JOIN student_info s ON s.Student_ID = r.Student_ID
     LEFT JOIN users u ON u.User_ID = r.User_ID
     " . $whereSql . "
     ORDER BY " . $sortOptions[$sort][1] . "
     LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
    $types,
    $params
);

// Comments + replies for the reports on this page (one query)
$topComments = [];     // [report_id] => list of comments
$replies = [];         // [comment_id] => list of replies
$commentTotals = [];   // [report_id] => number of comments + replies

if (count($reports) > 0) {
    $ids = implode(",", array_map(function ($r) { return (int) $r["Report_ID"]; }, $reports));

    $comments = fetchAll(
        $con,
        "SELECT c.Comment_ID, c.Report_ID, c.User_ID, c.Parent_ID, c.Comment_Text, c.Edited_At,
                TIMESTAMPDIFF(SECOND, c.Posted_At, NOW()) AS Age, c.Posted_At,
                s.First_Name, s.Last_Name, u.Username,
                (SELECT COUNT(*) FROM report_comment_likes l WHERE l.Comment_ID = c.Comment_ID) AS Like_Count,
                (SELECT COUNT(*) FROM report_comment_likes l2 WHERE l2.Comment_ID = c.Comment_ID AND l2.User_ID = ?) AS Liked
         FROM report_comments c
         LEFT JOIN student_info s ON s.User_ID = c.User_ID
         LEFT JOIN users u ON u.User_ID = c.User_ID
         WHERE c.Report_ID IN (" . $ids . ")
         ORDER BY c.Posted_At ASC, c.Comment_ID ASC",
        "i",
        [$userId]
    );

    foreach ($comments as $c) {
        $rid = (int) $c["Report_ID"];
        $commentTotals[$rid] = ($commentTotals[$rid] ?? 0) + 1;
        if ($c["Parent_ID"] === null) {
            $topComments[$rid][] = $c;
        } else {
            $replies[(int) $c["Parent_ID"]][] = $c;
        }
    }
}

// Messages (shown once)
$flash = null;
if (isset($_SESSION["report_flash"])) {
    $flash = $_SESSION["report_flash"];
    unset($_SESSION["report_flash"]);
}

$openId = (int) ($_GET["open"] ?? 0);
$myName = "";
$meRow = fetchAll($con, "SELECT s.First_Name, s.Last_Name, u.Username FROM users u LEFT JOIN student_info s ON s.User_ID = u.User_ID WHERE u.User_ID = ? LIMIT 1", "i", [$userId]);
if (count($meRow) === 1) {
    $myName = displayName($so, $meRow[0]["First_Name"], $meRow[0]["Last_Name"], $meRow[0]["Username"]);
}

// filter badge + chips
$filterCount = 0;
if ($scope !== "all") { $filterCount++; }
if ($status !== "all") { $filterCount++; }
if ($category !== "all") { $filterCount++; }
if ($sort !== "latest") { $filterCount++; }

$chips = [];
if ($q !== "")           { $p = $baseParams; unset($p["q"]);        $chips[] = ["Search: " . $q, pageUrl($p)]; }
if ($scope !== "all")    { $p = $baseParams; unset($p["scope"]);    $chips[] = ["My posts", pageUrl($p)]; }
if ($status !== "all")   { $p = $baseParams; unset($p["status"]);   $chips[] = [$status, pageUrl($p)]; }
if ($category !== "all") { $p = $baseParams; unset($p["category"]); $chips[] = [$category, pageUrl($p)]; }
if ($sort !== "latest")  { $p = $baseParams; unset($p["sort"]);     $chips[] = [$sortOptions[$sort][0], pageUrl($p)]; }

if ($itemId > 0)         { $chips[] = ["Report #" . $itemId, "report.php"]; }

$resetFiltersUrl = pageUrl($q !== "" ? ["q" => $q] : []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TamFinds - Report</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Rubik+Mono+One&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
</head>
<body class="plain page-soft" data-report-page>
    <?php include("./includes/navbar.php"); ?>

    <main class="reports" id="reports">
        <header class="reports-head">
            <p class="kicker">Lost &amp; found board</p>
            <h1 class="section-title">Reports</h1>
            <p class="section-sub">Browse what has been reported on campus, or post your own lost item.</p>
        </header>

        <?php if ($flash) { ?>
            <div class="notice notice--<?php echo $flash[0] === "ok" ? "ok" : "err"; ?>" role="status"><?php echo e($flash[1]); ?></div>
        <?php } ?>

        <!-- Search + filters + report button -->
        <div class="toolbar">
            <form class="toolbar-form" method="GET" action="report.php" role="search">
                <div class="search">
                    <button class="search-btn" type="submit" aria-label="Search">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
                    </button>
                    <input class="search-input" type="search" name="q" value="<?php echo e($q); ?>" placeholder="Search item name" maxlength="100" aria-label="Search item name">
                </div>

                <details class="filter">
                    <summary class="btn-filter">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/><circle cx="9" cy="7" r="2.2" fill="#fff"/><circle cx="15" cy="12" r="2.2" fill="#fff"/><circle cx="8" cy="17" r="2.2" fill="#fff"/></svg>
                        <span class="btn-filter-label">Filters</span>
                        <?php if ($filterCount > 0) { ?><span class="filter-badge"><?php echo $filterCount; ?></span><?php } ?>
                    </summary>

                    <div class="filter-panel">
                        <fieldset class="fgroup">
                            <legend>Posts</legend>
                            <label class="chip"><input type="radio" name="scope" value="all" <?php echo $scope === "all" ? "checked" : ""; ?>><span>All posts</span></label>
                            <label class="chip"><input type="radio" name="scope" value="mine" <?php echo $scope === "mine" ? "checked" : ""; ?>><span>My posts</span></label>
                        </fieldset>

                        <fieldset class="fgroup">
                            <legend>Status</legend>
                            <label class="chip"><input type="radio" name="status" value="all" <?php echo $status === "all" ? "checked" : ""; ?>><span>Any</span></label>
                            <label class="chip"><input type="radio" name="status" value="Unresolved" <?php echo $status === "Unresolved" ? "checked" : ""; ?>><span>Unresolved</span></label>
                            <label class="chip"><input type="radio" name="status" value="Resolved" <?php echo $status === "Resolved" ? "checked" : ""; ?>><span>Resolved</span></label>
                        </fieldset>

                        <fieldset class="fgroup">
                            <legend>Category</legend>
                            <label class="chip"><input type="radio" name="category" value="all" <?php echo $category === "all" ? "checked" : ""; ?>><span>All</span></label>
                            <?php foreach ($categories as $cat) { ?>
                                <label class="chip"><input type="radio" name="category" value="<?php echo e($cat); ?>" <?php echo $category === $cat ? "checked" : ""; ?>><span><?php echo e($cat); ?></span></label>
                            <?php } ?>
                        </fieldset>

                        <fieldset class="fgroup">
                            <legend>Sort by</legend>
                            <?php foreach ($sortOptions as $key => $opt) { ?>
                                <label class="chip"><input type="radio" name="sort" value="<?php echo e($key); ?>" <?php echo $sort === $key ? "checked" : ""; ?>><span><?php echo e($opt[0]); ?></span></label>
                            <?php } ?>
                        </fieldset>

                        <div class="filter-actions">
                            <a class="reset-link" href="<?php echo e($resetFiltersUrl); ?>">Reset filters</a>
                            <button class="btn" type="submit">Apply</button>
                        </div>
                    </div>
                </details>
            </form>

            <button type="button" class="btn btn--report" data-open-report>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Report Item
            </button>
        </div>

        <!-- Report form: opens right here on the page -->
        <section class="report-panel <?php echo $openForm ? "is-open" : ""; ?>" id="report-form" aria-label="Report a lost item">
            <form class="report-form" method="POST" action="<?php echo e($selfUrl); ?>" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <h2 class="form-title">Report a Lost Item</h2>
                <p class="form-lead">Lost something on campus? Share the details below so the TamFinds team can review your report and help you find it.</p>

                <?php if ($formError !== "") { ?>
                    <div class="notice notice--err" role="alert"><?php echo e($formError); ?></div>
                <?php } ?>

                <div class="form-grid">
                    <div>
                        <label class="label" for="item_name">Item name *</label>
                        <input class="field" type="text" id="item_name" name="item_name" maxlength="100" placeholder="e.g. Black wallet" value="<?php echo e($form["name"]); ?>" required>
                    </div>
                    <div>
                        <label class="label" for="category">Category *</label>
                        <select class="field" id="category" name="category" required>
                            <option value="" disabled <?php echo $form["category"] === "" ? "selected" : ""; ?>>Choose a category</option>
                            <?php foreach ($categories as $cat) { ?>
                                <option value="<?php echo e($cat); ?>" <?php echo $form["category"] === $cat ? "selected" : ""; ?>><?php echo e($cat); ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="span-2">
                        <label class="label" for="description">Description *</label>
                        <textarea class="field field--area" id="description" name="description" maxlength="<?php echo $maxDesc; ?>" rows="4" placeholder="Describe the item, including its color, brand, identifying marks, or what was inside." data-counter-for="descCount" required><?php echo e($form["description"]); ?></textarea>
                        <span class="counter" id="descCount" data-max="<?php echo $maxDesc; ?>"><?php echo mb_strlen($form["description"]); ?> / <?php echo $maxDesc; ?></span>
                    </div>

                    <div>
                        <label class="label" for="location_lost">Where did you lose it? *</label>
                        <input class="field" type="text" id="location_lost" name="location_lost" maxlength="150" placeholder="e.g. Library, Room 204" value="<?php echo e($form["location"]); ?>" required>
                    </div>
                    <div>
                        <label class="label" for="date_lost">Date lost *</label>
                        <input class="field" type="date" id="date_lost" name="date_lost" max="<?php echo date("Y-m-d"); ?>" value="<?php echo e($form["date"]); ?>" required>
                    </div>

                    <div class="span-2">
                        <label class="label" for="picture">Item photo (optional)</label>
                        <input class="field field--file" type="file" id="picture" name="picture" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-max-bytes="<?php echo $maxPhoto; ?>">
                        <span class="hint-sm" id="pictureHint">JPG, PNG, or WEBP only. Maximum file size: 5 MB.</span>
                    </div>
                </div>

                <div class="form-foot">
                    <p class="form-note">Please provide accurate details. Your report starts as <strong>Unresolved</strong> until you mark it as resolved.</p>
                    <div class="form-buttons">
                        <button type="button" class="btn btn--outline" data-close-report>Cancel</button>
                        <button type="submit" class="btn" name="btnReport">Submit Report</button>
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
                <a class="tag tag--clear" href="report.php">Clear all</a>
            </div>
        <?php } ?>

        <p class="result-count">
            <?php
                if ($totalReports === 0) { echo "No reports"; }
                else { echo "Showing " . (($page - 1) * $perPage + 1) . "&ndash;" . (($page - 1) * $perPage + count($reports)) . " of " . $totalReports . " report" . ($totalReports === 1 ? "" : "s"); }
            ?>
        </p>

        <!-- Report cards -->
        <?php if (count($reports) === 0) { ?>
            <div class="empty">
                <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
                <h2 class="empty-title"><?php echo $itemId > 0 ? "That report isn't available" : (count($baseParams) > 0 ? "No reports match your search" : "No reports yet"); ?></h2>
                <p><?php echo $itemId > 0 ? "It may have been removed by the person who posted it." : (count($baseParams) > 0 ? "Try a different word or clear the filters." : "Be the first to report a lost item."); ?></p>
                <?php if (count($baseParams) > 0) { ?><a class="btn" href="report.php"><?php echo $itemId > 0 ? "View all reports" : "Clear filters"; ?></a><?php } ?>
            </div>
        <?php } else { ?>
            <div class="report-list">
                <?php foreach ($reports as $r) {
                    $rid = (int) $r["Report_ID"];
                    $isMine = ((int) $r["User_ID"] === $userId);
                    $isResolved = ($r["Status"] === "Resolved");
                    $posterName = displayName($so, $r["First_Name"], $r["Last_Name"], $r["Username"]);
                    $short = shortText($r["Description"]);
                    $count = $commentTotals[$rid] ?? 0;
                    $commentsOpen = ($openId === $rid);
                    $photoVer = (int) $r["Edited_Ts"];   // changes when the report is edited, so a new photo is never served from the browser cache
                    $isEditing = ($editRid === $rid);
                    $ef = $isEditing ? $editForm : [
                        "name"        => $r["Item_Name"],
                        "category"    => $r["Category"],
                        "description" => $r["Description"],
                        "location"    => $r["Location_Lost"],
                        "date"        => date("Y-m-d", strtotime($r["Datetime_Lost"])),
                    ];
                    $commentCtx = ["so" => $so, "self" => $selfUrl, "uid" => $userId, "rid" => $rid, "replies" => $replies];
                ?>
                    <article class="report-card <?php echo $isMine ? "report-card--mine" : ""; ?> <?php echo $isEditing ? "is-editing" : ""; ?>" id="report-<?php echo $rid; ?>">
                        <?php if ($isMine) { ?>
                            <form class="delete-post" method="POST" action="<?php echo e($selfUrl); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="report_id" value="<?php echo $rid; ?>">
                                <input type="hidden" name="btnDeleteReport" value="1">
                                <button type="submit" class="trash-btn" title="Delete report" aria-label="Delete report: <?php echo e($r["Item_Name"]); ?>" data-confirm-delete data-confirm-title="Delete this report?" data-confirm-text="Its photo and all of its comments will be removed too. This can't be undone.">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                </button>
                            </form>
                        <?php } ?>

                        <?php if ((int) $r["Has_Pic"] === 1) { ?>
                            <a class="report-thumb" href="report.php?img=<?php echo $rid; ?>&amp;v=<?php echo $photoVer; ?>" data-lightbox="report.php?img=<?php echo $rid; ?>&amp;v=<?php echo $photoVer; ?>" data-alt="Photo of <?php echo e($r["Item_Name"]); ?>" aria-label="View larger photo of <?php echo e($r["Item_Name"]); ?>">
                                <img src="report.php?img=<?php echo $rid; ?>&amp;thumb=1&amp;v=<?php echo $photoVer; ?>" alt="Photo of <?php echo e($r["Item_Name"]); ?>" loading="lazy">
                            </a>
                        <?php } ?>

                        <div class="report-main">
                            <div class="report-view">
                            <div class="report-top">
                                <div>
                                    <h3 class="report-title"><?php echo e($r["Item_Name"]); ?></h3>
                                    <span class="cat"><?php echo e($r["Category"]); ?></span>
                                    <?php if ($isMine) { ?><span class="cat cat--mine">Your post</span><?php } ?>
                                </div>
                                <time class="report-time" datetime="<?php echo e($r["Posted_At"]); ?>"><?php echo e(date("M j, g:i a", strtotime($r["Posted_At"]))); ?><?php echo !empty($r["Edited_At"]) ? " &middot; edited" : ""; ?></time>
                            </div>

                            <ul class="report-meta">
                                <li>
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                    <?php echo e($r["Location_Lost"]); ?>
                                </li>
                                <li>
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/></svg>
                                    <?php echo e(date("m/d/Y", strtotime($r["Datetime_Lost"]))); ?>
                                </li>
                                <li>
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                                    <?php echo $posterName; ?>
                                </li>
                            </ul>

                            <p class="desc">
                                <strong>Description:</strong>
                                <?php if ($short !== null) { ?>
                                    <span class="desc-short"><?php echo e($short); ?> <button type="button" class="read-more" aria-expanded="false">read more.</button></span>
                                    <span class="desc-full"><?php echo nl2br(e($r["Description"])); ?> <button type="button" class="read-more" aria-expanded="true">show less</button></span>
                                <?php } else { ?>
                                    <span class="desc-plain"><?php echo nl2br(e($r["Description"])); ?></span>
                                <?php } ?>
                            </p>
                            </div>

                            <?php if ($isMine) { ?>
                                <form class="edit-report" method="POST" action="<?php echo e($selfUrl); ?>" enctype="multipart/form-data">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="report_id" value="<?php echo $rid; ?>">
                                    <h4 class="edit-title">Edit report</h4>

                                    <?php if ($isEditing && $editError !== "") { ?>
                                        <div class="notice notice--err" role="alert"><?php echo e($editError); ?></div>
                                    <?php } ?>

                                    <div class="form-grid">
                                        <div>
                                            <label class="label" for="edit-name-<?php echo $rid; ?>">Item name *</label>
                                            <input class="field" type="text" id="edit-name-<?php echo $rid; ?>" name="item_name" maxlength="100" value="<?php echo e($ef["name"]); ?>" required>
                                        </div>
                                        <div>
                                            <label class="label" for="edit-cat-<?php echo $rid; ?>">Category *</label>
                                            <select class="field" id="edit-cat-<?php echo $rid; ?>" name="category" required>
                                                <?php foreach ($categories as $cat) { ?>
                                                    <option value="<?php echo e($cat); ?>" <?php echo $ef["category"] === $cat ? "selected" : ""; ?>><?php echo e($cat); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>

                                        <div class="span-2">
                                            <label class="label" for="edit-desc-<?php echo $rid; ?>">Description *</label>
                                            <textarea class="field field--area" id="edit-desc-<?php echo $rid; ?>" name="description" maxlength="<?php echo $maxDesc; ?>" rows="4" data-counter-for="editCount-<?php echo $rid; ?>" required><?php echo e($ef["description"]); ?></textarea>
                                            <span class="counter" id="editCount-<?php echo $rid; ?>" data-max="<?php echo $maxDesc; ?>"><?php echo mb_strlen($ef["description"]); ?> / <?php echo $maxDesc; ?></span>
                                        </div>

                                        <div>
                                            <label class="label" for="edit-loc-<?php echo $rid; ?>">Where did you lose it? *</label>
                                            <input class="field" type="text" id="edit-loc-<?php echo $rid; ?>" name="location_lost" maxlength="150" value="<?php echo e($ef["location"]); ?>" required>
                                        </div>
                                        <div>
                                            <label class="label" for="edit-date-<?php echo $rid; ?>">Date lost *</label>
                                            <input class="field" type="date" id="edit-date-<?php echo $rid; ?>" name="date_lost" max="<?php echo date("Y-m-d"); ?>" value="<?php echo e($ef["date"]); ?>" required>
                                        </div>

                                        <div class="span-2">
                                            <label class="label" for="edit-pic-<?php echo $rid; ?>"><?php echo (int) $r["Has_Pic"] === 1 ? "Replace photo (optional)" : "Item photo (optional)"; ?></label>
                                            <input class="field field--file" type="file" id="edit-pic-<?php echo $rid; ?>" name="picture" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-max-bytes="<?php echo $maxPhoto; ?>">
                                            <span class="hint-sm">JPG, PNG, or WEBP only. Maximum file size: 5 MB.</span>
                                            <?php if ((int) $r["Has_Pic"] === 1) { ?>
                                                <label class="check-row"><input type="checkbox" name="remove_picture" value="1"> Remove the current photo</label>
                                            <?php } ?>
                                        </div>
                                    </div>

                                    <div class="edit-actions">
                                        <button type="button" class="btn btn--outline btn--sm cancel-btn" data-cancel-edit-report>Cancel</button>
                                        <button type="submit" class="btn btn--sm" name="btnEditReport">Save changes</button>
                                    </div>
                                </form>
                            <?php } ?>

                            <div class="report-foot">
                                <span class="status <?php echo $isResolved ? "status--resolved" : "status--unresolved"; ?>"><?php echo e($r["Status"]); ?></span>

                                <?php if ($isMine) { ?>
                                    <form class="inline-form" method="POST" action="<?php echo e($selfUrl); ?>">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="report_id" value="<?php echo $rid; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $isResolved ? "Unresolved" : "Resolved"; ?>">
                                        <button type="submit" class="btn-mini" name="btnStatus"><?php echo $isResolved ? "Mark as unresolved" : "Mark as resolved"; ?></button>
                                    </form>
                                    <button type="button" class="btn-mini" data-toggle-edit="<?php echo $rid; ?>" aria-expanded="<?php echo $isEditing ? "true" : "false"; ?>">Edit</button>
                                <?php } ?>

                                <button type="button" class="btn-comment" data-toggle-comments="<?php echo $rid; ?>" aria-expanded="<?php echo $commentsOpen ? "true" : "false"; ?>" aria-controls="comments-<?php echo $rid; ?>">
                                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>
                                    Comment<?php echo $count > 0 ? " (" . $count . ")" : ""; ?>
                                </button>
                            </div>

                            <!-- Comments -->
                            <div class="comments <?php echo $commentsOpen ? "is-open" : ""; ?>" id="comments-<?php echo $rid; ?>">
                                <?php if (empty($topComments[$rid])) { ?>
                                    <p class="no-comments">No comments yet. Start the conversation.</p>
                                <?php } else { ?>
                                    <div class="comment-list">
                                        <?php foreach ($topComments[$rid] as $c) { echo renderBranch($c, 0, $commentCtx); } ?>
                                    </div>
                                <?php } ?>

                                <form class="comment-form" method="POST" action="<?php echo e($selfUrl); ?>">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="report_id" value="<?php echo $rid; ?>">
                                    <span class="avatar avatar--sm" aria-hidden="true"><?php echo e(initialOf($myName !== "" ? $myName : "?")); ?></span>
                                    <textarea class="comment-input" name="comment_text" rows="1" maxlength="500" placeholder="Write a comment..." aria-label="Write a comment" required></textarea>
                                    <button type="submit" class="btn btn--sm" name="btnComment">Post</button>
                                </form>
                            </div>
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

    <!-- Delete confirmation pop-up (same look as the log out pop-up). Without JS the delete buttons simply submit. -->
    <div class="modal" id="confirm-modal" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmText">
        <div class="modal-backdrop" id="confirmBackdrop"></div>

        <div class="modal-box">
            <div class="modal-icon modal-icon--danger" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            </div>
            <h2 class="modal-title" id="confirmTitle">Delete?</h2>
            <p class="modal-text" id="confirmText"></p>

            <div class="modal-actions">
                <button type="button" class="btn btn--outline" id="confirmCancel">Cancel</button>
                <button type="button" class="btn btn--danger" id="confirmOk">Delete</button>
            </div>
        </div>
    </div>

    <!-- Photo viewer (opens when a card photo is clicked) -->
    <div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Photo preview">
        <button type="button" class="lightbox-close" id="lightboxClose" aria-label="Close photo">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><line x1="5" y1="5" x2="19" y2="19"/><line x1="19" y1="5" x2="5" y2="19"/></svg>
        </button>
        <img id="lightboxImg" src="" alt="">
    </div>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
</body>
</html>
