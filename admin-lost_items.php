<?php
session_start();
include("./helpers/nocache.php");
include("./connection/config.php");
include("./helpers/SystemOperators.php");
include("./helpers/functions.php");
include("./helpers/auth.php");
$con = connection();
$so = new SystemOperators();
date_default_timezone_set("Asia/Manila"); 

/*This function is in helpers/auth.php
additional security to redirect users away if role !=admin
checks admin session ID (might be removed once integrated with the rest of the system)*/
requireAdmin($con);

$activePage = "inventory";   // highlights this page in the admin navbar

// Small helper used by the page below
function e($text) { return htmlspecialchars((string) $text, ENT_QUOTES, "UTF-8"); }

// ---------- Settings similar to report.php ----------
$categories = ["Electronics", "Wallets & Bags", 
               "IDs & Cards", "Keys", 
               "Clothing", "Books & School Supplies", 
               "Accessories", "Bottles & Containers", 
               "Others"];
$maxDesc = 500; //Maximun Description number
$perPage = 8; //max list per page
$maxPhoto = 5 * 1024 * 1024; //5mb

// Resize uploaded images into smaller JPEG files. (Same from Report.php)
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
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    imagejpeg($dst, null, $quality);
    $out = ob_get_clean();
    return $out;
}

//Form as a array
$form = [
    "itemname" => "", "category" => "", "description" => "",
    "foundby" => "", "receivedby" => "", "foundin" => "", "surrenderedattime" => ""
];

//Errors gets called here
$formError = ""; 

// ---------- Read search and filter selections ----------
//Get the search input, remove extra spaces, convert it to text, and limit it to 100 characters.
$search = mb_substr(trim((string) ($_GET["search"] ?? "")), 0, 100);

// checks whether the category exists inside the $categories array.
$category = in_array(
    $_GET["category"] ?? "all", 
    $categories, true
) 
? $_GET["category"] : "all"; //Use "all" category is invalid

// Sorting options for filter
$sortOptions = [
    "latest" => ["Latest first", "Posted_At DESC, ItemID DESC"],
    "oldest" => ["Oldest first", "Posted_At ASC, ItemID ASC"],
    "az"     => ["A to Z", "Item_Name ASC, ItemID DESC"],
    "za"     => ["Z to A", "Item_Name DESC, ItemID DESC"]
];
//Checks whether the sorting option received exists in your $sortOptions array
$sort = isset($sortOptions[$_GET["sort"] ?? ""])? $_GET["sort"]: "latest"; //isset() checks whether the specified key exists in the $sortOptions array and is not null.

// ---------- Keep selected filters in the URL ----------
// If the $"" isn't empty, save it inside $baseParams.
$baseParams = [];

if ($search !== "") {
    $baseParams["search"] = $search;
}

if ($category !== "all") {
    $baseParams["category"] = $category;
}

if ($sort !== "latest") {
    $baseParams["sort"] = $sort;
}

// Makes a page URL while keeping the selected filters
function lostItemsPageUrl($params = []) { //Pass an associative array containing URL parameters.

    $query = http_build_query($params); //Converts an associative array into URL query parameters.

    if ($query !== "") {
        return $_SERVER["PHP_SELF"] . "?" . $query;
    } else {
        return $_SERVER["PHP_SELF"];
    }
}

$selfUrl = lostItemsPageUrl($baseParams); //New page URL after creating a new one with the function

// Every form on this page carries a secret token (same as the other pages)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !checkCsrf()) {
    $_SESSION['feedback'] = "Your session expired. Please try again.";
    $_SESSION['feedback_type'] = "error";
    header("Location: " . $selfUrl);
    exit();
}

/* ------------- Adapted from report.php, with changes for this page -------------
Gets the submitted form values/Cleans submitted values through trimming 
and normalizing spaces before inserting to the Database */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin-inventory-btnSubmit'])) {
    $form["itemname"]    = trim((string) ($_POST["Item_Name"] ?? ""));
    $form["category"] = (string) ($_POST["Category"] ?? "");
    $form["description"] =  trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST["Description"] ?? "")));
    $form["foundby"] = trim((string) ($_POST["Found_By"] ?? ""));
    $form["receivedby"] = "";   // an item added here always starts as Unclaimed; it is marked as received later
    $form["foundin"] = trim((string) ($_POST["Found_In"] ?? ""));
    $form["surrenderedattime"] = (string) ($_POST["Surrendered_At_Time"] ?? "");

    /*If admin don't input anything in the form for "found by" and "recieve by" it 
    will be "Anonymous"/"Unclaimed" */
    $status = ($form["receivedby"] !== "") ? "Claimed" : "Unclaimed";

    if ($form["foundby"] === "")    { $form["foundby"] = "Anonymous"; }
    if ($form["receivedby"] === "") { $form["receivedby"] = "Unclaimed"; }

    //Reads the text using the format Y-m-d
    $dateObj = DateTime::createFromFormat("Y-m-d", $form["surrenderedattime"]);
    /*If dateObj returns true:convert it back to text
    and compare it to the original submitted value. If they're not identical,
    PHP had to adjust the date, so the original wasn't a real date.*/
    $dateOk = $dateObj && $dateObj->format("Y-m-d") === $form["surrenderedattime"];

    // ---------- checks for possible errors in inputs ---------- //
    // any admin can add items
    if ($form["itemname"] === "" || $form["description"] === "" || $form["foundin"] === "" || $form["surrenderedattime"] === "" || $form["category"] === "") {
        $formError = "Please fill in all required fields.";
    } elseif (!$dateOk) {
        $formError = "Please Choose A Valid Date";
    } elseif (mb_strlen($form["itemname"]) > 100) {
        $formError = "Item name must be 100 characters or less.";
    } elseif (!in_array($form["category"], $categories, true)) {
        $formError = "Please choose a category from the list.";
    } elseif (mb_strlen($form["description"]) > $maxDesc) {
        $formError = "Description must be " . $maxDesc . " characters or less.";
    } elseif (mb_strlen($form["foundby"]) > 100) {
        $formError = "Name must be 100 characters or less.";
    } elseif (mb_strlen($form["receivedby"]) > 100) {
        $formError = "Name must be 100 characters or less.";
    } elseif (mb_strlen($form["foundin"]) > 150) {
        $formError = "Location must be 150 characters or less.";
    } elseif ($form["surrenderedattime"] > date("Y-m-d")) {
    $formError = "Future dates are not allowed.";
    }

