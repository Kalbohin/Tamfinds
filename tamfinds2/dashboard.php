<?php
session_start();
include("./helpers/nocache.php");

$isFeed = isset($_GET["feed"]);   // dashboard.php?feed=1 is asked by the page itself (live updates)

if (!isset($_SESSION["user_id"])) {
    if ($isFeed) { http_response_code(401); exit(); }
    header("Location: index.php");
    exit();
}

include("./connection/config.php");
include("./helpers/functions.php");
$con = connection();

$activePage = "home";

// The carousel always shows the 5 newest rows of report_items (newest first).
// When a new report is added, the oldest card drops off and the new one takes the first spot.
try {
    $lostItems = latestReports($con, 5);
} catch (mysqli_sql_exception $ex) {
    error_log("TamFinds carousel: " . $ex->getMessage());   // e.g. the Unique_ID column was not added yet
    $lostItems = [];
}

// Live updates: the page asks for this every few seconds and only redraws the cards when something changed
if ($isFeed) {
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["sig" => lostFeedSignature($lostItems), "html" => lostSlidesHtml($lostItems)]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TamFinds - Home</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Rubik+Mono+One&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
</head>
<body class="plain">
    <?php include("./includes/navbar.php"); ?>

    <main>
        <!-- 1) HERO: the school picture + gradient live only inside this section -->
        <section class="hero">
            <div class="hero-inner">
                <span class="hero-eyebrow">FEU Roosevelt Lost &amp; Found</span>
                <h1 class="hero-heading">Lost something? Let’s help you <span class="hl">find it.</span></h1>
                <p class="hero-text">Report what you lost, browse what’s been found, and get it back — all in one place, right on campus.</p>

                <div class="hero-actions">
                    <a class="btn-pill btn-pill--solid" href="lostitem.php">View items</a>
                    <a class="btn-pill" href="report.php?new=1">Report an item</a>
                </div>
            </div>

            <a class="scroll-cue" href="#lost-items" aria-label="Scroll down to recently reported items">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </a>
        </section>

        <!-- 2) CAROUSEL: scrolls by itself every 3 seconds, or use the arrows / swipe -->
        <section class="section" id="lost-items">
            <div class="carousel" data-carousel data-feed="dashboard.php?feed=1" data-sig="<?php echo lostFeedSignature($lostItems); ?>" role="region" aria-roledescription="carousel" aria-label="Recently reported lost items">
                <div class="section-head">
                    <div>
                        <p class="kicker">Lost items</p>
                        <h2 class="section-title">Recently reported</h2>
                        <p class="section-sub">Fresh reports from around campus.</p>
                    </div>

                    <div class="carousel-controls">
                        <button type="button" class="icon-btn" data-carousel-prev aria-label="Previous item">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                        <button type="button" class="icon-btn" data-carousel-next aria-label="Next item">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                        <button type="button" class="icon-btn" data-carousel-play aria-label="Pause auto-scroll" aria-pressed="false">
                            <svg class="i-pause" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
                            <svg class="i-play" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M7 5v14l12-7z"/></svg>
                        </button>
                    </div>
                </div>

                <div class="carousel-track" tabindex="0" aria-live="off"><?php echo lostSlidesHtml($lostItems); ?></div>

                <div class="carousel-foot">
                    <div class="carousel-progress" aria-hidden="true"><span class="carousel-thumb"></span></div>
                    <a class="view-all" href="lostitem.php">View all <span aria-hidden="true">&rarr;</span></a>
                </div>
            </div>
        </section>

        <!-- 3) ABOUT: short intro with a link to the full About page -->
        <section class="about">
            <div class="about-inner">
                <div>
                    <p class="kicker">About TamFinds</p>
                    <h2 class="section-title">Getting lost things back where they belong.</h2>
                    <p class="about-text">TamFinds is a campus lost and found for FEU Roosevelt. Report an item, browse what has been turned in, and get back to your belongings faster, without the endless asking around.</p>
                    <a class="btn" href="about.php">Read more</a>
                </div>

                <ol class="about-steps">
                    <li>
                        <span class="step-num">1</span>
                        <div>
                            <h3 class="step-title">Report</h3>
                            <p class="step-text">Tell us what you lost or found.</p>
                        </div>
                    </li>
                    <li>
                        <span class="step-num">2</span>
                        <div>
                            <h3 class="step-title">Browse</h3>
                            <p class="step-text">Check the latest reported items.</p>
                        </div>
                    </li>
                    <li>
                        <span class="step-num">3</span>
                        <div>
                            <h3 class="step-title">Reunite</h3>
                            <p class="step-text">Get your belongings back.</p>
                        </div>
                    </li>
                </ol>
            </div>
        </section>
    </main>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
</body>
</html>
