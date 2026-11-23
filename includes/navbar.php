<?php
    // Set $activePage ("home", "about", "report", "lostitem" or "receiveditem") before including
    // this file so the current link gets the underline.
    $activePage = isset($activePage) ? $activePage : "";
    $itemsActive = ($activePage === "lostitem" || $activePage === "receiveditem");
?>
<!-- Marks the page as "JS is on" before anything is drawn (the phone menu is only collapsed when JS can open it) -->
<script>document.documentElement.className += " js";</script>

<header class="topbar">
    <a class="logo" href="dashboard">
        <img class="logo-img" src="images/logo-navbar.png" alt="" width="40" height="40">
        <span class="logo-text">TamFinds</span>
    </a>

    <!-- Hamburger button (only visible on phones / small screens) -->
    <button type="button" class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="mainNav">
        <span class="bar"></span>
        <span class="bar"></span>
        <span class="bar"></span>
    </button>

    <nav class="nav" id="mainNav">
        <a href="dashboard" <?php echo ($activePage === "home") ? 'class="active" aria-current="page"' : ''; ?>>Home</a>
        <a href="about" <?php echo ($activePage === "about") ? 'class="active" aria-current="page"' : ''; ?>>About</a>
        <a href="report" <?php echo ($activePage === "report") ? 'class="active" aria-current="page"' : ''; ?>>Report</a>

        <!-- Items dropdown (click to open) -->
        <details class="dropdown">
            <summary <?php echo $itemsActive ? 'class="active"' : ''; ?>>Items</summary>
            <div class="dropdown-menu">
                <a href="lostitem" <?php echo ($activePage === "lostitem") ? 'class="active" aria-current="page"' : ''; ?>>Lost Items</a>
                <a href="claimedlist" <?php echo ($activePage === "claimedlist") ? 'class="active" aria-current="page"' : ''; ?>>Received Items</a>
            </div>
        </details>

        <!-- Opens the confirm pop-up below. It still works without JS because the pop-up also opens through #logout-modal -->
        <a href="#logout-modal" id="logoutLink">Log out</a>
    </nav>
</header>

<?php include(__DIR__ . "/logout_modal.php"); ?>
