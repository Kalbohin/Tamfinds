<?php

// ---------------------------------------------------------------------------
// The look of the emails people receive in their inbox.
//
// Email apps (Gmail, Outlook, ...) ignore most modern CSS, so the layout below is built with
// tables and inline styles on purpose. It has no pictures, so nothing gets blocked, and it
// shrinks nicely on a phone.
//
// To change the colors, edit the hex codes in the $c list. The text is further down.
// ---------------------------------------------------------------------------

// Returns ["subject" => ..., "html" => ..., "text" => ...] for the "Password Reset Request" email.
//   $name    = first name for the greeting (leave "" to just say "Hello!")
//   $code    = the 5-digit code
//   $minutes = how long the code works
function resetCodeEmail(string $name, string $code, int $minutes): array {
    $c = [
        "page"     => "#eef3ea",   // background behind the card
        "card"     => "#ffffff",
        "line"     => "#e3ebde",   // thin lines
        "green"    => "#2d6a14",   // header
        "greenDk"  => "#173a08",   // titles and the code
        "yellow"   => "#ffc20e",   // TAMFINDS word + accent bar
        "text"     => "#3d4a37",
        "muted"    => "#7d8c74",
        "codeBox"  => "#eef6e8",
        "codeLine" => "#cfe3c3",
    ];
    $font = "'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    $safeName = htmlspecialchars(trim($name), ENT_QUOTES, "UTF-8");
    $safeCode = htmlspecialchars($code, ENT_QUOTES, "UTF-8");
    $greeting = $safeName !== "" ? "Hello, {$safeName}!" : "Hello!";
    $year = date("Y");

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light only">
<title>TamFinds verification code</title>
</head>
<body style="margin:0;padding:0;background:{$c['page']};">

<!-- the short preview line some email apps show next to the subject -->
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:{$c['page']};font-size:1px;line-height:1px;">
Your TamFinds verification code is {$safeCode}. It expires in {$minutes} minutes.
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{$c['page']}" style="background:{$c['page']};">
<tr><td align="center" style="padding:28px 12px;">

  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="{$c['card']}" style="width:100%;max-width:600px;background:{$c['card']};border:1px solid {$c['line']};border-radius:18px;">

    <!-- header -->
    <tr>
      <td align="center" bgcolor="{$c['green']}" style="background:{$c['green']};background-image:linear-gradient(135deg,#14570a 0%,{$c['green']} 55%,#4f9a14 100%);padding:30px 20px 24px;border-radius:18px 18px 0 0;">
        <div style="font-family:{$font};font-size:28px;line-height:32px;font-weight:800;letter-spacing:5px;color:{$c['yellow']};">TAMFINDS</div>
        <div style="font-family:{$font};font-size:11px;line-height:16px;font-weight:700;letter-spacing:2.5px;color:#d8ebc9;padding-top:6px;">LOST AND FOUND SYSTEM</div>
      </td>
    </tr>
    <tr><td height="4" bgcolor="{$c['yellow']}" style="height:4px;line-height:4px;font-size:4px;background:{$c['yellow']};background-image:linear-gradient(90deg,#c8d600,{$c['yellow']});">&nbsp;</td></tr>

    <!-- message -->
    <tr>
      <td align="center" style="padding:34px 32px 8px;font-family:{$font};">
        <div style="font-size:22px;line-height:28px;font-weight:700;color:{$c['greenDk']};">Password Reset Request</div>
        <div style="font-size:15px;line-height:22px;color:{$c['text']};padding-top:16px;">{$greeting}</div>
        <div style="font-size:15px;line-height:23px;color:{$c['text']};padding-top:10px;">We received a request to reset your TamFinds account password. Use the verification code below to continue.</div>
      </td>
    </tr>

    <!-- the code -->
    <tr>
      <td align="center" style="padding:22px 32px 6px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{$c['codeBox']}" style="background:{$c['codeBox']};border:1px solid {$c['codeLine']};border-radius:16px;">
          <tr>
            <td align="center" style="padding:22px 12px 20px;font-family:{$font};">
              <div style="font-size:11px;line-height:16px;font-weight:700;letter-spacing:2px;color:#5a6b52;">YOUR VERIFICATION CODE</div>
              <div style="font-size:40px;line-height:48px;font-weight:800;letter-spacing:12px;color:{$c['greenDk']};padding:10px 0 12px 12px;">{$safeCode}</div>
              <div style="font-size:12px;line-height:18px;font-weight:700;color:#7a5200;background:#fff1c2;border-radius:999px;display:inline-block;padding:4px 14px;">Expires in {$minutes} minutes</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- safety notes -->
    <tr>
      <td align="center" style="padding:18px 32px 30px;font-family:{$font};">
        <div style="font-size:13px;line-height:20px;color:{$c['text']};">Never share this code with anyone. TamFinds will never ask you for it.</div>
        <div style="font-size:13px;line-height:20px;color:{$c['muted']};padding-top:8px;">If you didn't request a password reset, you can safely ignore this email.</div>
      </td>
    </tr>

    <!-- footer -->
    <tr><td style="padding:0 32px;"><div style="height:1px;line-height:1px;font-size:1px;background:{$c['line']};">&nbsp;</div></td></tr>
    <tr>
      <td align="center" style="padding:20px 32px 26px;font-family:{$font};">
        <div style="font-size:12px;line-height:18px;color:{$c['muted']};">This is an automated message from TamFinds.</div>
        <div style="font-size:12px;line-height:18px;color:{$c['muted']};padding-top:6px;">&copy; {$year} TamFinds. All rights reserved.</div>
      </td>
    </tr>

  </table>

</td></tr>
</table>

</body>
</html>
HTML;

    // The plain-text version (for email apps that don't show HTML)
    $text = "TAMFINDS - LOST AND FOUND SYSTEM\n\n"
          . "Password Reset Request\n\n"
          . ($name !== "" ? "Hello, " . trim($name) . "!" : "Hello!") . "\n\n"
          . "We received a request to reset your TamFinds account password. Use the verification code below to continue.\n\n"
          . "YOUR VERIFICATION CODE: " . $code . "\n"
          . "Expires in " . $minutes . " minutes.\n\n"
          . "Never share this code with anyone. TamFinds will never ask you for it.\n"
          . "If you didn't request a password reset, you can safely ignore this email.\n\n"
          . "This is an automated message from TamFinds.\n"
          . "(c) " . $year . " TamFinds. All rights reserved.\n";

    return [
        "subject" => "Your TamFinds verification code",
        "html"    => $html,
        "text"    => $text,
    ];
}

?>
