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
// What was typed stays in the boxes when something is wrong (passwords are the only ones cleared, for safety)
$val = ["student_no" => "", "username" => "", "contact" => ""];

// Already logged in? Go straight to your home page.
if (isset($_SESSION["user_id"])) {
    header("Location: " . homePageFor($_SESSION["role"] ?? ""));
    exit();
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnSubmit'])){
    foreach ($val as $name => $unused) {
        $val[$name] = mb_substr((string) ($_POST[$name] ?? ""), 0, 200);
    }
    $studentNo     = strtoupper(preg_replace('/\s+/', '', trim((string) ($_POST['student_no'] ?? ""))));
    $usernamePlain = strtolower(cleanInput('username'));
    $contactPlain  = normalizeContact(cleanInput('contact'));
    $password      = $_POST['password'] ?? "";
    $confirm       = $_POST['confirm_password'] ?? "";

    $emptyFields = [];
    foreach (["student_no" => $studentNo, "username" => $usernamePlain, "contact" => $contactPlain, "password" => $password, "confirm_password" => $confirm] as $name => $value) {
        if ($value === "") { $emptyFields[] = $name; }
    }

    if(count($emptyFields) > 0){
        $msg = "Please fill in all fields.";
        $errFields = $emptyFields;
    }elseif(strlen($studentNo) > 20){
        $msg = "That student number is too long.";
        $errFields = ["student_no"];
    }elseif(!preg_match('/^(?=.*[a-z])[a-z0-9_.]{3,20}$/', $usernamePlain)){
        $msg = "Username must be 3-20 characters: letters, numbers, _ or . (with at least one letter).";
        $errFields = ["username"];
    }elseif(!filter_var($contactPlain, FILTER_VALIDATE_EMAIL) || strlen($contactPlain) > 254){
        // email only: the password reset code is sent by email, so a phone number can't be used
        $msg = "Please enter a valid email address.";
        $errFields = ["contact"];
    }elseif(strlen($password) < 8){
        $msg = "Password requires a minimum of 8 characters.";
        $errFields = ["password", "confirm_password"];
    }elseif($password !== $confirm){
        $msg = "Passwords do not match.";
        $errFields = ["confirm_password", "password"];
    }else{
        // 1) Is this number on the student list? (the admin adds students there)
        $studentNoEnc = $so->encrypt($studentNo);
        $find_query = "SELECT `StudentInfo_ID`, `User_ID` FROM `student_info` WHERE `StudentNo_ID` = ? LIMIT 1";
        $find_stmt = $con->prepare($find_query);
        $find_stmt->bind_param('s', $studentNoEnc);
        $find_stmt->execute();
        $student = $find_stmt->get_result()->fetch_assoc();
        $find_stmt->close();

        // Not a student? Then it may be a staff ID (numbers only) from the staff list (stafflist_info).
        // A staff ID creates an ADMIN account.
        $staff = null;
        if(!$student && ctype_digit($studentNo) && strlen($studentNo) <= 10){
            $staff_stmt = $con->prepare("SELECT `StaffListInfo_ID`, `User_ID` FROM `stafflist_info` WHERE `StaffList_ID` = ? LIMIT 1");
            $staff_stmt->bind_param('s', $studentNo);
            $staff_stmt->execute();
            $staff = $staff_stmt->get_result()->fetch_assoc();
            $staff_stmt->close();
        }
        $isStaff = (!$student && $staff);

        $eligible = ((bool) $student) || ((bool) $staff);

        if(!$eligible){
            $msg = "You're not eligible to make an account.";
            $errFields = ["student_no"];
        }elseif(($isStaff ? $staff['User_ID'] : $student['User_ID']) !== null){
            $msg = $isStaff
                ? "An account already exists for this staff ID. Try logging in, or use Forgot Password."
                : "An account already exists for this student. Try logging in, or use Forgot Password.";
            $errFields = ["student_no"];
        }else{
            // the row to link the new account to: the staff record or the student record
            $studentInfoId = $isStaff ? (int) $staff['StaffListInfo_ID'] : (int) $student['StudentInfo_ID'];

            // 2) Encrypt with SystemOperators (same as the professor's code)
            $username = $so->encrypt($usernamePlain);
            $email    = $so->encrypt($contactPlain);
            $hashed   = password_hash($password, PASSWORD_DEFAULT);
            $role     = $isStaff ? "admin" : "user";

            // Is the email/phone or the username already used?
            $select_query = "SELECT `Email`, `Username` FROM `users` WHERE `Email` = ? OR `Username` = ?";
            $select_stmt = $con->prepare($select_query);
            $select_stmt->bind_param('ss', $email, $username);

            if($select_stmt->execute()){
                $result = $select_stmt->get_result();
                if($result->num_rows > 0){
                    $emailTaken = false;
                    while($row = $result->fetch_assoc()){
                        if($row['Email'] === $email){ $emailTaken = true; }
                    }
                    $errFields = $emailTaken ? ["contact"] : ["username"];
                    $msg = $emailTaken ? "That email address is already registered." : "That username is already taken.";
                }else{
                    try{
                        // Two steps that must succeed together: create the account, then link it to the student record
                        $con->begin_transaction();

                        $insert_query = 'INSERT INTO `users` (`Username`, `Password`, `Email`, `Role`) VALUES (?, ?, ?, ?)';
                        $insert_stmt = $con->prepare($insert_query);
                        $insert_stmt->bind_param('ssss', $username, $hashed, $email, $role);
                        $insert_stmt->execute();
                        $newUserId = $insert_stmt->insert_id;

                        $link_query = $isStaff
                            ? 'UPDATE `stafflist_info` SET `User_ID` = ? WHERE `StaffListInfo_ID` = ? AND `User_ID` IS NULL'
                            : 'UPDATE `student_info` SET `User_ID` = ? WHERE `StudentInfo_ID` = ? AND `User_ID` IS NULL';
                        $link_stmt = $con->prepare($link_query);
                        $link_stmt->bind_param('ii', $newUserId, $studentInfoId);
                        $link_stmt->execute();

                        if($link_stmt->affected_rows !== 1){
                            // somebody else just took this student / staff record
                            $con->rollback();
                            $msg = $isStaff
                                ? "An account already exists for this staff ID. Try logging in, or use Forgot Password."
                                : "An account already exists for this student. Try logging in, or use Forgot Password.";
                            $errFields = ["student_no"];
                        }else{
                            $con->commit();
                            $_SESSION["flash"] = "Account created! You can now log in.";   // shown on the login card
                            header("Location: index");
                            exit();
                        }
                    }catch(mysqli_sql_exception $e){
                        $con->rollback();
                        $msg = "Something went wrong. Please try again.";
                        // While testing, you can show the real reason with: $msg = $e->getMessage();
                    }
                }
            }else{
                $msg = "Something went wrong. Please try again.";
            }
            $select_stmt->close();
        }
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
        <form class="card card--signup" method="POST" action="signup">
            <h1 class="card-title">Create Account</h1>

            <?php echo formMsg($msg); ?>

            <!-- student number (or staff ID): must match the student list or the staff list -->
            <label class="label" for="student_no">Student Number / Staff ID</label>
            <input class="field field--upper <?php echo fieldClass($errFields, "student_no"); ?>" type="text" name="student_no" id="student_no" placeholder="e.g. R2025042251" maxlength="20" autocomplete="off" value="<?php echo esc($val['student_no']); ?>" <?php echo fieldAttrs($errFields, "student_no"); ?> required>

            <!-- username -->
            <label class="label" for="username">Username</label>
            <input class="field <?php echo fieldClass($errFields, "username"); ?>" type="text" name="username" id="username" placeholder="juandelacruz" minlength="3" maxlength="20" pattern="(?=.*[A-Za-z])[A-Za-z0-9_.]{3,20}" title="3-20 characters: letters, numbers, _ or . (at least one letter)" autocomplete="off" value="<?php echo esc($val['username']); ?>" <?php echo fieldAttrs($errFields, "username"); ?> required>

            <!-- email or phone -->
            <label class="label" for="contact">Email Address</label>
            <input class="field <?php echo fieldClass($errFields, "contact"); ?>" type="text" inputmode="email" name="contact" id="contact" placeholder="DelaCruz@gmail.com" maxlength="254" autocomplete="email" value="<?php echo esc($val['contact']); ?>" <?php echo fieldAttrs($errFields, "contact"); ?> required>

            <!-- create password -->
            <label class="label" for="password">Create Password</label>
            <div class="pw-wrap">
                <input class="field <?php echo fieldClass($errFields, "password"); ?>" type="password" name="password" id="password" minlength="8" autocomplete="new-password" <?php echo fieldAttrs($errFields, "password"); ?> required>
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
                <input class="field <?php echo fieldClass($errFields, "confirm_password"); ?>" type="password" name="confirm_password" id="confirm_password" minlength="8" autocomplete="new-password" <?php echo fieldAttrs($errFields, "confirm_password"); ?> required>
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
                <span>Already have an account? <a href="index">Log In</a></span>
            </div>
        </form>
    </main>

    <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
</body>
</html>
