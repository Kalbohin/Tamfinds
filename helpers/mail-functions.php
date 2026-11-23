<?php

use PHPMailer\PHPMailer\PHPMailer;

// ---------------------------------------------------------------------------
// Sends an email through your SMTP account (Brevo).
//
//   - PHPMailer is installed with Composer in the MAIN project folder:
//         composer require phpmailer/phpmailer
//   - The SMTP login lives in connection/mail-settings.php (host, port, username, password, sender).
//
// Both files are loaded only at the moment an email is really sent, so a missing library or
// settings file can never break the other pages: the email just fails and the page shows a message.
// ---------------------------------------------------------------------------

function loadMailer(?string &$error = null): bool {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    $settings = dirname(__DIR__) . '/connection/mail-settings.php';

    if (!is_file($autoload)) {
        $error = 'PHPMailer is not installed (vendor/autoload.php is missing). Run: composer require phpmailer/phpmailer';
        return false;
    }
    if (!is_file($settings)) {
        $error = 'connection/mail-settings.php is missing.';
        return false;
    }

    require_once $autoload;
    require_once $settings;
    return true;
}

/**
 * @param string      $recipient       who receives it
 * @param string      $subject
 * @param string      $message         the HTML of the email
 * @param string|null $attachmentPath  optional file to attach
 * @param string|null $altBody         plain-text version (for email apps that don't show HTML). Made from the HTML if left empty.
 * @param string|null $error           filled with the reason when it fails (handy while testing)
 */
function sendTamFindsEmail(
    string $recipient,
    string $subject,
    string $message,
    ?string $attachmentPath = null,
    ?string $altBody = null,
    ?string &$error = null
): bool {

    if (!loadMailer($error)) {
        error_log('TamFinds email error: ' . $error);
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Timeout = 15;                       // don't leave the page hanging if the mail server is slow

        $mail->SMTPAuth = defined('SMTP_AUTH') ? (bool) SMTP_AUTH : true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;

        // Brevo: port 587 with STARTTLS. (SMTP_SECURE is optional: only set it in mail-settings.php for another provider.)
        $secure = defined('SMTP_SECURE') ? SMTP_SECURE : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPSecure = $secure;
        $mail->SMTPAutoTLS = ($secure !== '');
        $mail->Port = SMTP_PORT;

        // Sender information
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($recipient);

        // Attach picture if provided
        if ($attachmentPath !== null) {
            if (!is_file($attachmentPath) || !is_readable($attachmentPath)) {
                throw new RuntimeException('Attachment file not found or unreadable.');
            }

            $mail->addAttachment(
                $attachmentPath,
                basename($attachmentPath)
            );
        }

        // Email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->AltBody = ($altBody !== null && $altBody !== '') ? $altBody : trim(strip_tags($message));

        $mail->send();

        return true;

    } catch (\Throwable $e) {
        $error = $e->getMessage();
        error_log('TamFinds email error: ' . $error);
        return false;
    }
}

?>
