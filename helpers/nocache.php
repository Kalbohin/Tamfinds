<?php
    // Tells the browser NOT to keep a copy of the page.
    // Include this right after session_start() on every page.
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
?>
