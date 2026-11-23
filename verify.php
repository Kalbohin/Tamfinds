<?php
session_start();
include("./helpers/nocache.php");
include("./connection/config.php");
include("./helpers/SystemOperators.php");
include("./helpers/functions.php");
$con = connection();
$so = new SystemOperators();

// Must come from forgot.php first
if(!isset($_SESSION['reset_user_id'])){
    header("Location: forgot");
    exit();
}

$userId = $_SESSION['reset_user_id'];
$contact = $_SESSION['reset_contact'];
$msg = "";
$msgType = "err";          // "err" (red) or "ok" (green)
$codeBad = false;           // true = outline the 5 boxes in red
$row = getCodeRow($con, $userId);
if(!$row){
    header("Location: forgot");
    exit();
}

// "Resend" button (only works after the wait time is over)
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnResend'])){
    if($row['reset_code'] !== null && $row['age'] < RESEND_SECONDS){
        $msg = "Please wait a moment before asking for a new code.";
    }else{
        if(issueResetCode($con, $so, $userId, $contact)){
            $msg = "A new code has been sent to your email.";
            $msgType = "ok";
        }else{
            $msg = "We couldn't send the email right now. Please try again in a moment.";
        }
        $row = getCodeRow($con, $userId);
    }
}

// "Confirm" button
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnConfirm'])){
    $boxes = (isset($_POST['code']) && is_array($_POST['code'])) ? $_POST['code'] : [];
    $code = implode("", array_map('trim', $boxes));

    if(!preg_match('/^[0-9]{5}$/', $code)){
        $msg = "Please enter the 5-digit code.";
        $codeBad = true;
    }elseif($row['reset_code'] === null || $row['age'] > CODE_LIFETIME_MIN * 60){
        $msg = "This code has expired. Please ask for a new one.";
        $codeBad = true;
    }elseif($so->encrypt($code) === $row['reset_code']){
        $_SESSION['reset_verified'] = true;
        header("Location: reset");
        exit();
    }else{
        $msg = "Wrong code. Please try again.";
        $codeBad = true;
    }
}

$secondsLeft = ($row['reset_code'] === null) ? 0 : max(0, RESEND_SECONDS - (int) $row['age']);
$demoCode = (SHOW_CODE_ON_SCREEN && $row['reset_code'] !== null) ? $so->decrypt($row['reset_code']) : "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification</title>
    
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
        <div class="card card--verify">
            <a class="back" href="forgot">&lsaquo; Back</a>

            <form method="POST" action="verify">
                <h1 class="card-title card-title--dark">Enter verification code</h1>
                <p class="hint">We've sent the verification code to <?php echo esc(maskEmail($contact)); ?>. Check your spam folder if you don't see it.</p>

                <?php echo formMsg($msg, $msgType); ?>

                <div class="otp <?php echo $codeBad ? "is-invalid" : ""; ?>">
                    <input type="text" name="code[]" maxlength="1" inputmode="numeric" autocomplete="one-time-code" autofocus>
                    <input type="text" name="code[]" maxlength="1" inputmode="numeric">
                    <input type="text" name="code[]" maxlength="1" inputmode="numeric">
                    <input type="text" name="code[]" maxlength="1" inputmode="numeric">
                    <input type="text" name="code[]" maxlength="1" inputmode="numeric">
                </div>

                <?php if ($demoCode !== "") { ?>
                    <p class="demo-code">Testing mode: your code is <strong><?php echo $demoCode; ?></strong></p>
                <?php } ?>

                <button class="btn btn--block" type="submit" name="btnConfirm">Confirm</button>
            </form>

            <form method="POST" action="verify" class="resend" data-seconds="<?php echo $secondsLeft; ?>">
                <span id="resendText" class="resend-text">Resend after <?php echo $secondsLeft; ?> seconds</span>
                <button type="submit" name="btnResend" id="resendBtn" class="link-btn" <?php echo ($secondsLeft > 0) ? "hidden" : ""; ?>>Resend code</button>
            </form>
        </div>
    </main>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
</body>
</html>
