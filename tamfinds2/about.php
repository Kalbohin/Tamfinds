<?php
session_start();
include("./helpers/nocache.php");
$loggedIn = isset($_SESSION["user_id"]);
$activePage = "about";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TamFinds - About Us</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Rubik+Mono+One&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
</head>
<body class="plain">
    <?php
        if ($loggedIn) {
            include("./includes/navbar.php");
        } else {
            include("./includes/header.php");
        }
    ?>

    <main class="page">
        <!-- About Us content goes here -->
    </main>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
</body>
</html>
