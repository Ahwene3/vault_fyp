<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(base_url('dashboard.php'));
}

$pdo = getPDO();

// Clean up expired tokens
$pdo->exec('DELETE FROM password_resets WHERE expires_at < NOW()');

$raw_token  = trim($_GET['token'] ?? '');
$token_hash = $raw_token !== '' ? hash('sha256', $raw_token) : '';

// Validate token
$reset_record = null;
if ($token_hash !== '') {
    $stmt = $pdo->prepare('SELECT email, expires_at FROM password_resets WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$token_hash]);
    $reset_record = $stmt->fetch() ?: null;
}

if (!$reset_record) {
    flash('error', 'This password reset link is invalid or has expired. Please request a new one.');
    redirect(base_url('forgot_password.php'));
}

$email = $reset_record['email'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = 'Password must contain uppercase, lowercase, and a number.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?')->execute([$hash, $email]);
            $pdo->prepare('DELETE FROM password_resets WHERE token_hash = ?')->execute([$token_hash]);

            flash('success', 'Your password has been reset. You can now sign in with your new password.');
            redirect(base_url('index.php'));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | FYP Vault</title>
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
                    <i class="bi bi-key-fill text-2xl text-white"></i>
                </span>
                <p class="mb-1 text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">FYP Vault</p>
                <h1 class="text-2xl font-bold text-white">Set a new password</h1>
                <p class="mt-2 text-center text-sm text-slate-400">
                    Resetting for <span class="font-semibold text-cyan-200"><?= e(mask_email($email)) ?></span>
                </p>
            </div>

            <?php if ($error): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl border border-red-400/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    <i class="bi bi-exclamation-circle-fill mt-0.5 text-red-400"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" id="resetForm" class="space-y-5">
                <?= csrf_field() ?>

                <!-- New password -->
                <div>
                    <label for="password" class="mb-2 block text-sm font-medium text-slate-200">New Password</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-slate-500">
                            <i class="bi bi-lock text-lg"></i>
                        </span>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            placeholder="••••••••"
                            class="w-full rounded-2xl border border-slate-700 bg-slate-800 py-3 pl-11 pr-12 text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/15"
                        >
                        <button type="button" id="togglePw" class="absolute inset-y-0 right-0 flex items-center px-4 text-slate-500 transition hover:text-cyan-300">
                            <i class="bi bi-eye-fill text-lg"></i>
                        </button>
                    </div>

                    <!-- Strength meter -->
                    <div class="mt-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <span class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-400">Strength</span>
                            <span id="strengthLabel" class="text-sm font-semibold text-slate-400">—</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-white/10">
                            <div id="strengthBar" class="h-full w-0 rounded-full transition-all duration-300"></div>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-1.5 text-xs text-slate-500">
                            <div id="r1" class="flex items-center gap-1.5"><i class="bi bi-dot text-lg leading-none"></i>8+ characters</div>
                            <div id="r2" class="flex items-center gap-1.5"><i class="bi bi-dot text-lg leading-none"></i>Uppercase letter</div>
                            <div id="r3" class="flex items-center gap-1.5"><i class="bi bi-dot text-lg leading-none"></i>Lowercase letter</div>
                            <div id="r4" class="flex items-center gap-1.5"><i class="bi bi-dot text-lg leading-none"></i>Number</div>
                        </div>
                    </div>
                </div>

                <!-- Confirm password -->
                <div>
                    <label for="password_confirm" class="mb-2 block text-sm font-medium text-slate-200">Confirm Password</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-slate-500">
                            <i class="bi bi-lock-fill text-lg"></i>
                        </span>
                        <input
                            type="password"
                            id="password_confirm"
                            name="password_confirm"
                            required
                            autocomplete="new-password"
                            placeholder="••••••••"
                            class="w-full rounded-2xl border border-slate-700 bg-slate-800 py-3 pl-11 pr-4 text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/15"
                        >
                    </div>
                    <p id="matchHint" class="mt-2 text-xs text-slate-500"></p>
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
                    <span id="btnLabel">Reset Password</span>
                    <i id="btnArrow" class="bi bi-check2-circle text-base"></i>
                </button>
            </form>

            <div class="mt-6 text-center text-sm text-slate-500">
                <a href="<?= base_url('index.php') ?>" class="font-semibold text-cyan-300 transition hover:text-white">Back to Sign In</a>
            </div>
        </section>
    </main>

    <script>
        const pwInput   = document.getElementById('password');
        const cfInput   = document.getElementById('password_confirm');
        const toggleBtn = document.getElementById('togglePw');
        const bar       = document.getElementById('strengthBar');
        const label     = document.getElementById('strengthLabel');
        const matchHint = document.getElementById('matchHint');

        toggleBtn.addEventListener('click', () => {
            const show = pwInput.type === 'password';
            pwInput.type = show ? 'text' : 'password';
            toggleBtn.innerHTML = show
                ? '<i class="bi bi-eye-slash-fill text-lg"></i>'
                : '<i class="bi bi-eye-fill text-lg"></i>';
        });

        function scorePassword(v) {
            const rules = [v.length >= 8, /[A-Z]/.test(v), /[a-z]/.test(v), /[0-9]/.test(v)];
            const score = rules.filter(Boolean).length;
            const configs = [
                { w: '15%', color: 'bg-red-500',    text: 'Weak',   cls: 'text-red-400' },
                { w: '40%', color: 'bg-amber-400',  text: 'Fair',   cls: 'text-amber-400' },
                { w: '72%', color: 'bg-cyan-400',   text: 'Good',   cls: 'text-cyan-400' },
                { w: '100%',color: 'bg-emerald-400',text: 'Strong', cls: 'text-emerald-400' },
            ];
            const c = configs[Math.max(0, score - 1)];
            bar.className = `h-full rounded-full transition-all duration-300 ${score ? c.color : ''}`;
            bar.style.width = score ? c.w : '0';
            label.textContent = score ? c.text : '—';
            label.className   = `text-sm font-semibold ${score ? c.cls : 'text-slate-400'}`;

            ['r1','r2','r3','r4'].forEach((id, i) => {
                document.getElementById(id).classList.toggle('text-emerald-300', rules[i]);
                document.getElementById(id).classList.toggle('text-slate-500', !rules[i]);
            });
            return score;
        }

        pwInput.addEventListener('input', () => {
            scorePassword(pwInput.value);
            updateMatch();
        });

        cfInput.addEventListener('input', updateMatch);

        function updateMatch() {
            if (!cfInput.value) { matchHint.textContent = ''; return; }
            const ok = cfInput.value === pwInput.value;
            matchHint.textContent = ok ? 'Passwords match.' : 'Passwords do not match.';
            matchHint.className = `mt-2 text-xs ${ok ? 'text-emerald-300' : 'text-red-300'}`;
        }

        document.getElementById('resetForm').addEventListener('submit', (e) => {
            const score = scorePassword(pwInput.value);
            updateMatch();

            if (pwInput.value !== cfInput.value) {
                e.preventDefault();
                cfInput.focus();
                return;
            }
            if (score < 4) {
                e.preventDefault();
                pwInput.focus();
                return;
            }

            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            document.getElementById('spinner').classList.remove('hidden');
            document.getElementById('btnLabel').textContent = 'Resetting…';
            document.getElementById('btnArrow').classList.add('hidden');
        });
    </script>
</body>
</html>
