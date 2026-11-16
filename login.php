
<?php
// ======================================================
// LOGIN.PHP - JURNAL KERJA PKL
// Split Screen 50:50 - Light Mode Only
// ======================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/activity.php';

$appName = defined('APP_NAME') ? APP_NAME : 'Jurnal PKL';

function loginEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Jika sudah login
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// ======================================================
// DETEKSI KOLOM DATABASE
// ======================================================

$hasUsernameCol = false;
$hasEmailCol = false;
$hasStatusCol = false;
$hasRememberCol = false;

try {
    $columns = $pdo->query("SHOW COLUMNS FROM users")
                   ->fetchAll(PDO::FETCH_COLUMN);

    $hasUsernameCol = in_array('username', $columns, true);
    $hasEmailCol = in_array('email', $columns, true);
    $hasStatusCol = in_array('status', $columns, true);
    $hasRememberCol = in_array('remember_token', $columns, true);

} catch (Throwable $e) {
    error_log('Login database detection: ' . $e->getMessage());
}

// ======================================================
// CSRF TOKEN
// ======================================================

if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_login'];

// ======================================================
// RATE LIMIT
// ======================================================

$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateKey = 'login_attempts_ip_' . md5($ipAddress);

$attempts = $_SESSION[$rateKey] ?? [];

$attempts = array_values(array_filter(
    $attempts,
    fn($time) => time() - (int) $time < 900
));

$_SESSION[$rateKey] = $attempts;
$isBlocked = count($attempts) >= 5;

$error = null;
$usernameInput = '';

