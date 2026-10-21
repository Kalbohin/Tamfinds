<?php
session_start();
include("./helpers/nocache.php");
include("./connection/config.php");
include("./helpers/SystemOperators.php");
include("./helpers/functions.php");
$con = connection();
$so = new SystemOperators();

$msg = "";

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnEnter'])){
    $contactPlain = normalizeContact(cleanInput('contact'));

    if(!isValidContact($contactPlain)){
        $msg = "Please enter a valid email or phone number.";
    }else{
        $contact = $so->encrypt($contactPlain);

        $select_query = "SELECT `User_ID` FROM `users` WHERE `Email` = ? LIMIT 1";
        $select_stmt = $con->prepare($select_query);
        $select_stmt->bind_param('s', $contact);

        if($select_stmt->execute()){
            $result = $select_stmt->get_result();
            if($result->num_rows > 0){
                $user = $result->fetch_assoc();

                issueResetCode($con, $so, $user['User_ID'], $contactPlain);

                $_SESSION['reset_user_id'] = $user['User_ID'];
                $_SESSION['reset_contact'] = $contactPlain;
                $_SESSION['reset_verified'] = false;

                header("Location: verify.php");
                exit();
            }else{
                $msg = "No account found with that email or phone number.";
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
    <title>TamFinds - Forgot Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Rubik+Mono+One&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
</head>
<body>
    <?php include("./includes/header.php"); ?>

    <!-- Background picture: images/background.jpg (green shading is added in style.css) -->
    <div class="bg-photo"></div>

    <main class="page">
        <form class="card card--forgot" method="POST" action="forgot.php">
            <svg class="lock-icon" viewBox="0 0 64 80" aria-hidden="true">
                <path d="M16 36V24a16 16 0 0 1 32 0v12" fill="none" stroke="#2d6a14" stroke-width="9"/>
                <rect x="2" y="32" width="60" height="46" rx="10" fill="#2d6a14"/>
                <circle cx="32" cy="50" r="6" fill="#fff"/>
                <path d="M29 52h6l2 14H27z" fill="#fff"/>
            </svg>

            <h1 class="card-title card-title--center card-title--dark">Forgot Password?</h1>
            <p class="hint hint--center">Enter your email or phone number so we could send you a verification code</p>

            <input class="field" type="text" name="contact" placeholder="Email or Phone" required>
            <button class="btn btn--block" type="submit" name="btnEnter">Enter</button>
        </form>
    </main>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
    <?php showAlert($msg); ?>
</body>
</html>
