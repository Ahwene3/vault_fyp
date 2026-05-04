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
    redirect(base_url('index.php'));
}

$user = get_unverified_user_by_email($pdo, $pending_email);
if (!$user) {
    unset($_SESSION['pending_verification_email']);
    flash('error', 'Account not found for OTP verification.');
    redirect(base_url('index.php'));
}

if (!should_require_otp_for_role((string) ($user['role'] ?? ''))) {
    if ((int) ($user['is_verified'] ?? 1) !== 1) {
        mark_email_verified($pdo, $pending_email);
    }
    delete_otp_for_email($pdo, $pending_email);
    unset($_SESSION['pending_verification_email']);
    flash('success', 'OTP verification is not required for this account. Please sign in.');
    redirect(base_url('index.php'));
}

if ((int) ($user['is_verified'] ?? 1) === 1) {
    unset($_SESSION['pending_verification_email']);
    flash('success', 'Your email is already verified. Please sign in.');
    redirect(base_url('index.php'));
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

            <form method="post" class="space-y-4">
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
                    class="inline-flex w-full items-center justify-center rounded-2xl bg-gradient-to-r from-indigo-500 via-violet-500 to-cyan-400 px-5 py-3 text-sm font-bold text-white transition hover:-translate-y-0.5"
                >
                    Verify OTP
                </button>
            </form>

            <form method="post" action="<?= base_url('resend_otp.php') ?>" class="mt-4">
                <?= csrf_field() ?>
                <button
                    type="submit"
                    class="w-full rounded-2xl border border-cyan-400/40 bg-cyan-500/10 px-5 py-3 text-sm font-semibold text-cyan-200 transition hover:bg-cyan-500/20"
                >
                    Resend OTP
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
    </script>
</body>
</html>
