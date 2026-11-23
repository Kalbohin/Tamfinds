<?php
session_start();
include("./helpers/nocache.php");
include("./connection/config.php");
include("./helpers/auth.php");
requireUser();   // visitors -> login page, admins -> their own interface
$con = connection();
date_default_timezone_set("Asia/Manila");

$activePage = "lostitem";         // highlights "Items" in the navbar
$statusShown = "Unclaimed";        // this page lists only the inventory_items with this Status
$thisPage = basename(__FILE__);     // links keep working even if the file is renamed

// ---------- Settings (same as the admin pages) ----------
$categories = ["Electronics", "Wallets & Bags",
               "IDs & Cards", "Keys",
               "Clothing", "Books & School Supplies",
               "Accessories", "Bottles & Containers",
               "Others"];
$perPage = 8;   // items per page

// Sort options: key => [label, ORDER BY]. Only these exact values can ever reach the SQL.
$sortOptions = [
    "latest" => ["Latest first", "Posted_At DESC, ItemID DESC"],
    "oldest" => ["Oldest first", "Posted_At ASC, ItemID ASC"],
    "az"     => ["A to Z", "Item_Name ASC, ItemID DESC"],
    "za"     => ["Z to A", "Item_Name DESC, ItemID DESC"]
];

function e($text) { return htmlspecialchars((string) $text, ENT_QUOTES, "UTF-8"); }

// A link back to this page with the given filters, e.g. lostitem.php?search=wallet&page=2
function pageUrl($params) {
    global $thisPage;
    return $thisPage . (count($params) > 0 ? "?" . http_build_query($params) : "");
}

// Turns an image into a smaller JPEG (used for the small photos on the cards). Returns null if GD isn't available.
function resizeToJpeg($bin, $maxSide, $quality) {
    if (!function_exists("imagecreatefromstring")) { return null; }
    @ini_set("memory_limit", "256M");

    $src = @imagecreatefromstring($bin);
    if (!$src) { return null; }

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
    return ob_get_clean();
}

