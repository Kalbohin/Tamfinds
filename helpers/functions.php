<?php
    // Shared helper functions + settings used by the login / signup / forgot password pages.

    define("RESEND_SECONDS", 40);        // wait time before a new code can be requested
    define("CODE_LIFETIME_MIN", 10);     // how long a verification code stays valid
    define("UNIQUE_ID_START", 1000901);  // first Unique_ID of report_items (Report_ID 1 -> 1000901, 2 -> 1000902, ...)
    define("UNIQUEITEM_ID_START", 3000901); // first UniqueItem_ID of inventory_items (ItemID 1 -> 3000901, 2 -> 3000902, ...)
    define("SHOW_CODE_ON_SCREEN", false); // Keep this false: the code must only travel by EMAIL.
                                          // For testing on a PC with no internet/SMTP you can set it to true: the code is then
                                          // shown on the verify page, and a failed email no longer stops the reset.

    // Same as your professor's filter_input line, trimmed. Example: cleanInput('firstname')
    function cleanInput($name) {
        return trim((string) filter_input(INPUT_POST, $name, FILTER_SANITIZE_SPECIAL_CHARS));
    }

    // Makes "Juan@Gmail.com " and "0912 345 6789" always look the same before they are encrypted,
    // so the encrypted value in the database can be matched on login.
    function normalizeContact($value) {
        $value = strtolower(trim($value));
        if (strpos($value, "@") === false) {
            $value = preg_replace('/[\s\-\(\)]/', '', $value); // phone: remove spaces, dashes, brackets
        }
        return $value;
    }

    function isValidContact($value) {
        return filter_var($value, FILTER_VALIDATE_EMAIL) || preg_match('/^\+?[0-9]{7,15}$/', $value);
    }

    // Pop-up message like your professor's echo "<script> alert(...) </script>", with an optional redirect.
    function showAlert($msg, $redirect = "") {
        if ($msg === "") { return; }
        echo "<script>alert(" . json_encode($msg) . ");";
        if ($redirect !== "") {
            echo "window.location=" . json_encode($redirect) . ";";
        }
        echo "</script>";
    }

    // Creates a 5-digit code, saves it (encrypted) in the database and EMAILS it (PHPMailer + Brevo).
    // Returns true when the email was sent. When it could not be sent, the saved code is removed again.
    function issueResetCode($con, $so, $userId, $contact) {
        $code = str_pad((string) random_int(0, 99999), 5, "0", STR_PAD_LEFT);
        $encCode = $so->encrypt($code);

        $stmt = $con->prepare("UPDATE `users` SET `Reset_Code` = ?, `Reset_Sent_At` = NOW() WHERE `User_ID` = ?");
        $stmt->bind_param('si', $encCode, $userId);
        $stmt->execute();
        $stmt->close();

        $sent = false;
        $why = "";

        // Codes are sent by email only
        if (strpos($contact, "@") !== false) {
            // first name for the greeting ("Hello, Juan!"), from the student list
            $name = "";
            $who = $con->prepare("SELECT `First_Name` FROM `student_info` WHERE `User_ID` = ? LIMIT 1");
            $who->bind_param('i', $userId);
            $who->execute();
            $whoRow = $who->get_result()->fetch_assoc();
            $who->close();
            if ($whoRow && $whoRow["First_Name"] !== "" && $whoRow["First_Name"] !== null) {
                $plain = $so->decrypt($whoRow["First_Name"]);
                $name = ($plain === false) ? "" : html_entity_decode((string) $plain, ENT_QUOTES, "UTF-8");
            }

            // a staff (admin) account has no student record: use the first name from the staff list instead
            if ($name === "") {
                try {
                    $whoStaff = $con->prepare("SELECT `First_Name` FROM `stafflist_info` WHERE `User_ID` = ? LIMIT 1");
                    $whoStaff->bind_param('i', $userId);
                    $whoStaff->execute();
                    $staffRow = $whoStaff->get_result()->fetch_assoc();
                    $whoStaff->close();
                    if ($staffRow && $staffRow["First_Name"] !== "" && $staffRow["First_Name"] !== null) {
                        $plain = @$so->decrypt($staffRow["First_Name"]);          // saved encrypted, or as plain text
                        $name = ($plain === false || $plain === "") ? (string) $staffRow["First_Name"] : html_entity_decode((string) $plain, ENT_QUOTES, "UTF-8");
                    }
                } catch (mysqli_sql_exception $ignore) { }                        // the staff table is not updated yet: just no name
            }

            include_once __DIR__ . "/mail-functions.php";
            include_once __DIR__ . "/mail-templates.php";
            $email = resetCodeEmail($name, $code, CODE_LIFETIME_MIN);
            $sent = sendTamFindsEmail($contact, $email["subject"], $email["html"], null, $email["text"], $why);
        }

        if (!$sent && SHOW_CODE_ON_SCREEN) {
            return true;       // testing mode: carry on, the code is shown on the verify page
        }

        if (!$sent) {
            $clear = $con->prepare("UPDATE `users` SET `Reset_Code` = NULL, `Reset_Sent_At` = NULL WHERE `User_ID` = ?");
            $clear->bind_param('i', $userId);
            $clear->execute();
            $clear->close();
        }
        return $sent;
    }

    // "benedict@gmail.com" -> "b*****t@gmail.com" (so the full address isn't shown on the screen)
    function maskEmail($email) {
        $parts = explode("@", (string) $email, 2);
        if (count($parts) < 2) { return (string) $email; }
        $local = $parts[0];
        $len = mb_strlen($local);
        $masked = ($len <= 2) ? mb_substr($local, 0, 1) . "*" : mb_substr($local, 0, 1) . str_repeat("*", min(5, $len - 2)) . mb_substr($local, -1);
        return $masked . "@" . $parts[1];
    }

    // Gets the saved code + how many seconds ago it was sent (MySQL does the time math).
    function getCodeRow($con, $userId) {
        $stmt = $con->prepare("SELECT `Reset_Code` AS reset_code, TIMESTAMPDIFF(SECOND, `Reset_Sent_At`, NOW()) AS age FROM `users` WHERE `User_ID` = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    // ---------- Helpers for report.php ----------

    // CSRF: a secret token kept in the session and sent with every form, so other websites
    // can't trick a logged-in user's browser into posting/liking/changing things.
    function csrfToken() {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }
    function csrfField() {
        return '<input type="hidden" name="csrf" value="' . csrfToken() . '">';
    }
    function checkCsrf() {
        return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
    }

    // Decrypts a saved name. A value that was typed in as plain text (not encrypted) is given back as it is.
    function decryptOrRaw($so, $value) {
        $value = (string) $value;
        if ($value === "") { return ""; }
        $plain = @$so->decrypt($value);
        return ($plain === false || $plain === "") ? $value : (string) $plain;
    }

    // "Juan Dela Cruz" from the encrypted student_info / stafflist_info names (falls back to the username)
    // Returns PLAIN text (escape it with htmlspecialchars when you print it)
    function displayName($so, $first, $last, $username) {
        $name = trim(($first ? decryptOrRaw($so, $first) : "") . " " . ($last ? decryptOrRaw($so, $last) : ""));
        if ($name === "") {
            $name = $username ? (string) $so->decrypt($username) : "";
        }
        $name = html_entity_decode($name, ENT_QUOTES, "UTF-8");   // older names were saved with entities like &#39;
        return $name !== "" ? $name : "Unknown user";
    }

    // The little "Admin" tag shown next to the name of an admin on reports and comments.
    // Usage: echo adminBadge($row["Poster_Role"] ?? "");   (prints nothing for regular users)
    function adminBadge($role) {
        return (strtolower(trim((string) $role)) === "admin") ? ' <span class="admin-badge" title="Posted by an admin">Admin</span>' : "";
    }

    // "5m ago", "2h ago", "3d ago" (returns "" when older than a week)
    function timeAgo($seconds) {
        $seconds = max(0, (int) $seconds);
        if ($seconds < 60)     { return "just now"; }
        if ($seconds < 3600)   { return floor($seconds / 60) . "m ago"; }
        if ($seconds < 86400)  { return floor($seconds / 3600) . "h ago"; }
        if ($seconds < 604800) { return floor($seconds / 86400) . "d ago"; }
        return "";
    }

    // ---------- Messages shown INSIDE the card (sign in, sign up, forgot password) ----------

    // Short HTML-escape helper
    function esc($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, "UTF-8");
    }

    // The message box inside the card. $type is "err" (red) or "ok" (green). Prints nothing when there is no message.
    function formMsg($text, $type = "err") {
        if ($text === "") { return ""; }
        return '<p class="form-msg form-msg--' . ($type === "ok" ? "ok" : "err") . '" role="' . ($type === "ok" ? "status" : "alert") . '">' . esc($text) . '</p>';
    }

    // For the boxes that need fixing: $bad is the list of their names.
    // fieldClass() gives the red outline, fieldAttrs() marks it as invalid and puts the cursor in the FIRST one.
    function fieldClass($bad, $name) {
        return in_array($name, $bad, true) ? "is-invalid" : "";
    }
    function fieldAttrs($bad, $name) {
        if (!in_array($name, $bad, true)) { return ""; }
        return 'aria-invalid="true"' . ($bad[0] === $name ? ' autofocus' : '');
    }

    // ---------- Helpers for the Home page carousel (dashboard.php) ----------

    // The newest reports, newest first (the Home carousel only ever asks for 5).
    function latestReports($con, $limit = 5) {
        $limit = max(1, (int) $limit);
        $result = $con->query(
            "SELECT Report_ID, Unique_ID, Item_Name, Location_Lost, Datetime_Lost,
                    (Picture IS NOT NULL) AS Has_Pic,
                    COALESCE(UNIX_TIMESTAMP(Edited_At), 0) AS Edited_Ts
             FROM report_items
             ORDER BY Posted_At DESC, Report_ID DESC
             LIMIT " . $limit
        );
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    }

    // Changes whenever a card would look different, so the page knows when to refresh the carousel.
    function lostFeedSignature($items) {
        return md5(json_encode($items));
    }

    // The carousel cards (<div class="slide">...). Used for the first page load AND by the live refresh,
    // so both always look exactly the same.
    function lostSlidesHtml($items) {
        $total = count($items);
        ob_start();

        if ($total === 0) { ?>
            <p class="carousel-empty">No items have been reported yet. <a href="report?new=1">Report the first one.</a></p>
        <?php }

        foreach ($items as $i => $item) {
            $rid   = (int) $item["Report_ID"];
            $uid   = (int) $item["Unique_ID"];
            $name  = htmlspecialchars($item["Item_Name"], ENT_QUOTES, "UTF-8");
            $place = htmlspecialchars($item["Location_Lost"], ENT_QUOTES, "UTF-8");
            $date  = htmlspecialchars(date("M j", strtotime($item["Datetime_Lost"])), ENT_QUOTES, "UTF-8");
            $hasPic = ((int) $item["Has_Pic"] === 1);
        ?>
            <div class="slide" role="group" aria-roledescription="slide" aria-label="<?php echo $i + 1; ?> of <?php echo $total; ?>">
                <a class="lost-card" href="report?item=<?php echo $uid; ?>">
                    <div class="lost-media">
                        <?php if ($hasPic) { ?>
                            <img src="report?img=<?php echo $rid; ?>&amp;thumb=1&amp;v=<?php echo (int) $item["Edited_Ts"]; ?>" alt="Photo of <?php echo $name; ?>">
                        <?php } else { ?>
                            <span class="lost-nopic">
                                <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>
                                </svg>
                                <span class="lost-nopic-text">No picture provided</span>
                            </span>
                        <?php } ?>
                        <span class="lost-badge">Lost</span>
                    </div>
                    <div class="lost-body">
                        <h3 class="lost-title"><?php echo $name; ?></h3>
                        <p class="lost-meta">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                            <?php echo $place; ?>
                        </p>
                        <p class="lost-meta">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/></svg>
                            <?php echo $date; ?>
                        </p>
                        <span class="lost-link">View details <span aria-hidden="true">&rarr;</span></span>
                    </div>
                </a>
            </div>
        <?php }

        return ob_get_clean();
    }

    // ---------- Helpers for the inventory carousels + shared item links ----------

    // The full web address of this site's folder, e.g. https://tamfinds.com or http://localhost/tamfinds3%28test%29
    // (used for links an admin copies and sends to a user)
    function siteBaseUrl() {
        $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
        $folder = str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? ""));
        $folder = trim($folder, "/");
        $path = ($folder === "") ? "" : "/" . implode("/", array_map("rawurlencode", explode("/", $folder)));
        return ($https ? "https" : "http") . "://" . ($_SERVER["HTTP_HOST"] ?? "localhost") . $path;
    }

    // The newest items of inventory_items with the given Status, newest first:
    // "Unclaimed" = the Lost Items list, "Claimed" = the Claimed List (the Home page only asks for 5).
    function latestInventory($con, $status, $limit = 5) {
        $limit = max(1, (int) $limit);
        $stmt = $con->prepare(
            "SELECT ItemID, UniqueItem_ID, Item_Name, Found_In, Surrendered_At_Time,
                    (Picture IS NOT NULL) AS Has_Pic, COALESCE(LENGTH(Picture), 0) AS Pic_V
             FROM inventory_items
             WHERE Status = ?
             ORDER BY Posted_At DESC, ItemID DESC
             LIMIT ?"
        );
        $stmt->bind_param('si', $status, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    // The cards of an inventory carousel. $kind is "lost" (Lost Items) or "claimed" (Claimed List).
    // Same as lostSlidesHtml() above: used for the first page load AND by the live refresh.
    // "View details" opens that one item on its list page (lostitem.php?item=12).
    function inventorySlidesHtml($items, $kind) {
        $isClaimed = ($kind === "claimed");
        $listPage = $isClaimed ? "claimedlist" : "lostitem";
        $badge = $isClaimed ? "Claimed" : "Unclaimed";
        $total = count($items);
        ob_start();

        if ($total === 0) { ?>
            <p class="carousel-empty"><?php echo $isClaimed ? "No items have been claimed yet." : "No lost items right now."; ?></p>
        <?php }

        foreach ($items as $i => $item) {
            $iid   = (int) $item["ItemID"];            // used for the photo
            $uid   = (int) $item["UniqueItem_ID"];     // used in the link (lostitem.php?item=3000901)
            $name  = htmlspecialchars($item["Item_Name"], ENT_QUOTES, "UTF-8");
            $place = htmlspecialchars($item["Found_In"], ENT_QUOTES, "UTF-8");
            $date  = htmlspecialchars(date("M j", strtotime($item["Surrendered_At_Time"])), ENT_QUOTES, "UTF-8");
            $hasPic = ((int) $item["Has_Pic"] === 1);
        ?>
            <div class="slide" role="group" aria-roledescription="slide" aria-label="<?php echo $i + 1; ?> of <?php echo $total; ?>">
                <a class="lost-card" href="<?php echo $listPage; ?>?item=<?php echo $uid; ?>">
                    <div class="lost-media">
                        <?php if ($hasPic) { ?>
                            <img src="<?php echo $listPage; ?>?img=<?php echo $iid; ?>&amp;thumb=1&amp;v=<?php echo (int) $item["Pic_V"]; ?>" alt="Photo of <?php echo $name; ?>">
                        <?php } else { ?>
                            <span class="lost-nopic">
                                <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>
                                </svg>
                                <span class="lost-nopic-text">No picture provided</span>
                            </span>
                        <?php } ?>
                        <span class="lost-badge"><?php echo $badge; ?></span>
                    </div>
                    <div class="lost-body">
                        <h3 class="lost-title"><?php echo $name; ?></h3>
                        <p class="lost-meta">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                            <?php echo $place; ?>
                        </p>
                        <p class="lost-meta">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/></svg>
                            <?php echo $date; ?>
                        </p>
                        <span class="lost-link">View details <span aria-hidden="true">&rarr;</span></span>
                    </div>
                </a>
            </div>
        <?php }

        return ob_get_clean();
    }
?>
