<?php
// login.php
session_start();
require_once __DIR__ . '/includes/functions.php';

if (isset($_SESSION['user_id'])) redirect('dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $hasUsername = false;
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM users") as $c) {
                if ($c['Field'] === 'username') { $hasUsername = true; break; }
            }
        } catch (Exception $e) {}

        if ($hasUsername) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        }
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role'];
            $_SESSION['photo']   = $user['photo'];
            if (!empty($user['username'])) $_SESSION['username'] = $user['username'];
            redirect('dashboard.php');
        } else {
            $error = 'Username atau password salah!';
        }
    } else {
        $error = 'Mohon isi username dan password.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk - <?= APP_NAME ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📔</text></svg>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] }, colors: { primary: '#4F46E5' } } } }
    </script>
    <style>
        .hero-bg { background: linear-gradient(-45deg, #4F46E5, #7C3AED, #3B82F6, #8B5CF6); background-size: 400% 400%; animation: gradientMove 15s ease infinite; }
        @keyframes gradientMove { 0%,100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
        .glass { background: rgba(255,255,255,0.85); backdrop-filter: blur(20px); }
    </style>
</head>
<body class="font-sans antialiased min-h-screen flex">

    <div class="hidden lg:flex lg:w-1/2 hero-bg relative overflow-hidden items-center justify-center p-12">
        <div class="relative z-10 text-center text-white max-w-md">
            <div class="inline-flex items-center justify-center w-24 h-24 bg-white/20 backdrop-blur rounded-3xl mb-8 text-6xl">📔</div>
            <h1 class="text-4xl font-extrabold mb-4">Jurnal PKL</h1>
            <p class="text-lg text-indigo-100 mb-8">Platform terpusat untuk mencatat jurnal harian, absensi, tugas, dan komunikasi kelompok PKL.</p>
            <div class="flex flex-wrap justify-center gap-2">
                <span class="bg-white/20 backdrop-blur px-3 py-1.5 rounded-full text-xs font-semibold">📝 Jurnal</span>
                <span class="bg-white/20 backdrop-blur px-3 py-1.5 rounded-full text-xs font-semibold">✅ Absensi</span>
                <span class="bg-white/20 backdrop-blur px-3 py-1.5 rounded-full text-xs font-semibold">📋 Tugas</span>
                <span class="bg-white/20 backdrop-blur px-3 py-1.5 rounded-full text-xs font-semibold">💬 Chat</span>
            </div>
        </div>
    </div>

    <div class="w-full lg:w-1/2 flex items-center justify-center p-6 bg-gray-50">
        <div class="w-full max-w-md">
            <div class="lg:hidden text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-primary to-purple-600 rounded-2xl mb-3 text-3xl text-white shadow-lg">📔</div>
                <h1 class="text-2xl font-extrabold text-gray-900"><?= APP_NAME ?></h1>
            </div>

            <div class="glass rounded-3xl shadow-2xl border border-white/60 p-8 sm:p-10">
                <div class="mb-8">
                    <h2 class="text-3xl font-extrabold text-gray-900 mb-2">Masuk</h2>
                    <p class="text-sm text-gray-500">Selamat datang kembali! Silakan masuk ke akun kamu.</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-100 flex items-start gap-3">
                        <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-sm text-red-700 font-medium"><?= e($error) ?></p>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-5">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Username</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </div>
                            <input type="text" name="username" required placeholder="Masukkan username" value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username" autofocus class="w-full pl-11 pr-4 py-3.5 bg-white/70 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary outline-none">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </div>
                            <input type="password" name="password" id="password" required placeholder="••••••••" class="w-full pl-11 pr-12 py-3.5 bg-white/70 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary outline-none">
                            <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-gray-600">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-gradient-to-r from-primary to-purple-600 hover:shadow-xl text-white py-3.5 rounded-xl font-bold transition-all">Masuk ke Sistem</button>
                </form>

                <div class="mt-8 pt-6 border-t border-gray-200/60 text-center">
                    <a href="<?= BASE_URL ?>/index.php" class="text-sm text-gray-500 hover:text-primary">← Kembali ke Beranda</a>
                </div>
            </div>
            <p class="text-center text-xs text-gray-400 mt-6">&copy; <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</p>
        </div>
    </div>

    <script>
        function togglePassword() {
            const inp = document.getElementById('password');
            inp.type = inp.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>
</html>