<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(base_url('dashboard.php'));
}

require_once __DIR__ . '/includes/mail.php';

$pdo = getPDO();

// Ensure reset tokens table exists
$pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim(strtolower($_POST['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Always show success to avoid email enumeration
            $success = 'If that email is registered, you will receive a password reset link shortly.';

            $stmt = $pdo->prepare('SELECT id, full_name, first_name FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Delete any existing tokens for this email
                $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);

                $token      = bin2hex(random_bytes(32));
                $token_hash = hash('sha256', $token);
                $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour

                $pdo->prepare('INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)')
                    ->execute([$email, $token_hash, $expires_at]);

                $reset_url  = get_app_url('reset_password.php') . '?token=' . urlencode($token);
                $name       = trim((string) ($user['full_name'] ?: $user['first_name'] ?: 'User'));

                send_password_reset_email($email, $name, $reset_url);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | FYP Vault</title>
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } }
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-5xl items-center justify-center px-6 py-10">
        <section class="w-full max-w-md rounded-3xl border border-slate-800 bg-slate-900/80 p-8 shadow-2xl backdrop-blur">

            <!-- Icon -->
            <div class="mb-6 flex flex-col items-center">
                <span class="mb-4 grid h-16 w-16 place-items-center rounded-2xl border border-cyan-400/25 bg-gradient-to-br from-indigo-500 via-violet-500 to-cyan-400 shadow-[0_0_30px_rgba(34,211,238,0.22)]">
                    <i class="bi bi-shield-lock-fill text-2xl text-white"></i>
                </span>
                <p class="mb-1 text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">FYP Vault</p>
                <h1 class="text-2xl font-bold text-white">Forgot your password?</h1>
                <p class="mt-2 text-center text-sm text-slate-400">
                    Enter your registered email and we'll send you a link to reset your password.
                </p>
            </div>

            <?php if ($success): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                    <i class="bi bi-check-circle-fill mt-0.5 text-emerald-400"></i>
                    <span><?= e($success) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl border border-red-400/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    <i class="bi bi-exclamation-circle-fill mt-0.5 text-red-400"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="post" id="forgotForm" class="space-y-5">
                <?= csrf_field() ?>

                <div>
                    <label for="email" class="mb-2 block text-sm font-medium text-slate-200">Email address</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-slate-500">
                            <i class="bi bi-envelope text-lg"></i>
                        </span>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            required
                            autocomplete="email"
                            value="<?= e($_POST['email'] ?? '') ?>"
                            placeholder="your.email@example.com"
                            class="w-full rounded-2xl border border-slate-700 bg-slate-800 py-3 pl-11 pr-4 text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/15"
                        >
                    </div>
                </div>

                <button
                    type="submit"
                    id="submitBtn"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-indigo-500 via-violet-500 to-cyan-400 px-5 py-3 text-sm font-bold text-white shadow-lg transition hover:-translate-y-0.5 disabled:cursor-not-allowed disabled:opacity-60 disabled:translate-y-0"
                >
                    <svg id="spinner" class="hidden h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span id="btnLabel">Send Reset Link</span>
                    <i id="btnArrow" class="bi bi-send text-base"></i>
                </button>
            </form>
            <?php endif; ?>

            <div class="mt-6 text-center text-sm text-slate-500">
                Remembered your password?
                <a href="<?= base_url('index.php') ?>" class="font-semibold text-cyan-300 transition hover:text-white">Back to Sign In</a>
            </div>
        </section>
    </main>

    <script>
        document.getElementById('forgotForm')?.addEventListener('submit', () => {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            document.getElementById('spinner').classList.remove('hidden');
            document.getElementById('btnLabel').textContent = 'Sending…';
            document.getElementById('btnArrow').classList.add('hidden');
        });
    </script>
</body>
</html>
