<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/otp.php';

if (is_logged_in()) {
    redirect(base_url('dashboard.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Please enter your email and password.';
        } else {
            $pdo = getPDO();
            ensure_user_archive_columns($pdo);
            ensure_email_verification_columns($pdo);
            $stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, role, department, is_active, archived_permanent, is_verified FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if ((int) ($user['is_active'] ?? 1) !== 1) {
                    $error = ((int) ($user['archived_permanent'] ?? 0) === 1)
                        ? 'Your account has been permanently archived and cannot be restored.'
                        : 'Your account is archived. Contact admin for restoration.';
                } elseif (should_require_otp_for_role((string) ($user['role'] ?? '')) && (int) ($user['is_verified'] ?? 1) !== 1) {
                    $_SESSION['pending_verification_email'] = $user['email'];
                    flash('error', 'Please verify your email before signing in.');
                    redirect(base_url('verify_otp.php'));
                } else {
                    login_user($user);
                    redirect(base_url('dashboard.php'));
                }
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}

$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | FYP Vault</title>
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
                radial-gradient(circle at 15% 15%, rgba(34, 211, 238, 0.16), transparent 28%),
                radial-gradient(circle at 82% 12%, rgba(99, 102, 241, 0.24), transparent 26%),
                radial-gradient(circle at 50% 80%, rgba(168, 85, 247, 0.14), transparent 32%),
                #050816;
        }

        .grid-overlay {
            background-image:
                linear-gradient(rgba(148, 163, 184, 0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, 0.08) 1px, transparent 1px);
            background-size: 56px 56px;
            mask-image: radial-gradient(circle at center, black 36%, transparent 88%);
        }

        .vault-scene {
            position: relative;
            min-height: 100%;
            overflow: hidden;
        }

        .vault-stage {
            position: relative;
            width: min(84vw, 560px);
            aspect-ratio: 1 / 1;
            margin: 0 auto;
            display: grid;
            place-items: center;
        }

        .vault-core {
            position: relative;
            width: 72%;
            aspect-ratio: 1 / 1;
            border-radius: 50%;
            background:
                radial-gradient(circle at 50% 42%, rgba(255, 255, 255, 0.14), transparent 24%),
                radial-gradient(circle at 50% 50%, #1e293b 0%, #0f172a 58%, #020617 100%);
            box-shadow:
                0 0 0 1px rgba(148, 163, 184, 0.18),
                0 0 50px rgba(34, 211, 238, 0.12),
                inset 0 0 35px rgba(255, 255, 255, 0.08);
        }

        .vault-ring {
            position: absolute;
            inset: 5.5%;
            border-radius: 50%;
            border: 1px solid rgba(125, 211, 252, 0.22);
            box-shadow: inset 0 0 0 8px rgba(15, 23, 42, 0.22);
        }

        .vault-door {
            position: absolute;
            inset: 12%;
            border-radius: 50%;
            background:
                radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.15), transparent 24%),
                linear-gradient(145deg, #334155 0%, #1e293b 38%, #0f172a 100%);
            border: 1px solid rgba(148, 163, 184, 0.24);
            box-shadow:
                inset 0 0 0 16px rgba(15, 23, 42, 0.5),
                inset 0 0 20px rgba(255, 255, 255, 0.05);
            transform-origin: 34% 50%;
            animation: vault-door 8s ease-in-out infinite;
        }

        .vault-door::before {
            content: '';
            position: absolute;
            inset: 11%;
            border-radius: 50%;
            border: 1px solid rgba(34, 211, 238, 0.18);
        }

        .vault-door::after {
            content: '';
            position: absolute;
            inset: 24%;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(34, 211, 238, 0.18), transparent 68%);
            filter: blur(2px);
        }

        .vault-hub {
            position: absolute;
            inset: 40%;
            border-radius: 50%;
            background:
                radial-gradient(circle, rgba(255, 255, 255, 0.9), rgba(34, 211, 238, 0.55) 38%, rgba(15, 23, 42, 0.95) 72%);
            box-shadow: 0 0 25px rgba(34, 211, 238, 0.26);
        }

        .vault-hub::before,
        .vault-hub::after {
            content: '';
            position: absolute;
            inset: 28%;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.35);
        }

        .vault-hub::after {
            inset: 15%;
            border-color: rgba(34, 211, 238, 0.35);
        }

        .vault-slot {
            position: absolute;
            inset: 49% 26%;
            height: 2.2%;
            border-radius: 999px;
            background: linear-gradient(90deg, transparent, rgba(34, 211, 238, 0.95), transparent);
            box-shadow: 0 0 14px rgba(34, 211, 238, 0.65);
        }

        .vault-handles span {
            position: absolute;
            inset: 46%;
            border-radius: 50%;
            border: 1px solid rgba(148, 163, 184, 0.45);
        }

        .vault-handles span:nth-child(1) { transform: rotate(0deg) translateY(-62px); }
        .vault-handles span:nth-child(2) { transform: rotate(60deg) translateY(-62px); }
        .vault-handles span:nth-child(3) { transform: rotate(120deg) translateY(-62px); }
        .vault-handles span:nth-child(4) { transform: rotate(180deg) translateY(-62px); }
        .vault-handles span:nth-child(5) { transform: rotate(240deg) translateY(-62px); }
        .vault-handles span:nth-child(6) { transform: rotate(300deg) translateY(-62px); }

        .vault-doc {
            position: absolute;
            width: clamp(56px, 8vw, 84px);
            aspect-ratio: 3 / 4;
            border-radius: 18px;
            background:
                linear-gradient(145deg, rgba(34, 211, 238, 0.95), rgba(96, 165, 250, 0.75));
            box-shadow:
                0 0 18px rgba(34, 211, 238, 0.36),
                inset 0 0 0 1px rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(6px);
            animation: doc-flight 6.5s ease-in-out infinite;
        }

        .vault-doc::before {
            content: '';
            position: absolute;
            inset: 16%;
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.08));
            opacity: 0.75;
        }

        .vault-doc::after {
            content: '';
            position: absolute;
            left: 16%;
            right: 16%;
            bottom: 16%;
            height: 10%;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.55);
            box-shadow: 0 -12px 0 rgba(255, 255, 255, 0.35);
        }

        .vault-doc--one { top: 10%; left: 18%; animation-delay: 0s; }
        .vault-doc--two { top: 18%; right: 12%; animation-delay: 1.1s; }
        .vault-doc--three { bottom: 18%; left: 12%; animation-delay: 2.2s; }

        .vault-particle {
            position: absolute;
            border-radius: 999px;
            background: rgba(125, 211, 252, 0.7);
            box-shadow: 0 0 12px rgba(34, 211, 238, 0.9);
            animation: particle-float 9s linear infinite;
        }

        .vault-particle--one { width: 8px; height: 8px; top: 18%; left: 12%; animation-duration: 10s; }
        .vault-particle--two { width: 6px; height: 6px; top: 64%; left: 18%; animation-duration: 12s; animation-delay: 1s; }
        .vault-particle--three { width: 7px; height: 7px; top: 22%; right: 16%; animation-duration: 11s; animation-delay: 2s; }
        .vault-particle--four { width: 5px; height: 5px; bottom: 26%; right: 18%; animation-duration: 9s; animation-delay: 1.4s; }

        .vault-base-glow {
            position: absolute;
            inset: auto 12% 10%;
            height: 18%;
            background: radial-gradient(circle, rgba(34, 211, 238, 0.24), transparent 70%);
            filter: blur(18px);
        }

        @keyframes vault-door {
            0%, 100% { transform: rotate(0deg); }
            40% { transform: rotate(-14deg); }
            70% { transform: rotate(-5deg); }
        }

        @keyframes doc-flight {
            0%, 100% { transform: translate3d(0, 0, 0) scale(0.95); opacity: 0.65; }
            30% { transform: translate3d(14px, 16px, 0) scale(1); opacity: 1; }
            60% { transform: translate3d(40px, 42px, 0) scale(0.9); opacity: 0.9; }
        }

        @keyframes particle-float {
            0% { transform: translate3d(0, 0, 0); opacity: 0.35; }
            50% { transform: translate3d(12px, -20px, 0); opacity: 1; }
            100% { transform: translate3d(0, 0, 0); opacity: 0.35; }
        }

        @media (max-width: 1024px) {
            .vault-stage {
                width: min(86vw, 480px);
            }
        }

        @media (max-width: 640px) {
            .vault-stage {
                width: min(92vw, 380px);
            }
        }
    </style>
