<?php
    // Shared helper functions + settings used by the login / signup / forgot password pages.

    define("RESEND_SECONDS", 40);        // wait time before a new code can be requested
    define("CODE_LIFETIME_MIN", 10);     // how long a verification code stays valid
    define("SHOW_CODE_ON_SCREEN", true); // TESTING ONLY: shows the code on the verify page because
                                         // XAMPP can't send real emails/SMS. Set to false when done.
    define("UNIQUE_ID_START", 1000901);  // first Unique_ID of report_items (Report_ID 1 -> 1000901, 2 -> 1000902, ...)

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

    // Creates a 5-digit code, saves it (encrypted) in the database and emails it.
    function issueResetCode($con, $so, $userId, $contact) {
        $code = str_pad((string) random_int(0, 99999), 5, "0", STR_PAD_LEFT);
        $encCode = $so->encrypt($code);

        $stmt = $con->prepare("UPDATE `users` SET `Reset_Code` = ?, `Reset_Sent_At` = NOW() WHERE `User_ID` = ?");
        $stmt->bind_param('si', $encCode, $userId);
        $stmt->execute();
        $stmt->close();

        // Only emails can be sent (and only if XAMPP's sendmail is set up). Phone numbers would need an SMS service.
        if (strpos($contact, "@") !== false) {
            $body = "Your TamFinds verification code is: " . $code . "\nIt expires in " . CODE_LIFETIME_MIN . " minutes.";
            @mail($contact, "TamFinds verification code", $body, "From: no-reply@tamfinds.local");
        }
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

    // "Juan Dela Cruz" from the encrypted student_info names (falls back to the username)
    function displayName($so, $first, $last, $username) {
        $name = trim(($first ? (string) $so->decrypt($first) : "") . " " . ($last ? (string) $so->decrypt($last) : ""));
        if ($name === "") {
            $name = $username ? (string) $so->decrypt($username) : "";
        }
        return $name !== "" ? $name : "Unknown user";
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
            <p class="carousel-empty">No items have been reported yet. <a href="report.php?new=1">Report the first one.</a></p>
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
                <a class="lost-card" href="report.php?item=<?php echo $uid; ?>">
                    <div class="lost-media">
                        <?php if ($hasPic) { ?>
                            <img src="report.php?img=<?php echo $rid; ?>&amp;thumb=1&amp;v=<?php echo (int) $item["Edited_Ts"]; ?>" alt="Photo of <?php echo $name; ?>">
                        <?php } else { ?>
                            <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>
                            </svg>
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
?>
