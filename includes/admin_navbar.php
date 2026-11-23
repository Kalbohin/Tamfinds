<?php
    // Navbar of the ADMIN interface.
    // Set $activePage ("students", "staffs", "inventory", "claimed" or "reports") before including this file.
    // Inventory = admin-lost_items.php (items still waiting for their owner)
    // Claimed List = admin-received_items.php (items already received by their owner)
    $activePage = isset($activePage) ? $activePage : "";

    // [key, page, label]
    $adminLinks = [
        ["students",  "studentlist",   "Students"],
        ["staffs",    "stafflist",     "Staffs"],
        ["inventory", "admin-lost_items",     "Inventory"],
        ["claimed",   "admin-received_items", "Claimed List"],
        ["reports",   "reportlist",    "Report List"]
    ];
?>
<!-- Marks the page as "JS is on" before anything is drawn (the phone menu is only collapsed when JS can open it) -->
<script>document.documentElement.className += " js";</script>

<header class="topbar">
    <div class="brand">
        <a class="logo" href="studentlist">TamFinds</a>
        <span class="admin-tag">Admin</span>
    </div>

    <!-- Hamburger button (only visible on phones / small screens) -->
    <button type="button" class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="mainNav">
        <span class="bar"></span>
        <span class="bar"></span>
        <span class="bar"></span>
    </button>

    <nav class="nav nav--wide" id="mainNav">
        <?php foreach ($adminLinks as $link) { ?>
            <a href="<?php echo $link[1]; ?>" <?php echo ($activePage === $link[0]) ? 'class="active" aria-current="page"' : ''; ?>><?php echo $link[2]; ?></a>
        <?php } ?>

        <!-- Opens the confirm pop-up below. It still works without JS because the pop-up also opens through #logout-modal -->
        <a href="#logout-modal" id="logoutLink">Log out</a>
    </nav>
</header>

<?php include(__DIR__ . "/logout_modal.php"); ?>
