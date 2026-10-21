<?php
    // Database connection for XAMPP (default: user "root", no password)
    // Same idea as your professor's: call connection() to get the $con object.
    function connection() {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // makes try/catch(mysqli_sql_exception) work

        $db_host = "localhost";
        $db_user = "root";
        $db_pass = "";
        $db_name = "lostnfound";

        try {
            $con = new mysqli($db_host, $db_user, $db_pass, $db_name);
        } catch (mysqli_sql_exception $e) {
            die("Connection failed: " . $e->getMessage());
        }
        return $con;
    }
?>
