<?php
// index.php — Landing Page Jurnal Kerja PKL
require_once __DIR__ . '/includes/functions.php';

// ============ KONFIGURASI CADANGAN ============
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Jurnal PKL');
}
if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}
if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', rtrim(BASE_URL, '/') . '/uploads/');
}
if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', __DIR__ . '/uploads/');
}

$baseUrl = rtrim(BASE_URL, '/');

// Pastikan helper e() tersedia
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// ============ PASTIKAN $pdo TERSEDIA ============
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Koneksi Database Gagal</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-50 flex items-center justify-center min-h-screen">
        <div class="text-center p-8 bg-white rounded-2xl shadow-lg max-w-md">
            <div class="text-6xl mb-4">🔌</div>
            <h1 class="text-xl font-bold text-gray-800 mb-2">Koneksi Database Gagal</h1>
            <p class="text-sm text-gray-500">Pastikan konfigurasi database di <code>includes/functions.php</code> sudah benar dan server MySQL berjalan.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============ CEK KOLOM DATABASE ============
$userCols = [];
try {
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        $userCols[$column['Field']] = true;
    }
} catch (Throwable $e) {
    $userCols = [];
}

$hasBio     = isset($userCols['bio']);
$hasJurusan = isset($userCols['jurusan']);
$hasSekolah = isset($userCols['sekolah']);
$hasPhoto   = isset($userCols['photo']);

