<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/otp.php';

if (is_logged_in()) {
    redirect(base_url('dashboard.php'));
}

$pdo = getPDO();
ensure_otp_schema($pdo);

$pending_email = trim((string) ($_SESSION['pending_verification_email'] ?? ''));
if ($pending_email === '') {
    flash('error', 'No pending verification found. Please register or sign in.');
    redirect(base_url('register.php'));
}

// Support both the new session-only flow (user not yet in DB) and the legacy flow.
$pending_reg = $_SESSION['pending_registration'] ?? null;
$session_flow = $pending_reg && (($pending_reg['email'] ?? '') === $pending_email);

if (!$session_flow) {
    // Legacy: user already in DB — check it exists and isn't already verified.
    $user = get_unverified_user_by_email($pdo, $pending_email);
    if (!$user) {
        unset($_SESSION['pending_verification_email']);
        flash('error', 'Account not found for OTP verification.');
        redirect(base_url('index.php'));
    }
    if ((int) ($user['is_verified'] ?? 1) === 1) {
        unset($_SESSION['pending_verification_email']);
        flash('success', 'Your email is already verified. Please sign in.');
        redirect(base_url('index.php'));
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $otp = preg_replace('/\D+/', '', (string) ($_POST['otp'] ?? ''));
        if (strlen($otp) !== 6) {
            $error = 'Please enter a valid 6-digit OTP.';
        } else {
            $verify_error = null;
            if (!verify_otp_code($pdo, $pending_email, $otp, $verify_error)) {
                $error = $verify_error ?: 'Unable to verify OTP.';
            } else {
                if ($session_flow) {
                    // Insert user into DB only now that OTP is confirmed.
                    $reg = $_SESSION['pending_registration'];
                    $stmt = $pdo->prepare(
                        'INSERT INTO users (email, password_hash, full_name, first_name, last_name, level, role, department, reg_number, is_verified, verified_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())'
                    );
                    $stmt->execute([
                        $reg['email'],
                        $reg['password_hash'],
                        $reg['full_name'],
                        $reg['first_name'],
                        $reg['last_name'],
                        $reg['level'],
                        'student',
                        $reg['department'],
                        $reg['index_number'],
                    ]);
                    delete_otp_for_email($pdo, $pending_email);
                    unset($_SESSION['pending_verification_email'], $_SESSION['pending_registration']);
                    flash('success', 'Email verified! Your account is ready. You can now sign in.');
                    redirect(base_url('index.php'));
                } else {
                    if (!mark_email_verified($pdo, $pending_email)) {
                        $error = 'Unable to update verification status. Please try again.';
                    } else {
                        delete_otp_for_email($pdo, $pending_email);
                        unset($_SESSION['pending_verification_email']);
                        flash('success', 'Email verified successfully. You can now sign in.');
                        redirect(base_url('index.php'));
                    }
                }
            }
        }
    }
}

$pageTitle = 'Verify OTP';
$success = flash('success');
$flash_error = flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP | FYP Vault</title>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-5xl items-center justify-center px-6 py-10">
        <section class="w-full max-w-xl rounded-3xl border border-slate-800 bg-slate-900/80 p-8 shadow-2xl backdrop-blur">
            <div class="mb-6">
                <p class="mb-2 text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">FYP Vault Security</p>
                <h1 class="text-3xl font-bold text-white">Verify your email</h1>
                <p class="mt-2 text-sm text-slate-300">
                    Enter the 6-digit code sent to <span class="font-semibold text-cyan-200"><?= e(mask_email($pending_email)) ?></span>.
                </p>
            </div>

            <?php if ($success): ?>
                <div class="mb-4 rounded-xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100"><?= e($success) ?></div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
                <div class="mb-4 rounded-xl border border-red-400/30 bg-red-500/10 px-4 py-3 text-sm text-red-100"><?= e($flash_error) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="mb-4 rounded-xl border border-red-400/30 bg-red-500/10 px-4 py-3 text-sm text-red-100"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="space-y-4" id="verifyForm">
                <?= csrf_field() ?>
                <div>
                    <label for="otp" class="mb-2 block text-sm font-medium text-slate-200">OTP Code</label>
                    <input
                        type="text"
                        id="otp"
                        name="otp"
                        inputmode="numeric"
                        maxlength="6"
                        autocomplete="one-time-code"
                        placeholder="123456"
                        required
                        class="w-full rounded-2xl border border-slate-700 bg-slate-800 px-4 py-3 text-center text-2xl tracking-[0.42em] text-white outline-none transition focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/15"
                    >
                </div>
                <button
                    type="submit"
                    id="verifyBtn"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-indigo-500 via-violet-500 to-cyan-400 px-5 py-3 text-sm font-bold text-white transition hover:-translate-y-0.5 disabled:opacity-60 disabled:cursor-not-allowed"
                >
                    <svg id="verifySpinner" class="hidden h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span id="verifyLabel">Verify OTP</span>
                </button>
            </form>

            <form method="post" action="<?= base_url('resend_otp.php') ?>" class="mt-4" id="resendForm">
                <?= csrf_field() ?>
                <button
                    type="submit"
                    id="resendBtn"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-cyan-400/40 bg-cyan-500/10 px-5 py-3 text-sm font-semibold text-cyan-200 transition hover:bg-cyan-500/20 disabled:opacity-60 disabled:cursor-not-allowed"
                >
                    <svg id="resendSpinner" class="hidden h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span id="resendLabel">Resend OTP</span>
                </button>
            </form>

            <div class="mt-6 text-center text-sm text-slate-400">
                Already verified? <a href="<?= base_url('index.php') ?>" class="font-semibold text-cyan-200 hover:text-white">Back to Sign In</a>
            </div>
        </section>
    </main>

    <script>
        const otpInput = document.getElementById('otp');
        otpInput.addEventListener('input', () => {
            otpInput.value = otpInput.value.replace(/\D+/g, '').slice(0, 6);
        });

        document.getElementById('verifyForm').addEventListener('submit', () => {
            const btn = document.getElementById('verifyBtn');
            btn.disabled = true;
            document.getElementById('verifySpinner').classList.remove('hidden');
            document.getElementById('verifyLabel').textContent = 'Verifying…';
        });

        document.getElementById('resendForm').addEventListener('submit', () => {
            const btn = document.getElementById('resendBtn');
            btn.disabled = true;
            document.getElementById('resendSpinner').classList.remove('hidden');
            document.getElementById('resendLabel').textContent = 'Sending…';
        });
    </script>
</body>
</html>