$picBin = null;
// Only checked when there are no errors so far and a file was chosen.
if ($formError === "" && isset($_FILES["Picture"]) && $_FILES["Picture"]["error"] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES["Picture"];

    if ($file["error"] !== UPLOAD_ERR_OK) {
        $formError = "The photo could not be uploaded. Please try again.";
    } elseif ($file["size"] > $maxPhoto) {
        $formError = "The photo is larger than 5 MB.";
    } else {
        // Get information about the uploaded image.
        $info = @getimagesize($file["tmp_name"]);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $formError = "The photo must be a JPG, PNG, or WEBP image.";
        } elseif ($info[0] * $info[1] > 24000000) {
            $formError = "The photo's resolution is too large.";
        } else {
            // Read the original uploaded image.
            $raw = file_get_contents($file["tmp_name"]);
            // Resize and compress the image.
            $small = resizeToJpeg($raw, 1600, 82, $info[2] === IMAGETYPE_JPEG);
            // Use the resized image if successful; otherwise use the original.
            $picBin = ($small !== null) ? $small : $raw;
        }
    }
}
     // Save (only runs if nothing above set an error)
    if ($formError === "") {
        $dtSurrendered = $form["surrenderedattime"] . " " . date("H:i:s"); //Puts the date in Hour/minutes/seconds
        //Inserts all the values into the inventory_items table in database
        try {
           // The item and its UniqueItem_ID are saved together (all or nothing)
           $con->begin_transaction();

           if ($picBin !== null) {
                $insert_stmt = $con->prepare("INSERT INTO `inventory_items` (`Item_Name`, `Category`, `Description`, `Found_By`, `Received_By`, `Found_In`, `Surrendered_At_Time`, `Picture`, `Status`, `Posted_At`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $insert_stmt->bind_param('sssssssss', $form["itemname"], $form["category"], $form["description"], $form["foundby"], $form["receivedby"], $form["foundin"], $dtSurrendered, $picBin, $status);
            } else {
                $insert_stmt = $con->prepare("INSERT INTO `inventory_items` (`Item_Name`, `Category`, `Description`, `Found_By`, `Received_By`, `Found_In`, `Surrendered_At_Time`, `Status`, `Posted_At`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $insert_stmt->bind_param('ssssssss', $form["itemname"], $form["category"], $form["description"], $form["foundby"], $form["receivedby"], $form["foundin"], $dtSurrendered, $status);
            }
            $insert_stmt->execute();
            $newItemId = (int) $insert_stmt->insert_id;
            $insert_stmt->close();

            // UniqueItem_ID: 3000901, 3000902, 3000903 ... (the ItemID counted up from UNIQUEITEM_ID_START)
            $uid_stmt = $con->prepare("UPDATE `inventory_items` SET `UniqueItem_ID` = `ItemID` + " . (UNIQUEITEM_ID_START - 1) . " WHERE `ItemID` = ?");
            $uid_stmt->bind_param('i', $newItemId);
            $uid_stmt->execute();
            $uid_stmt->close();

            $con->commit();

            $_SESSION['feedback'] = ($status === "Claimed")
                ? "Item added and moved to the received items list."
                : "Item added to the lost items list.";
            $_SESSION['feedback_type'] = "success";

            header("Location: " . $selfUrl);
            exit();
        } catch (mysqli_sql_exception $ex) {
            try { $con->rollback(); } catch (mysqli_sql_exception $ignored) { }
            error_log("TamFinds admin add item: " . $ex->getMessage());
            if (stripos($ex->getMessage(), "max_allowed_packet") !== false || stripos($ex->getMessage(), "packet") !== false) {
            $formError = "That photo is too big for the database settings. Try a smaller photo (or raise max_allowed_packet in XAMPP's my.ini).";
        } else {
            $formError = "Something went wrong. Please try again.";
            }
        }
    }
}

// ---------- Edit function: ANY admin can edit ANY item (not only the admin who added it) ----------
// Checks an optional uploaded photo (same rules as the Add Item form). Returns [error message, image bytes]. No file = ["", null].
function readInventoryPhoto($key, $maxPhoto) {
    if (!isset($_FILES[$key]) || $_FILES[$key]["error"] === UPLOAD_ERR_NO_FILE) { return ["", null]; }

    $file = $_FILES[$key];
    if ($file["error"] === UPLOAD_ERR_INI_SIZE || $file["error"] === UPLOAD_ERR_FORM_SIZE) {
        return ["That photo is larger than this server allows (" . ini_get("upload_max_filesize") . "). Please choose a smaller one.", null];
    }
    if ($file["error"] !== UPLOAD_ERR_OK) { return ["The photo could not be uploaded. Please try again.", null]; }
    if ($file["size"] > $maxPhoto) { return ["The photo is larger than 5 MB.", null]; }

    $info = @getimagesize($file["tmp_name"]);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return ["The photo must be a JPG, PNG, or WEBP image.", null];
    }
    if ($info[0] * $info[1] > 24000000) { return ["The photo's resolution is too large.", null]; }

    $raw = file_get_contents($file["tmp_name"]);
    $small = resizeToJpeg($raw, 1600, 82, $info[2] === IMAGETYPE_JPEG);
    return ["", ($small !== null) ? $small : $raw];
}

