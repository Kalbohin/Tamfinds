<?php
session_start();
include("./helpers/nocache.php");
include("./connection/config.php");
include("./helpers/SystemOperators.php");
include("./helpers/functions.php");
$con = connection();
$so = new SystemOperators();

// Must have passed the verification code page first
if(!isset($_SESSION['reset_user_id']) || empty($_SESSION['reset_verified'])){
    header("Location: forgot");
    exit();
}

$msg = "";
$errFields = [];

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnChange'])){
    $newPass = $_POST['new_password'] ?? "";
    $confirm = $_POST['confirm_password'] ?? "";

    if(strlen($newPass) < 8){
        $msg = "Password requires a minimum of 8 characters.";
        $errFields = ["new_password", "confirm_password"];
    }elseif($newPass !== $confirm){
        $msg = "Passwords do not match.";
        $errFields = ["confirm_password", "new_password"];
    }else{
        $hashed = password_hash($newPass, PASSWORD_DEFAULT);
        $userId = $_SESSION['reset_user_id'];

        // Save the new password and remove the used code
        $update_query = "UPDATE `users` SET `Password` = ?, `Reset_Code` = NULL, `Reset_Sent_At` = NULL WHERE `User_ID` = ?";
        $update_stmt = $con->prepare($update_query);
        $update_stmt->bind_param('si', $hashed, $userId);
        try{
            $update_stmt->execute();

            unset($_SESSION['reset_user_id'], $_SESSION['reset_contact'], $_SESSION['reset_verified']);
            $_SESSION['flash'] = "Password changed!";
            header("Location: index");
            exit();
        }catch(mysqli_sql_exception $e){
            $msg = "Something went wrong. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TamFinds - Reset Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Rubik+Mono+One&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
</head>
<body>
    <?php include("./includes/header.php"); ?>

    <!-- Background picture: images/background.jpg (green shading is added in style.css) -->
    <div class="bg-photo"></div>

    <main class="page">
        <form class="card card--reset" method="POST" action="reset">
            <h1 class="card-title card-title--dark">Reset Password</h1>
            <p class="hint">Password requires a minimum of 8 characters</p>

            <?php echo formMsg($msg); ?>

            <div class="pw-wrap">
                <input class="field <?php echo fieldClass($errFields, "new_password"); ?>" type="password" name="new_password" placeholder="New Password" minlength="8" <?php echo fieldAttrs($errFields, "new_password"); ?> required>
                <button type="button" class="eye-btn" onclick="togglePassword(this)" aria-label="Show or hide password">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12z"/>
                        <circle cx="12" cy="12" r="3"/>
                        <line class="slash" x1="3" y1="3" x2="21" y2="21"/>
                    </svg>
                </button>
            </div>
            <div class="pw-wrap">
                <input class="field <?php echo fieldClass($errFields, "confirm_password"); ?>" type="password" name="confirm_password" placeholder="Confirm Password" minlength="8" <?php echo fieldAttrs($errFields, "confirm_password"); ?> required>
                <button type="button" class="eye-btn" onclick="togglePassword(this)" aria-label="Show or hide password">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12z"/>
                        <circle cx="12" cy="12" r="3"/>
                        <line class="slash" x1="3" y1="3" x2="21" y2="21"/>
                    </svg>
                </button>
            </div>

            <button class="btn btn--block btn--spaced" type="submit" name="btnChange">Change Password</button>
        </form>
    </main>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
</body>
</html>
