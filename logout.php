<?php
// logout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load config DULU sebelum apapun (biar APP_NAME, BASE_URL ada)
require_once __DIR__ . '/config/db.php';

// Simpan nama app sebelum destroy
$appName = defined('APP_NAME') ? APP_NAME : 'Jurnal PKL';
$baseUrl = defined('BASE_URL') ? BASE_URL : '';

// Hapus session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="2;url=<?= $baseUrl ?>/index.php">
    <title>Keluar - <?= htmlspecialchars($appName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
        }
        .logout-bg {
            background: linear-gradient(-45deg, #4F46E5, #7C3AED, #3B82F6, #8B5CF6);
            background-size: 400% 400%;
            animation: gradientMove 8s ease infinite;
            min-height: 100vh;
        }
        @keyframes gradientMove {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-up {
            animation: fadeUp 0.6s ease-out both;
        }
        @keyframes wave {
            0%, 100% { transform: rotate(0deg); }
            25% { transform: rotate(20deg); }
            75% { transform: rotate(-10deg); }
        }
        .wave-hand {
            display: inline-block;
            animation: wave 1.5s ease-in-out infinite;
            transform-origin: 70% 70%;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .spinner {
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
        }
        .progress-bar {
            width: 0%;
            transition: width 2s linear;
        }
    </style>
</head>
<body class="logout-bg flex items-center justify-center p-6">

    <!-- Blob dekorasi -->
    <div class="absolute top-20 -left-20 w-72 h-72 bg-white/10 rounded-full blur-3xl"></div>
    <div class="absolute bottom-20 -right-20 w-80 h-80 bg-white/10 rounded-full blur-3xl"></div>

    <div class="fade-up relative z-10 text-center text-white max-w-md w-full">
        <div class="inline-flex items-center justify-center w-24 h-24 bg-white/20 backdrop-blur rounded-full mb-6 text-5xl">
            <span class="wave-hand">👋</span>
        </div>

        <h1 class="text-3xl font-extrabold mb-3">Sampai Jumpa!</h1>
        <p class="text-indigo-100 mb-8">
            Kamu berhasil keluar dari sistem.<br>Terima kasih sudah menggunakan <?= htmlspecialchars($appName) ?>!
        </p>

        <!-- Progress bar -->
        <div class="w-full max-w-xs mx-auto h-1.5 bg-white/20 rounded-full overflow-hidden mb-4">
            <div id="progress" class="h-full bg-white rounded-full progress-bar"></div>
        </div>

        <!-- Loading -->
        <div class="flex items-center justify-center gap-2 text-sm text-indigo-100 mb-6">
            <div class="spinner"></div>
            <span>Mengalihkan ke beranda...</span>
        </div>

        <a href="<?= $baseUrl ?>/index.php"
           class="inline-block bg-white text-indigo-600 font-bold px-6 py-2.5 rounded-full hover:bg-indigo-50 transition shadow-lg">
            Ke Beranda Sekarang
        </a>
    </div>

    <script>
        // Animasi progress bar
        window.addEventListener('load', () => {
            setTimeout(() => {
                const p = document.getElementById('progress');
                if (p) p.style.width = '100%';
            }, 100);
        });
    </script>
</body>
</html>