</head>
<body class="min-h-screen overflow-x-hidden text-slate-100 antialiased">
    <div class="relative min-h-screen overflow-hidden">
        <div class="pointer-events-none absolute inset-0 grid-overlay opacity-40"></div>
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(8,15,35,0)_0%,rgba(5,8,22,0.35)_72%,rgba(5,8,22,0.88)_100%)]"></div>

        <header class="relative z-10 flex items-center justify-between gap-4 px-6 py-5 sm:px-8 lg:px-12">
            <a href="<?= base_url('index.php') ?>" class="inline-flex items-center gap-3">
                <span class="grid h-11 w-11 place-items-center rounded-2xl border border-cyan-400/25 bg-gradient-to-br from-indigo-500 via-violet-500 to-cyan-400 shadow-[0_0_30px_rgba(34,211,238,0.18)]">
                    <i class="bi bi-safe2-fill text-lg text-white"></i>
                </span>
                <span class="leading-tight">
                    <span class="block text-lg font-extrabold tracking-[0.28em] text-white">FYP VAULT</span>
                    <span class="block text-xs uppercase tracking-[0.34em] text-slate-400">Final Year Project Hub</span>
                </span>
            </a>
            <span class="hidden rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs uppercase tracking-[0.28em] text-slate-300 backdrop-blur md:inline-flex">
                Secure collaboration workspace
            </span>
        </header>

        <main class="relative z-10 mx-auto grid min-h-[calc(100vh-88px)] max-w-[1600px] gap-8 px-6 pb-8 sm:px-8 lg:grid-cols-[1.15fr_0.85fr] lg:px-12">
            <section class="vault-scene flex items-center justify-center rounded-[2rem] border border-white/5 bg-white/5 px-4 py-10 shadow-[0_30px_120px_rgba(15,23,42,0.42)] backdrop-blur-sm lg:px-8">
                <div class="relative flex w-full max-w-3xl flex-col items-center gap-10">
                    <div class="max-w-xl text-center lg:text-left">
                        <p class="mb-4 inline-flex items-center gap-2 rounded-full border border-cyan-400/20 bg-cyan-400/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.28em] text-cyan-200">
                            <span class="h-2 w-2 rounded-full bg-cyan-300 shadow-[0_0_14px_rgba(34,211,238,1)]"></span>
                            Welcome back
                        </p>
                        <h1 class="max-w-2xl text-4xl font-black tracking-tight text-white sm:text-5xl lg:text-6xl">
                            Your project vault opens with one secure sign in.
                        </h1>
                        <p class="mt-5 max-w-xl text-base leading-8 text-slate-300 sm:text-lg">
                            Manage your project, messages, documents, and approvals in a futuristic workspace designed for students, supervisors, and HODs.
                        </p>
                        <div class="mt-6 flex flex-wrap justify-center gap-3 lg:justify-start">
                            <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-200 backdrop-blur">Encrypted access</span>
                            <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-200 backdrop-blur">Team collaboration</span>
                            <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-200 backdrop-blur">Real-time progress</span>
                        </div>
                    </div>

                    <div class="vault-stage">
                        <div class="vault-base-glow"></div>
                        <div class="vault-particle vault-particle--one"></div>
                        <div class="vault-particle vault-particle--two"></div>
                        <div class="vault-particle vault-particle--three"></div>
                        <div class="vault-particle vault-particle--four"></div>
                        <div class="vault-doc vault-doc--one"></div>
                        <div class="vault-doc vault-doc--two"></div>
                        <div class="vault-doc vault-doc--three"></div>
                        <div class="vault-core">
                            <div class="vault-ring"></div>
                            <div class="vault-door">
                                <div class="vault-slot"></div>
                                <div class="vault-hub"></div>
                                <div class="vault-handles">
                                    <span></span><span></span><span></span><span></span><span></span><span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="flex items-center justify-center">
                <div class="w-full max-w-xl rounded-[2rem] border border-white/10 bg-white/5 p-5 shadow-[0_25px_80px_rgba(15,23,42,0.55)] backdrop-blur-2xl sm:p-7 lg:p-8">
                    <div class="mb-8">
                        <p class="mb-2 text-sm font-semibold uppercase tracking-[0.3em] text-cyan-200/80">Secure Sign In</p>
                        <h2 class="text-3xl font-bold tracking-tight text-white">Welcome back</h2>
                        <p class="mt-3 text-sm leading-7 text-slate-300">Sign in to continue where you left off.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="mb-5 rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                            <?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="space-y-5">
                        <?= csrf_field() ?>
                        <div>
                            <label for="email" class="mb-2 block text-sm font-medium text-slate-200">Email</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                required
                                autocomplete="email"
                                value="<?= e($_POST['email'] ?? '') ?>"
                                placeholder="your@email.com"
                                class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-cyan-300/70 focus:ring-4 focus:ring-cyan-400/15"
                            >
                        </div>

                        <div>
                            <label for="password" class="mb-2 block text-sm font-medium text-slate-200">Password</label>
                            <div class="relative">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    required
                                    autocomplete="current-password"
                                    placeholder="••••••••"
                                    class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 pr-12 text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-cyan-300/70 focus:ring-4 focus:ring-cyan-400/15"
                                >
                                <button
                                    type="button"
                                    id="togglePassword"
                                    aria-label="Show or hide password"
                                    class="absolute inset-y-0 right-0 flex items-center px-4 text-slate-400 transition hover:text-cyan-200"
                                >
                                    <i class="bi bi-eye-fill text-lg"></i>
                                </button>
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="group inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-indigo-500 via-violet-500 to-cyan-400 px-5 py-3.5 text-sm font-bold text-white shadow-[0_18px_40px_rgba(99,102,241,0.35)] transition hover:-translate-y-0.5 hover:shadow-[0_24px_50px_rgba(34,211,238,0.28)]"
                        >
                            Sign In
                            <i class="bi bi-arrow-right-short text-xl transition group-hover:translate-x-0.5"></i>
                        </button>
                    </form>

                    <div class="mt-5 flex items-center justify-between gap-3 text-sm">
                        <a href="#" class="text-slate-400 transition hover:text-cyan-200" onclick="return false;">Forgot Password?</a>
                        <a href="<?= base_url('register.php') ?>" class="font-semibold text-cyan-200 transition hover:text-white">Create account</a>
                    </div>
                    <p class="mt-3 text-xs text-slate-400">
                        Registration is only for students without an existing account. Supervisor, HOD, and admin accounts are created by the department.
                    </p>
                </div>
            </section>
        </main>
    </div>

    <script>
        const passwordInput = document.getElementById('password');
        const togglePassword = document.getElementById('togglePassword');

        togglePassword.addEventListener('click', () => {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            togglePassword.innerHTML = isHidden
                ? '<i class="bi bi-eye-slash-fill text-lg"></i>'
                : '<i class="bi bi-eye-fill text-lg"></i>';
        });
    </script>
</body>
</html>
