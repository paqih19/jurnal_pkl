<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/db.php';

$appName = defined('APP_NAME') ? APP_NAME : 'Jurnal PKL';
$baseUrl = defined('BASE_URL') ? BASE_URL : '';

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
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
        body { font-family: sans-serif; margin: 0; }
        .logout-bg { background: linear-gradient(-45deg,#4F46E5,#7C3AED,#3B82F6,#8B5CF6); background-size: 400% 400%; animation: gm 8s ease infinite; min-height: 100vh; }
        @keyframes gm { 0%,100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
        @keyframes spin { to { transform: rotate(360deg); } }
        .spinner { border: 3px solid rgba(255,255,255,0.3); border-top-color: white; border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; }
    </style>
</head>
<body class="logout-bg flex items-center justify-center p-6">
    <div class="text-center text-white max-w-md">
        <div class="inline-flex items-center justify-center w-24 h-24 bg-white/20 backdrop-blur rounded-full mb-6 text-5xl">👋</div>
        <h1 class="text-3xl font-extrabold mb-3">Sampai Jumpa!</h1>
        <p class="text-indigo-100 mb-8">Kamu berhasil keluar dari sistem.<br>Terima kasih sudah menggunakan <?= htmlspecialchars($appName) ?>!</p>
        <div class="flex items-center justify-center gap-2 text-sm text-indigo-100 mb-6">
            <div class="spinner"></div>
            <span>Mengalihkan ke beranda...</span>
        </div>
        <a href="<?= $baseUrl ?>/index.php" class="inline-block bg-white text-indigo-600 font-bold px-6 py-2.5 rounded-full">Ke Beranda</a>
    </div>
</body>
</html>