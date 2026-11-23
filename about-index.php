<?php
    session_start();
    include("./helpers/nocache.php");

    $loggedIn = isset($_SESSION["user_id"]);   // logged in = full navbar, visitor = simple header
    $activePage = "about-index";

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
    <title>TamFinds - About</title>
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
    </main>

    <script src="js/script.js?v=<?php echo filemtime(__DIR__ . '/js/script.js'); ?>"></script>
</body>
</html>
