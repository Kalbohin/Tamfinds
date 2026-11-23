<?php
    session_start();
    include("./helpers/nocache.php");

    $loggedIn = isset($_SESSION["user_id"]);   // logged in = full navbar, visitor = simple header
    $activePage = "about";

    // ---------- Edit the text here ----------
    $teamName = "Darksystem Team";

    // Team members, shown as one equal grid (everyone gets the same card).
    // 'photo' is optional: put the picture in the images folder and write its path (example: "images/benedict.jpg").
    // Leave it empty to show the initials instead. An empty 'title' shows "Team Member".
    $members = [
        ["name" => "Benedict Paloma",     "title" => "Lead Developer", "photo" => "images/benedict.jpg"],
        ["name" => "Carl Paz",            "title" => "Lead Database Management", "photo" => "images/carl.jpg"],
        ["name" => "Felix Sevilla",       "title" => "Database Management", "photo" => "images/felix.webp"],
        ["name" => "Clein Nebrida",       "title" => "Support Developer", "photo" => "images/clein.jpg"],
        ["name" => "Donnovan De Guzman",  "title" => "Support Developer", "photo" => "images/donnovan.jpg"],
        ["name" => "Clerish Montalla",    "title" => "UI Designer", "photo" => ""],
        ["name" => "Evanna Mae Luciano",  "title" => "UI Designer", "photo" => "images/evanna.jpg"]
    ];
    // ----------------------------------------

    $icons = [
        "plus"   => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        "search" => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/>',
        "chat"   => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>'
    ];

    function e($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

    // "Benedict Paloma" -> "BP"
    function initialsOf($name) {
        $words = preg_split('/\s+/', trim($name));
        $text = mb_substr($words[0], 0, 1);
        if (count($words) > 1) { $text .= mb_substr(end($words), 0, 1); }
        return mb_strtoupper($text);
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TamFinds - About</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Rubik+Mono+One&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap-grid.min.css" integrity="sha384-VIeCCV0aVHr99fkXDSmzLRuJhJWQXe4bDXs6hgg6w6zOTbtAWr8RqSNWu4a2ef9p" crossorigin="anonymous">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
</head>
<body class="plain page-soft">
    <?php
        if ($loggedIn) {
            include("./includes/navbar.php");
        } else {
            include("./includes/header.php");
        }
    ?>

    <main>
        <!-- hero section for about text -->
        <section class="hero hero--page">
            <div class="hero-inner">
                <h1 class="hero-heading">About</h1>
                <!-- about text -->
                <div class="row g-4 g-lg-5">
                    <div class="col-lg-6">
                        <p class="hero-copy">TamFinds is the Lost and Found System of FEU Roosevelt. It is designed to help students and the school community report, search for, and recover lost belongings more easily. Through this platform, students can report items they have lost or surrender the found item on campus, browse existing reports, and check if their missing belongings have already been found. Users can provide important details about the reported items, such as their names, descriptions, and other information that can help identify them. These details help students recognize their belongings and check if an item belongs to them.</p>
                    </div>
                    <div class="col-lg-6 hero-divider">
                        <p class="hero-copy">The main goal of TamFinds is to make the lost and found process faster, easier, and more convenient. Instead of asking different people around the campus, going to the One Tam Space, or visiting the Dean's Office to look for lost items, students can use one platform to check reported information. This can save time and make it easier for students to find their missing belongings. It also helps improve communication between students and the administrator. Overall, TamFinds aims to help students recover their lost items and make the lost and found process more organized for the FEU Roosevelt community.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Team cards to show the members -->
        <section class="team-section">
            <div class="container page-container">
                <header class="team-head">
                    <p class="kicker">Meet the team</p>
                    <h2 class="section-title"><?php echo e($teamName); ?></h2>
                    <p class="section-sub">The people building TamFinds for FEU Roosevelt.</p>
                </header>

                <div class="row g-3 g-md-4 justify-content-center">
                    <?php foreach ($members as $member) {
                        $title = trim($member["title"]) !== "" ? $member["title"] : "Team Member";
                    ?>
                        <div class="col-6 col-md-4 col-lg-3">
                            <article class="team-card">
                                <div class="team-photo <?php echo $member["photo"] === "" ? "team-photo--empty" : ""; ?>">
                                    <?php if ($member["photo"] !== "") { ?>
                                        <img src="<?php echo e($member["photo"]); ?>" alt="Photo of <?php echo e($member["name"]); ?>" loading="lazy">
                                    <?php } else { ?>
                                        <span class="team-initials" aria-hidden="true"><?php echo e(initialsOf($member["name"])); ?></span>
                                    <?php } ?>
                                </div>

                                <h3 class="team-name"><?php echo e($member["name"]); ?></h3>
                                <span class="team-role"><?php echo e($title); ?></span>
                            </article>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </section>
    </main>

    <script src="js/script.js?v=<?php echo filemtime(__DIR__ . '/js/script.js'); ?>"></script>
</body>
</html>
