<?php
// ============================================================
//  MAILER.PHP  (shared/)
//  Sends HTML emails over SMTP using PHP's native socket
//  functions — no Composer or PHPMailer required.
//
//  Configuration (set in app/.env):
//    MAIL_HOST      SMTP server hostname   e.g. smtp.gmail.com
//    MAIL_PORT      587 (STARTTLS) or 465 (SSL)
//    MAIL_USER      SMTP login / sender address
//    MAIL_PASS      SMTP password or App Password
//    MAIL_FROM_NAME Display name           e.g. BCP Student Portal
//    MAIL_ENCRYPT   tls  (STARTTLS, default) | ssl | none
//
//  Gmail quick-start:
//    1. Enable 2-Factor Auth on the Google account.
//    2. Generate an App Password (Google → Security → App Passwords).
//    3. Set MAIL_HOST=smtp.gmail.com  MAIL_PORT=587
//       MAIL_USER=you@gmail.com  MAIL_PASS=<app-password>
//       MAIL_ENCRYPT=tls
//
//  PUBLIC FUNCTIONS:
//    send_email(string $to, string $subject, string $html): bool
//    email_template(string $title, string $body_html,
//                   string $cta_url = '', string $cta_text = ''): string
// ============================================================

// ── Load SMTP config from .env ────────────────────────────────
function _mailer_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $env = [];
    $env_file = __DIR__ . '/../.env';
    if (is_readable($env_file)) {
        foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v);
            }
        }
    }

    // Server environment variables OVERRIDE .env values.
    // Set MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS, MAIL_ENCRYPT, MAIL_FROM_NAME
    // in your hosting control panel to avoid storing credentials in files.
    foreach (['MAIL_HOST','MAIL_PORT','MAIL_USER','MAIL_PASS','MAIL_FROM_NAME','MAIL_ENCRYPT'] as $key) {
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            $env[$key] = $val;
        }
    }

    $cfg = [
        'host'      => $env['MAIL_HOST']      ?? '',
        'port'      => (int)($env['MAIL_PORT'] ?? 587),
        'user'      => $env['MAIL_USER']       ?? '',
        'pass'      => $env['MAIL_PASS']       ?? '',
        'from_name' => $env['MAIL_FROM_NAME']  ?? 'BCP Student Portal',
        'encrypt'   => strtolower($env['MAIL_ENCRYPT'] ?? 'tls'),
    ];
    return $cfg;
}

// ── Low-level SMTP send ───────────────────────────────────────
/**
 * Send an HTML email via SMTP (STARTTLS or SSL).
 *
 * @param  string $to      Recipient email address
 * @param  string $subject Email subject line
 * @param  string $html    Full HTML body
 * @return bool            true on success
 * @throws RuntimeException on SMTP failure
 */
function send_email(string $to, string $subject, string $html): bool {
    $c = _mailer_config();

    if (empty($c['host']) || empty($c['user']) || empty($c['pass'])) {
        throw new RuntimeException(
            'SMTP not configured. Add MAIL_HOST, MAIL_USER, MAIL_PASS to your .env file.'
        );
    }

    $host    = $c['host'];
    $port    = $c['port'];
    $encrypt = $c['encrypt'];   // 'tls', 'ssl', or 'none'
    $user    = $c['user'];
    $pass    = $c['pass'];
    $from    = $c['user'];      // sender address == SMTP login
    $name    = $c['from_name'];
    $timeout = 20; // increased for hosted environments with higher latency

    // Auto-detect SSL mode: if port is 465 force ssl regardless of MAIL_ENCRYPT setting
    if ($port === 465) $encrypt = 'ssl';

    // ── 1. Open socket ────────────────────────────────────────
    $socket_host = ($encrypt === 'ssl') ? "ssl://$host" : $host;
    $errno = $errstr = null;
    $sock = @fsockopen($socket_host, $port, $errno, $errstr, $timeout);
    if (!$sock) {
        throw new RuntimeException("SMTP connection failed to $host:$port — $errstr ($errno)");
    }
    stream_set_timeout($sock, $timeout);

    // Helper: read one response line (or multi-line block)
    $read = function () use ($sock): string {
        $out = '';
        while (!feof($sock)) {
            $line = fgets($sock, 512);
            if ($line === false) break;
            $out .= $line;
            // Multi-line responses have a '-' as the 4th char; last line has a space
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $out;
    };

    // Helper: send a command and read response
    $cmd = function (string $command) use ($sock, $read): string {
        fwrite($sock, $command . "\r\n");
        return $read();
    };

    // Helper: assert response code
    $expect = function (string $resp, string $code, string $context) {
        if (!str_starts_with(trim($resp), $code)) {
            throw new RuntimeException("SMTP $context failed: $resp");
        }
    };

    // ── 2. Greeting ───────────────────────────────────────────
    $expect($read(), '220', 'greeting');

    // ── 3. EHLO ───────────────────────────────────────────────
    $ehlo_resp = $cmd("EHLO " . ($host ?: 'localhost'));
    $expect($ehlo_resp, '250', 'EHLO');

    // ── 4. STARTTLS upgrade (port 587) ───────────────────────
    if ($encrypt === 'tls') {
        $expect($cmd('STARTTLS'), '220', 'STARTTLS');
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS crypto negotiation failed.');
        }
        // Re-send EHLO after upgrade
        $ehlo_resp = $cmd("EHLO " . ($host ?: 'localhost'));
        $expect($ehlo_resp, '250', 'EHLO after STARTTLS');
    }

    // ── 5. AUTH LOGIN ─────────────────────────────────────────
    $expect($cmd('AUTH LOGIN'), '334', 'AUTH LOGIN');
    $expect($cmd(base64_encode($user)), '334', 'AUTH username');
    $expect($cmd(base64_encode($pass)), '235', 'AUTH password');

    // ── 6. Envelope ───────────────────────────────────────────
    $expect($cmd("MAIL FROM:<$from>"), '250', 'MAIL FROM');
    $expect($cmd("RCPT TO:<$to>"),     '250', 'RCPT TO');
    $expect($cmd('DATA'),              '354', 'DATA');

    // ── 7. Build RFC 2822 message ─────────────────────────────
    $boundary = '==BCP_' . bin2hex(random_bytes(8));
    $date     = date('r');
    $msg_id   = '<' . uniqid('bcp', true) . '@' . $host . '>';
    $enc_subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $enc_name = '=?UTF-8?B?' . base64_encode($name)    . '?=';

    // Plain-text fallback (strip tags)
    $plain = wordwrap(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), 76, "\n", true);

    $headers =
        "Date: $date\r\n" .
        "From: $enc_name <$from>\r\n" .
        "To: <$to>\r\n" .
        "Subject: $enc_subj\r\n" .
        "Message-ID: $msg_id\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n" .
        "X-Mailer: BCP-SMS-Mailer/1.0\r\n";

    $body =
        "--$boundary\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: base64\r\n\r\n" .
        chunk_split(base64_encode($plain)) . "\r\n" .
        "--$boundary\r\n" .
        "Content-Type: text/html; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: base64\r\n\r\n" .
        chunk_split(base64_encode($html)) . "\r\n" .
        "--$boundary--\r\n";

    fwrite($sock, $headers . "\r\n" . $body . "\r\n.\r\n");
    $data_resp = $read();
    $expect($data_resp, '250', 'message accepted');

    // ── 8. Quit ───────────────────────────────────────────────
    $cmd('QUIT');
    fclose($sock);

    return true;
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