// ============ AMBIL DATA TIM ============
$users = [];
try {
    $select = "id, name, role";
    if ($hasPhoto)   $select .= ", photo";
    if ($hasJurusan) $select .= ", jurusan";
    if ($hasSekolah) $select .= ", sekolah";
    if ($hasBio)     $select .= ", LEFT(COALESCE(bio, ''), 120) AS bio";

    $stmt = $pdo->query("
        SELECT {$select}
        FROM users
        WHERE role NOT IN ('admin')
        ORDER BY
            CASE
                WHEN role IN ('pembimbing', 'teacher') THEN 1
                ELSE 2
            END,
            id ASC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $users = [];
}

foreach ($users as &$user) {
    $user['photo']   = $user['photo'] ?? null;
    $user['jurusan'] = $user['jurusan'] ?? null;
    $user['sekolah'] = $user['sekolah'] ?? null;
    $user['bio']     = $user['bio'] ?? null;
}
unset($user);

// ============ STATISTIK ============
$total_users = count($users);
$total_journals = 0;
$total_photos = 0;
$total_tasks = 0;

try {
    $total_journals = (int) $pdo->query("SELECT COUNT(*) FROM journals")->fetchColumn();
} catch (Throwable $e) {}

try {
    $total_photos = (int) $pdo->query("SELECT COUNT(*) FROM journal_photos")->fetchColumn();
} catch (Throwable $e) {}

try {
    $total_tasks = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'done'")->fetchColumn();
} catch (Throwable $e) {}

$pembimbings = array_filter($users, fn($u) => in_array($u['role'], ['pembimbing', 'teacher'], true));
$siswas     = array_filter($users, fn($u) => !in_array($u['role'], ['pembimbing', 'teacher', 'admin'], true));

$justLoggedOut = isset($_GET['logout']);
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> — Dokumentasi PKL</title>
    <meta name="description" content="Platform Jurnal Kerja PKL untuk mencatat presensi, jurnal kegiatan, tugas, dokumentasi, dan komunikasi kelompok.">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📔</text></svg>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: { primary: '#4F46E5' },
                    animation: {
                        'blob': 'blob 14s ease-in-out infinite',
                        'float': 'float 6s ease-in-out infinite'
                    },
                    keyframes: {
                        blob: {
                            '0%,100%': { transform: 'translate(0,0) scale(1)' },
                            '33%': { transform: 'translate(40px,-50px) scale(1.15)' },
                            '66%': { transform: 'translate(-30px,30px) scale(.9)' }
                        },
                        float: {
                            '0%,100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-10px)' }
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .line-clamp-3 { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
        body { overflow-x: hidden; transition: background-color .3s, color .3s; }
        .hero-gradient { background: linear-gradient(135deg,#EEF2FF 0%,#FAF5FF 50%,#EFF6FF 100%); }
        .dark .hero-gradient { background: linear-gradient(135deg,#0F172A 0%,#1E1B4B 50%,#0F172A 100%); }
        .glass { background: rgba(255,255,255,.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
        .dark .glass { background: rgba(30,41,59,.7); }
        .reveal { opacity: 0; transform: translateY(30px); transition: all .8s cubic-bezier(.16,1,.3,1); }
        .reveal.active { opacity: 1; transform: translateY(0); }
        .btn-shine { position: relative; overflow: hidden; }
        .btn-shine::after {
            content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg,transparent,rgba(255,255,255,.3),transparent);
            transition: left .6s;
        }
        .btn-shine:hover::after { left: 100%; }
        .text-gradient {
            background: linear-gradient(135deg,#4F46E5,#a855f7,#3b82f6,#4F46E5);
            background-size: 300% 300%; -webkit-background-clip: text; background-clip: text;
            color: transparent; animation: gradientMove 6s ease infinite;
        }
        .dark .text-gradient {
            background: linear-gradient(135deg,#A5B4FC,#C4B5FD,#93C5FD,#A5B4FC);
            background-size: 300% 300%; -webkit-background-clip: text; background-clip: text;
            color: transparent;
        }
        @keyframes gradientMove { 0%,100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(180deg,#6366f1,#a855f7); border-radius: 4px; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-100%); } to { opacity: 1; transform: translateY(0); } }
        .logout-banner { animation: slideDown .4s ease-out; }
    </style>
</head>

<body class="bg-white dark:bg-slate-900 font-sans text-gray-800 dark:text-gray-100">

<?php if ($justLoggedOut): ?>
<div id="logout-banner" class="logout-banner fixed top-0 left-0 right-0 z-[60] bg-gradient-to-r from-green-500 to-emerald-500 text-white text-center py-3 px-4 shadow-lg">
    <div class="max-w-6xl mx-auto flex items-center justify-center gap-2 text-sm font-semibold">
        <span>✅</span>
        <span>Anda telah berhasil keluar. Sampai jumpa lagi!</span>
        <button onclick="document.getElementById('logout-banner').remove()" class="ml-2 text-white/80 hover:text-white" aria-label="Tutup notifikasi">✕</button>
    </div>
</div>
<script>
    setTimeout(function () {
        const banner = document.getElementById('logout-banner');
        if (banner) {
            banner.style.transition = 'opacity .4s, transform .4s';
            banner.style.opacity = '0';
            banner.style.transform = 'translateY(-100%)';
            setTimeout(() => banner.remove(), 400);
        }
    }, 5000);
    window.history.replaceState({}, '', window.location.pathname);
</script>
<?php endif; ?>

<!-- NAVBAR -->
<nav class="glass sticky top-0 z-50 border-b border-gray-100 dark:border-slate-700 <?= $justLoggedOut ? 'mt-12' : '' ?>">
    <div class="max-w-6xl mx-auto px-4 h-16 flex justify-between items-center">
        <a href="<?= e($baseUrl) ?>/index.php" class="flex items-center gap-2 hover:opacity-80 transition">
            <span class="text-2xl">📔</span>
            <span class="text-gradient font-extrabold text-lg"><?= e(APP_NAME) ?></span>
        </a>
        <div class="flex items-center gap-2">
            <button id="theme-toggle" type="button" class="text-gray-500 dark:text-gray-400 hover:text-primary p-2 rounded-full hover:bg-gray-100 dark:hover:bg-slate-700 transition" title="Mode gelap" aria-label="Ganti tema">
                <svg class="w-5 h-5 theme-icon-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                </svg>
                <svg class="w-5 h-5 theme-icon-sun hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
            </button>
            <a href="<?= e($baseUrl) ?>/login.php" class="btn-shine bg-gradient-to-r from-primary to-purple-600 text-white px-5 py-2 rounded-full text-sm font-semibold hover:shadow-lg transition-all hover:-translate-y-0.5">
                Masuk →
            </a>
        </div>
    </div>
</nav>

<!-- HERO -->
<header class="hero-gradient relative overflow-hidden">
    <div class="absolute top-20 -left-20 w-72 h-72 bg-indigo-300/40 dark:bg-indigo-500/20 rounded-full blur-3xl animate-blob"></div>
    <div class="absolute top-40 -right-20 w-80 h-80 bg-purple-300/40 dark:bg-purple-500/20 rounded-full blur-3xl animate-blob" style="animation-delay:3s"></div>
    <div class="absolute bottom-0 left-1/3 w-64 h-64 bg-blue-300/30 dark:bg-blue-500/20 rounded-full blur-3xl animate-blob" style="animation-delay:6s"></div>

    <div class="relative max-w-4xl mx-auto px-4 pt-20 pb-24 text-center">
        <div class="reveal inline-flex items-center gap-2 bg-white/80 dark:bg-slate-800/80 border border-indigo-100 dark:border-slate-700 px-4 py-1.5 rounded-full text-xs font-semibold text-primary dark:text-indigo-400 mb-8 shadow-sm">
            <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
            Platform Jurnal Kerja PKL
        </div>

        <h1 class="reveal text-4xl md:text-6xl font-extrabold text-gray-900 dark:text-white mb-6 leading-tight tracking-tight">
            Dokumentasi Kegiatan
            <br>
            <span class="text-gradient">Praktik Kerja Lapangan</span>
        </h1>

        <p class="reveal text-base md:text-lg text-gray-600 dark:text-gray-300 max-w-2xl mx-auto mb-10 leading-relaxed">
            Satu tempat untuk mencatat
            <b class="text-gray-900 dark:text-white">jurnal kegiatan</b>,
            <b class="text-gray-900 dark:text-white">presensi</b>,
            <b class="text-gray-900 dark:text-white">tugas</b>,
            dokumentasi, dan komunikasi kelompok PKL.
        </p>

        <div class="reveal mb-14">
            <a href="<?= e($baseUrl) ?>/login.php" class="btn-shine inline-flex items-center gap-2 bg-gradient-to-r from-primary to-purple-600 text-white px-8 py-3.5 rounded-full font-bold text-base hover:shadow-xl transition-all hover:-translate-y-1">
                Mulai Sekarang
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </a>
        </div>

        <div class="reveal flex flex-wrap gap-2 justify-center mb-16">
            <?php
            $features = [
                '📝 Jurnal Kegiatan',
                '✅ Presensi Harian',
                '📷 Dokumentasi Foto',
                '📋 Manajemen Tugas',
                '💬 Obrolan Kelompok',
                '🔔 Notifikasi'
            ];
            ?>
            <?php foreach ($features as $feature): ?>
                <span class="bg-white/90 dark:bg-slate-800/90 border border-indigo-50 dark:border-slate-700 text-gray-700 dark:text-gray-300 text-xs font-medium px-3.5 py-1.5 rounded-full shadow-sm">
                    <?= e($feature) ?>
                </span>
            <?php endforeach; ?>
        </div>

        <!-- STATISTIK -->
        <div class="reveal grid grid-cols-2 md:grid-cols-4 gap-3 max-w-3xl mx-auto">
            <?php
            $statistics = [
                ['Anggota', $total_users],
                ['Jurnal', $total_journals],
                ['Foto', $total_photos],
                ['Tugas Selesai', $total_tasks]
            ];
            ?>
            <?php foreach ($statistics as $stat): ?>
                <div class="bg-white/90 dark:bg-slate-800/90 border border-indigo-50 dark:border-slate-700 rounded-2xl p-5 shadow-sm hover:shadow-md transition">
                    <div class="text-3xl md:text-4xl font-extrabold text-gradient counter" data-target="<?= (int)$stat[1] ?>">0</div>
                    <div class="text-xs text-gray-600 dark:text-gray-400 mt-1 font-medium"><?= e($stat[0]) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</header>

<!-- TIM KELOMPOK -->
<main class="max-w-6xl mx-auto px-4 py-20">
    <div class="reveal text-center mb-8">
        <span class="text-xs font-bold text-primary dark:text-indigo-400 uppercase tracking-[0.2em]">Kenalan</span>
        <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900 dark:text-white mt-3 mb-3">
            Tim Kelompok <span class="text-gradient">Kami</span>
        </h2>
        <p class="text-gray-500 dark:text-gray-400 text-sm md:text-base max-w-md mx-auto">
            <?= count($pembimbings) ?> pembimbing dan <?= count($siswas) ?> siswa PKL siap berkolaborasi.
        </p>
    </div>

    <!-- FILTER TIM -->
    <div class="reveal flex flex-wrap justify-center gap-2 mb-10">
        <button data-filter="all" class="team-filter px-5 py-2 rounded-full text-sm font-semibold bg-primary text-white shadow-sm transition">Semua</button>
        <button data-filter="pembimbing" class="team-filter px-5 py-2 rounded-full text-sm font-semibold bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition">Pembimbing</button>
        <button data-filter="siswa" class="team-filter px-5 py-2 rounded-full text-sm font-semibold bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition">Siswa</button>
    </div>

    <!-- DAFTAR ANGGOTA -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5" id="team-grid">
        <?php foreach ($users as $i => $user): ?>
            <?php
            $isPembimbing = in_array($user['role'], ['pembimbing', 'teacher'], true);
            $roleType     = $isPembimbing ? 'pembimbing' : 'siswa';
            $namaFoto     = $user['name'];

            $foto = !empty($user['photo'])
                ? rtrim(UPLOAD_URL, '/') . '/' . rawurlencode(basename($user['photo']))
                : 'https://ui-avatars.com/api/?name=' . urlencode($namaFoto) . '&background=EEF2FF&color=4F46E5&size=200';

            $jurusan = $user['jurusan'] ?: 'Anggota PKL';
            $bio     = $user['bio'] ?: 'Belum ada deskripsi profil.';
            $sekolah = $user['sekolah'] ?: 'Belum diisi';
            ?>
            <div
                class="team-card reveal bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-slate-700 hover:shadow-xl hover:-translate-y-1 transition-all duration-300 text-center group"
                data-role="<?= e($roleType) ?>"
                style="transition-delay: <?= (int)$i * 50 ?>ms"
            >
                <div class="relative inline-block mb-4">
                    <img
                        src="<?= e($foto) ?>"
                        alt="Foto <?= e($namaFoto) ?>"
                        loading="lazy"
                        onerror="this.onerror=null;this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($namaFoto) ?>&background=EEF2FF&color=4F46E5&size=200'"
                        class="w-24 h-24 rounded-full object-cover mx-auto ring-4 ring-indigo-50 dark:ring-slate-700 group-hover:ring-indigo-100 dark:group-hover:ring-slate-600 transition-all group-hover:scale-105"
                    >
                    <span class="absolute -bottom-1 -right-1 <?= $isPembimbing ? 'bg-gradient-to-br from-amber-400 to-orange-500' : 'bg-gradient-to-br from-indigo-400 to-purple-500' ?> text-white text-[10px] font-bold px-2.5 py-1 rounded-full border-2 border-white dark:border-slate-800 shadow-md whitespace-nowrap">
                        <?= $isPembimbing ? '👨‍🏫 Pembimbing' : '👤 Siswa' ?>
                    </span>
                </div>

                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1 group-hover:text-primary dark:group-hover:text-indigo-400 transition-colors">
                    <?= e($namaFoto) ?>
                </h3>
                <p class="text-sm font-medium text-primary dark:text-indigo-400 mb-3"><?= e($jurusan) ?></p>
                <p class="text-sm text-gray-500 dark:text-gray-400 line-clamp-3 leading-relaxed min-h-[60px]"><?= e($bio) ?></p>

                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-slate-700 text-xs text-gray-400 dark:text-gray-500 flex items-center justify-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    <?= e($sekolah) ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($users)): ?>
            <div class="col-span-full text-center text-gray-500 py-10">Belum ada anggota tim yang terdaftar.</div>
        <?php endif; ?>
    </div>
</main>

<!-- FOOTER -->
<footer class="bg-gray-50 dark:bg-slate-800 border-t border-gray-100 dark:border-slate-700">
    <div class="max-w-6xl mx-auto px-4 py-8">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-2 font-bold">
                <span class="text-xl">📔</span>
                <span class="text-gradient"><?= e(APP_NAME) ?></span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 text-center md:text-right">
                &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Dibuat dengan <span class="text-red-500">❤️</span> untuk Siswa PKL.
            </p>
        </div>
    </div>
</footer>

<!-- TOMBOL KEMBALI KE ATAS -->
<button id="back-top" type="button" class="hidden fixed bottom-6 right-6 bg-gradient-to-br from-primary to-purple-600 text-white p-3.5 rounded-full shadow-lg hover:shadow-xl transition-all hover:-translate-y-1 z-40" title="Ke atas" aria-label="Kembali ke atas">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
    </svg>
</button>

<script>
// ============ MODE GELAP ============
(function () {
    const html = document.documentElement;
    const toggle = document.getElementById('theme-toggle');
    let savedTheme = null;
    try { savedTheme = localStorage.getItem('theme'); } catch (error) {}

    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const darkMode = savedTheme === 'dark' || (!savedTheme && prefersDark);

    if (darkMode) html.classList.add('dark');
    updateIcon(darkMode);

    toggle?.addEventListener('click', function () {
        const isDark = html.classList.toggle('dark');
        try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (error) {}
        updateIcon(isDark);
    });

    function updateIcon(isDark) {
        document.querySelector('.theme-icon-moon')?.classList.toggle('hidden', isDark);
        document.querySelector('.theme-icon-sun')?.classList.toggle('hidden', !isDark);
    }
})();

// ============ ANIMASI REVEAL ============
if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('active');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
} else {
    document.querySelectorAll('.reveal').forEach(el => el.classList.add('active'));
}

// ============ ANIMASI ANGKA STATISTIK ============
if ('IntersectionObserver' in window) {
    const counterObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;

            const element = entry.target;
            const target = parseInt(element.dataset.target, 10) || 0;
            let current = 0;
            const step = Math.max(1, Math.ceil(target / 40));

            const timer = setInterval(function () {
                current += step;
                if (current >= target) { current = target; clearInterval(timer); }
                element.textContent = current;
            }, 30);

            counterObserver.unobserve(element);
        });
    }, { threshold: 0.3 });

    document.querySelectorAll('.counter').forEach(el => counterObserver.observe(el));
} else {
    document.querySelectorAll('.counter').forEach(el => {
        el.textContent = el.dataset.target || '0';
    });
}

// ============ FILTER ANGGOTA TIM ============
document.querySelectorAll('.team-filter').forEach(function (button) {
    button.addEventListener('click', function () {
        document.querySelectorAll('.team-filter').forEach(function (item) {
            item.classList.remove('bg-primary', 'text-white', 'shadow-sm');
            item.classList.add('bg-gray-100', 'dark:bg-slate-800', 'text-gray-700', 'dark:text-gray-300');
        });

        button.classList.add('bg-primary', 'text-white', 'shadow-sm');
        button.classList.remove('bg-gray-100', 'dark:bg-slate-800', 'text-gray-700', 'dark:text-gray-300');

        const filter = button.dataset.filter;
        document.querySelectorAll('.team-card').forEach(function (card) {
            const show = filter === 'all' || card.dataset.role === filter;
            card.style.display = show ? '' : 'none';
            if (show) card.classList.add('active');
        });
    });
});

// ============ KEMBALI KE ATAS ============
const backButton = document.getElementById('back-top');
if (backButton) {
    window.addEventListener('scroll', function () {
        backButton.classList.toggle('hidden', window.scrollY < 400);
    });
    backButton.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}
</script>

</body>
</html>