<?php
session_start();
include("./helpers/nocache.php");
include("./connection/config.php");
include("./helpers/SystemOperators.php");
include("./helpers/functions.php");
$con = connection();
$so = new SystemOperators();

$msg = "";
$errFields = [];
$contactValue = "";      // kept in the box when something is wrong

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnEnter'])){
    $contactValue = (string) ($_POST['contact'] ?? "");
    $contactPlain = normalizeContact(cleanInput('contact'));

    if(!isValidContact($contactPlain)){
        $msg = "Please enter a valid email address.";
        $errFields = ["contact"];
    }elseif(strpos($contactPlain, "@") === false){
        // the code is sent by email, so a phone number can't be used here
        $msg = "The code is sent by email. Please enter the email address of your account.";
        $errFields = ["contact"];
    }else{
        $contact = $so->encrypt($contactPlain);

        // Only emails that have an account go on to the verify page
        $select_query = "SELECT `User_ID` FROM `users` WHERE `Email` = ? LIMIT 1";
        $select_stmt = $con->prepare($select_query);
        $select_stmt->bind_param('s', $contact);

        if($select_stmt->execute()){
            $result = $select_stmt->get_result();
            if($result->num_rows > 0){
                $user = $result->fetch_assoc();

                // A code was already emailed a moment ago? Don't send another one (stops people from flooding an inbox).
                $existing = getCodeRow($con, $user['User_ID']);
                $justSent = $existing && $existing['reset_code'] !== null && $existing['age'] !== null && (int) $existing['age'] < RESEND_SECONDS;

                if($justSent || issueResetCode($con, $so, $user['User_ID'], $contactPlain)){
                    $_SESSION['reset_user_id'] = $user['User_ID'];
                    $_SESSION['reset_contact'] = $contactPlain;
                    $_SESSION['reset_verified'] = false;

                    header("Location: verify");
                    exit();
                }else{
                    $msg = "We couldn't send the email right now. Please try again in a moment.";
                }
            }else{
                $msg = "No account found with that email address.";
                $errFields = ["contact"];
            }
        }else{
            $msg = "Something went wrong. Please try again.";
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
    <title>Forgot Password</title>
    
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:title" content="TamFinds">
    <meta property="og:description" content="Lost something? Let’s help you find it.">
    <meta property="og:image" content="https://www.tamfinds.online/images/logo.jpg">
    <meta property="og:url" content="https://www.tamfinds.online/">
    <meta property="og:type" content="website">

    <!-- Icon for tab -->
    <link href="images/logo.jpg" rel="icon" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Rubik+Mono+One&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
</head>
<body>
    <?php include("./includes/header.php"); ?>

    <!-- Background picture: images/background.jpg (green shading is added in style.css) -->
    <div class="bg-photo"></div>

    <main class="page">
        <form class="card card--forgot" method="POST" action="forgot">
            <svg class="lock-icon" viewBox="0 0 64 80" aria-hidden="true">
                <path d="M16 36V24a16 16 0 0 1 32 0v12" fill="none" stroke="#2d6a14" stroke-width="9"/>
                <rect x="2" y="32" width="60" height="46" rx="10" fill="#2d6a14"/>
                <circle cx="32" cy="50" r="6" fill="#fff"/>
                <path d="M29 52h6l2 14H27z" fill="#fff"/>
            </svg>

            <h1 class="card-title card-title--center card-title--dark">Forgot Password?</h1>
            <p class="hint hint--center">Enter your email address so we could send you a verification code</p>

            <?php echo formMsg($msg); ?>

            <input class="field <?php echo fieldClass($errFields, "contact"); ?>" type="text" name="contact" placeholder="Email" value="<?php echo esc($contactValue); ?>" <?php echo fieldAttrs($errFields, "contact"); ?> required>
            <button class="btn btn--block" type="submit" name="btnEnter">Enter</button>
        </form>
    </main>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
</body>
</html>
