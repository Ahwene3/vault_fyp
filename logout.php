<?php
require_once __DIR__ . '/includes/auth.php';

$loginUrl = base_url('index.php');
$dashboardUrl = base_url('dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash('error', 'Invalid logout request. Please try again.');
        redirect(is_logged_in() ? $dashboardUrl : $loginUrl);
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'cancel') {
        redirect(is_logged_in() ? $dashboardUrl : $loginUrl);
    }

    if ($action === 'logout') {
        if (is_logged_in()) {
            logout_user();
            flash('success', 'You have been logged out securely.');
        }
        redirect($loginUrl);
    }

    redirect(is_logged_in() ? $dashboardUrl : $loginUrl);
}

if (!is_logged_in()) {
    redirect($loginUrl);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out | FYP Vault</title>
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            color-scheme: dark;
        }

        body {
            font-family: Inter, system-ui, sans-serif;
            background:
                radial-gradient(circle at 14% 16%, rgba(34, 211, 238, 0.16), transparent 28%),
                radial-gradient(circle at 84% 10%, rgba(99, 102, 241, 0.22), transparent 24%),
                radial-gradient(circle at 48% 84%, rgba(168, 85, 247, 0.16), transparent 28%),
                #050816;
        }

        .grid-overlay {
            background-image:
                linear-gradient(rgba(148, 163, 184, 0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, 0.08) 1px, transparent 1px);
            background-size: 56px 56px;
            mask-image: radial-gradient(circle at center, black 36%, transparent 88%);
        }

        .vault-stage {
            position: relative;
            width: min(86vw, 430px);
            aspect-ratio: 1 / 1;
            margin: 0 auto;
            display: grid;
            place-items: center;
        }

        .vault-core {
            position: relative;
            width: 76%;
            aspect-ratio: 1 / 1;
            border-radius: 50%;
            background:
                radial-gradient(circle at 50% 45%, rgba(255, 255, 255, 0.16), transparent 26%),
                radial-gradient(circle at 50% 50%, #334155 0%, #1e293b 45%, #0f172a 72%, #020617 100%);
            box-shadow:
                0 0 0 1px rgba(148, 163, 184, 0.2),
                0 0 48px rgba(34, 211, 238, 0.14),
                inset 0 0 22px rgba(255, 255, 255, 0.12);
        }

        .vault-door {
            position: absolute;
            inset: 12%;
            border-radius: 50%;
            background:
                radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.14), transparent 26%),
                linear-gradient(140deg, #475569 0%, #334155 38%, #0f172a 100%);
            border: 1px solid rgba(148, 163, 184, 0.3);
            box-shadow:
                inset 0 0 0 14px rgba(15, 23, 42, 0.52),
                inset 0 0 20px rgba(255, 255, 255, 0.07);
            transform-origin: 34% 50%;
            transform: rotate(-34deg);
        }

        .animate-start .vault-door {
            animation: door-close 3.5s cubic-bezier(0.21, 0.79, 0.35, 1) forwards;
        }

        .vault-ring {
            position: absolute;
            inset: 5%;
            border-radius: 50%;
            border: 1px solid rgba(125, 211, 252, 0.25);
        }

        .vault-wheel {
            position: absolute;
            inset: 39%;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.9), rgba(34, 211, 238, 0.48) 38%, rgba(15, 23, 42, 0.9) 72%);
            box-shadow: 0 0 22px rgba(34, 211, 238, 0.28);
        }

        .vault-wheel::before,
        .vault-wheel::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            inset: 23%;
            border: 1px solid rgba(255, 255, 255, 0.45);
        }

        .vault-wheel::after {
            inset: 9%;
            border-color: rgba(34, 211, 238, 0.42);
        }

        .vault-slot {
            position: absolute;
            inset: 49% 24%;
            height: 2.2%;
            border-radius: 999px;
            background: linear-gradient(90deg, transparent, rgba(34, 211, 238, 0.95), transparent);
            box-shadow: 0 0 14px rgba(34, 211, 238, 0.65);
        }

        .logout-doc {
            position: absolute;
            width: clamp(48px, 7.5vw, 72px);
            aspect-ratio: 3 / 4;
            border-radius: 16px;
            background: linear-gradient(140deg, rgba(34, 211, 238, 0.95), rgba(99, 102, 241, 0.76));
            box-shadow:
                0 0 16px rgba(34, 211, 238, 0.35),
                inset 0 0 0 1px rgba(255, 255, 255, 0.22);
            opacity: 0;
        }

        .logout-doc::before {
            content: '';
            position: absolute;
            inset: 18%;
            border-radius: 10px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(255, 255, 255, 0.1));
            opacity: 0.72;
        }

        .logout-doc--one { top: 10%; left: 16%; }
        .logout-doc--two { top: 16%; right: 12%; }
        .logout-doc--three { bottom: 17%; left: 11%; }

        .animate-start .logout-doc--one { animation: doc-fly 2.4s ease-in-out .2s forwards; }
        .animate-start .logout-doc--two { animation: doc-fly 2.4s ease-in-out .55s forwards; }
        .animate-start .logout-doc--three { animation: doc-fly 2.4s ease-in-out .95s forwards; }

        .vault-lock {
            position: absolute;
            bottom: 7%;
            width: 74px;
            height: 86px;
            opacity: 0;
            transform: scale(0.75) translateY(12px);
        }

        .vault-lock__shackle {
            position: absolute;
            left: 50%;
            top: 0;
            width: 44px;
            height: 34px;
            transform: translateX(-50%);
            border: 5px solid rgba(226, 232, 240, 0.86);
            border-bottom: none;
            border-radius: 32px 32px 0 0;
            box-shadow: 0 0 12px rgba(148, 163, 184, 0.5);
        }

        .vault-lock__body {
            position: absolute;
            left: 50%;
            bottom: 0;
            width: 74px;
            height: 56px;
            transform: translateX(-50%);
            border-radius: 14px;
            background: linear-gradient(140deg, #cbd5e1 0%, #94a3b8 45%, #334155 100%);
            border: 1px solid rgba(255, 255, 255, 0.36);
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.24);
        }

        .vault-lock__body::before {
            content: '';
            position: absolute;
            width: 12px;
            height: 12px;
            border-radius: 999px;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            background: #0f172a;
            box-shadow: 0 0 8px rgba(15, 23, 42, 0.55);
        }

        .animate-start .vault-lock {
            animation:
                lock-appear .7s ease-out 3.2s forwards,
                lock-click .32s ease-out 3.95s both;
        }

        .vault-glow {
            position: absolute;
            inset: auto 12% 8%;
            height: 16%;
            background: radial-gradient(circle, rgba(34, 211, 238, 0.3), transparent 72%);
            filter: blur(18px);
        }

        @keyframes door-close {
            0% { transform: rotate(-34deg); }
            72% { transform: rotate(2deg); }
            100% { transform: rotate(0deg); }
        }

        @keyframes doc-fly {
            0% { opacity: 0; transform: translate3d(0, 0, 0) scale(0.92); }
            15% { opacity: 1; }
            74% { opacity: 1; transform: translate3d(44px, 46px, 0) scale(0.8); }
            100% { opacity: 0; transform: translate3d(76px, 66px, 0) scale(0.32); }
        }

        @keyframes lock-appear {
            from { opacity: 0; transform: scale(0.75) translateY(12px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        @keyframes lock-click {
            0% { filter: drop-shadow(0 0 0 rgba(34, 211, 238, 0)); }
            50% { filter: drop-shadow(0 0 16px rgba(34, 211, 238, 0.65)); }
            100% { filter: drop-shadow(0 0 8px rgba(34, 211, 238, 0.28)); }
        }
    </style>
</head>
<body class="min-h-screen overflow-x-hidden text-slate-100 antialiased animate-start">
    <div class="pointer-events-none fixed inset-0 grid-overlay opacity-40"></div>
    <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(circle_at_center,rgba(8,15,35,0)_0%,rgba(5,8,22,0.35)_72%,rgba(5,8,22,0.88)_100%)]"></div>

    <main class="relative z-10 mx-auto flex min-h-screen w-full max-w-5xl items-center justify-center px-6 py-10">
        <section class="w-full rounded-[2rem] border border-white/10 bg-white/5 p-6 shadow-[0_30px_120px_rgba(15,23,42,0.48)] backdrop-blur-2xl sm:p-8 lg:p-10">
            <div class="mb-6 flex items-center justify-center gap-3 text-center">
                <span class="grid h-12 w-12 place-items-center rounded-2xl border border-cyan-400/25 bg-gradient-to-br from-indigo-500 via-violet-500 to-cyan-400 shadow-[0_0_30px_rgba(34,211,238,0.18)]">
                    <i class="bi bi-safe2-fill text-lg text-white"></i>
                </span>
                <span class="text-left">
                    <span class="block text-lg font-extrabold tracking-[0.26em] text-white">FYP VAULT</span>
                    <span class="block text-xs uppercase tracking-[0.32em] text-slate-400">Collaboration Hub</span>
                </span>
            </div>

            <div class="mx-auto max-w-3xl text-center">
                <h1 class="text-3xl font-black tracking-tight text-white sm:text-4xl">Logging you out securely...</h1>
                <p class="mt-3 text-base text-slate-300 sm:text-lg">Your project vault is now safely locked.</p>
                <p class="mt-1 text-sm text-slate-400">Thank you for using FYP Vault.</p>
            </div>

            <div class="mt-8 vault-stage">
                <div class="vault-glow"></div>
                <div class="logout-doc logout-doc--one"></div>
                <div class="logout-doc logout-doc--two"></div>
                <div class="logout-doc logout-doc--three"></div>

                <div class="vault-core">
                    <div class="vault-ring"></div>
                    <div class="vault-door">
                        <div class="vault-slot"></div>
                        <div class="vault-wheel"></div>
                    </div>
                </div>

                <div class="vault-lock" aria-hidden="true">
                    <div class="vault-lock__shackle"></div>
                    <div class="vault-lock__body"></div>
                </div>
            </div>

            <div class="mx-auto mt-8 w-full max-w-2xl">
                <div class="mb-2 flex items-center justify-between text-sm text-slate-300">
                    <span id="statusText">Logging out in <span id="countdown">5</span> seconds...</span>
                    <span id="percentText">0%</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-800/90">
                    <div id="progressBar" class="h-full w-0 rounded-full bg-gradient-to-r from-indigo-500 via-violet-500 to-cyan-400 transition-[width] duration-100"></div>
                </div>
            </div>

            <div class="mx-auto mt-8 flex w-full max-w-2xl flex-col gap-3 sm:flex-row">
                <form method="post" class="w-full sm:w-1/2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <button
                        type="submit"
                        class="w-full rounded-2xl border border-slate-600/70 bg-slate-800/70 px-5 py-3 text-sm font-semibold text-slate-200 transition hover:border-cyan-300/45 hover:bg-slate-700/70 hover:text-white"
                    >
                        Cancel Logout
                    </button>
                </form>

                <button
                    type="button"
                    id="logoutNowBtn"
                    class="w-full rounded-2xl bg-gradient-to-r from-indigo-500 via-violet-500 to-cyan-400 px-5 py-3 text-sm font-bold text-white transition hover:-translate-y-0.5 sm:w-1/2"
                >
                    Confirm Logout Now
                </button>
            </div>

            <form method="post" id="autoLogoutForm" class="hidden">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="logout">
            </form>
        </section>
    </main>

    <script>
        const totalMs = 5000;
        const start = performance.now();
        const progressBar = document.getElementById('progressBar');
        const percentText = document.getElementById('percentText');
        const countdownEl = document.getElementById('countdown');
        const statusText = document.getElementById('statusText');
        const autoLogoutForm = document.getElementById('autoLogoutForm');
        const logoutNowBtn = document.getElementById('logoutNowBtn');
        let finished = false;

        function submitLogout() {
            if (finished) return;
            finished = true;
            statusText.textContent = 'Redirecting to login...';
            autoLogoutForm.submit();
        }

        logoutNowBtn.addEventListener('click', submitLogout);

        function animate(now) {
            if (finished) return;
            const elapsed = Math.min(totalMs, now - start);
            const percent = Math.round((elapsed / totalMs) * 100);
            progressBar.style.width = `${percent}%`;
            percentText.textContent = `${percent}%`;

            const secs = Math.max(0, Math.ceil((totalMs - elapsed) / 1000));
            countdownEl.textContent = String(secs);

            if (elapsed >= totalMs) {
                submitLogout();
                return;
            }
            requestAnimationFrame(animate);
        }

        requestAnimationFrame(animate);
    </script>
</body>
</html>