$editsReceivedBy = false;   // the Claimed List also lets you correct who received the item
$editId = 0;                 // the card that opens again in edit mode when saving fails
$editError = "";
$editVals = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin-inventory-btnEdit'])) {
    $editItemId = (int) ($_POST["Item_ID"] ?? 0);
    $editVals = [
        "itemname"    => trim((string) ($_POST["Item_Name"] ?? "")),
        "category"    => (string) ($_POST["Category"] ?? ""),
        "description" => trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST["Description"] ?? ""))),
        "foundby"     => trim((string) ($_POST["Found_By"] ?? "")),
        "receivedby"  => trim((string) ($_POST["Received_By"] ?? "")),
        "foundin"     => trim((string) ($_POST["Found_In"] ?? "")),
        "surrendered" => (string) ($_POST["Surrendered_At_Time"] ?? ""),
    ];
    if ($editVals["foundby"] === "") { $editVals["foundby"] = "Anonymous"; }

    try {
        $oldStmt = $con->prepare("SELECT Item_Name, Category, Description, Found_By, Received_By, Found_In, Surrendered_At_Time, (Picture IS NOT NULL) AS Has_Pic FROM inventory_items WHERE ItemID = ? LIMIT 1");
        $oldStmt->bind_param("i", $editItemId);
        $oldStmt->execute();
        $old = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();

        if (!$old) {
            $_SESSION['feedback'] = "Unable to edit item #$editItemId. The item may no longer exist in the inventory.";
            $_SESSION['feedback_type'] = "error";
            header("Location: " . $selfUrl);
            exit();
        }
        if (!$editsReceivedBy) { $editVals["receivedby"] = (string) $old["Received_By"]; }   // not edited on this page

        $dateObj = DateTime::createFromFormat("Y-m-d", $editVals["surrendered"]);
        $dateOk = $dateObj && $dateObj->format("Y-m-d") === $editVals["surrendered"];

        // ---------- checks for possible errors in inputs (same rules as the Add Item form) ---------- //
        if ($editVals["itemname"] === "" || $editVals["description"] === "" || $editVals["foundin"] === "" || $editVals["surrendered"] === "" || $editVals["category"] === "" || $editVals["receivedby"] === "") {
            $editError = "Please fill in all required fields.";
        } elseif (!$dateOk) {
            $editError = "Please Choose A Valid Date";
        } elseif (mb_strlen($editVals["itemname"]) > 100) {
            $editError = "Item name must be 100 characters or less.";
        } elseif (!in_array($editVals["category"], $categories, true)) {
            $editError = "Please choose a category from the list.";
        } elseif (mb_strlen($editVals["description"]) > $maxDesc) {
            $editError = "Description must be " . $maxDesc . " characters or less.";
        } elseif (mb_strlen($editVals["foundby"]) > 100 || mb_strlen($editVals["receivedby"]) > 100) {
            $editError = "Name must be 100 characters or less.";
        } elseif (mb_strlen($editVals["foundin"]) > 150) {
            $editError = "Location must be 150 characters or less.";
        } elseif ($editVals["surrendered"] > date("Y-m-d")) {
            $editError = "Future dates are not allowed.";
        }

        $newPic = null;
        if ($editError === "") {
            list($editError, $newPic) = readInventoryPhoto("Picture", $maxPhoto);
        }
        $removePic = ($newPic === null && isset($_POST["remove_picture"]) && (int) $old["Has_Pic"] === 1);

        if ($editError !== "") {
            $editId = $editItemId;         // show the same card again, still open, with what was typed
        } else {
            $oldDate = substr((string) $old["Surrendered_At_Time"], 0, 10);
            $unchanged = $editVals["itemname"] === $old["Item_Name"]
                && $editVals["category"] === $old["Category"]
                && $editVals["description"] === $old["Description"]
                && $editVals["foundby"] === $old["Found_By"]
                && $editVals["receivedby"] === $old["Received_By"]
                && $editVals["foundin"] === $old["Found_In"]
                && $editVals["surrendered"] === $oldDate
                && $newPic === null
                && !$removePic;

            if ($unchanged) {
                $_SESSION['feedback'] = "No changes to save.";
                $_SESSION['feedback_type'] = "success";
            } else {
                // keep the time of day the item was first logged with; only the date can change
                $timePart = substr((string) $old["Surrendered_At_Time"], 11, 8);
                $newDt = $editVals["surrendered"] . " " . ($timePart !== "" ? $timePart : "00:00:00");

                $sets = "`Item_Name` = ?, `Category` = ?, `Description` = ?, `Found_By` = ?, `Found_In` = ?, `Surrendered_At_Time` = ?";
                $bindTypes = "ssssss";
                $bindVals = [$editVals["itemname"], $editVals["category"], $editVals["description"], $editVals["foundby"], $editVals["foundin"], $newDt];
                if ($editsReceivedBy) { $sets .= ", `Received_By` = ?"; $bindTypes .= "s"; $bindVals[] = $editVals["receivedby"]; }
                if ($newPic !== null)  { $sets .= ", `Picture` = ?";    $bindTypes .= "s"; $bindVals[] = $newPic; }
                elseif ($removePic)    { $sets .= ", `Picture` = NULL"; }
                $bindTypes .= "i";
                $bindVals[] = $editItemId;

                $editStmt = $con->prepare("UPDATE `inventory_items` SET $sets WHERE `ItemID` = ?");
                $editStmt->bind_param($bindTypes, ...$bindVals);
                $editStmt->execute();
                $editStmt->close();

                $_SESSION['feedback'] = "Item #$editItemId has been successfully updated.";
                $_SESSION['feedback_type'] = "success";
            }
            header("Location: " . $selfUrl . "#item-" . $editItemId);
            exit();
        }
    } catch (mysqli_sql_exception $ex) {
        error_log("TamFinds admin edit item: " . $ex->getMessage());
        $editId = $editItemId;
        if (stripos($ex->getMessage(), "packet") !== false) {
            $editError = "That photo is too big for the database settings. Try a smaller photo (or raise max_allowed_packet in XAMPP's my.ini).";
        } else {
            $editError = "Something went wrong. Please try again.";
        }
    }
}

