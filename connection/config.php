<?php
    // Database connection for XAMPP (default: user "root", no password)
    // Same idea as your professor's: call connection() to get the $con object.
    // (The function is only defined once and the connection is reused, so including this file twice is harmless.)
    if (!function_exists("connection")) {
        function connection() {
            static $con = null;
            if ($con !== null) {
                return $con;
            }

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // makes try/catch(mysqli_sql_exception) work

            $db_host = "sql201.infinityfree.com";
            $db_user = "if0_43268721";
            $db_pass = "Kalboako999";
            $db_name = "if0_43268721_lostnfound";

            try {
                $con = new mysqli($db_host, $db_user, $db_pass, $db_name);
            } catch (mysqli_sql_exception $e) {
                die("Connection failed: " . $e->getMessage());
            }
            return $con;
        }
    }
?>
