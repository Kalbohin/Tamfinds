<?php
session_start();
include("./helpers/nocache.php");
include("./connection/config.php");
include("./helpers/SystemOperators.php");
include("./helpers/functions.php");
$con = connection();
$so = new SystemOperators();

$msg = "";
$redirect = "";

// Already logged in? Go straight to the dashboard.
if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit();
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnSubmit'])){
    $fnamePlain    = cleanInput('firstname');
    $lnamePlain    = cleanInput('lastname');
    $usernamePlain = strtolower(cleanInput('username'));
    $contactPlain  = normalizeContact(cleanInput('contact'));
    $password      = $_POST['password'] ?? "";
    $confirm       = $_POST['confirm_password'] ?? "";

    if($fnamePlain === "" || $lnamePlain === "" || $usernamePlain === "" || $contactPlain === "" || $password === "" || $confirm === ""){
        $msg = "Please fill in all fields.";
    }elseif(strlen($fnamePlain) > 100 || strlen($lnamePlain) > 100){
        $msg = "Names must be 100 characters or less.";
    }elseif(!preg_match('/^(?=.*[a-z])[a-z0-9_.]{3,20}$/', $usernamePlain)){
        $msg = "Username must be 3-20 characters: letters, numbers, _ or . (with at least one letter).";
    }elseif(!isValidContact($contactPlain)){
        $msg = "Please enter a valid email or phone number.";
    }elseif(strlen($password) < 8){
        $msg = "Password requires a minimum of 8 characters.";
    }elseif($password !== $confirm){
        $msg = "Passwords do not match.";
    }else{
        // Encrypt with SystemOperators (same as the professor's code)
        $fname    = $so->encrypt($fnamePlain);
        $lname    = $so->encrypt($lnamePlain);
        $username = $so->encrypt($usernamePlain);
        $email    = $so->encrypt($contactPlain);
        $hashed   = password_hash($password, PASSWORD_DEFAULT);
        $role     = "user";

        // Is the email/phone or the username already used?
        $select_query = "SELECT `Email`, `Username` FROM `users` WHERE `Email` = ? OR `Username` = ?";
        $select_stmt = $con->prepare($select_query);
        $select_stmt->bind_param('ss', $email, $username);

        if($select_stmt->execute()){
            $result = $select_stmt->get_result();
            if($result->num_rows > 0){
                $emailTaken = false;
                $usernameTaken = false;
                while($row = $result->fetch_assoc()){
                    if($row['Email'] === $email){ $emailTaken = true; }
                    if($row['Username'] === $username){ $usernameTaken = true; }
                }
                $msg = $emailTaken ? "That email or phone number is already registered." : "That username is already taken.";
            }else{
                try{
                    // Two inserts that must succeed together: users first, then student_info
                    $con->begin_transaction();

                    $insert_query = 'INSERT INTO `users` (`Username`, `Password`, `Email`, `Role`) VALUES (?, ?, ?, ?)';
                    $insert_stmt = $con->prepare($insert_query);
                    $insert_stmt->bind_param('ssss', $username, $hashed, $email, $role);
                    $insert_stmt->execute();
                    $newUserId = $insert_stmt->insert_id;   // the User_ID MySQL just created

                    // CHANGE these column names if your student_info table uses different ones
                    $student_query = 'INSERT INTO `student_info` (`User_ID`, `First_Name`, `Last_Name`) VALUES (?, ?, ?)';
                    $student_stmt = $con->prepare($student_query);
                    $student_stmt->bind_param('iss', $newUserId, $fname, $lname);
                    $student_stmt->execute();

                    $con->commit();
                    $msg = "Account created! You can now log in.";
                    $redirect = "index.php";
                }catch(mysqli_sql_exception $e){
                    $con->rollback();   // undo the users row if student_info failed
                    $msg = "Something went wrong. Please try again.";
                    // While testing, you can show the real reason with: $msg = $e->getMessage();
                }
            }
        }else{
            $msg = "Failed to execute";
        }
        $select_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TamFinds - Sign Up</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Rubik+Mono+One&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
</head>
<body>
    <?php include("./includes/header.php"); ?>

    <!-- Background picture: images/background.jpg (green shading is added in style.css) -->
    <div class="bg-photo"></div>

    <main class="page">
        <form class="card card--signup" method="POST" action="signup.php">
            <h1 class="card-title">Create Account</h1>

            <!-- first & last name -->
            <div class="form-row">
                <div>
                    <label class="label" for="firstname">First Name</label>
                    <input class="field" type="text" name="firstname" id="firstname" placeholder="Juan" autocomplete="given-name" required>
                </div>
                <div>
                    <label class="label" for="lastname">Last Name</label>
                    <input class="field" type="text" name="lastname" id="lastname" placeholder="Dela Cruz" autocomplete="family-name" required>
                </div>
            </div>

            <!-- username -->
            <label class="label" for="username">Username</label>
            <input class="field" type="text" name="username" id="username" placeholder="juandelacruz" minlength="3" maxlength="20" pattern="(?=.*[A-Za-z])[A-Za-z0-9_.]{3,20}" title="3-20 characters: letters, numbers, _ or . (at least one letter)" autocomplete="off" required>

            <!-- email or phone -->
            <label class="label" for="contact">Email Address or Phone</label>
            <input class="field" type="text" name="contact" id="contact" placeholder="DelaCruz@gmail.com" autocomplete="username" required>

            <!-- create password -->
            <label class="label" for="password">Create Password</label>
            <div class="pw-wrap">
                <input class="field" type="password" name="password" id="password" minlength="8" autocomplete="new-password" required>
                <button type="button" class="eye-btn" onclick="togglePassword(this)" aria-label="Show or hide password">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12z"/>
                        <circle cx="12" cy="12" r="3"/>
                        <line class="slash" x1="3" y1="3" x2="21" y2="21"/>
                    </svg>
                </button>
            </div>

            <!-- confirm password -->
            <label class="label" for="confirm_password">Confirm Password</label>
            <div class="pw-wrap">
                <input class="field" type="password" name="confirm_password" id="confirm_password" minlength="8" autocomplete="new-password" required>
                <button type="button" class="eye-btn" onclick="togglePassword(this)" aria-label="Show or hide password">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12z"/>
                        <circle cx="12" cy="12" r="3"/>
                        <line class="slash" x1="3" y1="3" x2="21" y2="21"/>
                    </svg>
                </button>
            </div>

            <!-- submit or go back to sign in -->
            <div class="form-actions">
                <button class="btn" type="submit" name="btnSubmit">Submit</button>
                <span>Already have an account? <a href="index.php">Log In</a></span>
            </div>
        </form>
    </main>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
    <?php showAlert($msg, $redirect); ?>
</body>
</html>
