<?php
    // Set $activePage ("home", "about", "report", "lostitem" or "receiveditem") before including
    // this file so the current link gets the underline.
    $activePage = isset($activePage) ? $activePage : "";
    $itemsActive = ($activePage === "lostitem" || $activePage === "receiveditem");
?>
<!-- Marks the page as "JS is on" before anything is drawn (the phone menu is only collapsed when JS can open it) -->
<script>document.documentElement.className += " js";</script>

<header class="topbar">
    <a class="logo" href="dashboard.php">TamFinds</a>

    <!-- Hamburger button (only visible on phones / small screens) -->
    <button type="button" class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="mainNav">
        <span class="bar"></span>
        <span class="bar"></span>
        <span class="bar"></span>
    </button>

    <nav class="nav" id="mainNav">
        <a href="dashboard.php" <?php echo ($activePage === "home") ? 'class="active" aria-current="page"' : ''; ?>>Home</a>
        <a href="about.php" <?php echo ($activePage === "about") ? 'class="active" aria-current="page"' : ''; ?>>About us</a>
        <a href="report.php" <?php echo ($activePage === "report") ? 'class="active" aria-current="page"' : ''; ?>>Report</a>

        <!-- Items dropdown (click to open) -->
        <details class="dropdown">
            <summary <?php echo $itemsActive ? 'class="active"' : ''; ?>>Items</summary>
            <div class="dropdown-menu">
                <a href="lostitem.php" <?php echo ($activePage === "lostitem") ? 'class="active" aria-current="page"' : ''; ?>>Lost Items</a>
                <a href="receiveditem.php" <?php echo ($activePage === "receiveditem") ? 'class="active" aria-current="page"' : ''; ?>>Received Items</a>
            </div>
        </details>

        <!-- Opens the confirm pop-up below. It still works without JS because the pop-up also opens through #logout-modal -->
        <a href="#logout-modal" id="logoutLink">Log out</a>
    </nav>
</header>

<!-- Log out confirmation pop-up (plain HTML + CSS, no <dialog>, so it works on every browser) -->
<div class="modal" id="logout-modal" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
    <a class="modal-backdrop" href="#" id="logoutBackdrop" aria-label="Close"></a>

    <div class="modal-box">
        <div class="modal-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
        </div>
        <h2 class="modal-title" id="logoutTitle">Log out?</h2>
        <p class="modal-text">Do you want to log out of TamFinds?</p>

        <div class="modal-actions">
            <a class="btn btn--outline" href="#" id="logoutCancel">Cancel</a>
            <a class="btn" href="logout.php" id="logoutConfirm">Log out</a>
        </div>
    </div>
</div>
