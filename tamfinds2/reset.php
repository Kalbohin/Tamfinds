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
    header("Location: forgot.php");
    exit();
}

$msg = "";

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnChange'])){
    $newPass = $_POST['new_password'] ?? "";
    $confirm = $_POST['confirm_password'] ?? "";

    if(strlen($newPass) < 8){
        $msg = "Password requires a minimum of 8 characters.";
    }elseif($newPass !== $confirm){
        $msg = "Passwords do not match.";
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
            header("Location: index.php");
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
        <form class="card card--reset" method="POST" action="reset.php">
            <h1 class="card-title card-title--dark">Reset Password</h1>
            <p class="hint">Password requires a minimum of 8 characters</p>

            <input class="field" type="password" name="new_password" placeholder="New Password" minlength="8" required>
            <input class="field" type="password" name="confirm_password" placeholder="Confirm Password" minlength="8" required>

            <button class="btn btn--block btn--spaced" type="submit" name="btnChange">Change Password</button>
        </form>
    </main>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
    <?php showAlert($msg); ?>
</body>
</html>