// ---------------------------------------------------------------------------
// Photo of an item: the photo lives in the database, so this sends it to the browser.
// (small version for the cards, full size when the photo is clicked)
// ---------------------------------------------------------------------------
if (isset($_GET["img"])) {
    $imgId = (int) $_GET["img"];
    $isThumb = isset($_GET["thumb"]);
    $etag = '"i' . $imgId . ($isThumb ? "t" : "f") . '"';

    // photos never change, so let the browser keep them for a day
    header_remove("Pragma");
    header_remove("Expires");
    header("Cache-Control: private, max-age=86400");
    header("ETag: " . $etag);

    if (isset($_SERVER["HTTP_IF_NONE_MATCH"]) && trim($_SERVER["HTTP_IF_NONE_MATCH"]) === $etag) {
        http_response_code(304);
        exit();
    }

    $picStmt = $con->prepare("SELECT Picture FROM inventory_items WHERE ItemID = ? AND Picture IS NOT NULL LIMIT 1");
    $picStmt->bind_param("i", $imgId);
    $picStmt->execute();
    $picRow = $picStmt->get_result()->fetch_assoc();
    $picStmt->close();

    if (!$picRow) {
        header("Cache-Control: no-store");
        http_response_code(404);
        exit();
    }

    $bin = $picRow["Picture"];
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

// ---------- What the visitor chose (search, filters, page) ----------
$search = mb_substr(trim((string) ($_GET["search"] ?? "")), 0, 100);
$category = (string) ($_GET["category"] ?? "all");
if ($category !== "all" && !in_array($category, $categories, true)) { $category = "all"; }
$sortKey = (string) ($_GET["sort"] ?? "");
$sort = isset($sortOptions[$sortKey]) ? $sortKey : "latest";
$page = max(1, (int) ($_GET["page"] ?? 1));

// "View details" on a Home page card opens e.g. lostitem.php?item=12: only that one item is shown (search/filters are ignored)
$itemId = (int) ($_GET["item"] ?? 0);
if ($itemId > 0) {
    $search = "";
    $category = "all";
    $sort = "latest";

    // A shared link can point to the other list (the item was claimed, or moved back, since): send the visitor to the right one
    $whereIs = $con->prepare("SELECT Status FROM inventory_items WHERE UniqueItem_ID = ? LIMIT 1");
    $whereIs->bind_param("i", $itemId);
    $whereIs->execute();
    $whereIsRow = $whereIs->get_result()->fetch_assoc();
    $whereIs->close();
    if ($whereIsRow && $whereIsRow["Status"] === "Claimed") {
        header("Location: claimedlist?item=" . $itemId);
        exit();
    }
}

// The filters again as link parameters (the page number is added only for the pager)
$baseParams = [];
if ($search !== "")       { $baseParams["search"] = $search; }
if ($category !== "all")  { $baseParams["category"] = $category; }
if ($sort !== "latest")   { $baseParams["sort"] = $sort; }
if ($itemId > 0)          { $baseParams["item"] = $itemId; }

$filterCount = ($category !== "all" ? 1 : 0) + ($sort !== "latest" ? 1 : 0);   // number on the Filters button

// Small "x" tags under the toolbar: each one removes that filter
$chips = [];
$without = function ($key) use ($baseParams) { $p = $baseParams; unset($p[$key]); return pageUrl($p); };
if ($search !== "")      { $chips[] = ['Search: "' . $search . '"', $without("search")]; }
if ($category !== "all") { $chips[] = ["Category: " . $category, $without("category")]; }
if ($sort !== "latest")  { $chips[] = ["Sort: " . $sortOptions[$sort][0], $without("sort")]; }
if ($itemId > 0)         { $chips[] = ["Item #" . $itemId, pageUrl([])]; }

$resetFiltersUrl = pageUrl($search !== "" ? ["search" => $search] : []);

// ---------- Build the SQL from the choices ----------
$where = ["Status = ?"];
$types = "s";
$params = [$statusShown];

if ($itemId > 0) {
    // opened from a Home page card: just that one item
    $where[] = "UniqueItem_ID = ?";
    $types .= "i";
    $params[] = $itemId;
}

if ($search !== "") {
    $where[] = "Item_Name LIKE ?";
    $types .= "s";
    $params[] = "%" . addcslashes($search, "%_\\") . "%";
}
if ($category !== "all") {
    $where[] = "Category = ?";
    $types .= "s";
    $params[] = $category;
}
$whereSql = "WHERE " . implode(" AND ", $where);

// ---------- Count, then load only the items of the current page ----------
$countStmt = $con->prepare("SELECT COUNT(*) AS total FROM inventory_items $whereSql");
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalItems = (int) $countStmt->get_result()->fetch_assoc()["total"];
$countStmt->close();

$totalPages = max(1, (int) ceil($totalItems / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $con->prepare("
    SELECT ItemID, Item_Name, Category, Description, Found_In, Surrendered_At_Time, Posted_At,
           (Picture IS NOT NULL) AS Has_Pic
    FROM inventory_items
    $whereSql
    ORDER BY " . $sortOptions[$sort][1] . "
    LIMIT ? OFFSET ?
");
$listStmt->bind_param($types . "ii", ...array_merge($params, [$perPage, $offset]));
$listStmt->execute();
$items = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TamFinds - Lost Items</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Rubik+Mono+One&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
</head>
<body class="plain page-soft">
    <?php include("./includes/navbar.php"); ?>

    <main class="reports" id="reports">
        <header class="reports-head">
            <p class="kicker">Items</p>
            <h1 class="section-title">Lost Items</h1>
            <p class="section-sub">Items that were turned in and are still waiting for their owner.</p>
        </header>

        <!-- ===== Search + Filters ===== -->
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
        </div>

        <!-- ===== Active filters ===== -->
        <?php if (count($chips) > 0) { ?>
            <div class="chips-row">
                <?php foreach ($chips as $chip) { ?>
                    <a class="tag" href="<?php echo e($chip[1]); ?>" aria-label="Remove filter <?php echo e($chip[0]); ?>"><?php echo e($chip[0]); ?> <span aria-hidden="true">&times;</span></a>
                <?php } ?>
                <a class="tag tag--clear" href="<?php echo e(pageUrl([])); ?>">Clear all</a>
            </div>
        <?php } ?>

        <!-- ===== Result count ===== -->
        <p class="result-count">
            <?php if ($totalItems > 0) {
                $startItem = $offset + 1;
                $endItem = min($offset + $perPage, $totalItems);
                echo "Showing " . $startItem . "&ndash;" . $endItem . " of " . $totalItems . " item" . ($totalItems === 1 ? "" : "s");
            } else { echo "No items"; } ?>
        </p>

        <!-- ===== Cards ===== -->
        <?php if (count($items) > 0) { ?>
            <div class="report-list">
            <?php foreach ($items as $row) {
                $iid = (int) $row["ItemID"];
                $hasPic = ((int) $row["Has_Pic"] === 1);
                $postedAt = strtotime($row["Posted_At"] ?? "");
                $foundOn = strtotime($row["Surrendered_At_Time"] ?? "");
            ?>
                <article class="report-card" id="item-<?php echo $iid; ?>">

                    <?php if ($hasPic) { ?>
                        <!-- click the photo to open it full size -->
                        <a class="report-thumb" href="<?php echo e($thisPage); ?>?img=<?php echo $iid; ?>" target="_blank" rel="noopener" aria-label="Open photo of <?php echo e($row["Item_Name"]); ?>">
                            <img src="<?php echo e($thisPage); ?>?img=<?php echo $iid; ?>&amp;thumb=1" alt="Photo of <?php echo e($row["Item_Name"]); ?>" loading="lazy">
                        </a>
                    <?php } else { ?>
                        <div class="report-thumb report-thumb--static report-thumb--empty">
                            <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/></svg>
                            <span>No picture provided</span>
                        </div>
                    <?php } ?>

                    <div class="report-main">
                        <div class="report-top">
                            <div>
                                <h3 class="report-title"><?php echo e($row["Item_Name"]); ?></h3>
                                <span class="cat"><?php echo e($row["Category"]); ?></span>
                            </div>
                            <time class="report-time"><?php echo $postedAt ? e(date("M j, g:i a", $postedAt)) : ""; ?></time>
                        </div>

                        <ul class="report-meta">
                            <li>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                Found in: <?php echo e($row["Found_In"]); ?>
                            </li>
                            <li>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/></svg>
                                Surrendered: <?php echo $foundOn ? e(date("M j, Y", $foundOn)) : "N/A"; ?>
                            </li>
                        </ul>

                        <p class="desc">
                            <strong>Description:</strong>
                            <span class="desc-plain"><?php echo nl2br(e($row["Description"])); ?></span>
                        </p>

                        <div class="report-foot">
                            <span class="status status--unresolved">Unclaimed</span>
                        </div>
                    </div>
                </article>
            <?php } ?>
            </div>
        <?php } else { ?>
            <!-- Empty state -->
            <div class="empty">
                <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
                <h2 class="empty-title"><?php echo $itemId > 0 ? "That item isn't available" : (count($baseParams) > 0 ? "No items match your search" : "No lost items right now"); ?></h2>
                <p><?php echo $itemId > 0 ? "It may have been claimed or removed." : (count($baseParams) > 0 ? "Try a different word or clear the filters." : "Items that are turned in will show up here."); ?></p>
                <?php if (count($baseParams) > 0) { ?><a class="btn" href="<?php echo e(pageUrl([])); ?>"><?php echo $itemId > 0 ? "View all items" : "Clear filters"; ?></a><?php } ?>
            </div>
        <?php } ?>

        <!-- ===== Pagination ===== -->
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
    </main>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
</body>
</html>
