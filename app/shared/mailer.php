<?php
// ============================================================
//  MAILER.PHP  (shared/)
//  Sends emails using PHP mail() with SMTP via php.ini settings.
//
//  For XAMPP local testing, configure C:\xampp\php\php.ini:
//    [mail function]
//    SMTP = smtp.gmail.com
//    smtp_port = 587
//    sendmail_from = your@gmail.com
//
//  Or use a free SMTP relay like Brevo (formerly Sendinblue),
//  Mailgun, or Mailtrap for testing.
//
//  USAGE:
//    require_once __DIR__ . '/mailer.php';
//    send_email('student@email.com', 'Subject', '<h1>HTML body</h1>');
// ============================================================

function send_email(string $to, string $subject, string $html_body): bool {
    $from_name  = 'BCP Student Portal';
    $from_email = 'noreply@bcp.edu.ph';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$from_name} <{$from_email}>\r\n";
    $headers .= "Reply-To: {$from_email}\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION;

    return @mail($to, $subject, $html_body, $headers);
}

// ── Email template builder ────────────────────────────────────
function email_template(string $title, string $body_html, string $cta_url = '', string $cta_text = ''): string {
    $cta_block = $cta_url ? "
    <div style='text-align:center;margin:32px 0;'>
      <a href='{$cta_url}'
         style='background:#1a3a8c;color:#fff;text-decoration:none;
                padding:14px 36px;border-radius:8px;font-weight:700;
                font-size:16px;display:inline-block;'>
        {$cta_text}
      </a>
    </div>
    <p style='text-align:center;font-size:12px;color:#aaa;'>
      Or copy this link into your browser:<br>
      <a href='{$cta_url}' style='color:#2563eb;font-size:11px;word-break:break-all;'>{$cta_url}</a>
    </p>" : '';

    return "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'/><meta name='viewport' content='width=device-width,initial-scale=1.0'/></head>
<body style='margin:0;padding:0;background:#f0f4f8;font-family:Segoe UI,sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0'>
    <tr><td align='center' style='padding:32px 16px;'>
      <table width='560' cellpadding='0' cellspacing='0'
             style='background:#fff;border-radius:12px;overflow:hidden;
                    box-shadow:0 4px 20px rgba(0,0,0,.1);max-width:100%;'>
        <!-- Header -->
        <tr>
          <td style='background:#1a3a8c;padding:24px 32px;text-align:center;'>
            <h1 style='color:#fff;font-size:20px;margin:0;font-weight:700;'>
              Bestlink College of the Philippines
            </h1>
            <p style='color:#c8d8f5;font-size:13px;margin:6px 0 0;'>Student Portal</p>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style='padding:32px;'>
            <h2 style='color:#1a3a8c;font-size:18px;margin:0 0 16px;'>{$title}</h2>
            {$body_html}
            {$cta_block}
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style='background:#f8fafc;padding:20px 32px;border-top:1px solid #e8edf4;
                     text-align:center;font-size:12px;color:#aaa;'>
            &copy; " . date('Y') . " Bestlink College of the Philippines &mdash; eLearning Commons<br>
            This is an automated message. Please do not reply.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>";
}
