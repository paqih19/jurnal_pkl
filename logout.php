<?php
// logout.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';

$appName = defined('APP_NAME') ? APP_NAME : 'Jurnal PKL';
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

$loggedOutUserId = $_SESSION['user_id'] ?? null;
$loggedOutName   = $_SESSION['name'] ?? ($_SESSION['username'] ?? 'unknown');
$loggedOutRole   = $_SESSION['role'] ?? 'unknown';

// Catat aktivitas logout
try {
    if (function_exists('logActivity')) {
        logActivity('logout', "Logout: {$loggedOutName} ({$loggedOutRole})");
    } elseif (isset($pdo) && $loggedOutUserId) {
        $stmt = $pdo->prepare("
            INSERT INTO activity_log
            (user_id, username, role, aksi, detail, ip, user_agent, created_at)
            VALUES (?, ?, ?, 'logout', ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $loggedOutUserId,
            $loggedOutName,
            $loggedOutRole,
            'Logout',
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }
} catch (Throwable $e) {}

// Hapus remember-me token
if (isset($pdo) && $loggedOutUserId) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
        $stmt->execute([$loggedOutUserId]);
    } catch (Throwable $e) {}
}

// Hapus cookie remember-me
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Hapus session
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'] ?: '/',
        'domain'   => $params['domain'] ?: '',
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => 'Lax',
    ]);
}

session_destroy();

// Anti-cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$homeUrl  = $baseUrl . '/index.php?logout=1';
$loginUrl = $baseUrl . '/login.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">

    <title>Logout - <?= htmlspecialchars($appName) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { primary: '#4F46E5' },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        };
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; }

        .page-bg {
            background: linear-gradient(135deg, #EEF2FF 0%, #FAF5FF 50%, #EFF6FF 100%);
        }

        .decor {
            position: absolute;
            border-radius: 50%;
            filter: blur(65px);
            opacity: .35;
            pointer-events: none;
        }

        .glass-card {
            background: rgba(255, 255, 255, .88);
            border: 1px solid rgba(255, 255, 255, .9);
            box-shadow: 0 25px 70px rgba(79, 70, 229, .13);
            backdrop-filter: blur(16px);
        }

        .gradient-text {
            background: linear-gradient(90deg, #4F46E5, #A855F7, #3B82F6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .icon-box {
            background: linear-gradient(135deg, #4F46E5, #9333EA, #3B82F6);
            box-shadow: 0 12px 28px rgba(99, 102, 241, .3);
        }

        .main-button {
            background: linear-gradient(90deg, #4F46E5, #9333EA);
            transition: all .25s ease;
        }

        .main-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(99, 102, 241, .3);
        }

        .spinner {
            width: 18px;
            height: 18px;
            border: 2.5px solid #DDD6FE;
            border-top-color: #4F46E5;
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .progress-bar { animation: progress 5s linear forwards; }

        @keyframes progress {
            from { width: 100%; }
            to { width: 0%; }
        }

        .card-appear { animation: appear .5s ease-out; }

        @keyframes appear {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>

<body class="page-bg min-h-screen flex items-center justify-center px-5 py-10 relative overflow-hidden">

    <!-- Dekorasi -->
    <div class="decor bg-indigo-400 w-72 h-72 -top-24 -left-20"></div>
    <div class="decor bg-purple-400 w-80 h-80 -bottom-24 -right-20"></div>
    <div class="decor bg-blue-300 w-52 h-52 top-1/3 right-1/4"></div>

    <!-- Konten -->
    <main class="relative z-10 w-full max-w-md card-appear">
        <div class="glass-card rounded-3xl p-8 sm:p-10 text-center">

            <!-- Ikon logout -->
            <div class="icon-box mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-2xl text-white">
                <svg xmlns="http://www.w3.org/2000/svg"
                     class="w-10 h-10"
                     fill="none"
                     viewBox="0 0 24 24"
                     stroke="currentColor"
                     stroke-width="1.8">
                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          d="M17 16l4-4m0 0l-4-4m4 4H9m4 4v1a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2h5a2 2 0 012 2v1"/>
                </svg>
            </div>

            <p class="text-xs font-bold tracking-widest uppercase text-indigo-500 mb-3">
                <?= htmlspecialchars($appName) ?>
            </p>

            <h1 class="text-3xl font-extrabold text-slate-800 mb-3">
                Sampai <span class="gradient-text">Jumpa!</span>
            </h1>

            <p class="text-sm leading-7 text-slate-500">
                Kamu berhasil keluar dari sistem.
                Terima kasih sudah menggunakan
                <strong class="text-indigo-600"><?= htmlspecialchars($appName) ?></strong>.
            </p>

            <!-- Status -->
            <div class="mt-7 rounded-2xl border border-indigo-100 bg-indigo-50/80 p-4">
                <div class="flex items-center justify-center gap-3">
                    <div class="spinner"></div>
                    <span class="text-sm font-semibold text-indigo-700">
                        Mengalihkan ke halaman beranda
                    </span>
                </div>

                <p class="mt-3 text-sm text-slate-500">
                    Otomatis dalam
                    <strong id="countdown" class="text-indigo-600">5</strong>
                    detik
                </p>

                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-indigo-100">
                    <div class="progress-bar h-full rounded-full bg-gradient-to-r from-indigo-600 via-purple-600 to-blue-500"></div>
                </div>
            </div>

            <!-- Tombol -->
            <div class="mt-6 flex flex-col gap-3">
                <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>"
                   class="main-button flex items-center justify-center gap-2 rounded-xl px-5 py-3.5 text-sm font-bold text-white">
                    Kembali ke Beranda
                    <span>→</span>
                </a>

                <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>"
                   class="rounded-xl border border-indigo-200 bg-white px-5 py-3 text-sm font-semibold text-indigo-600 transition hover:bg-indigo-50">
                    Login Kembali
                </a>
            </div>

            <div class="mt-7 border-t border-slate-100 pt-5">
                <p class="text-xs text-slate-400">
                    &copy; <?= date('Y') ?> <?= htmlspecialchars($appName) ?>
                </p>
            </div>
        </div>
    </main>

    <script>
        let seconds = 5;
        const countdown = document.getElementById('countdown');

        const timer = setInterval(function () {
            seconds--;
            countdown.textContent = seconds;

            if (seconds <= 0) {
                clearInterval(timer);
                window.location.replace(
                    <?= json_encode($homeUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                );
            }
        }, 1000);
    </script>

</body>
</html>