// ---------- Delete function ----------
if (isset($_POST['admin-inventory-btnDelete'])) {
    $ItemID = $_POST['Item_ID'];
    
    $stmt = $con->prepare("
    DELETE 
    FROM inventory_items 
    WHERE ItemID = ?
    ");

    $stmt->bind_param("i", $ItemID);
    $stmt->execute();

    if($stmt->affected_rows > 0) { 
        $_SESSION['feedback'] = "Item #$ItemID has been successfully deleted.";
        $_SESSION['feedback_type'] = "success";
    }else{
        $_SESSION['feedback'] = "Unable to delete item #$ItemID. The item may no longer exist in the inventory.";
        $_SESSION['feedback_type'] = "error";
    }

    $stmt->close();

    header("Location: " . $selfUrl);
    exit();
}
// ---------- Updates the item status to 'found' and the name of recipient to the 'Recieved_By' when 'Mark recieved' button clicked ---------- 
if (isset($_POST['admin-markFound-btnRecieved'])) {
    $ItemID = $_POST['markFound_Item_ID'];
    $recipientName = trim((string) ($_POST['received_by'] ?? ""));
    
    if ($recipientName === "" || mb_strlen($recipientName) > 100) {
        $_SESSION['feedback'] = "Please enter a valid recipient name (maximum 100 characters).";
        $_SESSION['feedback_type'] = "error";

        header("Location: " . $selfUrl);
        exit();
    }

    $stmt = $con->prepare("
    UPDATE inventory_items 
    SET Status = 'Claimed', Received_By = ?
    WHERE ItemID = ?
    ");

    $stmt->bind_param("si", $recipientName, $ItemID);
    $stmt->execute();

    if($stmt->affected_rows > 0) {
        $_SESSION['feedback'] = "Item #$ItemID has been successfully marked as received.";
        $_SESSION['feedback_type'] = "success";
    }else{
        $_SESSION['feedback'] = "Unable to mark item #$ItemID as received. The item may no longer exist in the inventory.";
        $_SESSION['feedback_type'] = "error";
    }

    $stmt->close();

    header("Location: " . $selfUrl);
    exit();
}
// ----------  Search and filter, dynamically builds the SQL query depending on what the admin selects. ---------- 
//Initialize the variables 
$where = ["Status = 'Unclaimed'"]; //Creates an empty array. will store the SQL conditions that the admin selects.
$types = ""; //Creates an empty string. will store the data types of the values to bind to our prepared statement.
$params = []; //Creates an empty array. will store the actual parameters to bind to SQL placeholders

// Search item name
if ($search !== "") { //Checks if search is not empty
    $where[] = "Item_Name LIKE ?"; //Add SQL conditions that the admin selects
    $types .= "s"; //Data type of the values
    $params[] = "%" . addcslashes($search, "%_\\") . "%"; //
}
// Filter by category
if ($category !== "all") { //If category is "all", skip the condition.
    $where[] = "Category = ?";  //Add SQL conditions that the admin selects
    $types .= "s"; //Data type of the values
    $params[] = $category;
}
// This is where all the conditions we collected are combined.
if (count($where) > 0) {  //Counts how many elements are inside the $where array and if it is >0
    $whereSql = "WHERE " . implode(" AND ", $where); //If array contains conditions, this combines them into one SQL string.
} else {
    $whereSql = "";
}

// ---------- Count Matching Items ----------
$countStmt = $con->prepare("
    SELECT COUNT(*) AS total
    FROM inventory_items
    $whereSql
");

if ($types !== "") { //Binds the selected filter values if there are placeholders
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute(); //Executes the counting query

$countRow = $countStmt->get_result()->fetch_assoc(); //Retrieves the count as an associative array

$totalItems = (int) $countRow["total"]; //Stores the total number of matching items
 
$countStmt->close(); //Closes the prepared statement

// ---------- Pagination ----------
$totalPages = max(1, (int) ceil($totalItems / $perPage)); //Calculate the total number of pages needed

$page = min(max(1, (int) ($_GET["page"] ?? 1)), $totalPages); //Get the current page from the URL

$offset = ($page - 1) * $perPage; //Calculate how many items to skip
 
// ---------- Retrieve Matching Items ----------
$stmt = $con->prepare("
    SELECT *
    FROM inventory_items
    $whereSql
    ORDER BY " . $sortOptions[$sort][1] . "
    LIMIT " . (int) $perPage . "
    OFFSET " . (int) $offset
);

//Bind the filter values if there are any
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute(); //Execute the query
$result = $stmt->get_result(); //Retrieve the matching records

// ---------- Filter Badge ----------
$filterCount = 0;
if ($category !== "all") {
    $filterCount++; //adds filtercount
}

if ($sort !== "latest") {
    $filterCount++; //adds filtercount
}
// ---------- Active Filter Chips ----------
$chips = [];

if ($search !== "") { 
    $p = $baseParams;
    unset($p["search"]); //removes "search" from $p which is the copy of $baseparams
    $chips[] = ["Search: " . $search, lostItemsPageUrl($p)]; //Creates the removable chip
}

if ($category !== "all") {
    $p = $baseParams;
    unset($p["category"]); //removes "search" from $p which is the copy of $baseparams
    $chips[] = [$category, lostItemsPageUrl($p)]; //Creates the removable chip
}

if ($sort !== "latest") {
    $p = $baseParams;
    unset($p["sort"]);  //removes "search" from $p which is the copy of $baseparams
    $chips[] = [$sortOptions[$sort][0], lostItemsPageUrl($p)]; //Creates the removable chip
}
//Reset filters but keep the search
$resetFiltersUrl = lostItemsPageUrl(
    $search !== "" ? ["search" => $search] : []
);

//Clear everything
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>admin:Lost Items</title>
    
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:title" content="TamFinds">
    <meta property="og:description" content="Lost something? Let’s help you find it.">
    <meta property="og:image" content="https://www.tamfinds.online/images/logo.jpg">
    <meta property="og:url" content="https://www.tamfinds.online/">
    <meta property="og:type" content="website">

    <!-- Icon for tab -->
    <link href="images/logo.jpg" rel="icon" />
    
    <script>document.documentElement.className += " js";</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Rubik+Mono+One&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
</head>

<body class="plain page-soft">
    <?php include("./includes/admin_navbar.php"); ?>

    <main class="reports" id="reports">
        <header class="reports-head">
            <p class="kicker">Admin</p>
            <h1 class="section-title">Lost Items</h1>
            <p class="section-sub">Items that were turned in and are still waiting for their owner.</p>
        </header>

        <!---------- ===== Message after adding, deleting or moving an item ===== ---------->
        <?php if (isset($_SESSION['feedback'])) { ?>
            <div class="notice notice--<?php echo (($_SESSION['feedback_type'] ?? "") === "success") ? "ok" : "err"; ?>" role="status"><?php echo e($_SESSION['feedback']); ?></div>
            <?php
                //Unsets the variables to remove the message on the next page reload
                unset($_SESSION['feedback']);
                unset($_SESSION['feedback_type']);
            ?>
        <?php } ?>

        <!---------- ===== Search + Filters + Add Item ===== ---------->
        <div class="toolbar">
            <form class="toolbar-form" method="GET" action="" role="search">
                <div class="search">
                    <button class="search-btn" type="submit" aria-label="Search">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
                    </button>
                    <input class="search-input" type="search" name="search" value="<?php echo e($search); ?>" placeholder="Search item name" maxlength="100" aria-label="Search item name">
                </div>

                <details class="filter">
                    <summary class="btn-filter">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/><circle cx="9" cy="7" r="2.2" fill="#fff"/><circle cx="15" cy="12" r="2.2" fill="#fff"/><circle cx="8" cy="17" r="2.2" fill="#fff"/></svg>
                        <span class="btn-filter-label">Filters</span>
                        <?php if ($filterCount > 0) { ?><span class="filter-badge"><?php echo $filterCount; ?></span><?php } ?>
                    </summary>

                    <div class="filter-panel">
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

            <button type="button" class="btn btn--report" id="toggleAddForm">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Item
            </button>
        </div>

        <!---------- ===== Form (hidden until the button is clicked) ===== ---------->
        <section class="report-panel <?php echo $formError !== "" ? "is-open" : ""; ?>" id="addForm" aria-label="Add a Found Item">
            <form class="report-form" action="" method="POST" enctype="multipart/form-data" name="admin-add-form">
                <?php echo csrfField(); ?>
                <h2 class="form-title">Add a Found Item</h2>
                <p class="form-lead">Log an item that was turned in so students can find and claim it.</p>

                <?php if ($formError !== "") { ?>
                    <div class="notice notice--err" role="alert"><?php echo e($formError); ?></div>
                <?php } ?>

                <div class="form-grid">
                    <div>
                        <label class="label" for="Item_Name">Item name *</label>
                        <input class="field" type="text" id="Item_Name" name="Item_Name" maxlength="100" placeholder="e.g. Black wallet" required value="<?php echo e($form["itemname"]); ?>">
                    </div>
                    <div>
                        <label class="label" for="category">Category *</label>
                        <select class="field" name="Category" id="category" required>
                            <option value="" disabled <?php echo $form["category"] === "" ? "selected" : ""; ?>>Choose a category</option>
                            <?php foreach ($categories as $cat) { ?>
                                <option value="<?php echo e($cat); ?>" <?php echo $form["category"] === $cat ? "selected" : ""; ?>><?php echo e($cat); ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="span-2">
                        <label class="label" for="description">Description *</label>
                        <textarea class="field field--area" id="description" name="Description" required maxlength="<?php echo $maxDesc; ?>" rows="4" placeholder="Describe the item, including its color, brand, identifying marks, or what was inside."><?php echo e($form["description"]); ?></textarea>
                        <span class="counter" id="descCounter" data-max="<?php echo $maxDesc; ?>">0 / <?php echo $maxDesc; ?></span>
                    </div>

                    <div>
                        <label class="label" for="Found_By">Found by (optional)</label>
                        <input class="field" type="text" id="Found_By" name="Found_By" maxlength="100" value="<?php echo e($form["foundby"]); ?>" placeholder="e.g. Juan Dela Cruz">
                        <span class="hint-sm">Leave empty if the student wants to stay anonymous. Defaults to "Anonymous".</span>
                    </div>
                    <div>
                        <label class="label" for="Found_In">Found in *</label>
                        <input class="field" type="text" id="Found_In" name="Found_In" maxlength="150" value="<?php echo e($form["foundin"]); ?>" placeholder="e.g. Room 402" required>
                    </div>
                    <div>
                        <label class="label" for="surrendered_at_time">Surrendered on *</label>
                        <input class="field" type="date" id="surrendered_at_time" name="Surrendered_At_Time" required max="<?php echo date('Y-m-d'); ?>" value="<?php echo e($form["surrenderedattime"]); ?>">
                    </div>

                    <div>
                        <label class="label" for="picture">Picture (optional)</label>
                        <input class="field field--file" type="file" id="picture" name="Picture" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-max-bytes="<?php echo $maxPhoto; ?>">
                        <span class="hint-sm" id="pictureHint">JPG, PNG, or WEBP only. Maximum file size: 5 MB.</span>
                    </div>
                </div>

                <div class="form-foot">
                    <p class="form-note">Fields marked * are required.</p>
                    <div class="form-buttons">
                        <button type="button" class="btn btn--outline" id="form_submitCancel" name="admin-inventory-btnCancel">Cancel</button>
                        <button type="submit" class="btn" id="form_submitBtn" name="admin-inventory-btnSubmit">Add Item</button>
                    </div>
                </div>
            </form>
        </section>

        <!---------- ===== Active filters ===== ---------->
        <?php if (count($chips) > 0) { ?>
            <div class="chips-row">
                <?php foreach ($chips as $chip) { ?>
                    <a class="tag" href="<?php echo e($chip[1]); ?>" aria-label="Remove filter <?php echo e($chip[0]); ?>"><?php echo e($chip[0]); ?> <span aria-hidden="true">&times;</span></a>
                <?php } ?>
                <a class="tag tag--clear" href="<?php echo e(lostItemsPageUrl()); ?>">Clear all</a>
            </div>
        <?php } ?>

        <!---------- ===== Result Count ===== ---------->
        <p class="result-count">
            <?php if ($totalItems > 0) {
                $startItem = ($offset + 1);                       //number of the first item on this page
                $endItem = min($offset + $perPage, $totalItems);  //number of the last item on this page
                echo "Showing " . $startItem . "&ndash;" . $endItem . " of " . $totalItems . " item" . ($totalItems === 1 ? "" : "s");
            } else { echo "No items"; } ?>
        </p>

        <!---------- ===== CARDS ===== ---------->
        <?php if ($result->num_rows > 0) { ?>
            <div class="report-list">
            <?php while ($row = $result->fetch_assoc()) { ?>
                <?php
                    $itemIdNow = (int) $row['ItemID'];
                    $isEditing = ($editId === $itemIdNow);   // opens in edit mode again when the last save had an error
                    $ef = $isEditing ? $editVals : [
                        "itemname"    => $row['Item_Name'] ?? '',
                        "category"    => $row['Category'] ?? '',
                        "description" => $row['Description'] ?? '',
                        "foundby"     => $row['Found_By'] ?? '',
                        "receivedby"  => $row['Received_By'] ?? '',
                        "foundin"     => $row['Found_In'] ?? '',
                        "surrendered" => strtotime($row['Surrendered_At_Time'] ?? '') ? date('Y-m-d', strtotime($row['Surrendered_At_Time'])) : '',
                    ];
                ?>
                <article class="report-card report-card--admin <?php echo $isEditing ? "is-editing" : ""; ?>" id="item-<?php echo $itemIdNow; ?>">

                    <!-- tiny red trash button, top right of the card (asks first) -->
                    <form action="" method="POST" class="delete-post confirm-form" data-danger="true" data-title="Delete this item?" data-message="Are you sure you want to delete this item? This can't be undone and it will be removed from the inventory list for good.">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="Item_ID" value="<?php echo (int) $row['ItemID']; ?>">
                        <button type="submit" class="trash-btn" name="admin-inventory-btnDelete" title="Delete item" aria-label="Delete item: <?php echo e($row['Item_Name'] ?? 'Item'); ?>">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                        </button>
                    </form>

                    <!--Check if 'Picture' value is empty: if empty it shows the placeholder-->
                    <?php if (!empty($row['Picture'])) { ?>
                        <div class="report-thumb report-thumb--static">
                            <img src="data:image/jpeg;base64,<?php echo base64_encode($row['Picture']); ?>" alt="Photo of <?php echo e($row['Item_Name'] ?? 'item'); ?>">
                        </div>
                    <?php } else { ?>
                        <div class="report-thumb report-thumb--static report-thumb--empty">
                            <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/></svg>
                            <span>No picture provided</span>
                        </div>
                    <?php } ?>

                    <div class="report-main">
                        <div class="report-view">
                        <div class="report-top">
                            <div>
                                <h3 class="report-title"><?php echo e($row['Item_Name'] ?? 'Item'); ?></h3>
                                <span class="cat"><?php echo e($row['Category'] ?? ''); ?></span>
                            </div>
                            <!--Time the item was added-->
                            <time class="report-time"><?php $t = strtotime($row['Posted_At'] ?? ''); echo $t ? e(date('M j, g:i a', $t)) : ''; ?></time>
                        </div>

                        <ul class="report-meta">
                            <li>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                Found in: <?php echo e($row['Found_In'] ?? 'none'); ?>
                            </li>
                            <li>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/></svg>
                                Date: <?php $d = strtotime($row['Surrendered_At_Time'] ?? ''); echo $d ? e(date('m/d/Y', $d)) : 'N/A'; ?>
                            </li>
                            <li>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                                Found by: <?php echo e($row['Found_By'] ?? 'Anonymous'); ?>
                            </li>
                            <li>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
                                Received by: <?php echo e($row['Received_By'] ?? 'Unclaimed'); ?>
                            </li>
                        </ul>

                        <p class="desc">
                            <strong>Description:</strong>
                            <span class="desc-plain"><?php echo nl2br(e($row['Description'] ?? 'none')); ?></span>
                        </p>
                        </div>

                        <form class="edit-report" method="POST" action="<?php echo e($selfUrl); ?>" enctype="multipart/form-data">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="Item_ID" value="<?php echo $itemIdNow; ?>">
                            <h4 class="edit-title">Edit item</h4>

                            <?php if ($isEditing && $editError !== "") { ?>
                                <div class="notice notice--err" role="alert"><?php echo e($editError); ?></div>
                            <?php } ?>

                            <div class="form-grid">
                                <div>
                                    <label class="label" for="edit-name-<?php echo $itemIdNow; ?>">Item name *</label>
                                    <input class="field" type="text" id="edit-name-<?php echo $itemIdNow; ?>" name="Item_Name" maxlength="100" value="<?php echo e($ef["itemname"]); ?>" required>
                                </div>
                                <div>
                                    <label class="label" for="edit-cat-<?php echo $itemIdNow; ?>">Category *</label>
                                    <select class="field" id="edit-cat-<?php echo $itemIdNow; ?>" name="Category" required>
                                        <?php foreach ($categories as $cat) { ?>
                                            <option value="<?php echo e($cat); ?>" <?php echo $ef["category"] === $cat ? "selected" : ""; ?>><?php echo e($cat); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>

                                <div class="span-2">
                                    <label class="label" for="edit-desc-<?php echo $itemIdNow; ?>">Description *</label>
                                    <textarea class="field field--area" id="edit-desc-<?php echo $itemIdNow; ?>" name="Description" maxlength="<?php echo $maxDesc; ?>" rows="4" data-counter-for="editCount-<?php echo $itemIdNow; ?>" required><?php echo e($ef["description"]); ?></textarea>
                                    <span class="counter" id="editCount-<?php echo $itemIdNow; ?>" data-max="<?php echo $maxDesc; ?>"><?php echo mb_strlen($ef["description"]); ?> / <?php echo $maxDesc; ?></span>
                                </div>

                                <div>
                                    <label class="label" for="edit-foundby-<?php echo $itemIdNow; ?>">Found by (optional)</label>
                                    <input class="field" type="text" id="edit-foundby-<?php echo $itemIdNow; ?>" name="Found_By" maxlength="100" value="<?php echo e($ef["foundby"]); ?>" placeholder="Leave empty for Anonymous">
                                </div>
                                <div>
                                    <label class="label" for="edit-foundin-<?php echo $itemIdNow; ?>">Found in *</label>
                                    <input class="field" type="text" id="edit-foundin-<?php echo $itemIdNow; ?>" name="Found_In" maxlength="150" value="<?php echo e($ef["foundin"]); ?>" required>
                                </div>

                                <div>
                                    <label class="label" for="edit-date-<?php echo $itemIdNow; ?>">Surrendered on *</label>
                                    <input class="field" type="date" id="edit-date-<?php echo $itemIdNow; ?>" name="Surrendered_At_Time" max="<?php echo date('Y-m-d'); ?>" value="<?php echo e($ef["surrendered"]); ?>" required>
                                </div>

                                <div>
                                    <label class="label" for="edit-pic-<?php echo $itemIdNow; ?>"><?php echo !empty($row['Picture']) ? "Replace picture (optional)" : "Picture (optional)"; ?></label>
                                    <input class="field field--file" type="file" id="edit-pic-<?php echo $itemIdNow; ?>" name="Picture" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-max-bytes="<?php echo $maxPhoto; ?>">
                                    <span class="hint-sm">JPG, PNG, or WEBP only. Maximum file size: 5 MB.</span>
                                    <?php if (!empty($row['Picture'])) { ?>
                                        <label class="check-row"><input type="checkbox" name="remove_picture" value="1"> Remove the current picture</label>
                                    <?php } ?>
                                </div>
                            </div>

                            <div class="edit-actions">
                                <button type="button" class="btn btn--outline btn--sm cancel-btn" data-cancel-edit-item>Cancel</button>
                                <button type="submit" class="btn btn--sm" name="admin-inventory-btnEdit">Save changes</button>
                            </div>
                        </form>

                        <div class="report-foot">
                            <span class="status status--unresolved">Unclaimed</span>
                            <?php $uniqueItemId = (int) ($row['UniqueItem_ID'] ?? 0);
                                if ($uniqueItemId > 0) { ?>
                                <button type="button" class="btn-copylink" data-copy-link="<?php echo e(siteBaseUrl() . "/lostitem?item=" . $uniqueItemId); ?>" aria-label="Copy link to this item">
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                    <span>Copy link</span>
                                </button>
                            <?php } ?>
                            <button type="button" class="btn-mini" data-edit-item="<?php echo $itemIdNow; ?>" aria-expanded="<?php echo $isEditing ? "true" : "false"; ?>">Edit</button>
                            <form action="" method="POST" class="inline-form confirm-form" data-require-recipient="true" data-title="Mark as received?" data-message="Are you sure you want to mark this item as received? It will be moved to the received list.">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="markFound_Item_ID" value="<?php echo (int) $row['ItemID']; ?>">
                                <input type="hidden" name="received_by" value="">
                                <button type="submit" class="btn-mini" name="admin-markFound-btnRecieved">Mark as received</button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php } ?>
            </div>
        <?php } else { ?>
            <!-- Empty State -->
            <div class="empty">
                <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
                <h2 class="empty-title"><?php echo (count($baseParams) > 0) ? "No items match your search" : "No lost items yet"; ?></h2>
                <p><?php echo (count($baseParams) > 0) ? "Try a different word or clear the filters." : "Use the Add Item button to log the first one."; ?></p>
                <?php if (count($baseParams) > 0) { ?><a class="btn" href="<?php echo e($resetFiltersUrl); ?>">Clear filters</a><?php } ?>
            </div>
        <?php } ?>

        <!-- ===== Pagination ===== -->
        <?php if ($totalPages > 1) { ?>
            <nav class="pager" aria-label="Pages">
                <?php if ($page > 1) { ?>
                    <a class="icon-btn" href="<?php echo e(lostItemsPageUrl(array_merge($baseParams, ['page' => $page - 1]))); ?>" aria-label="Previous page"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></a>
                <?php } else { ?>
                    <span class="icon-btn is-disabled" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></span>
                <?php } ?>

                <span class="pager-text">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>

                <?php if ($page < $totalPages) { ?>
                    <a class="icon-btn" href="<?php echo e(lostItemsPageUrl(array_merge($baseParams, ['page' => $page + 1]))); ?>" aria-label="Next page"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></a>
                <?php } else { ?>
                    <span class="icon-btn is-disabled" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                <?php } ?>
            </nav>
        <?php } ?>
    </main>

    <!---------- ===== Modal Popups (same look as the log out pop-up) ===== ---------->
    <!-- Asks who received the item (only for 'Mark as received') -->
    <div class="modal" id="recipientModal" role="dialog" aria-modal="true" aria-labelledby="recipientTitle">
        <div class="modal-backdrop" id="recipientBackdrop"></div>
        <div class="modal-box">
            <div class="modal-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
            </div>
            <h2 class="modal-title" id="recipientTitle">Item received by</h2>
            <p class="modal-text" id="recipientboxP">Who received this item?</p>
            <input class="field" type="text" id="recipientName" maxlength="100" placeholder="e.g. Juan Dela Cruz" autocomplete="off">
            <div class="modal-actions">
                <button type="button" class="btn btn--outline" id="cancelRecipient">Cancel</button>
                <button type="button" class="btn" id="continueRecipient">Continue</button>
            </div>
        </div>
    </div>

    <!-- Confirmation for 'Delete' and for the mark buttons -->
    <div class="modal" id="confirmModal" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmMessage">
        <div class="modal-backdrop" id="confirmBackdrop"></div>
        <div class="modal-box">
            <div class="modal-icon" id="confirmIcon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <h2 class="modal-title" id="confirmTitle">Confirm action</h2>
            <p class="modal-text" id="confirmMessage">Are you sure you want to continue?</p>
            <div class="modal-actions">
                <button type="button" class="btn btn--outline" id="cancelConfirm">Cancel</button>
                <button type="button" class="btn" id="proceedConfirm">Confirm</button>
            </div>
        </div>
    </div>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
    <script>
    (function () {
        /*---------- ===== Form Toggler ===== ----------*/
        var toggleBtn = document.getElementById('toggleAddForm');                       /* 'Add Item' button */
        var addForm = document.getElementById('addForm');                               /* The form panel */
        var cancelBtn = document.getElementsByName('admin-inventory-btnCancel')[0];     /* Cancel button of the form */

        if (toggleBtn && addForm) {
            toggleBtn.addEventListener('click', function () {                           /* opens the form and scrolls to it */
                addForm.classList.add('is-open');
                addForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
                var first = addForm.querySelector('input, select, textarea');
                if (first) { first.focus({ preventScroll: true }); }
            });
        }
        if (cancelBtn && addForm) {
            cancelBtn.addEventListener('click', function () { addForm.classList.remove('is-open'); });   /* closes the form */
        }

        /*---------- ===== Word counter for description in form ===== ----------*/
        var inputDesc = document.querySelector('#description');
        var wordCount = document.querySelector('#descCounter');

        function updateCounter() {
            var max = parseInt(wordCount.getAttribute('data-max'), 10);
            wordCount.textContent = inputDesc.value.length + ' / ' + max;
            wordCount.classList.toggle('is-near', inputDesc.value.length >= max - 40);
        }
        if (inputDesc && wordCount) {
            updateCounter();                                  /* once when the page loads */
            inputDesc.addEventListener('input', updateCounter);   /* and whenever the admin types */
        }

        /*---------- ===== Photo size warning ===== ----------*/
        var pictureInput = document.getElementById('picture');
        var pictureHint = document.getElementById('pictureHint');
        if (pictureInput && pictureHint) {
            var hintOriginal = pictureHint.textContent;
            pictureInput.addEventListener('change', function () {
                var chosen = pictureInput.files && pictureInput.files[0];
                if (chosen && chosen.size > parseInt(pictureInput.getAttribute('data-max-bytes'), 10)) {
                    pictureHint.textContent = 'That photo is larger than 5 MB. Please choose a smaller one.';
                    pictureHint.classList.add('is-error');
                    pictureInput.value = '';
                } else {
                    pictureHint.textContent = hintOriginal;
                    pictureHint.classList.remove('is-error');
                }
            });
        }

        /*---------- ===== Confirmation Modal Popup ===== ----------*/
        /* Names with 'recipient' belong to the 'Mark as received' pop-up that asks for the receiver's name first */
        var recipientModal = document.getElementById('recipientModal');
        var recipientName = document.getElementById('recipientName');
        var cancelRecipient = document.getElementById('cancelRecipient');
        var continueRecipient = document.getElementById('continueRecipient');
        var confirmModal = document.getElementById('confirmModal');
        var confirmTitle = document.getElementById('confirmTitle');
        var confirmMessage = document.getElementById('confirmMessage');
        var confirmIcon = document.getElementById('confirmIcon');
        var cancelConfirm = document.getElementById('cancelConfirm');
        var proceedConfirm = document.getElementById('proceedConfirm');

        var pendingForm = null;        /* the form the admin wants to submit (Delete / Mark as ...) */
        var pendingSubmitter = null;   /* the button that was clicked (PHP knows the action by the button's name) */
        var allowSubmit = false;       /* lets the form through after the admin confirmed */

        function openModal(modal) { modal.classList.add('is-open'); document.body.classList.add('modal-open'); }
        function closeModal(modal) { modal.classList.remove('is-open'); document.body.classList.remove('modal-open'); }
        function forgetPending() { pendingForm = null; pendingSubmitter = null; }

        function showConfirm(form) {
            var danger = (form.dataset.danger === 'true');
            confirmTitle.textContent = form.dataset.title || 'Confirm action';
            confirmMessage.textContent = form.dataset.message;
            confirmIcon.classList.toggle('modal-icon--danger', danger);       /* red pop-up for Delete */
            proceedConfirm.className = danger ? 'btn btn--danger' : 'btn';
            proceedConfirm.textContent = danger ? 'Delete' : 'Confirm';
            openModal(confirmModal);
            cancelConfirm.focus();
        }

        document.querySelectorAll('.confirm-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (allowSubmit) { allowSubmit = false; return; }
                event.preventDefault();                  /* stop the form so the pop-up can show first */

                pendingForm = this;
                pendingSubmitter = event.submitter;      /* the clicked button */

                if (this.dataset.requireRecipient === 'true') {   /* 'Mark as received' asks for a name first */
                    recipientName.value = '';
                    openModal(recipientModal);
                    recipientName.focus();
                    return;
                }
                showConfirm(this);
            });
        });

        /* Recipient pop-up: once a name is typed and 'Continue' is clicked */
        continueRecipient.addEventListener('click', function () {
            var name = recipientName.value.trim();
            if (name === '') { alert('Please enter the recipient\'s name.'); recipientName.focus(); return; }

            pendingForm.querySelector('[name="received_by"]').value = name;   /* put the name in the hidden input */
            closeModal(recipientModal);
            showConfirm(pendingForm);
        });
        recipientName.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); continueRecipient.click(); }
        });

        function cancelRecipientBox() { closeModal(recipientModal); forgetPending(); }
        function cancelConfirmBox() { closeModal(confirmModal); forgetPending(); }
        cancelRecipient.addEventListener('click', cancelRecipientBox);
        document.getElementById('recipientBackdrop').addEventListener('click', cancelRecipientBox);
        cancelConfirm.addEventListener('click', cancelConfirmBox);
        document.getElementById('confirmBackdrop').addEventListener('click', cancelConfirmBox);

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            if (recipientModal.classList.contains('is-open')) { cancelRecipientBox(); }
            else if (confirmModal.classList.contains('is-open')) { cancelConfirmBox(); }
        });

        /* Confirm button of the confirmation pop-up */
        proceedConfirm.addEventListener('click', function () {
            if (!pendingForm) { return; }
            var form = pendingForm;
            var submitter = pendingSubmitter;

            closeModal(confirmModal);
            forgetPending();

            allowSubmit = true;               /* lets the form through */
            form.requestSubmit(submitter);    /* submits with the originally clicked button, so PHP knows the action */
        });

        /*---------- ===== Copy link (works like the Google Drive button) ===== ----------*/
        var copyToast = null;
        var copyToastTimer = null;

        function showCopyToast(message) {
            if (!copyToast) {
                copyToast = document.createElement('div');
                copyToast.className = 'copy-toast';
                copyToast.setAttribute('role', 'status');
                document.body.appendChild(copyToast);
            }
            copyToast.textContent = message;
            copyToast.classList.add('is-visible');
            clearTimeout(copyToastTimer);
            copyToastTimer = setTimeout(function () { copyToast.classList.remove('is-visible'); }, 2400);
        }

        function copyText(text) {
            if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(text); }
            return new Promise(function (resolve, reject) {          /* older browsers, or a site opened without https */
                var box = document.createElement('textarea');
                box.value = text;
                box.setAttribute('readonly', '');
                box.style.position = 'fixed';
                box.style.opacity = '0';
                document.body.appendChild(box);
                box.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
                document.body.removeChild(box);
                if (ok) { resolve(); } else { reject(); }
            });
        }

        document.querySelectorAll('[data-copy-link]').forEach(function (btn) {
            var label = btn.querySelector('span');
            btn.addEventListener('click', function () {
                copyText(btn.getAttribute('data-copy-link')).then(function () {
                    btn.classList.add('is-copied');
                    label.textContent = 'Copied';
                    showCopyToast('Link copied');
                    setTimeout(function () { btn.classList.remove('is-copied'); label.textContent = 'Copy link'; }, 2000);
                }).catch(function () {
                    showCopyToast('Could not copy. Please copy the link by hand.');
                });
            });
        });

        /*---------- ===== Edit item (opens inside the card) ===== ----------*/
        /* Puts the counter and the photo hint of an edit form back to how the saved item looks */
        function refreshEditForm(f) {
            var area = f.querySelector('textarea[data-counter-for]');
            var count = area ? document.getElementById(area.getAttribute('data-counter-for')) : null;
            if (area && count) {
                var max = parseInt(count.getAttribute('data-max'), 10);
                count.textContent = area.value.length + ' / ' + max;
                count.classList.toggle('is-near', area.value.length >= max - 40);
            }
            var hint = f.querySelector('.hint-sm');
            if (hint && hint.getAttribute('data-original') !== null) {
                hint.textContent = hint.getAttribute('data-original');
                hint.classList.remove('is-error');
            }
        }

        document.querySelectorAll('.edit-report').forEach(function (f) {
            var area = f.querySelector('textarea[data-counter-for]');
            var file = f.querySelector('input[type=file]');
            var hint = f.querySelector('.hint-sm');
            if (hint) { hint.setAttribute('data-original', hint.textContent); }

            if (area) { area.addEventListener('input', function () { refreshEditForm(f); }); }
            if (file && hint) {
                file.addEventListener('change', function () {
                    var chosen = file.files && file.files[0];
                    if (chosen && chosen.size > parseInt(file.getAttribute('data-max-bytes'), 10)) {
                        hint.textContent = 'That photo is larger than 5 MB. Please choose a smaller one.';
                        hint.classList.add('is-error');
                        file.value = '';
                    } else {
                        hint.textContent = hint.getAttribute('data-original');
                        hint.classList.remove('is-error');
                    }
                });
            }
        });

        document.addEventListener('click', function (e) {
            /* 'Edit' button: swap the card's details for the edit form */
            var openBtn = e.target.closest('[data-edit-item]');
            if (openBtn) {
                var card = document.getElementById('item-' + openBtn.getAttribute('data-edit-item'));
                if (card) {
                    var editing = card.classList.toggle('is-editing');
                    openBtn.setAttribute('aria-expanded', editing ? 'true' : 'false');
                    if (editing) {
                        var firstField = card.querySelector('.edit-report .field');
                        if (firstField) { firstField.focus(); }
                    }
                }
                return;
            }

            /* 'Cancel' in the edit form: put everything back as it was */
            var cancelEdit = e.target.closest('[data-cancel-edit-item]');
            if (cancelEdit) {
                var editCard = cancelEdit.closest('.report-card');
                var editForm = cancelEdit.closest('form');
                editForm.reset();
                refreshEditForm(editForm);
                var oldError = editForm.querySelector('.notice--err');
                if (oldError) { oldError.parentNode.removeChild(oldError); }
                editCard.classList.remove('is-editing');
                var toggler = editCard.querySelector('[data-edit-item]');
                if (toggler) { toggler.setAttribute('aria-expanded', 'false'); toggler.focus(); }
                editCard.scrollIntoView({ block: 'nearest' });
            }
        });

        /* A card that opens already in edit mode (the last save had an error): bring it into view */
        var openEditCard = document.querySelector('.report-card.is-editing');
        if (openEditCard && !window.location.hash) {
            setTimeout(function () { openEditCard.scrollIntoView({ block: 'start' }); }, 100);
        }
    })();
    </script>
</body>
</html>