// ======================================================
// PROSES LOGIN
// ======================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        empty($_POST['csrf']) ||
        empty($_SESSION['csrf_login']) ||
        !hash_equals($_SESSION['csrf_login'], (string) $_POST['csrf'])
    ) {
        $error = 'Sesi kedaluwarsa. Silakan muat ulang halaman.';

    } elseif (!empty($_POST['website'])) {

        $_SESSION[$rateKey][] = time();
        $error = 'Login gagal.';

    } elseif ($isBlocked) {

        $error = 'Terlalu banyak percobaan login. Silakan coba lagi setelah 15 menit.';

    } else {

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);

        $usernameInput = $username;

        if ($username === '' || $password === '') {

            $error = 'Username dan password wajib diisi.';

        } elseif (
            (function_exists('mb_strlen') ? mb_strlen($username) : strlen($username)) > 50
        ) {

            $error = 'Username terlalu panjang.';

        } else {

            try {

                if ($hasUsernameCol) {

                    $stmt = $pdo->prepare("
                        SELECT *
                        FROM users
                        WHERE username = ?
                        LIMIT 1
                    ");

                    $stmt->execute([$username]);

                } elseif ($hasEmailCol) {

                    $stmt = $pdo->prepare("
                        SELECT *
                        FROM users
                        WHERE email = ? OR email LIKE ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $username,
                        $username . '@%'
                    ]);

                } else {

                    throw new RuntimeException(
                        'Kolom username dan email tidak ditemukan.'
                    );
                }

                $u = $stmt->fetch(PDO::FETCH_ASSOC);

                if (
                    !$u ||
                    empty($u['password']) ||
                    !password_verify($password, $u['password'])
                ) {

                    $_SESSION[$rateKey][] = time();

                    $error = 'Username atau password salah.';

                    if (function_exists('logActivity')) {
                        logActivity(
                            'login_failed',
                            'Percobaan login gagal: ' . substr($username, 0, 50)
                        );
                    }

                } else {

                    // Cek status akun
                    if ($hasStatusCol) {

                        $accountStatus = strtolower(
                            trim((string) ($u['status'] ?? 'aktif'))
                        );

                        $inactiveStatuses = [
                            'nonaktif',
                            'non-aktif',
                            'inactive',
                            'disabled',
                            'banned',
                            '0'
                        ];

                        if (in_array($accountStatus, $inactiveStatuses, true)) {
                            $error = 'Akun kamu sedang nonaktif. Hubungi admin atau pembimbing.';
                        }
                    }

                    // Verifikasi 2FA
                    if ($error === null) {

                        $twofaActive =
                            !empty($u['twofa_enabled']) &&
                            !empty($u['twofa_secret']);

                        if ($twofaActive) {

                            if (file_exists(__DIR__ . '/verify_2fa.php')) {

                                $_SESSION['2fa_user_id'] = (int) $u['id'];
                                $_SESSION['2fa_pending'] = true;
                                $_SESSION['2fa_remember'] = $remember;

                                header('Location: verify_2fa.php');
                                exit;

                            } else {

                                $error = 'Verifikasi keamanan belum tersedia. Hubungi administrator.';
                            }
                        }
                    }

                    // Login berhasil
                    if ($error === null) {

                        $_SESSION = [];

                        session_regenerate_id(true);

                        $_SESSION['user_id'] = (int) $u['id'];
                        $_SESSION['name'] = $u['name'] ?? '';
                        $_SESSION['username'] = $u['username'] ?? $username;
                        $_SESSION['email'] = $u['email'] ?? '';
                        $_SESSION['role'] = $u['role'] ?? 'student';
                        $_SESSION['photo'] = $u['photo'] ?? null;
                        $_SESSION['last_activity'] = time();
                        $_SESSION['csrf_login'] = bin2hex(random_bytes(32));

                        // Remember me
                        $cookiePath = '/';

                        if (defined('BASE_URL')) {
                            $basePath = parse_url(BASE_URL, PHP_URL_PATH);

                            if (is_string($basePath) && $basePath !== '') {
                                $cookiePath = rtrim($basePath, '/') . '/';
                            }
                        }

                        $isSecure = (
                            !empty($_SERVER['HTTPS']) &&
                            strtolower((string) $_SERVER['HTTPS']) !== 'off'
                        );

                        if ($hasRememberCol) {

                            try {

                                if ($remember) {

                                    $token = bin2hex(random_bytes(32));
                                    $hashedToken = hash('sha256', $token);

                                    $stmtRemember = $pdo->prepare("
                                        UPDATE users
                                        SET remember_token = ?
                                        WHERE id = ?
                                    ");

                                    $stmtRemember->execute([
                                        $hashedToken,
                                        $u['id']
                                    ]);

                                    setcookie('remember_token', $token, [
                                        'expires' => time() + (60 * 60 * 24 * 30),
                                        'path' => $cookiePath,
                                        'secure' => $isSecure,
                                        'httponly' => true,
                                        'samesite' => 'Lax'
                                    ]);

                                } else {

                                    $pdo->prepare("
                                        UPDATE users
                                        SET remember_token = NULL
                                        WHERE id = ?
                                    ")->execute([$u['id']]);

                                    setcookie('remember_token', '', [
                                        'expires' => time() - 3600,
                                        'path' => $cookiePath,
                                        'secure' => $isSecure,
                                        'httponly' => true,
                                        'samesite' => 'Lax'
                                    ]);
                                }

                            } catch (Throwable $e) {
                                error_log('Remember me error: ' . $e->getMessage());
                            }
                        }

                        if (function_exists('logActivity')) {
                            logActivity(
                                'login',
                                'Login berhasil via username: ' . substr($username, 0, 50)
                            );
                        }

                        unset($_SESSION[$rateKey]);

                        header('Location: dashboard.php');
                        exit;
                    }
                }

            } catch (Throwable $e) {

                $error = 'Terjadi kesalahan sistem. Silakan coba lagi.';

                error_log('Login error: ' . $e->getMessage());
            }
        }
    }
}

