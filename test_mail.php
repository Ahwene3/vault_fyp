<?php
/**
 * Email functionality test — remove this file before going to production.
 */

require_once __DIR__ . '/includes/mail.php';

$result   = null;
$log_tail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? 'basic';
    $to   = trim($_POST['to'] ?? '');
    $error = null;

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result = ['ok' => false, 'msg' => 'Please enter a valid recipient email address.'];
    } else {
        switch ($type) {
            case 'otp':
                $otp  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $ok   = sendOTPEmail($to, 'Test User', $otp, $error);
                $result = ['ok' => $ok, 'msg' => $ok ? "OTP email sent (code: $otp)." : "Failed: $error"];
                break;

            case 'template':
                $ok = send_template_email(
                    $to,
                    'FYP Vault — Test Template Email',
                    'This is a test',
                    '<p>Hello! This is a <strong>template email</strong> sent from the FYP Vault test page.</p><p>If you received this, your SMTP configuration is working correctly.</p>',
                    'Visit FYP Vault',
                    'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/vault/',
                    $error
                );
                $result = ['ok' => $ok, 'msg' => $ok ? 'Template email sent successfully.' : "Failed: $error"];
                break;

            case 'debug':
                ob_start();
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                try {
                    $mail->SMTPDebug  = 3;
                    $mail->Debugoutput = 'echo';
                    $mail->isSMTP();
                    $mail->Host       = MAILERSEND_SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = MAILERSEND_SMTP_USERNAME;
                    $mail->Password   = MAILERSEND_SMTP_PASSWORD;
                    $mail->Port       = MAILERSEND_SMTP_PORT;
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->CharSet    = 'UTF-8';
                    $mail->isHTML(true);
                    $mail->setFrom(SITE_EMAIL, MAIL_FROM_NAME);
                    $mail->addAddress($to);
                    $mail->Subject = 'FYP Vault — SMTP Debug Test';
                    $mail->Body    = '<p>Debug test email.</p>';
                    $mail->send();
                    $debug_out = ob_get_clean();
                    $result = ['ok' => true, 'msg' => 'Debug email sent!', 'debug' => $debug_out];
                } catch (\Throwable $e) {
                    $debug_out = ob_get_clean();
                    $result = ['ok' => false, 'msg' => 'Failed: ' . $e->getMessage(), 'debug' => $debug_out];
                }
                break;

            default: // basic
                $ok = send_email(
                    $to,
                    'FYP Vault — SMTP Test',
                    '<p>This is a <strong>basic SMTP test</strong> from FYP Vault.</p><p>Your email configuration is working!</p>',
                    SITE_EMAIL,
                    $error
                );
                $result = ['ok' => $ok, 'msg' => $ok ? 'Basic email sent successfully.' : "Failed: $error"];
        }
    }

    // Read last 30 lines of email log
    $log_file = __DIR__ . '/logs/emails.log';
    if (file_exists($log_file)) {
        $lines    = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $log_tail = implode("\n", array_slice($lines, -40));
    }
}

$cfg = [
    'Host'       => defined('MAILERSEND_SMTP_HOST')       ? MAILERSEND_SMTP_HOST       : '(not set)',
    'Port'       => defined('MAILERSEND_SMTP_PORT')       ? MAILERSEND_SMTP_PORT       : '(not set)',
    'Encryption' => defined('MAILERSEND_SMTP_ENCRYPTION') ? MAILERSEND_SMTP_ENCRYPTION : '(not set)',
    'Username'   => defined('MAILERSEND_SMTP_USERNAME')   ? MAILERSEND_SMTP_USERNAME   : '(not set)',
    'Password'   => defined('MAILERSEND_SMTP_PASSWORD') && MAILERSEND_SMTP_PASSWORD !== ''
                        ? str_repeat('*', 8) . substr(MAILERSEND_SMTP_PASSWORD, -4)
                        : '(not set)',
    'From Email' => defined('SITE_EMAIL') ? SITE_EMAIL : '(not set)',
    'From Name'  => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '(not set)',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Test — FYP Vault</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; color: #1e293b; min-height: 100vh; padding: 32px 16px; }
  .wrap { max-width: 720px; margin: 0 auto; }
  h1 { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
  .subtitle { color: #64748b; font-size: 14px; margin-bottom: 28px; }
  .card { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 24px; margin-bottom: 20px; }
  .card h2 { font-size: 15px; font-weight: 600; color: #0f172a; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; }
  td:first-child { color: #64748b; font-weight: 500; width: 140px; }
  td:last-child { font-family: monospace; color: #0f172a; word-break: break-all; }
  label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px; }
  input[type=email], select { width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; transition: border-color .15s; }
  input[type=email]:focus, select:focus { border-color: #6366f1; }
  .row { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
  .row > * { flex: 1; min-width: 200px; }
  button { padding: 10px 24px; background: #6366f1; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
  button:hover { background: #4f46e5; }
  .alert { padding: 12px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; margin-bottom: 20px; }
  .alert.ok  { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
  .alert.err { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
  pre { background: #0f172a; color: #94a3b8; padding: 16px; border-radius: 8px; font-size: 12px; line-height: 1.7; overflow-x: auto; white-space: pre-wrap; max-height: 320px; overflow-y: auto; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; background: #dcfce7; color: #166534; margin-left: 8px; vertical-align: middle; }
  .warn-banner { background: #fef3c7; border: 1px solid #fde68a; color: #92400e; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Email Test <span class="badge">DEV ONLY</span></h1>
  <p class="subtitle">Use this page to verify your SMTP configuration and test email delivery.</p>

  <div class="warn-banner">Delete or restrict access to this file (<code>test_mail.php</code>) before deploying to production.</div>

  <?php if ($result): ?>
  <div class="alert <?= $result['ok'] ? 'ok' : 'err' ?>">
    <?= htmlspecialchars($result['msg']) ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>SMTP Configuration</h2>
    <table>
      <?php foreach ($cfg as $k => $v): ?>
      <tr><td><?= htmlspecialchars($k) ?></td><td><?= htmlspecialchars((string)$v) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h2>Send Test Email</h2>
    <form method="POST">
      <div class="row">
        <div>
          <label for="to">Recipient Email</label>
          <input type="email" id="to" name="to" placeholder="you@example.com"
                 value="<?= htmlspecialchars($_POST['to'] ?? '') ?>" required>
        </div>
        <div>
          <label for="type">Email Type</label>
          <select id="type" name="type">
            <option value="basic"    <?= ($_POST['type'] ?? '') === 'basic'    ? 'selected' : '' ?>>Basic (plain HTML)</option>
            <option value="otp"      <?= ($_POST['type'] ?? '') === 'otp'      ? 'selected' : '' ?>>OTP Verification</option>
            <option value="template" <?= ($_POST['type'] ?? '') === 'template' ? 'selected' : '' ?>>Branded Template</option>
            <option value="debug"    <?= ($_POST['type'] ?? '') === 'debug'    ? 'selected' : '' ?>>SMTP Debug (verbose)</option>
          </select>
        </div>
      </div>
      <button type="submit">Send Test Email</button>
    </form>
  </div>

  <?php if (!empty($result['debug'])): ?>
  <div class="card">
    <h2>SMTP Debug Output</h2>
    <pre><?= htmlspecialchars($result['debug']) ?></pre>
  </div>
  <?php endif; ?>

  <?php if ($log_tail !== ''): ?>
  <div class="card">
    <h2>Email Log (last entries)</h2>
    <pre><?= htmlspecialchars($log_tail) ?></pre>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
