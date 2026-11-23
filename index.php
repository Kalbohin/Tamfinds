<?php
session_start();
include("./helpers/nocache.php");
include("./connection/config.php");
include("./helpers/SystemOperators.php");
include("./helpers/functions.php");
include("./helpers/auth.php");
$con = connection();
$so = new SystemOperators();

$msg = "";
$errFields = [];        // boxes to highlight in red
$contactValue = "";      // what was typed in the first box (kept when something is wrong)
$toast = "";

// Already logged in? Go straight to your home page (admin -> studentlist.php, user -> dashboard.php).
if (isset($_SESSION["user_id"])) {
    header("Location: " . homePageFor($_SESSION["role"] ?? ""));
    exit();
}

// "Password changed!" message coming from reset.php (shows once, then disappears)
if (isset($_SESSION["flash"])) {
    $toast = $_SESSION["flash"];
    unset($_SESSION["flash"]);
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnLogin'])){
    $contactValue = (string) ($_POST['contact'] ?? "");
    $contact = $so->encrypt(normalizeContact(cleanInput('contact')));
    $password = $_POST['password'] ?? "";   // not sanitized on purpose, so symbols in passwords still work

    // The box accepts an email/phone OR a username, so check both columns
    $select_query = "SELECT * FROM `users` WHERE `Email` = ? OR `Username` = ? LIMIT 1";
    $select_stmt = $con->prepare($select_query);
    $select_stmt->bind_param('ss', $contact, $contact);

    if($select_stmt->execute()){
        $result = $select_stmt->get_result();
        if($result->num_rows > 0){
            $user = $result->fetch_assoc();

            if(password_verify($password, $user['Password'])){
                session_regenerate_id(true);
                $_SESSION["user_id"] = $user['User_ID'];
                $_SESSION["username"] = $so->decrypt($user['Username']);
                $_SESSION["role"] = $user['Role'];

                header("Location: " . homePageFor($user['Role']));   // admin -> studentlist.php, user -> dashboard.php
                exit();
            }else{
                $msg = "Incorrect email/username or password.";
                $errFields = ["password", "contact"];
            }
        }else{
            $msg = "Incorrect email/username or password.";
                $errFields = ["password", "contact"];
        }
    }else{
        $msg = "Something went wrong. Please try again.";
    }
    $select_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TamFinds - Log In</title>
    
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
<body data-reload-on-back>
    <?php include("./includes/header.php"); ?>

    <!-- Background picture: images/background.jpg (green shading is added in style.css) -->
    <div class="bg-photo"></div>

    <main class="page">
        <form class="card card--login" method="POST" action="index">
            <h1 class="card-title card-title--center">Sign In</h1>

            <?php echo formMsg($msg); ?>

            <input class="field <?php echo fieldClass($errFields, "contact"); ?>" type="text" name="contact" placeholder="Email or Username" autocomplete="username" value="<?php echo esc($contactValue); ?>" <?php echo fieldAttrs($errFields, "contact"); ?> required>

            <div class="pw-wrap">
                <input class="field <?php echo fieldClass($errFields, "password"); ?>" type="password" name="password" placeholder="Password" autocomplete="current-password" <?php echo fieldAttrs($errFields, "password"); ?> required>
                <button type="button" class="eye-btn" onclick="togglePassword(this)" aria-label="Show or hide password">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12z"/>
                        <circle cx="12" cy="12" r="3"/>
                        <line class="slash" x1="3" y1="3" x2="21" y2="21"/>
                    </svg>
                </button>
            </div>

            <a class="link-small" href="forgot">Forgot password</a>

            <button class="btn btn--block" type="submit" name="btnLogin">Sign In</button>

            <p class="muted-label">Don't have an account?</p>
            <a class="btn btn--center" href="signup">Sign Up</a>

            <?php if ($toast !== "") { ?>
                <div class="toast"><?php echo $toast; ?></div>
            <?php } ?>
        </form>
    </main>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
</body>
</html>