$attempts = $_SESSION[$rateKey] ?? [];
$isBlocked = count($attempts) >= 5;

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">

    <title>Masuk — <?= loginEscape($appName) ?></title>

    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📔</text></svg>">

    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    },
                    colors: {
                        primary: '#4F46E5'
                    }
                }
            }
        }
    </script>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #EEF2FF 0%, #FAF5FF 50%, #EFF6FF 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .main-card {
            width: 100%;
            max-width: 1100px;
            min-height: 650px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: #fff;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 25px 70px rgba(79, 70, 229, .13);
            border: 1px solid rgba(255,255,255,.9);
            position: relative;
        }

        .gradient-bg {
            background: linear-gradient(135deg, #4F46E5 0%, #9333EA 52%, #3B82F6 100%);
        }

        .gradient-text {
            background: linear-gradient(135deg, #4F46E5, #9333EA, #3B82F6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .primary-button {
            background: linear-gradient(110deg, #4F46E5, #9333EA, #3B82F6);
            background-size: 200% 200%;
            transition: .25s ease;
        }

        .primary-button:hover {
            background-position: right center;
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(79,70,229,.25);
        }

        .login-input {
            transition: .2s ease;
        }

        .login-input:focus {
            border-color: #6366F1;
            box-shadow: 0 0 0 4px rgba(99,102,241,.12);
            background: #fff;
        }

        .floating {
            animation: floating 5s ease-in-out infinite;
        }

        @keyframes floating {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .decor-circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,.12);
            pointer-events: none;
        }

        .feature-card {
            transition: .2s ease;
        }

        .feature-card:hover {
            transform: translateX(5px);
            background: rgba(255,255,255,.18);
        }

        @media (max-width: 767px) {
            body {
                padding: 18px 12px;
            }

            .main-card {
                grid-template-columns: 1fr;
                min-height: auto;
                border-radius: 22px;
            }

            .welcome-panel {
                min-height: auto !important;
                padding: 30px 24px !important;
            }

            .welcome-illustration {
                margin: 25px auto;
            }

            .login-panel {
                padding: 35px 24px !important;
            }

            .welcome-title {
                font-size: 27px !important;
            }
        }

        @media (max-width: 380px) {
            .login-panel {
                padding: 28px 18px !important;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body class="flex items-center justify-center px-4 py-8">

    <!-- Background Decoration -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute w-80 h-80 rounded-full bg-indigo-200/40 blur-3xl -top-32 -left-20"></div>
        <div class="absolute w-96 h-96 rounded-full bg-purple-200/40 blur-3xl -bottom-40 -right-20"></div>
        <div class="absolute w-64 h-64 rounded-full bg-blue-200/30 blur-3xl top-1/3 left-1/3"></div>
    </div>

    <main class="relative w-full max-w-[1100px]">

        <div class="main-card">

            <!-- =================================================
                 PANEL KIRI - INFORMASI APLIKASI
            ================================================== -->
            <section class="welcome-panel gradient-bg relative flex flex-col justify-between p-9 md:p-11 text-white overflow-hidden">

                <div class="decor-circle w-72 h-72 -top-24 -right-24"></div>
                <div class="decor-circle w-52 h-52 bottom-10 -left-24"></div>
                <div class="decor-circle w-32 h-32 bottom-28 right-10"></div>

                <!-- Logo -->
                <div class="relative z-10">
                    <a href="index.php" class="inline-flex items-center gap-3">
                        <div class="w-12 h-12 bg-white/20 rounded-2xl flex items-center justify-center backdrop-blur">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                    d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5S19.832 5.477 21 6.253v13C19.832 18.477 18.246 18 16.5 18s-3.332.477-4.5 1.253"/>
                            </svg>
                        </div>

                        <span class="font-extrabold text-xl">
                            <?= loginEscape($appName) ?>
                        </span>
                    </a>
                </div>

                <!-- Konten Sambutan -->
                <div class="relative z-10 py-8">

                    <div class="floating welcome-illustration flex justify-center mb-8">
                        <div class="relative w-56 h-52 flex items-center justify-center">

                            <div class="absolute w-48 h-48 bg-white/10 rounded-full"></div>
                            <div class="absolute w-36 h-36 bg-white/10 rounded-full"></div>

                            <div class="relative bg-white rounded-[30px] w-32 h-36 shadow-2xl flex flex-col items-center justify-center text-indigo-600 rotate-[-5deg]">
                                <svg class="w-14 h-14 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                                        d="M4 19.5A2.5 2.5 0 016.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/>
                                </svg>
                                <div class="w-14 h-1.5 rounded-full bg-indigo-200 mb-2"></div>
                                <div class="w-10 h-1.5 rounded-full bg-purple-200"></div>
                            </div>

                            <div class="absolute top-5 right-1 bg-white text-indigo-600 p-3 rounded-2xl shadow-lg rotate-12">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622C17.176 19.29 21 14.591 21 9c0-.92-.104-1.815-.3-2.684z"/>
                                </svg>
                            </div>

                            <div class="absolute bottom-4 left-1 bg-white text-purple-600 p-3 rounded-2xl shadow-lg -rotate-12">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>

                        </div>
                    </div>

                    <h1 class="welcome-title text-3xl md:text-4xl font-black leading-tight text-center">
                        Selamat Datang di
                        <span class="block text-indigo-100 mt-1">
                            <?= loginEscape($appName) ?>
                        </span>
                    </h1>

                    <p class="text-indigo-100 text-sm leading-relaxed text-center mt-5 max-w-sm mx-auto">
                        Platform digital untuk membantu kegiatan Praktik Kerja Lapangan menjadi lebih terorganisir, mudah, dan terdokumentasi.
                    </p>

                    <!-- Fitur -->
                    <div class="space-y-3 mt-8">

                        <div class="feature-card flex items-center gap-4 p-3.5 rounded-2xl bg-white/10 border border-white/15">
                            <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-bold text-sm">Presensi Digital</h3>
                                <p class="text-xs text-indigo-100 mt-1">Catat kehadiran dengan lebih praktis.</p>
                            </div>
                        </div>

                        <div class="feature-card flex items-center gap-4 p-3.5 rounded-2xl bg-white/10 border border-white/15">
                            <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-bold text-sm">Jurnal Kegiatan</h3>
                                <p class="text-xs text-indigo-100 mt-1">Dokumentasikan pekerjaan harian.</p>
                            </div>
                        </div>

                        <div class="feature-card flex items-center gap-4 p-3.5 rounded-2xl bg-white/10 border border-white/15">
                            <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-4l-5 4v-4z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-bold text-sm">Komunikasi Terpadu</h3>
                                <p class="text-xs text-indigo-100 mt-1">Terhubung dengan anggota dan pembimbing.</p>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="relative z-10 text-xs text-indigo-100 flex justify-between items-center">
                    <span>Jurnal Kerja Praktik Lapangan</span>
                    <span>&copy; <?= date('Y') ?></span>
                </div>

            </section>

            <!-- =================================================
                 PANEL KANAN - FORM LOGIN
            ================================================== -->
            <section class="login-panel flex flex-col justify-center p-8 md:p-12 lg:p-14 bg-white">

                <div class="max-w-md mx-auto w-full">

                    <div class="mb-8">

                        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-indigo-50 text-indigo-700 text-xs font-bold mb-6">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                            SISTEM INFORMASI PKL
                        </span>

                        <h2 class="text-3xl md:text-4xl font-black text-slate-800">
                            Selamat Datang!
                        </h2>

                        <p class="text-slate-500 text-sm leading-relaxed mt-3">
                            Masuk ke akun kamu untuk melanjutkan aktivitas Praktik Kerja Lapangan.
                        </p>

                    </div>

                    <?php if ($error): ?>
                        <div role="alert" class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5 flex items-start gap-2">
                            <span>⚠️</span>
                            <span><?= loginEscape($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['logout'])): ?>
                        <div role="status" class="bg-indigo-50 border border-indigo-200 text-indigo-700 text-sm px-4 py-3 rounded-xl mb-5 flex items-start gap-2">
                            <span>✓</span>
                            <span>Anda berhasil keluar. Silakan login kembali.</span>
                        </div>
                    <?php endif; ?>

                    <?php if ($isBlocked): ?>
                        <div role="alert" class="bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3 rounded-xl mb-5 flex items-start gap-2">
                            <span>🔒</span>
                            <span>Terlalu banyak percobaan login. Silakan coba kembali setelah 15 menit.</span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" autocomplete="on" id="login-form">

                        <input type="hidden" name="csrf" value="<?= loginEscape($csrf) ?>">

                        <!-- HONEYPOT -->
                        <div class="hidden" aria-hidden="true">
                            <label for="website">Website</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <!-- USERNAME -->
                        <div class="mb-5">
                            <label for="username" class="block text-sm font-bold text-slate-700 mb-2">
                                Username
                            </label>

                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                </span>

                                <input
                                    type="text"
                                    id="username"
                                    name="username"
                                    value="<?= loginEscape($usernameInput) ?>"
                                    required
                                    autofocus
                                    autocomplete="username"
                                    maxlength="50"
                                    placeholder="Masukkan username"
                                    <?= $isBlocked ? 'disabled' : '' ?>
                                    class="login-input w-full pl-12 pr-4 py-3.5 border border-slate-200 rounded-xl outline-none text-slate-800 placeholder-slate-400 bg-slate-50">
                            </div>
                        </div>

                        <!-- PASSWORD -->
                        <div class="mb-5">
                            <div class="flex justify-between items-center mb-2">
                                <label for="password" class="text-sm font-bold text-slate-700">
                                    Password
                                </label>

                                <button type="button" onclick="togglePassword()" id="toggle-pwd"
                                    class="text-xs text-indigo-600 hover:text-purple-600 font-bold">
                                    Lihat
                                </button>
                            </div>

                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                </span>

                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    required
                                    autocomplete="current-password"
                                    placeholder="Masukkan password"
                                    <?= $isBlocked ? 'disabled' : '' ?>
                                    class="login-input w-full pl-12 pr-12 py-3.5 border border-slate-200 rounded-xl outline-none text-slate-800 placeholder-slate-400 bg-slate-50">
                            </div>
                        </div>

                        <!-- REMEMBER & FORGOT PASSWORD -->
                        <div class="flex items-center justify-between gap-3 mb-7">

                            <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    name="remember"
                                    value="1"
                                    class="w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                    <?= $isBlocked ? 'disabled' : '' ?>>
                                Ingat saya
                            </label>

                            <?php if (file_exists(__DIR__ . '/lupa_password.php')): ?>
                                <a href="lupa_password.php"
                                    class="text-sm text-indigo-600 hover:text-purple-600 font-semibold hover:underline">
                                    Lupa password?
                                </a>
                            <?php endif; ?>

                        </div>

                        <!-- SUBMIT -->
                        <button
                            type="submit"
                            id="submit-btn"
                            <?= $isBlocked ? 'disabled' : '' ?>
                            class="primary-button w-full text-white py-4 rounded-xl font-bold shadow-lg shadow-indigo-200 transition disabled:opacity-50 disabled:cursor-not-allowed">

                            <span id="button-text">Masuk ke Akun →</span>

                        </button>

                    </form>

                    <!-- INFORMASI BANTUAN -->
                    <div class="mt-8 pt-6 border-t border-slate-100 text-center">
                        <p class="text-sm text-slate-500">
                            Belum punya akun?
                        </p>

                        <p class="text-sm text-slate-700 font-semibold mt-1">
                            Hubungi admin atau pembimbing untuk mendapatkan akun.
                        </p>
                    </div>

                    <div class="mt-7 flex items-center justify-center gap-2 text-xs text-slate-400">
                        <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 11c.828 0 1.5-.672 1.5-1.5S12.828 8 12 8s-1.5.672-1.5 1.5S11.172 11 12 11zm0 0v2m-7 8h14a2 2 0 002-2V7a2 2 0 00-2-2h-2V4a5 5 0 00-10 0v1H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        Login aman dan terlindungi
                    </div>

                </div>

            </section>

        </div>

        <!-- FOOTER -->
        <div class="flex flex-col sm:flex-row justify-between items-center gap-3 mt-5 px-2">

            <a href="index.php"
                class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-700 hover:text-purple-700 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                Kembali ke halaman utama
            </a>

            <p class="text-xs text-slate-400 text-center">
                &copy; <?= date('Y') ?> <?= loginEscape($appName) ?> · Jurnal Kerja PKL
            </p>

        </div>

    </main>

    <script>
        // Lihat / sembunyikan password
        function togglePassword() {
            const password = document.getElementById('password');
            const button = document.getElementById('toggle-pwd');

            if (password.type === 'password') {
                password.type = 'text';
                button.textContent = 'Sembunyikan';
            } else {
                password.type = 'password';
                button.textContent = 'Lihat';
            }
        }

        // Indikator proses login
        document.getElementById('login-form').addEventListener('submit', function () {
            const button = document.getElementById('submit-btn');
            const buttonText = document.getElementById('button-text');

            if (button.disabled) {
                return;
            }

            button.disabled = true;
            buttonText.textContent = 'Memproses login...';
        });

        // Fokus ke username saat halaman dibuka
        window.addEventListener('load', function () {
            const username = document.getElementById('username');

            if (username && !username.disabled) {
                username.focus();
            }
        });
    </script>

</body>
</html>
